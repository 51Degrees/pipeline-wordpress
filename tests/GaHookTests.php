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

require_once(__DIR__ . "/../includes/ga-service.php");
require_once(__DIR__ . "/TestFlowElement.php");

use fiftyone\pipeline\core\PipelineBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use \Brain\Monkey\Functions;
use \Brain\Monkey\Actions;
use \Brain\Monkey\Filters;
use \Brain\Monkey;


class GaHookTests extends TestCase {

	public function set_up() {
        parent::set_up();
        $_POST = array();
        Functions\stubs([
            'sanitize_text_field',
            'wp_unslash'
        ]);
        Brain\Monkey\setUp();
    }

	public function tear_down() {
		Brain\Monkey\tearDown();
		parent::tear_down();
	}

    /**
     * Test that all the init methods needed for an admin are added as
     * admin_init hooks.
     */
    public function testAdminInitActions() {
        (new Fiftyonedegrees_Google_Analytics())->setup_wp_actions();
        // Note: the legacy `fiftyonedegrees_ga_authentication` admin_init
        // hook was removed during the OAuth refactor. has_action() with an unresolvable
        // callable string throws inside Brain Monkey validation, so the
        // negative assertion lives in testLegacyOauthOobSurfaceIsRemoved
        // below (`method_exists` check) instead.
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_logout()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_set_property()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_update_cd_indices()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_change_screen()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_enable_tracking()'));
    }

    /**
     * Test that all the actions for HTTP head are added as wp_head hooks.
     */
    public function testHeadActions() {
        (new Fiftyonedegrees_Google_Analytics())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'wp_head',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_add_analytics_code()'));
    }

    /**
     * Legacy OOB OAuth surface — the Access Code POST handler
     * (`fiftyonedegrees_ga_authentication`) and the sibling
     * `google_analytics_authenticate` exchange helper — was removed
     * during the OAuth refactor. The UI input that produced the POST is
     * gone, and the methods + admin_init hook were unreachable code that
     * was deleted outright. This test pins the removal so a future refactor
     * cannot accidentally resurrect either symbol.
     */
    public function testLegacyOauthOobSurfaceIsRemoved() {
        $service = new Fiftyonedegrees_Google_Analytics();

        $this->assertFalse(
            method_exists($service, 'fiftyonedegrees_ga_authentication'),
            'fiftyonedegrees_ga_authentication must not be re-introduced'
        );
        $this->assertFalse(
            method_exists($service, 'google_analytics_authenticate'),
            'google_analytics_authenticate must not be re-introduced'
        );
    }
    
    /**
     * Test that all GA related options are deleted when logging out.
     */
    public function testGaLogout() {
        $_POST = array(
            "ga_log_out" => ""
        );
        Functions\when('get_admin_url')->justReturn('admin/');
        Functions\when('delete_transient')->justReturn(true);

        $service = new Fiftyonedegrees_Google_Analytics();
        Functions\expect('delete_option')->once()->with(Options::GA_AUTH_CODE);
        Functions\expect('delete_option')->once()->with(Options::GA_TOKEN);
        Functions\expect('delete_option')->once()->with(Options::GA_PROPERTIES);
        Functions\expect('delete_option')->once()->with(Options::GA_TRACKING_ID);
        Functions\expect('delete_option')->once()->with(Options::GA_ACCOUNT_ID);
        Functions\expect('delete_option')->once()->with(Options::GA_MAX_DIMENSIONS);
        Functions\expect('delete_option')->once()->with(Options::GA_SEND_PAGE_VIEW);
        Functions\expect('delete_option')->once()->with(Options::GA_JS);
        Functions\expect('delete_option')->once()->with(Options::ENABLE_GA);
        Functions\expect('delete_option')->once()->with(Options::GA_ERROR);
        Functions\expect('delete_option')->once()->with(Options::RESOURCE_KEY_UPDATED);
        Functions\expect('delete_option')->once()->with(Options::GA_DIMENSIONS);
        Functions\expect('delete_option')->once()->with(Options::GA_DIMENSIONS_UPDATED);
        Functions\expect('delete_option')->once()->with(Options::GA_ID_UPDATED);
        Functions\expect('delete_option')->once()->with(Options::GA_SEND_PAGE_VIEW_UPDATED);
        Functions\expect('delete_option')->once()->with(Options::GA_TRACKING_ID_ERROR);
        Functions\expect('delete_option')->once()->with(Options::GA_CUSTOM_DIMENSIONS_SCREEN);

        Functions\expect('wp_redirect')
            ->once()
            ->with('admin/options-general.php?page=51Degrees&tab=google-analytics');

        $service->fiftyonedegrees_ga_logout();
        $this->assertTrue(true);
    }

