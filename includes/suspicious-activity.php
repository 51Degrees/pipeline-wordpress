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
require_once __DIR__ . '/client-ip.php';
require_once __DIR__ . '/bot-exempt-paths.php';

/**
 * Suspicious activity detection engine.
 *
 * Tracks per-visitor request rates using WordPress transients and
 * redirects visitors who exceed a configured threshold.
 *

 */
class SuspiciousActivity
{
    /**
     * Registers the parse_request hook for suspicious activity checking.
     * Priority 11 ensures rest_api_loaded (priority 10) runs first: for
     * REST requests it defines REST_REQUEST and exits, so our hook never
     * fires for them. For non-REST requests REST_REQUEST remains
     * undefined, which the exclusion check handles.
     *
     * @access public
     *
     * @return void
     */
    public static function register()
    {
        add_action('parse_request', [__CLASS__, 'check_and_maybe_redirect'], 11);
    }

    /**
     * Main entry point called on parse_request at priority 11.
     * Checks exclusions, records the request, and redirects if threshold met.
     *
     * @access public
    
     * @return void
     */
    public static function check_and_maybe_redirect()
    {
        if (get_option(Options::SUSPICIOUS_ENABLE, 'off') !== 'on') {
            return;
        }

        if (self::is_excluded_context()) {
            return;
        }

        $window = (int) get_option(Options::SUSPICIOUS_WINDOW, 30);
        $threshold = (int) get_option(Options::SUSPICIOUS_REQUESTS, 5);

        if ($window <= 0 || $threshold <= 0) {
            return;
        }

        if (self::is_on_redirect_target()) {
            return;
        }

        if (headers_sent()) {
            error_log('Suspicious activity: cannot redirect, headers already sent');
            return;
        }

        $did = self::get_51did();
        $key = self::build_transient_key($did);
        $now = microtime(true);

        $timestamps = get_transient($key);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Prune expired entries.
        $timestamps = array_values(array_filter(
            $timestamps,
            function ($t) use ($now, $window) {
                return $t >= $now - $window;
            }
        ));

        $timestamps[] = $now;
        set_transient($key, $timestamps, $window);

        if (count($timestamps) >= $threshold) {
            $target = get_option(Options::SUSPICIOUS_REDIRECT_URL);
            if (!$target) {
                return;
            }
            wp_safe_redirect($target, 302);
            exit;
        }
    }

    /**
     * Resolves the visitor identity.
     * Tries IdProbLic then IdProbGlobal from the pipeline, falling back
     * to a SHA-256 hash of IP + User-Agent.
     *
     * @access public
    
     * @return string visitor identifier (always returns a value)
     */
    public static function get_51did()
    {
        $properties = Pipeline::$data['properties'] ?? [];

        foreach (['idproblic', 'idprobglobal'] as $propName) {
            foreach ($properties as $engine => $props) {
                if (isset($props[$propName])) {
                    $value = Pipeline::get($engine, $propName);
                    if (is_string($value) && $value !== '') {
                        $identifier = self::extract_owid_identifier($value);
                        if ($identifier !== null) {
                            return $identifier;
                        }
                    }
                }
            }
        }

        $ip = ClientIpResolver::resolve() ?: '127.0.0.1';
        $ua = sanitize_text_field(
            wp_unslash(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '')
        );
        return hash('sha256', $ip . '|' . $ua);
    }

    /**
     * OWID envelope version this reader understands.
     */
    const OWID_VERSION = 3;

    /**
     * Byte length of the OWID creation date field under version 3.
     */
    const OWID_DATE_LENGTH = 4;

    /**
     * Byte length of the little-endian field that gives the payload
     * length.
     */
    const OWID_PAYLOAD_LENGTH_FIELD = 4;

    /**
     * Byte length of the signature that closes an OWID envelope.
     */
    const OWID_SIGNATURE_LENGTH = 64;

    /**
     * Byte length of the flags field that opens a 51Did payload.
     */
    const DID_FLAGS_LENGTH = 1;

    /**
     * Byte length of the licence field that follows the flags.
     */
    const DID_LICENCE_LENGTH = 4;

    /**
     * Byte length of the payload header, being the flags and the licence
     * field, which every identifier type shares.
     */
    const DID_HEADER_LENGTH = self::DID_FLAGS_LENGTH + self::DID_LICENCE_LENGTH;

