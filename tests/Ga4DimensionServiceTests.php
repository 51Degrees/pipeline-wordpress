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

require_once __DIR__ . '/../includes/ga4-dimension-service.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class Ga4DimensionServiceTests extends TestCase
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

    // ─── helpers ────────────────────────────────────────────────────────

    private function dimension_row($parameterName, $displayName, $scope = 'EVENT')
    {
        $m = Mockery::mock();
        $m->shouldReceive('getParameterName')->andReturn($parameterName);
        $m->shouldReceive('getDisplayName')->andReturn($displayName);
        $m->shouldReceive('getScope')->andReturn($scope);
        return $m;
    }

    /**
     * Stub admin service exposing only the customDimensions resource.
     * Real Google\Service\GoogleAnalyticsAdmin is never instantiated.
     */
    private function admin_with_dimensions($listResponse_or_throwable, $createResult_or_throwable = null)
    {
        $resource = Mockery::mock();

        if ($listResponse_or_throwable instanceof \Throwable) {
            $resource->shouldReceive('listPropertiesCustomDimensions')->andThrow($listResponse_or_throwable);
        }
        else {
            $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($listResponse_or_throwable);
        }

        if ($createResult_or_throwable instanceof \Throwable) {
            $resource->shouldReceive('create')->andThrow($createResult_or_throwable);
        }
        elseif ($createResult_or_throwable !== null) {
            $resource->shouldReceive('create')->andReturn($createResult_or_throwable);
        }

        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;
        return $admin;
    }

    private function dimensions_response(array $rows, $nextPageToken = '')
    {
        $response = Mockery::mock();
        $response->shouldReceive('getCustomDimensions')->andReturn($rows);
        $response->shouldReceive('getNextPageToken')->andReturn($nextPageToken);
        return $response;
    }

    // ─── list_custom_dimensions ─────────────────────────────────────────

    public function testListReturnsRowsForExistingDimensions()
    {
        $admin = $this->admin_with_dimensions(
            $this->dimensions_response([
                $this->dimension_row('device_type',   '51Degrees DeviceType'),
                $this->dimension_row('hardware_name', '51Degrees HardwareName'),
            ])
        );

        $result = FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');

        $this->assertSame(
            [
                ['parameter_name' => 'device_type',   'display_name' => '51Degrees DeviceType',   'scope' => 'EVENT'],
                ['parameter_name' => 'hardware_name', 'display_name' => '51Degrees HardwareName', 'scope' => 'EVENT'],
            ],
            $result
        );
    }

    public function testListReturnsEmptyOnZeroDimensions()
    {
        $admin = $this->admin_with_dimensions($this->dimensions_response([]));

        $result = FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');

        $this->assertSame([], $result);
    }

    public function testListReturnsEmptyOnGenericException()
    {
        $admin = $this->admin_with_dimensions(new \Exception('server explosion', 500));

        $result = FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');

        $this->assertSame([], $result);
    }

    public function testListThrowsAuthErrorOn403()
    {
        $admin = $this->admin_with_dimensions(new \Exception('forbidden', 403));

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');
    }

    public function testListThrowsAuthErrorOn401()
    {
        $admin = $this->admin_with_dimensions(new \Exception('unauthorized', 401));

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');
    }

    public function testListReturnsEmptyForEmptyPropertyId()
    {
        // No API call should be issued for an empty property id; passing
        // an admin stub without a resource bound proves it.
        $admin = new \stdClass();

        $result = FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '');

        $this->assertSame([], $result);
    }

    public function testListReturnsEmptyForNullPropertyId()
    {
        // Same guard, null variant — defensive against a caller
        // passing through an absent option without coercing.
        $admin = new \stdClass();

        $result = FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, null);

        $this->assertSame([], $result);
    }

    public function testListLogsWarningWhenNextPageTokenPresent()
    {
        // Defence vs an API-shape change that lowers the default
        // page size below the property's existing CD count. If we
        // ever see a token we are no longer seeing the full list
        // and dimension_exists() could miss a real row — surface
        // the assumption breach via error_log so a future debugger
        // can correlate.
        $admin = $this->admin_with_dimensions(
            $this->dimensions_response([], 'NEXT-PAGE-TOKEN-XYZ')
        );

        // Brain Monkey routes error_log through error_log itself
        // (real PHP). Capture via a temporary error_log file.
        $tmp = tempnam(sys_get_temp_dir(), 'fod-test-');
        $orig = ini_set('error_log', $tmp);
        try {
            FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');
            $logged = (string) file_get_contents($tmp);
        }
        finally {
            ini_set('error_log', $orig);
            @unlink($tmp);
        }

        $this->assertStringContainsString('nextPageToken', $logged);
        $this->assertStringContainsString('properties/100', $logged);
    }

    public function testListPassesExplicitPageSize()
    {
        // Pin pageSize=200 so a future 360-tier property with more
        // than the SDK default cannot silently page-truncate and
        // make dimension_exists() return false for a row that lives
        // on a later page.
        $captured = null;
        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesCustomDimensions')
            ->andReturnUsing(function ($parent, $opts) use (&$captured) {
                $captured = ['parent' => $parent, 'opts' => $opts];
                $response = Mockery::mock();
                $response->shouldReceive('getCustomDimensions')->andReturn([]);
                $response->shouldReceive('getNextPageToken')->andReturn('');
                return $response;
            });
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        FiftyOneDegreesGa4DimensionService::list_custom_dimensions($admin, '100');

        $this->assertSame('properties/100', $captured['parent']);
        $this->assertArrayHasKey('pageSize', $captured['opts']);
        $this->assertSame(200, $captured['opts']['pageSize']);
    }

    // ─── create_custom_dimension ────────────────────────────────────────

    public function testCreateHappyPathReturnsTrue()
    {
        $admin = $this->admin_with_dimensions(
            $this->dimensions_response([]),
            Mockery::mock() // create returns a non-null model; service ignores it on success
        );

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertTrue($result);
    }

    public function testCreatePayloadCarriesPrefixedDisplayNameAndEventScope()
    {
        // Verify the create() call receives a payload with the
        // expected parameter_name, scope=EVENT, and the "51Degrees "
        // prefix on the displayName. Argument matcher pulls the
        // model setters back out via Mockery::on().
        $captured = null;
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andReturnUsing(function ($parent, $payload) use (&$captured) {
            $captured = ['parent' => $parent, 'payload' => $payload];
            return $payload;
        });
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertTrue($result);
        $this->assertSame('properties/100', $captured['parent']);
        $this->assertSame('device_type', $captured['payload']->getParameterName());
        $this->assertSame('51Degrees DeviceType', $captured['payload']->getDisplayName());
        $this->assertSame('EVENT', $captured['payload']->getScope());
    }

    public function testCreateReturnsTrueOn409WhenSameParameterAlreadyExists()
    {
        // GA4 returned 409 ALREADY_EXISTS — admin re-saved the CD
        // screen and the (param, EVENT) pair is still on the
        // property. Service re-fetches the list, sees the match,
        // promotes the failure to idempotent success.
        $listResponse = $this->dimensions_response([
            $this->dimension_row('device_type', '51Degrees DeviceType'),
        ]);
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('already exists', 409));
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($listResponse);
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertTrue($result, '409 ALREADY_EXISTS with the same parameter must be idempotent success');
    }

    public function testCreateReturnsFalseOn409WhenListHasDifferentParameter()
    {
        // 409 from create() but the property's dimension list does
        // not contain a (device_type, EVENT) row — must be a sibling
        // conflict (different scope, or displayName collision under
        // our prefix). Surface as failure so the admin knows to
        // resolve at the GA4 console.
        $listResponse = $this->dimensions_response([
            $this->dimension_row('hardware_name', '51Degrees HardwareName'),
        ]);
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('already exists', 409));
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($listResponse);
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertFalse($result, 'non-matching 409 must surface as failure');
    }

    public function testCreateReturnsFalseOn409WhenListHasDifferentScope()
    {
        // The list shows a row with the same parameter_name but a
        // USER scope (we always create EVENT-scope). Not idempotent.
        $listResponse = $this->dimensions_response([
            $this->dimension_row('device_type', '51Degrees DeviceType', 'USER'),
        ]);
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('already exists', 409));
        $resource->shouldReceive('listPropertiesCustomDimensions')->andReturn($listResponse);
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertFalse(
            $result,
            'same parameter_name under a different scope is not the dimension we asked to create'
        );
    }

    public function testCreateThrowsAuthErrorOn403()
    {
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('forbidden', 403));
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );
    }

    public function testCreateReturnsFalseOnGenericException()
    {
        // 5xx, network timeout, etc. — log and report failure
        // without promoting to idempotent success.
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('server explosion', 500));
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );

        $this->assertFalse($result);
    }

    public function testCreateReturnsFalseForEmptyPropertyId()
    {
        $admin = new \stdClass(); // no resource bound — proves no API call
        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '',
            'device_type',
            'DeviceType'
        );
        $this->assertFalse($result);
    }

    public function testCreateReturnsFalseForEmptyParameterName()
    {
        $admin = new \stdClass();
        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            '',
            'DeviceType'
        );
        $this->assertFalse($result);
    }

    public function testCreateReturnsFalseForEmptyDisplayLabel()
    {
        // Without this guard the payload would carry displayName=
        // "51Degrees " (prefix + trailing space), which GA4 either
        // rejects with an opaque INVALID_ARGUMENT or worse accepts
        // and leaves an unidentifiable stub row on the property.
        $admin = new \stdClass();
        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            ''
        );
        $this->assertFalse($result);
    }

    public function testCreateReturnsFalseForWhitespaceOnlyDisplayLabel()
    {
        // Whitespace-only must be rejected — same INVALID_ARGUMENT
        // failure mode as the empty-string case.
        $admin = new \stdClass();
        $result = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            "   \t  "
        );
        $this->assertFalse($result);
    }

    public function testCreateTrimsWhitespaceOnAcceptedInputs()
    {
        // Leading/trailing whitespace on accepted inputs must not
        // leak into the GA4 payload — would either be sanitized by
        // GA4 (silent breakage of subsequent lookups) or rejected.
        $captured = null;
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andReturnUsing(function ($parent, $payload) use (&$captured) {
            $captured = ['parent' => $parent, 'payload' => $payload];
            return $payload;
        });
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '  100  ',
            '  device_type  ',
            '  DeviceType  '
        );

        $this->assertSame('properties/100', $captured['parent']);
        $this->assertSame('device_type', $captured['payload']->getParameterName());
        $this->assertSame('51Degrees DeviceType', $captured['payload']->getDisplayName());
    }

    public function testCreate409WithAuthErrorOnListRefetchPropagates()
    {
        // The 409 idempotency path calls list_custom_dimensions to
        // verify the existing-row state. If the list call itself
        // hits an auth error mid-recovery (token rotated between
        // create and refetch), that error must propagate as
        // FiftyOneDegreesGa4AuthError rather than being swallowed
        // into a generic "create failed".
        $resource = Mockery::mock();
        $resource->shouldReceive('create')->andThrow(new \Exception('already exists', 409));
        $resource->shouldReceive('listPropertiesCustomDimensions')
            ->andThrow(new \Exception('unauthorized', 401));
        $admin = new \stdClass();
        $admin->properties_customDimensions = $resource;

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4DimensionService::create_custom_dimension(
            $admin,
            '100',
            'device_type',
            'DeviceType'
        );
    }

    // ─── would_exceed_limit (pre-flight capacity check) ─────────────────

    public function testWouldExceedLimitTrueAboveCap()
    {
        // Existing 48 + 3 new = 51 > 50 cap.
        $this->assertTrue(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(48, 3)
        );
    }

    public function testWouldExceedLimitFalseAtBoundary()
    {
        // 49 + 1 = 50 — fits exactly, must NOT trip the pre-check.
        $this->assertFalse(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(49, 1)
        );
    }

    public function testWouldExceedLimitFalseUnderCap()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(10, 5)
        );
    }

    public function testWouldExceedLimitFalseWithNoExisting()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(0, 50)
        );
        $this->assertTrue(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(0, 51)
        );
    }

    public function testWouldExceedLimitFalseForNegativeArguments()
    {
        // Defensive: negative counts are nonsense input but the
        // helper must not surprise the caller — arithmetic
        // (-1 + 5 = 4) keeps it well below the cap, so false.
        $this->assertFalse(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(-1, 5)
        );
        $this->assertFalse(
            FiftyOneDegreesGa4DimensionService::would_exceed_limit(10, -1)
        );
    }

    // ─── Constants pinned ──────────────────────────────────────────────

    public function testScopeIsEventOnly()
    {
        // Pin the EVENT-scope choice so a future refactor cannot
        // silently switch to USER-scope without surfacing in the
        // diff. USER-scope dimensions persist across sessions which
        // is wrong for per-pageview device properties.
        $this->assertSame('EVENT', FiftyOneDegreesGa4DimensionService::SCOPE);
    }

    public function testDisplayNamePrefixIsCanonical()
    {
        $this->assertSame('51Degrees ', FiftyOneDegreesGa4DimensionService::DISPLAY_NAME_PREFIX);
    }

    public function testMaxDimensionsPerPropertyMatchesGa4FreeTierLimit()
    {
        // GA4 enforces 50 for the free tier (125 for 360). Pin the
        // conservative free-tier value so a misread of the docs or
        // accidental bump above the limit fails this test.
        $this->assertSame(50, FiftyOneDegreesGa4DimensionService::MAX_DIMENSIONS_PER_PROPERTY);
    }
}
