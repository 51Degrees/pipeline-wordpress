<?php

/*
    This Original Work is copyright of 51 Degrees Mobile Experts Limited.
    Copyright 2019 51 Degrees Mobile Experts Limited, 5 Charlotte Close,
    Caversham, Reading, Berkshire, United Kingdom RG4 7BY.

    This Original Work is licensed under the European Union Public Licence (EUPL)
    v.1.2 and is subject to its terms as set out below.

    If a copy of the EUPL was not distributed with this file, You can obtain
    one at https://opensource.org/licenses/EUPL-1.2.

    The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
    amended by the European Commission) shall be deemed incompatible for
    the purposes of the Work and the provisions of the compatibility
    clause in Article 5 of the EUPL shall not apply.
*/

require_once __DIR__ . '/../options.php';

/**
 * Thrown by FiftyOneDegreesOauthState when state cannot be created or
 * verified. The $reason property is a stable, machine-readable token
 * that maps 1-to-1 to an i18n notice key in languages/oauth-strings.yaml
 * (e.g. 'bad_hmac' -> 'oauth.notice.bad_hmac').
 *
 * Possible reasons:
 *   - 'malformed'         — state string is not well-formed (bad separator,
 *                           base64, JSON, or missing fields)
 *   - 'bad_hmac'          — HMAC signature does not match
 *   - 'expired_state'     — state.expiry is in the past
 *   - 'host_mismatch'     — state.site_url host differs from expected host
 *   - 'missing_transient' — paired transient row is gone (TTL expired,
 *                           already-consumed, or never created)
 *   - 'user_mismatch'     — transient.user_id differs from current user
 *   - 'secret_corrupt'    — OAUTH_STATE_SECRET option exists but is too
 *                           short to be a valid secret; never auto-overwritten,
 *                           OR the option is absent on the verification path
 *                           (verify_state intentionally does not lazy-create)
 *   - 'encode_failure'    — wp_json_encode failed to serialize the payload
 *                           (e.g. non-UTF-8 input from a future caller); only
 *                           reachable from create_state
 *   - 'invalid_user_id'   — caller passed user_id <= 0 to create_state or
 *                           verify_state (typically means an unauthenticated
 *                           request reached this layer)
 */
class FiftyOneDegreesOauthStateException extends Exception
{
    public $reason;

    public function __construct($reason)
    {
        parent::__construct($reason);
        $this->reason = $reason;
    }
}

/**
 * OAuth state-and-PKCE engine.
 *
 * Builds and verifies the state parameter we send to Google through the
 * 51Degrees relay. State carries enough information to prove that a
 * callback was initiated by this site, by this admin, recently, and
 * (under PKCE) was paired with a verifier this site still remembers.
 *
 * Wire format:
 *   state_string := b64url(json(payload)) "." b64url(hmac_sha256(b64_payload, secret))
 *
 *   payload := {
 *       "v":        1,
 *       "site_url": admin_url('options-general.php?page=51Degrees&tab=google-analytics&oauth=callback'),
 *       "nonce":    32-hex-char random,
 *       "expiry":   unix-timestamp + STATE_TTL
 *   }
 *
 * The {user_id, code_verifier} pair is stored separately in a transient
 * keyed by nonce so that it never leaves our server (the state parameter
 * travels through Google and the 51Degrees relay; the transient does not).
 */
class FiftyOneDegreesOauthState
{
    public const TRANSIENT_PREFIX = 'fiftyonedegrees_oauth_pending_';
    public const STATE_TTL = 600;
    public const PAYLOAD_VERSION = 1;
    public const MIN_SECRET_LEN = 32;
    /** 16 random bytes -> 32 hex chars; collision-resistant nonce per state. */
    public const NONCE_BYTES = 16;

