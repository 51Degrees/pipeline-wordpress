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

require_once __DIR__ . '/../includes/ga4-property-service.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class Ga4PropertyServiceTests extends TestCase
{
    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
    }

    public function tear_down()
    {
        // Brain Monkey's tearDown closes Mockery internally — no
        // explicit Mockery::close() needed, matches every other test
        // file in this suite.
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function property_summary($property, $displayName)
    {
        $m = Mockery::mock();
        $m->shouldReceive('getProperty')->andReturn($property);
        $m->shouldReceive('getDisplayName')->andReturn($displayName);
        return $m;
    }

    private function account_summary($account, $displayName, array $propertySummaries)
    {
        $m = Mockery::mock();
        $m->shouldReceive('getAccount')->andReturn($account);
        $m->shouldReceive('getDisplayName')->andReturn($displayName);
        $m->shouldReceive('getPropertySummaries')->andReturn($propertySummaries);
        return $m;
    }

    /**
     * Builds a stub Admin service exposing only accountSummaries-list.
     * Real Google\Service\GoogleAnalyticsAdmin is never instantiated —
     * tests assert only the surface the property service touches.
     */
    private function admin_with_summaries(array $accountSummaries)
    {
        $response = Mockery::mock();
        $response->shouldReceive('getAccountSummaries')->andReturn($accountSummaries);

        $resource = Mockery::mock();
        $resource->shouldReceive('listAccountSummaries')->andReturn($response);

        $admin = new stdClass();
        $admin->accountSummaries = $resource;
        return $admin;
    }

    private function admin_with_summaries_throwing(\Throwable $t)
    {
        $resource = Mockery::mock();
        $resource->shouldReceive('listAccountSummaries')->andThrow($t);

        $admin = new stdClass();
        $admin->accountSummaries = $resource;
        return $admin;
    }

    private function web_stream($measurementId)
    {
        $webData = Mockery::mock();
        $webData->shouldReceive('getMeasurementId')->andReturn($measurementId);

        $stream = Mockery::mock();
        $stream->shouldReceive('getType')->andReturn('WEB_DATA_STREAM');
        $stream->shouldReceive('getWebStreamData')->andReturn($webData);
        return $stream;
    }

    private function non_web_stream($type)
    {
        // Deliberately do NOT stub getWebStreamData: if the picker is
        // ever refactored to read web-stream data on a non-web type,
        // Mockery raises "unexpected method call" and surfaces the
        // logic regression.
        $stream = Mockery::mock();
        $stream->shouldReceive('getType')->andReturn($type);
        return $stream;
    }

    private function admin_with_streams(array $streams)
    {
        $response = Mockery::mock();
        $response->shouldReceive('getDataStreams')->andReturn($streams);

        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesDataStreams')->andReturn($response);

        $admin = new stdClass();
        $admin->properties_dataStreams = $resource;
        return $admin;
    }

    // ─── list_account_summaries ─────────────────────────────────────────

    public function testListReturnsFlattenedPropertyRows()
    {
        $acc = $this->account_summary(
            'accounts/12345',
            'Acme Inc',
            [
                $this->property_summary('properties/100', 'Prod Web'),
                $this->property_summary('properties/200', 'Staging Web'),
            ]
        );

        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries([$acc])
        );

        $this->assertSame(
            [
                ['property_id' => '100', 'property_name' => 'Prod Web',    'account_id' => '12345', 'account_name' => 'Acme Inc'],
                ['property_id' => '200', 'property_name' => 'Staging Web', 'account_id' => '12345', 'account_name' => 'Acme Inc'],
            ],
            $result
        );
    }

    public function testListFlattensAcrossMultipleAccounts()
    {
        $acc1 = $this->account_summary('accounts/1', 'AccOne', [
            $this->property_summary('properties/10', 'Site A'),
        ]);
        $acc2 = $this->account_summary('accounts/2', 'AccTwo', [
            $this->property_summary('properties/20', 'Site B'),
            $this->property_summary('properties/21', 'Site C'),
        ]);

        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries([$acc1, $acc2])
        );

        $this->assertCount(3, $result);
        $this->assertSame('10', $result[0]['property_id']);
        $this->assertSame('AccOne', $result[0]['account_name']);
        $this->assertSame('21', $result[2]['property_id']);
        $this->assertSame('AccTwo', $result[2]['account_name']);
    }

    public function testListReturnsEmptyOnGenericException()
    {
        // Generic non-auth API failure (5xx, network timeout) — the
        // dropdown renders empty rather than crashing the admin page.
        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries_throwing(new \Exception('server explosion'))
        );

        $this->assertSame([], $result);
    }

    public function testListThrowsAuthErrorOn401()
    {
        // 401 = expired token / token revoked at Google's end. Caller
        // distinguishes this from "no properties" so it can surface a
        // reconnect-account notice rather than silently empty dropdown.
        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries_throwing(new \Exception('unauthorized', 401))
        );
    }

    public function testListThrowsAuthErrorOn403()
    {
        // 403 = scope-mismatch (e.g. admin revoked analytics.edit at
        // Google account level after consent). Same caller treatment
        // as 401.
        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries_throwing(new \Exception('forbidden', 403))
        );
    }

    public function testListReturnsEmptyWhenZeroAccounts()
    {
        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries([])
        );

        $this->assertSame([], $result);
    }

    public function testListSkipsAccountsWithNoProperties()
    {
        // Empty propertySummaries -> the account contributes zero rows
        // but does not break the loop for sibling accounts.
        $accEmpty = $this->account_summary('accounts/1', 'NoProps', []);
        $accReal  = $this->account_summary('accounts/2', 'HasProps', [
            $this->property_summary('properties/9', 'Site X'),
        ]);

        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries([$accEmpty, $accReal])
        );

        $this->assertCount(1, $result);
        $this->assertSame('9', $result[0]['property_id']);
        $this->assertSame('HasProps', $result[0]['account_name']);
    }

    // ─── get_measurement_id ─────────────────────────────────────────────

    public function testGetMeasurementIdReturnsFirstWebStream()
    {
        $admin = $this->admin_with_streams([
            $this->web_stream('G-ABC123'),
        ]);

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '100');

        $this->assertSame('G-ABC123', $result);
    }

    public function testGetMeasurementIdSkipsNonWebStreams()
    {
        // App streams come back first; web stream is second. Picker
        // must still find the web measurement id.
        $admin = $this->admin_with_streams([
            $this->non_web_stream('IOS_APP_DATA_STREAM'),
            $this->non_web_stream('ANDROID_APP_DATA_STREAM'),
            $this->web_stream('G-XYZ999'),
        ]);

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '100');

        $this->assertSame('G-XYZ999', $result);
    }

    public function testGetMeasurementIdReturnsNullWhenNoWebStream()
    {
        $admin = $this->admin_with_streams([
            $this->non_web_stream('IOS_APP_DATA_STREAM'),
        ]);

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '100');

        $this->assertNull(
            $result,
            'mobile-app-only property must not synthesise a Measurement ID'
        );
    }

    public function testGetMeasurementIdReturnsNullWhenZeroStreams()
    {
        $admin = $this->admin_with_streams([]);

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '100');

        $this->assertNull($result);
    }

    public function testGetMeasurementIdReturnsNullOn404()
    {
        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesDataStreams')
            ->andThrow(new \Exception('not found', 404));

        $admin = new stdClass();
        $admin->properties_dataStreams = $resource;

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '999');

        $this->assertNull($result);
    }

    public function testGetMeasurementIdThrowsAuthErrorOn403()
    {
        // 403 on dataStreams.list — typically scope revoked after
        // consent. Caller maps this to a reconnect-account notice
        // instead of the misleading "no Web data stream" copy.
        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesDataStreams')
            ->andThrow(new \Exception('forbidden', 403));

        $admin = new stdClass();
        $admin->properties_dataStreams = $resource;

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '999');
    }

    public function testGetMeasurementIdThrowsAuthErrorOn401()
    {
        $resource = Mockery::mock();
        $resource->shouldReceive('listPropertiesDataStreams')
            ->andThrow(new \Exception('unauthorized', 401));

        $admin = new stdClass();
        $admin->properties_dataStreams = $resource;

        $this->expectException(FiftyOneDegreesGa4AuthError::class);

        FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '999');
    }

    public function testGetMeasurementIdReturnsNullForEmptyPropertyId()
    {
        // Defense against the dropdown sentinel slipping past the
        // handler's pre-check — never issue an API call for an
        // empty id (would 4xx with an opaque parent-format error).
        $admin = new stdClass();
        // No properties_dataStreams resource — would throw if called.

        $result = FiftyOneDegreesGa4PropertyService::get_measurement_id($admin, '');

        $this->assertNull($result);
    }

    // ─── resource_id parsing edge cases ─────────────────────────────────

    public function testListYieldsEmptyIdsForMalformedResourceNames()
    {
        // Defensive: Google's resource shapes are stable, but if the
        // SDK ever returns something non-canonical the dropdown
        // renders with an empty id rather than leaking the raw string.
        $propBad  = $this->property_summary('not-a-resource',        'Bad No Slash');
        $propTail = $this->property_summary('properties/',           'Bad Trailing Slash');
        $propOk   = $this->property_summary('properties/777',        'OK');

        $acc = $this->account_summary(
            'accounts/42',
            'Acme',
            [$propBad, $propTail, $propOk]
        );

        $result = FiftyOneDegreesGa4PropertyService::list_account_summaries(
            $this->admin_with_summaries([$acc])
        );

        $this->assertSame('',    $result[0]['property_id'], 'no-slash input must collapse to empty id');
        $this->assertSame('',    $result[1]['property_id'], 'trailing-slash input must collapse to empty id');
        $this->assertSame('777', $result[2]['property_id']);
    }
}
