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
require_once __DIR__ . '/oauth-state.php';
require_once __DIR__ . '/oauth-notice.php';
require_once __DIR__ . '/google-client-factory.php';

/**
 * OAuth start handler: admin-post action `fiftyonedegrees_oauth_start`.
 *
 * The Connect button on the Google Analytics admin tab posts to
 * admin-post.php?action=fiftyonedegrees_oauth_start with a WP nonce.
 * This handler runs the safety gauntlet (capability, nonce, multisite,
 * HTTPS), generates a state + PKCE verifier pair via OauthState,
 * builds the Google authorization URL, and redirects the browser
 * there. The verifier is stashed in the per-nonce transient that
 * verify_state will read back on callback (see oauth-callback.php).
 *
 * The PKCE code_challenge is sent to Google through the second argument
 * of Google_Client::createAuthUrl(). The vendored google/apiclient v2.x
 * does not expose dedicated setCodeChallenge/setCodeChallengeMethod
 * setters; instead, $queryParams are merged into the auth URL by
 * createAuthUrl itself (array_filter([...config...]) + $queryParams —
 * see lib/vendor/google/apiclient/src/Client.php:443). DO NOT add
 * setCodeChallenge/setCodeChallengeMethod here without first removing
 * the createAuthUrl second-arg branch; both would otherwise duplicate.
 *
 * The matching code_verifier travels via the transient set in the state
 * engine (oauth-state.php) and is
 * consumed on the callback side through
 * Google_Client::fetchAccessTokenWithAuthCode($code, $codeVerifier), which
 * forwards it as code_verifier= in the token POST. See oauth-callback.php.
 *
 * The final wp_redirect to accounts.google.com deliberately uses
 * wp_redirect (not wp_safe_redirect): the latter restricts the Location
 * header to wp_allowed_redirect_hosts (same-host by default) and would
 * silently fall back to admin_url, breaking the flow end-to-end. The
 * target URL is built entirely from trusted constants + vendored
 * library + our own state — no user input flows in.
 *
 * Properties guaranteed by this handler:
 *   - No transient bloat: state is created only on actual click, not on
 *     every admin page render.
 *   - CSRF defence on start: WP nonce + check_admin_referer().
 *   - Unambiguous site URL: admin_url() is the single source.
 *   - HTTPS only: scheme check via home_url() before creating any state.
 *   - Multisite guard: OAuth flow is single-site only in this release.
 *
 * See oauth-callback.php for the consumer side of the flow (verify_state
 * -> delete_transient -> authenticate -> save_token).
 */
class FiftyOneDegreesOauthStart
{
    /**
     * WP nonce action shared with the Connect button render in
     * google-analytics.php. The
     * button calls wp_nonce_field(self::NONCE_ACTION) and this handler
     * verifies the same slug via check_admin_referer.
     */
    public const NONCE_ACTION = 'fiftyonedegrees_oauth_start';

    /**
     * Entry point. Hooked on admin_post_fiftyonedegrees_oauth_start by
     * fiftyonedegrees.php::setup_oauth_actions().
     */
    public static function handle()
    {
        // a. Login + capability. Unauthenticated requests go to wp-login;
        // there is no notice channel for them. An authenticated non-admin
        // also lands on the login screen — that matches WP's own pattern
        // for admin-post handlers that require manage_options.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_safe_redirect(wp_login_url());
            static::halt();
            return;
        }

        // b. CSRF: WP nonce. check_admin_referer() calls wp_die() on a
        // missing or bad nonce, which is the correct behavior for an
        // admin-post handler. Tests stub it to throw instead.
        check_admin_referer(self::NONCE_ACTION);

        $user_id = (int) get_current_user_id();

        // c. Multisite is out of scope for this release (roadmap: 1.0.13).
        if (is_multisite()) {
            self::reject('multisite_unsupported', $user_id);
            return;
        }

        // d. HTTPS gate. We read the scheme from home_url() — the
        // DB-stored siteurl — rather than $_SERVER. is_ssl() depends on
        // request-time server vars that may not reflect the external
        // scheme when WP sits behind a TLS-terminating proxy without a
        // matching site URL configuration. Trade-off: a site on a TLS
        // proxy where the admin has not yet updated General Settings to
        // https:// will fail this gate even though the request itself
        // is encrypted.
        if (parse_url((string) home_url(), PHP_URL_SCHEME) !== 'https') {
            self::reject('https_required', $user_id);
            return;
        }

        // e-f. Generate PKCE verifier + challenge, then build a state
        // string. create_state stashes the {user_id, verifier} pair in
        // a per-nonce transient that the callback will look up via
        // verify_state.
        try {
            $verifier = FiftyOneDegreesOauthState::generate_code_verifier();
            $challenge = FiftyOneDegreesOauthState::code_challenge_from($verifier);
            $state = FiftyOneDegreesOauthState::create_state($user_id, $verifier);
        } catch (FiftyOneDegreesOauthStateException $e) {
            // Typed branch: secret_corrupt / encode_failure / invalid_user_id.
            // $e->reason is a slug that maps 1:1 to an oauth.notice.* key.
            self::reject($e->reason, $user_id);
            return;
        } catch (\Throwable $e) {
            // Last-resort catch for random_bytes() CSPRNG failure or any
            // future non-typed crash from the state engine. Without this
            // the request would bubble out as a WSOD and the transient
            // table stays clean — but the admin sees nothing actionable.
            error_log('51Degrees OAuth start exception: ' . $e->getMessage());
            self::reject('start_failed', $user_id);
            return;
        }

        // g. Build the auth URL via the Google client. The factory has
        // already applied the fiftyonedegrees_oauth_redirect_url filter
        // to setRedirectUri, so we only attach the run-time state here.
        //
        // PKCE: the vendored google/apiclient does not surface
        // code_challenge as a config key, so PKCE params go through
        // createAuthUrl's second argument. DO NOT add
        // setCodeChallenge/setCodeChallengeMethod here without first
        // removing this branch — both would otherwise duplicate.
        $client = static::build_client();
        $client->setState($state);

        $url = $client->createAuthUrl(null, [
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        // h. Off to Google. wp_redirect (not wp_safe_redirect) — see
        // class docblock for the host-allowlist rationale.
        wp_redirect($url);
        static::halt();
    }

    /**
     * Stores a notice slug, fires the observability action, and PRGs
     * back to the GA admin tab. Mirrors FiftyOneDegreesOauthCallback::reject
     * so the same `fiftyonedegrees_oauth_rejection` hook fires from both
     * sides of the flow; the leaf slug matches a key under oauth.notice.*
     * in languages/oauth-strings.yaml.
     */
    private static function reject($branch, $user_id, array $context = [])
    {
        do_action('fiftyonedegrees_oauth_rejection', $branch, $user_id, $context);
        FiftyOneDegreesOauthNotice::set($branch);
        wp_safe_redirect(admin_url(
            'options-general.php?page=51Degrees&tab=google-analytics'
        ));
        static::halt();
    }

    /**
     * Test seam over the Google_Client construction. Production delegates
     * to the shared factory; tests override to inject a mock without
     * touching the real apiclient.
     *
     * @return Google_Client
     */
    protected static function build_client()
    {
        return FiftyOneDegreesGoogleClientFactory::make();
    }

    /**
     * Test seam over `exit`. Production exits after the wp_redirect /
     * wp_safe_redirect to prevent any further admin_post handler from
     * running. Tests override to flag and return so PHPUnit isn't killed.
     */
    protected static function halt()
    {
        exit;
    }
}
