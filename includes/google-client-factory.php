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
 * Builds the Google API client used to call the GA4 Admin API with a stored
 * access token. The OAuth sign-in flow does not use this client, so the client
 * carries no credentials or redirect URI — only the GA4 Admin edit scope. A
 * single definition keeps the token-reuse call site (ga-service) configured
 * consistently.
 *
 * The factory itself is exercised by tests/GoogleClientFactoryTests.php through
 * a real Google_Client.
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
        // The OAuth flow (start, callback and refresh) runs entirely through
        // the relay, so this client needs no client id, secret or redirect URI.
        // It exists only as the bearer-token carrier for GA4 Admin API calls —
        // the caller sets the stored access token. The scope is set for parity
        // with the granted token; Google does not validate it client-side on
        // bearer calls.
        $client = new Google_Client();
        $client->setScopes(Google_Service_GoogleAnalyticsAdmin::ANALYTICS_EDIT);

        return $client;
    }
}
