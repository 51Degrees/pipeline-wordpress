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

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use \Brain\Monkey\Functions;

class GaServiceTests extends TestCase {

    public function set_up() {
        parent::set_up();
        Brain\Monkey\setUp();
    }

    public function tear_down() {
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * In-memory option store helper. Matches the pattern used by
     * GaHookTests / OAuthMigrationTests so apply_custom_dimensions_to_ga4
     * can be exercised end-to-end without juggling individual
     * expect() calls for each option write.
     */
    private function stub_option_store(array $initial) {
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
        Functions\when('delete_transient')->justReturn(true);
        return $store;
    }

    private function admin_with_dimensions(array $existing) {
        $response = Mockery::mock();
        $response->shouldReceive('getCustomDimensions')->andReturn(array_map(function ($row) {
            $m = Mockery::mock();
            $m->shouldReceive('getParameterName')->andReturn($row['parameter_name']);
            $m->shouldReceive('getDisplayName')->andReturn($row['display_name'] ?? '');
            $m->shouldReceive('getScope')->andReturn($row['scope'] ?? 'EVENT');
            return $m;
        }, $existing));
        $response->shouldReceive('getNextPageToken')->andReturn('');

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($response);
        // Default: create succeeds (returns a non-null payload).
        $resource->shouldReceive('create')->andReturnUsing(function ($parent, $payload) {
            return $payload;
        });
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;
        return $admin;
    }

    // ─── delete_ga_options regression (kept from UA-era suite) ──────────

    public function testDeleteGaOptionsIncludesAuthDate() {
        $deleted = [];
        Functions\when('get_option')->justReturn('');
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        $svc = new Fiftyonedegrees_Google_Analytics();
        $svc->delete_ga_options();

        $this->assertContains(Options::GA_AUTH_DATE, $deleted);
        $this->assertContains(Options::GA_TOKEN, $deleted);
    }

    // ─── apply_custom_dimensions_to_ga4 ─────────────────────────────────

    public function testApplyFailsWithNoPropertyId() {
        $store = $this->stub_option_store([]);

        $svc = new Fiftyonedegrees_Google_Analytics();
        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertArrayHasKey(Options::GA_ERROR, $store->data);
        $this->assertStringContainsString('No GA4 property', $store->data[Options::GA_ERROR]);
    }

    public function testApplyReturnsTrueWhenCdMapEmpty() {
        // No CD configured — apply path is a no-op success so the
        // caller still marks tracking enabled. Frontend gtag will
        // emit a bare fod event under the Measurement ID.
        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID => '100',
        ]);