    /**
     * Returns the per-site HMAC secret, lazily initializing it on first call.
     *
     * Uses add_option(..., '', 'no') so the row is created with autoload=no
     * (we don't want to pull this on every request) and so a concurrent
     * second caller during initialization loses the race cleanly (returns
     * false from add_option, then re-reads the winner's value).
     *
     * Generated via random_bytes (CSPRNG) rather than wp_generate_password
     * to avoid the filter override surface — plugins can replace
     * wp_generate_password and silently weaken entropy. Hex encoding gives
     * 64 chars from 32 random bytes, comfortably above MIN_SECRET_LEN and
     * consistent with how nonces and PKCE verifiers are generated below.
     *
     * Throws FiftyOneDegreesOauthStateException('secret_corrupt') if the
     * option exists but the value is too short to be a real secret. We
     * never auto-overwrite a corrupted value because doing so would
     * invalidate any state strings that are still in-flight.
     */
    public static function get_or_create_secret()
    {
        $existing = get_option(Options::OAUTH_STATE_SECRET);
        if (is_string($existing) && strlen($existing) >= self::MIN_SECRET_LEN) {
            return $existing;
        }
        if ($existing !== false) {
            throw new FiftyOneDegreesOauthStateException('secret_corrupt');
        }

        $secret = bin2hex(random_bytes(32));
        $added = add_option(Options::OAUTH_STATE_SECRET, $secret, '', 'no');
        if ($added) {
            return $secret;
        }

        $winner = get_option(Options::OAUTH_STATE_SECRET);
        if (is_string($winner) && strlen($winner) >= self::MIN_SECRET_LEN) {
            return $winner;
        }

        throw new FiftyOneDegreesOauthStateException('secret_corrupt');
    }

    /**
     * Reads the per-site HMAC secret without creating it.
     *
     * Used by verify_state so that the verification path stays read-only:
     * we don't want a probing callback to trigger a DB write, and an
     * accidental deletion of the secret row must surface as a verifiable
     * error rather than silently being papered over with a fresh secret
     * (which would permanently invalidate any in-flight states).
     */
    private static function read_secret_or_fail()
    {
        $existing = get_option(Options::OAUTH_STATE_SECRET);
        if (!is_string($existing) || strlen($existing) < self::MIN_SECRET_LEN) {
            throw new FiftyOneDegreesOauthStateException('secret_corrupt');
        }

        return $existing;
    }

    /**
     * Builds a signed state string and stashes the paired {user_id,
     * code_verifier} record in a per-nonce transient with STATE_TTL TTL.
     *
     * @param int         $user_id       admin who initiated the flow (must be > 0)
     * @param null|string $code_verifier optional PKCE verifier (S256)
     * @return string the state string to embed in the auth URL
     * @throws FiftyOneDegreesOauthStateException on invalid user_id,
     *         missing/corrupt secret, or payload encoding failure
     */
    public static function create_state($user_id, $code_verifier = null)
    {
        if ((int) $user_id <= 0) {
            throw new FiftyOneDegreesOauthStateException('invalid_user_id');
        }

        $secret = self::get_or_create_secret();
        $payload = [
            'v' => self::PAYLOAD_VERSION,
            'site_url' => admin_url('options-general.php?page=51Degrees&tab=google-analytics&oauth=callback'),
            'nonce' => bin2hex(random_bytes(self::NONCE_BYTES)),
            'expiry' => static::now() + self::STATE_TTL,
        ];

        $payload_json = wp_json_encode($payload);
        if (!is_string($payload_json)) {
            throw new FiftyOneDegreesOauthStateException('encode_failure');
        }

        $b64_payload = self::b64url_encode($payload_json);
        $hmac = hash_hmac('sha256', $b64_payload, $secret, true);
        $state = $b64_payload . '.' . self::b64url_encode($hmac);

        $transient_value = [
            'user_id' => (int) $user_id,
            'code_verifier' => $code_verifier,
        ];
        set_transient(
            self::TRANSIENT_PREFIX . $payload['nonce'],
            $transient_value,
            self::STATE_TTL
        );

        return $state;
    }

