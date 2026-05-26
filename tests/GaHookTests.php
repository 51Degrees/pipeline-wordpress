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
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_authentication()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_logout()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'Fiftyonedegrees_Google_Analytics->fiftyonedegrees_ga_set_tracking_id()'));
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
     * Legacy OOB Access Code POST handler was neutered in S-9 review (the
     * UI input that produced the POST was removed in S-9). Until S-10
     * removes the hook entirely, any incoming POST must be a no-op: no
     * update_option, no delete_option, just a redirect back to the GA tab.
     * This test pins the defensive behaviour against replayed forms.
     */
    public function testGaAuthenticate_LegacyPostIsIgnored() {
        $_POST = array(
            "fiftyonedegrees_ga_code" => "some code",
            "submit" => ""
        );
        Functions\when('get_admin_url')->justReturn('admin/');
        $service = \Mockery::mock('Fiftyonedegrees_Google_Analytics')
            ->makePartial();

        // The handler must NOT mutate options or call authenticate any more.
        Functions\expect('update_option')->never();
        Functions\expect('delete_option')->never();
        $service->shouldNotReceive('authenticate');
        $service->shouldNotReceive('google_analytics_authenticate');

        Functions\expect('wp_redirect')
            ->once()
            ->with('admin/options-general.php?page=51Degrees&tab=google-analytics');

        $service->fiftyonedegrees_ga_authentication();
        $this->assertTrue(true);
    }
    
    /**
     * Test that all GA related options are deleted when logging out.
     */
    public function testGaLogout() {
        $_POST = array(
            "ga_log_out" => ""
        );
        Functions\when('get_admin_url')->justReturn('admin/');

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
