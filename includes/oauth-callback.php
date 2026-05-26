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
 * OAuth callback handler: admin_init priority 5.
 *
 * Receives the redirect from the 51Degrees relay (Google -> relay -> our
 * site_url?oauth=callback&code=...&state=...), runs the full safety
 * gauntlet (HMAC verify, host check, capability, multisite guard,
 * paired transient + user_id match), exchanges the code for a token via
 * the Google API client (with PKCE verifier when present), saves the
 * token, and PRG-redirects to a clean URL so a browser refresh does
 * not replay the exchange.
 *
 * Order of operations on the happy path (Pre-mortem R-1):
 *
 *   verify_state -> delete_transient(nonce) -> authenticate($code)
 *     -> update_option(GA_TOKEN) -> update_option(GA_AUTH_DATE) -> PRG
 *
 * The transient is consumed BEFORE the exchange call. This NARROWS the
 * state-replay window — Google's single-use enforcement on the authorization
 * code is what fully closes it. Two concurrent callbacks racing the same
 * state value can both pass verify_state (which only reads the transient,
 * see oauth-state.php::verify_state), both proceed to delete_transient,
 * and both call authenticate(): the second exchange fails because Google
 * burned the code on the first call. The early delete still helps any
 * later-arriving racer (e.g. a browser refresh) by collapsing them onto
 * the cheap `missing_transient` branch. The cost is that an exception
 * inside authenticate() also burns the transient (deliberate) — the user
 * simply restarts the flow via S-8 and gets a fresh state.
 *
 * Each rejection branch fires do_action('fiftyonedegrees_oauth_rejection',
 * $branch, $user_id, $context) for observability, sets a short-lived
 * notice transient, and PRG-redirects to a clean admin URL. The branch
 * slug matches the leaf key under oauth.notice.* in
 * languages/oauth-strings.yaml.
 */
class FiftyOneDegreesOauthCallback
{
    /**
     * Entry point. Hooked on admin_init priority 5 so it runs before
     * the rest of the admin bootstrap notices the empty token.
     */
    public static function handle()
    {
        // a. Early skip: not our callback. We're on every admin page load
        // at priority 5, so be cheap and silent here.
        if (!isset($_GET['oauth']) || $_GET['oauth'] !== 'callback') {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== '51Degrees') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'google-analytics') {
            return;
        }

