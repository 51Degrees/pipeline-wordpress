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

require_once __DIR__ . '/../includes/google-client-factory.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class GoogleClientFactoryTests extends TestCase
{
    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();

        // The factory references plugin-wide config constants. Tests run
        // outside the WP bootstrap that normally defines these in
        // Fiftyonedegrees::define_constants(), so we provide stable
        // stand-ins. defined() guards keep multiple test runs (and the
        // real bootstrap when integration suites also fire) idempotent.
        if (!defined('FIFTYONEDEGREES_PROMPT'))      { define('FIFTYONEDEGREES_PROMPT', 'force'); }
        if (!defined('FIFTYONEDEGREES_ACCESS_TYPE')) { define('FIFTYONEDEGREES_ACCESS_TYPE', 'offline'); }
        if (!defined('FIFTYONEDEGREES_CLIENT_ID'))   { define('FIFTYONEDEGREES_CLIENT_ID', 'test-client-id'); }
        if (!defined('FIFTYONEDEGREES_CLIENT_SECRET')) { define('FIFTYONEDEGREES_CLIENT_SECRET', 'test-client-secret'); }
        if (!defined('FIFTYONEDEGREES_REDIRECT'))    { define('FIFTYONEDEGREES_REDIRECT', 'https://relay.51degrees.example/oauth'); }

        // Default: apply_filters echoes the value back so resolve_redirect_uri
        // returns the constant unless a specific test overrides.
        Functions\when('apply_filters')->returnArg(2);
    }

    public function tear_down()
    {
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // ─── resolve_redirect_uri ────────────────────────────────────────────

    public function testResolveRedirectUriReturnsConstantByDefault()
    {
        $this->assertSame(
            FIFTYONEDEGREES_REDIRECT,
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri()
        );
    }

    public function testFilterCanOverrideRedirectUri()
    {
        Functions\when('apply_filters')->alias(function ($hook, $value) {
            if ($hook === 'fiftyonedegrees_oauth_redirect_url') {
                return 'https://multisite-aware.example/relay';
            }
            return $value;
        });

        $this->assertSame(
            'https://multisite-aware.example/relay',
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri()
        );
    }

    public function testInvalidFilterReturnFallsBackToConstant()
    {
        // Misbehaving filter returns a non-URL value (array, null, garbage
        // string). Factory must log + restore the default rather than feed
        // junk into Google_Client::setRedirectUri.
        Functions\when('apply_filters')->alias(function () {
            return ['not', 'a', 'string'];
        });

        $this->assertSame(
            FIFTYONEDEGREES_REDIRECT,
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri(),
            'array filter return must fall back to constant'
        );
    }

    public function testNonUrlStringFiltersAlsoFallBack()
    {
        Functions\when('apply_filters')->alias(function () {
            return 'definitely-not-a-url';
        });

        $this->assertSame(
            FIFTYONEDEGREES_REDIRECT,
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri()
        );
    }

    public function testHttpSchemeIsRejected()
    {
        // FILTER_VALIDATE_URL accepts http:// (and ftp/javascript/data), but
        // OAuth redirect URIs must be https. A filter override returning a
        // syntactically-valid http:// URL would otherwise sail through and
        // surface as an opaque "redirect_uri_mismatch" at Google. Fail loud
        // at filter time instead.
        Functions\when('apply_filters')->alias(function () {
            return 'http://insecure.example/relay';
        });

        $this->assertSame(
            FIFTYONEDEGREES_REDIRECT,
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri(),
            'http:// scheme must be rejected — only https is accepted for OAuth redirect URIs'
        );
    }

    public function testJavascriptSchemeIsRejected()
    {
        Functions\when('apply_filters')->alias(function () {
            return 'javascript:alert(1)';
        });

        $this->assertSame(
            FIFTYONEDEGREES_REDIRECT,
            FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri()
        );
    }

    public function testFilterReceivesConstantAsDefault()
    {
        // Lock down the contract: the filter must see the constant as the
        // second argument, not null/empty/something else. Subscribers may
        // chain off the default.
        $observed_default = null;
        Functions\when('apply_filters')->alias(function ($hook, $value) use (&$observed_default) {
            if ($hook === 'fiftyonedegrees_oauth_redirect_url') {
                $observed_default = $value;
            }
            return $value;
        });

        FiftyOneDegreesGoogleClientFactory::resolve_redirect_uri();

        $this->assertSame(FIFTYONEDEGREES_REDIRECT, $observed_default);
    }

    // ─── make() smoke test ───────────────────────────────────────────────

    public function testMakeReturnsConfiguredGoogleClient()
    {
        // Real Google_Client instantiation — verifies the credentials wire
        // through the setter chain and exposes the configured values via
        // the library's own getters. If a future apiclient upgrade renames
        // a setter, this test fails at instantiation.
        $client = FiftyOneDegreesGoogleClientFactory::make();

        $this->assertInstanceOf(Google_Client::class, $client);
        $this->assertSame(FIFTYONEDEGREES_CLIENT_ID, $client->getClientId());
        $this->assertSame(FIFTYONEDEGREES_REDIRECT, $client->getRedirectUri());
        // getScopes is a setter-mirror; expect the GA4 Admin edit scope.
        // The plugin needs write access to programmatically create
        // GA4 Custom Dimensions on behalf of the admin.
        $this->assertContains(
            Google_Service_GoogleAnalyticsAdmin::ANALYTICS_EDIT,
            $client->getScopes()
        );
    }

    public function testMakeRespectsRedirectFilter()
    {
        Functions\when('apply_filters')->alias(function ($hook, $value) {
            if ($hook === 'fiftyonedegrees_oauth_redirect_url') {
                return 'https://override.example/cb';
            }
            return $value;
        });

        $client = FiftyOneDegreesGoogleClientFactory::make();

        $this->assertSame('https://override.example/cb', $client->getRedirectUri(),
            'filter return value must reach the Google_Client redirect URI'
        );
    }
}