    /**
     * Byte length of the SHA-256 value carried by the Probabilistic and
     * HashedEmail identifier types.
     */
    const DID_HASH_LENGTH = 32;

    /**
     * Byte length of the GUID value carried by the Random identifier
     * type.
     */
    const DID_GUID_LENGTH = 16;

    /**
     * Identifier type derived from the device fingerprint and the IP
     * address. Identifiers issued before the type bits were assigned
     * carry zeroes there, so they read as this type.
     */
    const DID_TYPE_PROBABILISTIC = 0;

    /**
     * Identifier type holding a server-generated random GUID.
     */
    const DID_TYPE_RANDOM = 1;

    /**
     * Identifier type derived from a caller-supplied email and salt.
     */
    const DID_TYPE_HASHED_EMAIL = 2;

    /**
     * Extracts the stable value from a base64-encoded 51Did (an OWID v3
     * envelope) and returns it as a hex string.
     *
     * The envelope's ECDSA signature is regenerated on every request
     * (random nonce), so hashing the full token would produce a new
     * tracking key per request. The value is what the cloud computed
     * from the visitor and is stable per visitor, so that is what this
     * returns.
     *
     * The envelope is parsed rather than read at fixed offsets, because
     * neither the creator domain nor the payload has a fixed length. A
     * self-hosted deployment signs with its own domain, which moves the
     * payload, and an identifier carrying a creator context has extra
     * bytes after the value, which this reader keeps out of the value
     * and does not interpret.
     *
     * A Probabilistic or HashedEmail identifier gives 64 hex characters
     * and a Random one gives 32, because the value itself is shorter.
     * The caller only uses the result as a per-visitor key, so the
     * shorter string is still a complete and stable identity.
     *
     * @access public
     *
     * @param  mixed       $token base64-encoded 51Did
     * @return string|null hex value, or null on malformed input
     */
    public static function extract_owid_identifier($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $normalized = strtr($token, '-_', '+/');
        $pad = (4 - strlen($normalized) % 4) % 4;
        $decoded = base64_decode($normalized . str_repeat('=', $pad), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        $payload = self::read_owid_payload($decoded);
        if ($payload === null) {
            return null;
        }
        $value = self::read_did_value($payload);
        if ($value === null) {
            return null;
        }
        return bin2hex($value);
    }

    /**
     * Reads the payload out of an OWID envelope, which is a version
     * byte, the creator domain as ASCII with a zero terminator, the
     * date, the payload length as four little-endian bytes, the payload
     * itself and then the signature.
     *
     * Nothing here assumes a total length. The envelope only has to be
     * long enough for the payload the length field asks for and the
     * signature that follows it, so an envelope that later grows keeps
     * working.
     *
     * @access private
     *
     * @param  string      $envelope raw decoded envelope bytes
     * @return string|null payload bytes, or null when malformed
     */
    private static function read_owid_payload($envelope)
    {
        if (ord($envelope[0]) !== self::OWID_VERSION) {
            return null;
        }
        $terminator = strpos($envelope, "\x00", 1);
        if ($terminator === false) {
            return null;
        }
        $offset = $terminator + 1 + self::OWID_DATE_LENGTH;
        if (strlen($envelope) < $offset + self::OWID_PAYLOAD_LENGTH_FIELD) {
            return null;
        }
        $field = unpack(
            'V',
            substr($envelope, $offset, self::OWID_PAYLOAD_LENGTH_FIELD)
        );
        $length = $field[1];
        $offset += self::OWID_PAYLOAD_LENGTH_FIELD;
        $available = strlen($envelope) - $offset - self::OWID_SIGNATURE_LENGTH;
        if ($available < $length) {
            return null;
        }
        return substr($envelope, $offset, $length);
    }

    /**
     * Reads the value out of a 51Did payload, which is the flags byte,
     * the licence field, the value and then an optional creator context
     * section this plugin does not interpret.
     *
     * Bits 6 and 7 of the flags give the identifier type, and the type
     * gives the length of the value, so the context section that may
     * follow is never taken to be part of the value however long it is.
     *
     * @access private
     *
     * @param  string      $payload raw payload bytes
     * @return string|null value bytes, or null when malformed or when
     *                     the type is one this plugin cannot read
     */
    private static function read_did_value($payload)
    {
        if (strlen($payload) < self::DID_HEADER_LENGTH) {
            return null;
        }
        $type = (ord($payload[0]) >> 6) & 0x03;
        if ($type === self::DID_TYPE_RANDOM) {
            $length = self::DID_GUID_LENGTH;
        } elseif ($type === self::DID_TYPE_PROBABILISTIC
            || $type === self::DID_TYPE_HASHED_EMAIL) {
            $length = self::DID_HASH_LENGTH;
        } else {
            // The remaining type is not assigned yet, so the length of
            // its value is unknown and guessing one would produce a key
            // that is not stable. The caller falls back instead.
            return null;
        }
        if (strlen($payload) < self::DID_HEADER_LENGTH + $length) {
            return null;
        }
        return substr($payload, self::DID_HEADER_LENGTH, $length);
    }

    /**
     * Finds the engine dataKey that exposes IdProbLic or IdProbGlobal.
     * Used by the admin UI to display the active tracking mode.
     *
     * @access public
    
     * @return string|null engine dataKey, or null if not available
     */
    public static function id_engine_datakey()
    {
        $properties = Pipeline::$data['properties'] ?? [];
        foreach ($properties as $engine => $props) {
            if (isset($props['idproblic']) || isset($props['idprobglobal'])) {
                return $engine;
            }
        }
        return null;
    }

    /**
     * Builds the transient key for a given visitor identity.
     *
     * @access public
    
     * @param  string $did visitor identifier
     * @return string transient key (47 chars: "51d_suspicious_" + md5)
     */
    public static function build_transient_key($did)
    {
        return '51d_suspicious_' . md5($did);
    }

    /**
     * Returns true if the current request context should be excluded
     * from suspicious activity tracking.
     *
     * @access private
    
     * @return bool
     */
    private static function is_excluded_context()
    {
        if (is_admin()) {
            return true;
        }

        if (wp_doing_ajax()) {
            return true;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }

        if (php_sapi_name() === 'cli') {
            return true;
        }

        $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
        if (in_array($script, ['wp-login.php', 'wp-signup.php', 'wp-activate.php'], true)) {
            return true;
        }

        // Bot-facing policy files (robots.txt, ads.txt, /.well-known/*) are
        // meant to be fetched by crawlers; counting them as suspicious
        // activity redirects the bot away from the policies it was asked to
        // read. Mirrors the matching skip in robots-txt.php.
        if (fiftyonedegrees_is_bot_exempt_path(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/')) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the current request is for the suspicious redirect
     * target or the robots.txt bot redirect target. Skipping both prevents
     * loops with the suspicious feature itself and the robots.txt feature
     * (a bot redirected to its destination must not then be redirected
     * onward as suspicious).
     *
     * Resolves URLs to a WordPress post ID. This catches the case where
     * the target page is the static front page: WordPress canonicalises
     * /page-slug/ to / for the home request, and a plain path comparison
     * would treat them as different and loop.
     *
     * Note: FiftyoneService::current_page_is_pmp_exempt() (issue #61) does
     * structurally similar URL-vs-options matching with a couple of bug-
     * fix improvements (lowercase + URL-decode + root foot-gun guard +
     * subdir-multisite path stripping). If a third call-site appears the
     * two should be consolidated into a shared helper.
     *
     * @access private
     *
     * @return bool
     */
    private static function is_on_redirect_target()
    {
        $targets = array_filter([
            get_option(Options::SUSPICIOUS_REDIRECT_URL),
            get_option(Options::ROBOTS_REDIRECT_URL),
        ]);
        if (empty($targets)) {
            return false;
        }

        $current_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $current_path = trailingslashit(strtok($current_uri, '?'));
        $current_id = function_exists('url_to_postid') && function_exists('home_url')
            ? url_to_postid(home_url($current_uri))
            : 0;

        foreach ($targets as $target) {
            if ($current_id > 0) {
                $target_id = url_to_postid($target);
                if ($target_id > 0 && $current_id === $target_id) {
                    return true;
                }
            }
            $target_path = trailingslashit(wp_parse_url($target, PHP_URL_PATH) ?: '/');
            if ($current_path === $target_path) {
                return true;
            }
        }

        return false;
    }

    public static function delete_options() {
        delete_option(Options::SUSPICIOUS_ENABLE);
        delete_option(Options::SUSPICIOUS_REDIRECT_URL);
        delete_option(Options::SUSPICIOUS_REQUESTS);
        delete_option(Options::SUSPICIOUS_WINDOW);
    }
}

if (function_exists('add_action')) {
    SuspiciousActivity::register();
}