        // b. Capability + login. An unauthenticated request never had a
        // legitimate state, so we bounce to wp-login without consuming
        // anything. No notice — the user does not have an admin tab to
        // read it on.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_safe_redirect(wp_login_url());
            static::halt();
            return;
        }

        $user_id = (int) get_current_user_id();

        // c. Multisite is out of scope for this release (roadmap: 1.0.13).
        if (is_multisite()) {
            self::reject('multisite_unsupported', $user_id);
            return;
        }

        // d. Google-side error (user denied, missing scope, consent cancelled).
        // No code/state to verify — surface to the admin and stop.
        if (isset($_GET['error']) && $_GET['error'] !== '') {
            self::reject('google_error', $user_id, ['error' => (string) $_GET['error']]);
            return;
        }

        // e-i. State verify (HMAC, expiry, host, transient, user_id).
        // FiftyOneDegreesOauthStateException::$reason is a stable slug that
        // maps 1:1 to a leaf key under oauth.notice.* — translate directly
        // to a rejection branch.
        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        $expected_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

        try {
            $verified = FiftyOneDegreesOauthState::verify_state($state, $user_id, $expected_host);
        } catch (FiftyOneDegreesOauthStateException $e) {
            self::reject($e->reason, $user_id);
            return;
        }

        $nonce = $verified['nonce'];
        $transient_key = FiftyOneDegreesOauthState::TRANSIENT_PREFIX . $nonce;
        $code_verifier = isset($verified['transient']['code_verifier'])
            ? $verified['transient']['code_verifier']
            : null;

        // j. Consume the transient BEFORE the exchange call. See class-level
        // comment for the replay-window discussion.
        delete_transient($transient_key);

        // k. Exchange the authorization code for an access token. Refuse an
        // empty code up front so the user sees the right diagnostic instead
        // of a generic "exchange_failed" caused by the library rejecting ''.
        // The transient is already gone — retry needs a fresh state from S-8.
        $code = isset($_GET['code']) ? (string) $_GET['code'] : '';
        if ($code === '') {
            self::reject('missing_code', $user_id);
            return;
        }

        $client = static::build_client();

        // Rejection context for the exchange failure paths is deliberately
        // a small scalar: hook subscribers (logging plugins, error monitors)
        // serialize whatever we hand them, and the Google client's exception
        // messages / error-array responses can echo request bodies or
        // partial token material. The branch slug alone is enough for the
        // admin-facing notice; ops diagnostics go through error_log.
        //
        // fetchAccessTokenWithAuthCode (not the deprecated `authenticate`
        // alias) accepts the PKCE verifier as a second argument and forwards
        // it as code_verifier= in the token POST. The alias drops the
        // verifier silently and would defeat the PKCE binding from S-8.
        try {
            $token = $client->fetchAccessTokenWithAuthCode($code, $code_verifier);
        } catch (\Exception $e) {
            error_log('51Degrees OAuth exchange exception: ' . $e->getMessage());
            self::reject('exchange_failed', $user_id);
            return;
        }

        // Google's PHP client can return an array with an 'error' key
        // instead of throwing on protocol-level failures. Treat it as
        // an exchange failure.
        if (!is_array($token) || isset($token['error'])) {
            $error_code = is_array($token) && isset($token['error'])
                ? (string) $token['error']
                : 'unknown';
            self::reject('exchange_failed', $user_id, ['error' => $error_code]);
            return;
        }

        // m. SUCCESS. update_option may return false when the new value is
        // identical to the current one (WP semantics) — that's still a
        // valid save, so we do not check the return value.
        update_option(Options::GA_TOKEN, $token);
        update_option(Options::GA_AUTH_DATE, time());

        FiftyOneDegreesOauthNotice::set('success');
        wp_safe_redirect(self::clean_admin_url('&oauth-success=1'));
        static::halt();
    }

    /**
     * Stores the notice, fires the observability action, and PRG-redirects
     * to a clean admin URL (without code/state/error). The branch slug is
     * the same value used in the notice transient and in the
     * fiftyonedegrees_oauth_rejection action — keep them aligned with the
     * leaf keys in languages/oauth-strings.yaml.
     */
    private static function reject($branch, $user_id, array $context = [])
    {
        do_action('fiftyonedegrees_oauth_rejection', $branch, $user_id, $context);
        FiftyOneDegreesOauthNotice::set($branch);
        wp_safe_redirect(self::clean_admin_url());
        static::halt();
    }

    /**
     * Builds the bare admin URL for the GA tab. Callers pass extra query
     * pairs already in '&key=value' form for appending — admin_url() returns
     * a URL with at least one existing query parameter so this is safe.
     */
    private static function clean_admin_url($extra = '')
    {
        return admin_url('options-general.php?page=51Degrees&tab=google-analytics' . $extra);
    }

    /**
     * Test seam over the Google_Client construction. Production delegates
     * to the shared factory so the credentials/scope/redirect config stays
     * in lockstep across the three call sites (start, callback, ga-service).
     * Tests override to inject a mock without touching the real apiclient.
     *
     * @return Google_Client
     */
    protected static function build_client()
    {
        return FiftyOneDegreesGoogleClientFactory::make();
    }

    /**
     * Test seam over `exit`. handle() and reject() invoke this immediately
     * after wp_safe_redirect so unit tests can subclass and replace exit
     * with a tracking flag instead of killing the PHPUnit process.
     */
    protected static function halt()
    {
        exit;
    }
}
