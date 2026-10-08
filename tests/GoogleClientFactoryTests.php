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
    }

    public function tear_down()
    {
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // ─── make() smoke test ───────────────────────────────────────────────

    public function testMakeReturnsGoogleClientWithAnalyticsEditScope()
    {
        // The OAuth flow runs entirely through the relay, so the factory no
        // longer wires a client id / secret / redirect URI. The client is just
        // a bearer-token carrier; all it must carry is the GA4 Admin edit scope
        // (parity with the granted token). The plugin
        // needs write access to programmatically create GA4 Custom Dimensions
        // on behalf of the admin.
        $client = FiftyOneDegreesGoogleClientFactory::make();

        $this->assertInstanceOf(Google_Client::class, $client);
        $this->assertContains(
            Google_Service_GoogleAnalyticsAdmin::ANALYTICS_EDIT,
            $client->getScopes()
        );
    }
}
