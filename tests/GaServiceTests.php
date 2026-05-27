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

require(__DIR__ . "/../includes/ga-service.php");
require(__DIR__ . "/Mock_Google_Service_Analytics.php");

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
     *  Tests Get Account Id for authorized user.
     */
    public function testGetAccountId() {

        // Set tracking id to test the accoundId for.
        $trackingId = 'test-123456789-1';

        // Partially mock fiftyonedegrees analytics service.
        $fiftyone_ga_service = Mockery::mock('Fiftyonedegrees_Google_Analytics')
            ->makePartial();

        // Mock Google Analytics Service.
        $ga_mock = new Mock_Google_Service_Analytics();
        $ga_mock->mock_management_accountSummaries();        
        $result = $fiftyone_ga_service->get_account_id(
            $ga_mock->ga_service,
            $trackingId);

        $this->assertEquals("123456789", $result);
       
    }

    /**
     *  Tests Get Custom Dimensions for the authorized user.
     */
    public function testGetCustomDimensions() {

        // return values.
        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_TRACKING_ID)
            ->andReturn('test-123456789-0');
        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_MAX_DIMENSIONS)
            ->andReturn(0);
        Functions\expect('update_option')
            ->once()
            ->with(Options::GA_ACCOUNT_ID, "123456789");
        
        // Mock Google Analytics Service.
        $ga_mock = new Mock_Google_Service_Analytics();
        $ga_mock->mock_management_customDimensions();

        // Partially mock fiftyonedegrees analytics service.        
        $fiftyone_ga_service = Mockery::mock('Fiftyonedegrees_Google_Analytics')
            ->makePartial();
        $fiftyone_ga_service->shouldReceive('authenticate')->andReturn(true);
        $fiftyone_ga_service->shouldReceive('get_google_analytics_service')
            ->andReturn($ga_mock->ga_service);
        $fiftyone_ga_service->shouldReceive('get_account_id')
            ->andReturn("123456789");

        $result = $fiftyone_ga_service->get_custom_dimensions();
        $this->assertEquals(
            ['51D.testelement.testproperty1' => 1,
            '51D.testelement.testproperty2' => 2],
            $result["cust_dims_map"]);
        $this->assertEquals(2, $result["max_cust_dim_index"]);

    }

    // Data Provider for testInsertCustomDimensions
	public static function provider_testInsertCustomDimensions() {
        return array(
            array(array(array("custom_dimension_name" => "51D.testelement.testproperty1", "custom_dimension_ga_index" => 1, "custom_dimension_index" => 1),
            array("custom_dimension_name" => "51D.testelement.testproperty2", "custom_dimension_ga_index" => -1, "custom_dimension_index" => 2)), 1),
            array(array(array("custom_dimension_name" => "51D.testelement.testproperty1", "custom_dimension_ga_index" => -1, "custom_dimension_index" => 1),
            array("custom_dimension_name" => "51D.testelement.testproperty2", "custom_dimension_ga_index" => -1, "custom_dimension_index" => 2)), 2),
            array(array(array("custom_dimension_name" => "51D.testelement.testproperty1", "custom_dimension_ga_index" => 1, "custom_dimension_index" => 1),
            array("custom_dimension_name" => "51D.testelement.testproperty2", "custom_dimension_ga_index" => 2, "custom_dimension_index" => 2)), 0)
        );
    }
    /**
     *  Tests Custom Dimensions Insertion for the authorized user.
     *  @dataProvider provider_testInsertCustomDimensions
     */
    public function testInsertCustomDimensions($cust_dims_map, $expected_calls) {

        // return values.
        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_ACCOUNT_ID)
            ->andReturn('123456789');
        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_TRACKING_ID)
            ->andReturn('test-123456789-0');
        Functions\expect('get_option')
            ->once()
            ->with(Options::GA_CUSTOM_DIMENSIONS_MAP)
            ->andReturn($cust_dims_map);
        
        // Mock Google Analytics Service.
        $ga_mock = new Mock_Google_Service_Analytics();
        $ga_mock->mock_management_customDimensions();

        // Partially mock fiftyonedegrees analytics service.        
        $fiftyone_ga_service = Mockery::mock('Fiftyonedegrees_Google_Analytics')
            ->makePartial();
        $fiftyone_ga_service->shouldReceive('authenticate')->andReturn(true);
        $fiftyone_ga_service->shouldReceive('get_google_analytics_service')
            ->andReturn($ga_mock->ga_service);

        $result = $fiftyone_ga_service->insert_custom_dimensions();
        $this->assertEquals($expected_calls, $result);

    }

    /**
     * Regression: delete_ga_options must include GA_AUTH_DATE so that
     * uninstall fully clears the OAuth-touched options. The row is
     * written by oauth-callback on successful token exchange; it had
     * been historically absent from this sweep.
     */
    public function testDeleteGaOptionsIncludesAuthDate() {
        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $svc = new Fiftyonedegrees_Google_Analytics();
        $svc->delete_ga_options();

        $this->assertContains(Options::GA_AUTH_DATE, $deleted);
        $this->assertContains(Options::GA_TOKEN, $deleted);
    }

}