    /**
     * Verifies state against secret, expiry, expected host, and the paired
     * transient (existence + user_id match). Does NOT delete the transient
     * — that is the caller's responsibility. The callback handler must
     * delete the transient atomically with respect to the code exchange
     * to prevent state replay (see the callback handler's class-level
     * comment for the ordering rationale).
     *
     * The secret is read but never created on this path: an absent
     * secret option surfaces as 'secret_corrupt' rather than being
     * silently re-initialized with a fresh value (which would
     * permanently invalidate any in-flight states).
     *
     * The host check binds the state to the site that issued it.
     * $expected_host MUST be the current request host (typically
     * $_SERVER['HTTP_HOST']); the payload's site_url comes from
     * admin_url(), which reads the DB-stored siteurl, so a spoofed
     * Host header produces a mismatch.
     *
     * @param string $state              raw state string from the callback URL
     * @param int    $expected_user_id   currently authenticated admin (must be > 0)
     * @param string $expected_host      current request host
     *                                   ($_SERVER['HTTP_HOST'], NOT SERVER_NAME
     *                                   or anything derived from siteurl)
     * @return array{payload: array, transient: array, nonce: string}
     *         Decoded payload, transient body, and nonce on success
     * @throws FiftyOneDegreesOauthStateException on any failure mode
     */
    public static function verify_state($state, $expected_user_id, $expected_host)
    {
        if ((int) $expected_user_id <= 0) {
            throw new FiftyOneDegreesOauthStateException('invalid_user_id');
        }

        if (!is_string($state) || strpos($state, '.') === false) {
            throw new FiftyOneDegreesOauthStateException('malformed');
        }
        list($b64_payload, $b64_hmac) = explode('.', $state, 2);

        $payload_json = self::b64url_decode($b64_payload);
        $hmac_bytes = self::b64url_decode($b64_hmac);
        if ($payload_json === null || $hmac_bytes === null) {
            throw new FiftyOneDegreesOauthStateException('malformed');
        }

        $secret = self::read_secret_or_fail();
        $expected = hash_hmac('sha256', $b64_payload, $secret, true);
        if (!hash_equals($expected, $hmac_bytes)) {
            throw new FiftyOneDegreesOauthStateException('bad_hmac');
        }

        $payload = json_decode($payload_json, true);
        if (!is_array($payload) ||
            !isset($payload['v'], $payload['site_url'], $payload['nonce'], $payload['expiry'])
        ) {
            throw new FiftyOneDegreesOauthStateException('malformed');
        }

        if ((int) $payload['expiry'] < static::now()) {
            throw new FiftyOneDegreesOauthStateException('expired_state');
        }

        $payload_host = parse_url($payload['site_url'], PHP_URL_HOST);
        if (!is_string($payload_host) ||
            strcasecmp($payload_host, (string) $expected_host) !== 0
        ) {
            throw new FiftyOneDegreesOauthStateException('host_mismatch');
        }

        $transient = get_transient(self::TRANSIENT_PREFIX . $payload['nonce']);
        if (!is_array($transient) || !isset($transient['user_id'])) {
            throw new FiftyOneDegreesOauthStateException('missing_transient');
        }
        if ((int) $transient['user_id'] !== (int) $expected_user_id) {
            throw new FiftyOneDegreesOauthStateException('user_mismatch');
        }

        return [
            'payload' => $payload,
            'transient' => $transient,
            'nonce' => $payload['nonce'],
        ];
    }

    /**
     * Generates a PKCE code_verifier (RFC 7636): 64 hex chars, all in the
     * legal alphabet [A-Za-z0-9]. We don't reuse wp_generate_password
     * because its "special chars" mode produces characters outside the
     * verifier alphabet.
     *
     * @return string
     */
    public static function generate_code_verifier()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Computes the S256 code_challenge for a given verifier, per RFC 7636:
     *   challenge = base64url(sha256(verifier))
     *
     * @param string $verifier PKCE verifier produced by generate_code_verifier
     * @return string
     */
    public static function code_challenge_from($verifier)
    {
        return self::b64url_encode(hash('sha256', $verifier, true));
    }