        $svc = new Fiftyonedegrees_Google_Analytics();
        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $store->data);
    }

    public function testApplyFailsWhenAuthenticateReturnsFalse() {
        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type', 'property_name' => 'DeviceType'],
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(false);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertStringContainsString(
            'reconnect',
            strtolower($store->data[Options::GA_ERROR])
        );
    }

    public function testApplyFailsWithAuthRevokedDuringList() {
        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type', 'property_name' => 'DeviceType'],
            ],
        ]);

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')
            ->andThrow(new \Exception('forbidden', 403));
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertStringContainsString(
            'reconnect',
            strtolower($store->data[Options::GA_ERROR])
        );
    }

    public function testApplyFailsWhenWouldExceedLimit() {
        // 48 existing + 5 new = 53 > 50 cap. apply must refuse to
        // start the create loop and surface a single explanatory
        // notice instead of failing mid-batch on the 51st create.
        $existing_50ish = [];
        for ($i = 0; $i < 48; $i++) {
            $existing_50ish[] = [
                'parameter_name' => 'p_' . $i,
                'display_name'   => 'P ' . $i,
                'scope'          => 'EVENT',
            ];
        }
        $admin = $this->admin_with_dimensions($existing_50ish);

        $cd_map = [];
        for ($i = 0; $i < 5; $i++) {
            $cd_map[] = ['parameter_name' => 'new_' . $i, 'property_name' => 'N' . $i];
        }

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => $cd_map,
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertStringContainsString('50', $store->data[Options::GA_ERROR]);
        $this->assertStringContainsString('exceed', strtolower($store->data[Options::GA_ERROR]));
    }

    public function testApplyHappyPathCreatesAllNewDimensions() {
        $admin = $this->admin_with_dimensions([
            // One existing dimension that overlaps with the map
            // (should be skipped — not double-created).
            ['parameter_name' => 'device_type', 'display_name' => '51Degrees DeviceType'],
        ]);

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type',   'property_name' => 'DeviceType'],   // existing
                ['parameter_name' => 'hardware_name', 'property_name' => 'HardwareName'], // new
                ['parameter_name' => 'os_name',       'property_name' => 'OsName'],       // new
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $store->data);
    }

    public function testApplyFailsWhenCreateThrowsAuthErrorMidLoop() {
        // Token revoked between list (success) and create call.
        // Service catches the Ga4AuthError specifically inside the
        // create loop with distinct error copy ("revoked while
        // creating") — separate from the list-time auth path.
        $response = Mockery::mock();
        $response->shouldReceive('getCustomDimensions')->andReturn([]);
        $response->shouldReceive('getNextPageToken')->andReturn('');

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($response);
        $resource->shouldReceive('create')->andThrow(new \Exception('forbidden', 403));

        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type', 'property_name' => 'DeviceType'],
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertArrayHasKey(Options::GA_ERROR, $store->data);
        $err = strtolower($store->data[Options::GA_ERROR]);
        $this->assertStringContainsString('reconnect', $err);
        $this->assertStringContainsString('creating', $err,
            'auth error from create() must surface a distinct "creating" copy '
            . 'rather than the generic list-time reconnect message'
        );
    }

    public function testApplyMakesNoCreateCallWhenAllDimensionsAlreadyExist() {
        // Quota-burn guard: when the CD map is fully covered by
        // the property's existing dimensions, the create loop has
        // nothing to do and create() must not be invoked.
        // Mockery::shouldNotReceive catches the regression.
        $response = Mockery::mock();
        $response->shouldReceive('getCustomDimensions')->andReturn([
            (function () {
                $m = Mockery::mock();
                $m->shouldReceive('getParameterName')->andReturn('device_type');
                $m->shouldReceive('getDisplayName')->andReturn('51Degrees DeviceType');
                $m->shouldReceive('getScope')->andReturn('EVENT');
                return $m;
            })(),
        ]);
        $response->shouldReceive('getNextPageToken')->andReturn('');

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($response);
        $resource->shouldNotReceive('create');

        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type', 'property_name' => 'DeviceType'],
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $store->data);
    }

    public function testApplyFailsWhenPerRowCreateReturnsFalse() {
        // create() returns false (non-idempotent 409 — sibling row
        // with different scope, or other unmatched conflict). apply
        // surfaces the parameter name in the error copy so the
        // admin can resolve at the GA4 console.
        $response = Mockery::mock();
        $response->shouldReceive('getCustomDimensions')->andReturn([]);
        $response->shouldReceive('getNextPageToken')->andReturn('');

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($response);
        // create throws 409, refetch shows nothing matching -> false
        $resource->shouldReceive('create')->andThrow(new \Exception('already exists', 409));

        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                ['parameter_name' => 'device_type', 'property_name' => 'DeviceType'],
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertFalse($result);
        $this->assertArrayHasKey(Options::GA_ERROR, $store->data);
        $this->assertStringContainsString('device_type', $store->data[Options::GA_ERROR]);
    }

    public function testApplySkipsMalformedRows() {
        // Non-array entries and empty parameter_name rows are
        // dropped silently — same behavior as the frontend gtag
        // emission's get_event_parameters helper.
        $admin = $this->admin_with_dimensions([]);

        $store = $this->stub_option_store([
            Options::GA_PROPERTY_ID           => '100',
            Options::GA_CUSTOM_DIMENSIONS_MAP => [
                'not-an-array',
                ['parameter_name' => '', 'property_name' => 'Empty'],
                ['parameter_name' => 'real', 'property_name' => 'Real'],
            ],
        ]);

        $svc = Mockery::mock('Fiftyonedegrees_Google_Analytics')->makePartial();
        $svc->shouldReceive('authenticate')->andReturn(Mockery::mock(\stdClass::class));
        $svc->shouldReceive('get_ga4_admin_service')->andReturn($admin);

        $result = $svc->apply_custom_dimensions_to_ga4();

        $this->assertTrue($result);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $store->data);
    }
}
