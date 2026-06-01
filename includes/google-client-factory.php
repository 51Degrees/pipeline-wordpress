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

/**
 * Builds a configured Google_Client. Single source of truth for the
 * credentials / scope / redirect-uri block so the three call sites that
 * need a configured client (OAuth start, OAuth callback, ga-service
 * token reuse) cannot drift. The factory collapses an earlier 3-way
 * duplication into one definition. Two of the three call sites are OAuth-flow specific;
 * the third (token reuse) just needs an authenticated API client — hence
 * the class name omits the Oauth prefix that sibling classes carry.
 *
 * Redirect URI flows through the `fiftyonedegrees_oauth_redirect_url`
 * filter so external code (multisite-aware plugins, future relay
 * variants) can override the default `FIFTYONEDEGREES_REDIRECT` constant.
 * The filter return value is type-validated AND scheme-checked
 * (https only); an invalid override is logged and the constant is
 * restored, rather than passing junk into Google_Client::setRedirectUri.
 *
 * IMPORTANT for filter subscribers: the filter fires on every `make()`
 * call. A single tracking-enable request can produce two or three
 * filter invocations (start handler, callback handler, ga-service
 * authenticate() called once per custom-dimensions cycle). Subscribers
 * MUST be idempotent and cheap — no per-request memoization is done
 * inside the factory.
 *
 * Tests subclass / override callsites' own `build_client()` seam to
 * inject mocks; this factory itself is exercised by
 * tests/GoogleClientFactoryTests.php through a real Google_Client.
 */
class FiftyOneDegreesGoogleClientFactory
{
    /**
     * Returns a freshly-configured Google_Client. Caller decides whether
     * to also call setAccessToken (token reuse), setState (start flow),
     * or use the result directly for an exchange call.
     *
     * @return Google_Client
     */
    public static function make()
    {
        $client = new Google_Client();
        $client->setPrompt(FIFTYONEDEGREES_PROMPT);
        $client->setAccessType(FIFTYONEDEGREES_ACCESS_TYPE);
        $client->setClientId(FIFTYONEDEGREES_CLIENT_ID);
        $client->setClientSecret(FIFTYONEDEGREES_CLIENT_SECRET);
        $client->setRedirectUri(self::resolve_redirect_uri());
        $client->setScopes(Google_Service_GoogleAnalyticsAdmin::ANALYTICS_EDIT);

        return $client;
    }

    /**
     * Resolves the redirect URI through the public filter. A misbehaving
     * third-party hook can return null, false, an array, or an invalid
     * string; rather than silently propagating a broken URI into the
     * Google client, we log and fall back to the constant. The filter
     * still gets full control on the happy path.
     *
     * Scheme is restricted to https. FILTER_VALIDATE_URL alone accepts
     * `http:`, `javascript:`, `ftp:`, `data:` etc., which would either
     * fail the Google exchange with an opaque "redirect_uri_mismatch"
     * later in the flow or be flagged at security review. We fail loud
     * at filter time instead so the override is obviously wrong.
     */
    public static function resolve_redirect_uri()
    {
        $filtered = apply_filters(
            'fiftyonedegrees_oauth_redirect_url',
            FIFTYONEDEGREES_REDIRECT
        );

        if (!is_string($filtered)
            || filter_var($filtered, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($filtered, PHP_URL_SCHEME)) !== 'https'
        ) {
            error_log(
                '51Degrees OAuth: fiftyonedegrees_oauth_redirect_url filter '
                . 'returned an invalid value (must be a https:// URL); '
                . 'falling back to default'
            );
            return FIFTYONEDEGREES_REDIRECT;
        }

        return $filtered;
    }
}