    /**
     * Test that the JavaScript is printed when calling the add
     * analytics method.
     */
    public function testGaGetJavaScript() {
        $service = new Fiftyonedegrees_Google_Analytics();

        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_JS)
            ->andReturn("some javascript");
        Functions\when('esc_html')->returnArg();

        $this->expectOutputString("some javascript");

        $result = $service->fiftyonedegrees_ga_add_analytics_code();
        
    }

    // ─── fiftyonedegrees_ga_set_property handler (6 branches) ────────────

    /**
     * In-memory option store + the redirect/sanitize stubs needed by
     * fiftyonedegrees_ga_set_property. Returns the store reference so
     * the test can assert against the final option state.
     */
    private function set_property_handler_fixtures(array $initial) {
        $store = new \stdClass();
        $store->data = $initial;

        Functions\when('get_option')->alias(function ($key, $default = false) use ($store) {
            return array_key_exists($key, $store->data) ? $store->data[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use ($store) {
            $store->data[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use ($store) {
            unset($store->data[$key]);
            return true;
        });
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_redirect')->justReturn(null);
        Functions\when('get_admin_url')->justReturn('admin/');

        return $store;
    }

    private function admin_with_dataStreams($response_or_thrower) {
        $resource = Mockery::mock();
        if ($response_or_thrower instanceof \Throwable) {
            $resource->shouldReceive('listPropertiesDataStreams')->andThrow($response_or_thrower);
        }
        else {
            $resource->shouldReceive('listPropertiesDataStreams')->andReturn($response_or_thrower);
        }
        $admin = new \stdClass();
        $admin->properties_dataStreams = $resource;
        return $admin;
    }

    private function dataStreams_response(array $streams) {
        $response = Mockery::mock();
        $response->shouldReceive('getDataStreams')->andReturn($streams);
        return $response;
    }

    private function web_stream($mid) {
        $webData = Mockery::mock();
        $webData->shouldReceive('getMeasurementId')->andReturn($mid);
        $stream = Mockery::mock();
        $stream->shouldReceive('getType')->andReturn('WEB_DATA_STREAM');
        $stream->shouldReceive('getWebStreamData')->andReturn($webData);
        return $stream;
    }

    public function testSetPropertyBailsOnSentinelEmptyValue() {
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => '',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $service = new Fiftyonedegrees_Google_Analytics();
        $service->fiftyonedegrees_ga_set_property();

        $this->assertSame(true, $store->data[Options::GA_TRACKING_ID_ERROR] ?? null);
        $this->assertArrayNotHasKey(Options::GA_PROPERTY_ID, $store->data);
        $this->assertArrayNotHasKey(Options::GA_MEASUREMENT_ID, $store->data);
        $this->assertArrayNotHasKey(Options::GA_CUSTOM_DIMENSIONS_SCREEN, $store->data);
    }

    public function testSetPropertyBailsOnNonNumericValue() {
        // Defense against a hand-crafted POST or stale browser markup.
        // GA4 property ids are strict numeric strings.
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => 'properties/123/dataStreams',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $service = new Fiftyonedegrees_Google_Analytics();
        $service->fiftyonedegrees_ga_set_property();

        $this->assertSame(true, $store->data[Options::GA_TRACKING_ID_ERROR] ?? null);
        $this->assertArrayNotHasKey(Options::GA_PROPERTY_ID, $store->data);
    }

    public function testSetPropertyFailsWhenAuthenticateReturnsFalse() {
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => '123456',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $service = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $service->shouldReceive('authenticate')->andReturn(false);

        $service->fiftyonedegrees_ga_set_property();

        $this->assertArrayHasKey(Options::GA_ERROR, $store->data,
            'expired auth must surface GA_ERROR copy');
        $this->assertStringContainsString('reconnect', strtolower($store->data[Options::GA_ERROR]));
        $this->assertArrayNotHasKey(Options::GA_PROPERTY_ID, $store->data,
            'no partial write — property id must not land without measurement id');
        $this->assertArrayNotHasKey(Options::GA_MEASUREMENT_ID, $store->data);
    }

    public function testSetPropertyMapsAuthErrorToReconnectCopy() {
        // GA4 Admin API 403'd (scope revoked at Google's end). Handler
        // must surface a reconnect-account notice rather than the
        // misleading no-Web-stream copy, and must not persist
        // GA_PROPERTY_ID for a property whose ownership we can't verify.
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => '123456',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $admin = $this->admin_with_dataStreams(new \Exception('forbidden', 403));

        $service = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $service->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $service->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $service->fiftyonedegrees_ga_set_property();

        $this->assertArrayHasKey(Options::GA_ERROR, $store->data);
        $this->assertStringContainsString('reconnect', strtolower($store->data[Options::GA_ERROR]),
            'auth-revoked must say reconnect, not "no Web stream"');
        $this->assertArrayNotHasKey(Options::GA_PROPERTY_ID, $store->data);
        $this->assertArrayNotHasKey(Options::GA_MEASUREMENT_ID, $store->data);
    }

    public function testSetPropertyFailsWhenPropertyHasNoWebStream() {
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => '123456',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $admin = $this->admin_with_dataStreams($this->dataStreams_response([]));

        $service = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $service->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $service->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $service->fiftyonedegrees_ga_set_property();

        $this->assertArrayHasKey(Options::GA_ERROR, $store->data);
        $this->assertStringContainsString('Web data stream', $store->data[Options::GA_ERROR]);
        $this->assertArrayNotHasKey(Options::GA_PROPERTY_ID, $store->data,
            'no Web stream is a per-property failure — no half-write of GA_PROPERTY_ID');
        $this->assertArrayNotHasKey(Options::GA_MEASUREMENT_ID, $store->data);
    }

    public function testSetPropertyHappyPathPersistsBothIdsAndEnablesCdScreen() {
        $_POST = [
            'submit' => 'Save Changes',
            Options::GA_PROPERTY_ID => '123456',
        ];
        $store = $this->set_property_handler_fixtures([Options::GA_TOKEN => 'tok']);

        $admin = $this->admin_with_dataStreams(
            $this->dataStreams_response([$this->web_stream('G-ABC123')])
        );

        $service = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $service->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $service->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $service->fiftyonedegrees_ga_set_property();

        $this->assertSame('123456', $store->data[Options::GA_PROPERTY_ID]);
        $this->assertSame('G-ABC123', $store->data[Options::GA_MEASUREMENT_ID]);
        $this->assertSame('enabled', $store->data[Options::GA_CUSTOM_DIMENSIONS_SCREEN]);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $store->data);
        $this->assertArrayNotHasKey(Options::GA_TRACKING_ID_ERROR, $store->data);
    }

    /**
     * Test that custom dimensions are populated correctly when updated
     * from the admin page.
     */
    public function testGaUpdateCustomDimensions() {
        $_POST = array(
            "fiftyonedegrees_ga_update_cd_indices" => "Update Custom Dimension Mappings",
            "51D_dim1" => "firstproperty",
        );
        Functions\when('get_admin_url')->justReturn('admin/');
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" =>  $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);

        Functions\expect('get_option')
            ->once()
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);
        Functions\expect('wp_redirect')
            ->once()
            ->with('admin/options-general.php?page=51Degrees&tab=google-analytics');

        $expectedDim = array("dim1" => "firstproperty");
        Functions\expect('update_option')->once()->with(
             Options::GA_DIMENSIONS,
             $expectedDim);
        Functions\expect('update_option')->once()->with(
            Options::GA_DIMENSIONS_UPDATED,
            true);

        $service = new Fiftyonedegrees_Google_Analytics();

        $service->fiftyonedegrees_ga_update_cd_indices();
        $this->assertTrue(true);
    }
}
?>