    /**
     * Override point for tests. Default returns the current Unix time.
     */
    protected static function now()
    {
        return time();
    }

    /**
     * Uninstall hook contribution — removes the per-site HMAC secret.
     * The secret is single-row, autoload=no, but leaving it behind would
     * mean any future re-install of the plugin would silently keep
     * validating state strings from the previous install (until the new
     * site URL diverges, anyway). Symmetric with how SuspiciousActivity
     * and FiftyOneDegreesRobotsTxt expose delete_options() for the
     * top-level uninstall sweep.
     */
    public static function delete_options()
    {
        delete_option(Options::OAUTH_STATE_SECRET);
    }

    /**
     * Cron entry point: invokes cleanup_expired_pending under try/catch
     * and logs the count when non-zero. Registered as a listener on the
     * existing daily hook (`fiftyonedegrees_refresh_robots_txt`) from
     * setup_oauth_actions() so the OAuth subsystem owns its own wire-up
     * and the robots module does not need to know about OAuth.
     *
     * Piggy-backing on the existing daily hook (rather than registering
     * a second wp_schedule_event) is the deliberate tradeoff: shared
     * cron cost, but the cleanup fires whether or not the robots feature
     * is enabled — independent listeners on the same hook.
     *
     * The count + failure logs are written through error_log() rather
     * than a verbose WP debug logger because this runs on every install
     * including production; gating behind WP_DEBUG keeps shared-host
     * error logs quiet when nothing went wrong.
     */
    public static function cron_cleanup()
    {
        try {
            $count = self::cleanup_expired_pending();
            if ($count > 0 && defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '51Degrees: cleaned %d orphan oauth-pending transient(s)',
                    $count
                ));
            }
        } catch (\Throwable $e) {
            // Failures are always logged — silent breakage of the daily
            // sweep is worse than a noisy log line on a broken install.
            error_log('51Degrees: oauth-pending cleanup failed: ' . $e->getMessage());
        }
    }

    /**
     * Removes expired oauth-pending transient rows that the WP transient
     * API leaves behind in wp_options when their TTL passes without anyone
     * reading them (abandoned flows: user closed the tab, Google errored,
     * relay never returned). Returns the number of rows acted on so the
     * caller can log.
     *
     * Safe to call when $wpdb is unavailable (returns 0); cron_cleanup()
     * additionally wraps in try/catch so a DB hiccup never escapes.
     *
     * Single-blog scope: $wpdb->options resolves to the current blog's
     * options table. On multisite, orphans on other subsites are not
     * cleaned by this call — multisite OAuth support is deferred to 1.0.13.
     *
     * @internal Public for testability and the cron wrapper only —
     *           callers outside this class should go through cron_cleanup().
     */
    public static function cleanup_expired_pending()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        // SUBSTRING is anchored to the literal prefix, unlike REPLACE which
        // would strip every occurrence of '_transient_timeout_' anywhere in
        // the row — theoretical robustness since the prefix never appears
        // inside the bin2hex nonce, but the anchored form has no downside.
        $prefix    = '_transient_timeout_';
        $like      = $prefix . $wpdb->esc_like(self::TRANSIENT_PREFIX) . '%';
        $sql = $wpdb->prepare(
            "SELECT SUBSTRING(option_name, " . (strlen($prefix) + 1) . ")
             FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_value < %d",
            $like,
            static::now()
        );

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            delete_transient($key);
            $count++;
        }

        return $count;
    }

    private static function b64url_encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64url_decode($s)
    {
        if (!is_string($s) || $s === '') {
            return null;
        }
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($s, true);

        return $decoded === false ? null : $decoded;
    }
}
