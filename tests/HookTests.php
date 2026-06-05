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

require_once(__DIR__ . "/../includes/fiftyone-service.php");
require_once(__DIR__ . "/../includes/cloud-metadata.php");
require_once(__DIR__ . "/TestFlowElement.php");

use fiftyone\pipeline\core\PipelineBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use \Brain\Monkey\Functions;
use \Brain\Monkey\Actions;
use \Brain\Monkey\Filters;
use \Brain\Monkey;


class HookTests extends TestCase {

    private static $pipeline;
	public function set_up() {
        Pipeline::reset();
		parent::set_up();
		Brain\Monkey\setUp();
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        HookTests::$pipeline = array(
            "pipeline" =>  $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE) {
                return HookTests::$pipeline;
            }
            // Short-circuit the cache-version migration in setup_wp_actions
            // by reporting the schema is already current.
            if ($arg === Options::PIPELINE_CACHE_VERSION) {
                return FiftyoneService::PIPELINE_CACHE_VERSION;
            }
            return $default;
        });
	}

	public function tear_down() {
		Brain\Monkey\tearDown();
		parent::tear_down();
	}
    
    /**
     * Test that the main init method is added as an init hook.
     */
    public function testInitActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action('init', 'FiftyoneService->fiftyonedegrees_init()' ));
    }
    
    /**
     * Test that all the init methods needed for an admin are added as
     * admin_init hooks.
     */
    public function testAdminInitActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'admin_init',
            'FiftyoneService->fiftyonedegrees_register_settings()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'FiftyoneService->fiftyonedegrees_setup_blocks()'));
        self::assertNotFalse(has_action(
            'admin_init',
            'FiftyoneService->submit_rk_submit_action()'));
    }

    /**
     * Test that the options page is added to as an admin_menu hook.
     */
    public function testAdminMenuActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'admin_menu',
            'FiftyoneService->fiftyonedegrees_register_options_page()'));
    }

    /**
     * Test that the pipeline JavaScript is added as a wp_enqueue hook.
     */
    public function testScriptActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'wp_enqueue_scripts',
            'FiftyoneService->fiftyonedegrees_javascript()'));
    }

    /**
     * Test that the JavaScript for admin is added as an admin_enqueue_scripts hook.
     */
    public function testAdminScriptActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'admin_enqueue_scripts',
            'FiftyoneService->fiftyonedegrees_admin_enqueue_scripts()' ));
    }

    /**
     * Test that the function for the REST API endpoint is added as a rest_api_init hook.
     */
    public function testRestApiActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'rest_api_init',
            'FiftyoneService->fiftyonedegrees_rest_api_init()'));
    }

    /**
     * Test that our update option function is hooked to update_option.
     */
    public function testUpdateOptionActions() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'update_option',
            'FiftyoneService->fiftyonedegrees_update_option()'));
    }

    /**
     * Test that the rendering functions are added to the render_block hook.
     */
    public function testRenderBlockFilters() {
        (new FiftyoneService())->setup_wp_filters("");
        self::assertEquals(10, has_filter(
            'render_block',
            'FiftyoneService->fiftyonedegrees_block_filter()'));
        self::assertEquals(10, has_filter(
            'render_block',
            'FiftyoneService->fiftyonedegrees_render_block()'));
    }

    /**
     * Test that the block categories filter is added to the block_categories_all hook.
     */
    public function testBlockCategoryFilters() {
        (new FiftyoneService())->setup_wp_filters("");
        self::assertEquals(10, has_filter(
            'block_categories_all',
            'FiftyoneService->fiftyonedegrees_block_categories()'));
    }
    
    /**
     * Test that the actions links filter is added top the correctly named
     * hook.
     */
    public function testActionLinkFilters() {
        $pluginName = "fiftyone";
        (new FiftyoneService())->setup_wp_filters($pluginName);
        self::assertEquals(10, has_filter(
            'plugin_action_links_' . $pluginName,
            'FiftyoneService->fiftyonedegrees_add_plugin_page_settings_link()'));
    }

    /**
     * Test that the blocks, and their required scripts and styles,
     * are registered.
     */
    public function testRegisterBlocks() {
        Pipeline::process();
        Functions\when('plugins_url')
            ->returnArg();
        Functions\expect('wp_register_script')
            ->once()
            ->with(
                'fiftyonedegrees-conditional-group-block',
                '../conditional-group-block/build/index.js',
                Mockery::any(),
                Mockery::any());
        
        Functions\expect('wp_register_style')
            ->once()
            ->with(
                'fiftyonedegrees-conditional-group-block',
                '../conditional-group-block/src/editor.css',
                Mockery::any(),
                Mockery::any());
        
        Functions\expect('register_block_type')
            ->once()
            ->with(
                'fiftyonedegrees/conditional-group-block',
                Mockery::any());
                
        Functions\expect('wp_localize_script')
            ->once()
            ->with('fiftyonedegrees-conditional-group-block',
                'fiftyoneProperties',
                Mockery::any());
    
        (new FiftyoneService())->fiftyonedegrees_setup_blocks();

        // We are asserting via the expect, so tell PHPUnit not
        // to worry.
        $this->assertTrue(true);
    }

    /**
     * Test that the any scripts are correctly added.
     */
    function testAddedJavaScript() {
        Functions\when('plugin_dir_url')
            ->justReturn('root/includes/');
        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'fiftyonedegrees',
                'root/includes/../assets/js/fod.js',
                Mockery::any(),
                Mockery::any());

        Functions\expect('wp_add_inline_script')
            ->once()
            ->with("fiftyonedegrees", Mockery::any(), "before");

        (new FiftyoneService())->fiftyonedegrees_javascript();

        // We are asserting via the expect, so tell PHPUnit not
        // to worry.
        $this->assertTrue(true);
    }

    // ====================================================================
    // Issue #61 — PMP exemption on alt-button / terms pages
    // ====================================================================

    /**
     * Common environment for the PMP-enqueue tests: PMP enabled, valid
     * resource key, both PMP page options point at internal paths, plus
     * stubs for the WP helpers used by current_page_is_pmp_exempt() and
     * by the PMP enqueue block itself.
     *
     * url_to_postid defaults to 0 (no internal-post match) so the
     * exemption decision falls through to canonical-path comparison —
     * individual tests override this when they need a post-ID match.
     *
     * @param array  $optionOverrides  per-test option overrides
     * @param string $requestUri       value placed in $_SERVER['REQUEST_URI']
     */
    private function stagePmpEnqueueEnv(array $optionOverrides = [], string $requestUri = '/about', bool $stubEnqueueHelpers = true) {
        $options = array_merge([
            Options::PMP_ENABLE         => 'on',
            Options::RESOURCE_KEY       => 'valid-resource-key',
            Options::PMP_ALT_URL        => '/subscribe',
            Options::PMP_BRAND_TERMS_URL => '/terms',
        ], $optionOverrides);

        Functions\when('get_option')->alias(function ($arg, $default = null) use ($options) {
            if (array_key_exists($arg, $options)) {
                return $options[$arg];
            }
            if ($arg === Options::PIPELINE) {
                return HookTests::$pipeline;
            }
            return $default;
        });

        Functions\when('plugin_dir_url')->justReturn('root/includes/');
        Functions\when('home_url')->alias(function ($p = '/') {
            return 'https://example.com' . $p;
        });
        Functions\when('trailingslashit')->alias(function ($u) {
            return rtrim($u, '/') . '/';
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('wp_unslash')->returnArg();
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            return $value;
        });
        Functions\when('add_filter')->justReturn(true);

        // fod.js + Pipeline JS are enqueued unconditionally above the PMP
        // block; most PMP tests don't assert on those, so allow them through.
        // testFodJsStillEnqueuedOnPmpExemptPage sets strict expectations and
        // opts out of these default stubs.
        if ($stubEnqueueHelpers) {
            Functions\when('wp_enqueue_script')->justReturn(null);
            Functions\when('wp_add_inline_script')->justReturn(null);
        }

        $_SERVER['REQUEST_URI'] = $requestUri;
    }

    private function expectPmpRegistered() {
        Functions\expect('wp_register_script')
            ->once()
            ->with('fiftyonedegrees-pmp', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any());
    }

    private function expectPmpNotRegistered() {
        Functions\expect('wp_register_script')->never();
    }

    public function testPmpEnqueuedOnNormalPage() {
        $this->stagePmpEnqueueEnv([], '/about');
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedOnAltUrlPage() {
        $this->stagePmpEnqueueEnv([], '/subscribe');
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedOnTermsUrlPage() {
        $this->stagePmpEnqueueEnv([], '/terms');
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedOnAltUrlTrailingSlashVariation() {
        $this->stagePmpEnqueueEnv([Options::PMP_ALT_URL => '/subscribe'], '/subscribe/');
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedOnAltUrlWithQueryString() {
        $this->stagePmpEnqueueEnv([Options::PMP_ALT_URL => '/subscribe'], '/subscribe?utm_source=x');
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedWhenOptionStoredAsFullUrl() {
        // Production storage form — admin pastes the canonical full URL
        // from their address bar instead of a path-only value.
        $this->stagePmpEnqueueEnv(
            [Options::PMP_ALT_URL => 'https://example.com/subscribe/'],
            '/subscribe'
        );
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedCaseInsensitive() {
        $this->stagePmpEnqueueEnv([Options::PMP_ALT_URL => '/Subscribe'], '/subscribe');
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpEnqueuedWhenAltUrlIsExternal() {
        // External URL in PMP_ALT_URL — the current request is by definition
        // on this site, so the paths can't collide.
        $this->stagePmpEnqueueEnv(
            [
                Options::PMP_ALT_URL         => 'https://external-paywall.example.org/checkout',
                Options::PMP_BRAND_TERMS_URL => '',
            ],
            '/about'
        );
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpEnqueuedWhenBothPmpOptionsEmpty() {
        $this->stagePmpEnqueueEnv(
            [Options::PMP_ALT_URL => '', Options::PMP_BRAND_TERMS_URL => ''],
            '/subscribe'
        );
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpEnqueuedWhenAltUrlIsRoot() {
        // Foot-gun guard: PMP_ALT_URL='/' (or full home URL) would otherwise
        // exempt every page on the site. Treat as misconfigured.
        $this->stagePmpEnqueueEnv(
            [Options::PMP_ALT_URL => '/', Options::PMP_BRAND_TERMS_URL => 'https://example.com/'],
            '/about'
        );
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpEnqueuedOnCategoryArchive() {
        // Archive / category / search URLs return url_to_postid() = 0, so
        // the exemption falls through to canonical-path match. The archive
        // path doesn't collide with any configured page → PMP fires.
        $this->stagePmpEnqueueEnv([], '/category/news');
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedByPostIdMatchEvenIfPathsDiffer() {
        // Same post served under two URLs (e.g. permalink rewrite, /?p=N
        // pretty-URL aliasing). url_to_postid() collapses both sides to
        // the same ID — PMP must be exempted.
        $this->stagePmpEnqueueEnv(
            [Options::PMP_ALT_URL => '/new-slug', Options::PMP_BRAND_TERMS_URL => ''],
            '/old-slug'
        );
        // Override stagePmpEnqueueEnv's default url_to_postid stub (0) so
        // both the current URI and the target option resolve to the same
        // post ID, exercising the post-ID branch of the matcher.
        Functions\when('url_to_postid')->justReturn(42);
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testFodJsStillEnqueuedOnPmpExemptPage() {
        // Sanity: on a PMP-exempt page the PMP script is gated off, but
        // fod.js + Pipeline::getJavaScript() must still be enqueued.
        $this->stagePmpEnqueueEnv([], '/subscribe', false);
        $this->expectPmpNotRegistered();

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'fiftyonedegrees',
                'root/includes/../assets/js/fod.js',
                Mockery::any(),
                Mockery::any());
        Functions\expect('wp_add_inline_script')
            ->once()
            ->with('fiftyonedegrees', Mockery::any(), 'before');

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpSkippedOnSubdirMultisiteWithPathOnlyOption() {
        // Issue #61 + reviewer R-1: on subdir multisite REQUEST_URI starts
        // with the site path prefix ('/blog/terms/') while a path-only
        // option stays '/terms'. The matcher exposes a home-stripped
        // current path so both shapes still match.
        $this->stagePmpEnqueueEnv(
            [Options::PMP_ALT_URL => '/terms', Options::PMP_BRAND_TERMS_URL => ''],
            '/blog/terms'
        );
        // home_url('/') returns the subsite root including the path prefix.
        Functions\when('home_url')->alias(function ($p = '/') {
            return 'https://example.com/blog' . $p;
        });
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testExemptCheckSkippedWhenPmpDisabled() {
        // Ordering guarantee: when PMP_ENABLE='off' the function must short-
        // circuit BEFORE current_page_is_pmp_exempt() runs — otherwise we
        // pay url_to_postid + option reads on every front-end request on
        // sites that don't use PMP at all.
        $this->stagePmpEnqueueEnv([Options::PMP_ENABLE => 'off'], '/about');
        Functions\expect('url_to_postid')->never();
        $this->expectPmpNotRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    public function testPmpExemptFilterCanForceFalse() {
        // The fiftyonedegrees_pmp_exempt filter lets sites with i18n
        // plugins (or any custom logic) override the path/ID-match result.
        // Forcing the filter to return false re-enables PMP on an
        // otherwise-exempt page.
        $this->stagePmpEnqueueEnv([], '/subscribe');
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'fiftyonedegrees_pmp_exempt') {
                return false;
            }
            return $value;
        });
        $this->expectPmpRegistered();

        (new FiftyoneService())->fiftyonedegrees_javascript();

        $this->assertTrue(true);
    }

    /**
     * When PIPELINE_ENABLE is 'off', fiftyonedegrees_init() must not call
     * Pipeline::process() — Pipeline::$data stays null.
     */
    public function testPipelineEnableOffSkipsProcessing() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE_ENABLE) return 'off';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });
        Pipeline::reset();
        FiftyoneService::fiftyonedegrees_init();
        $this->assertNull(Pipeline::$data);
    }

    /**
     * When PIPELINE_ENABLE is 'on', fiftyonedegrees_init() must call
     * Pipeline::process() — Pipeline::$data is populated.
     */
    public function testPipelineEnableOnCallsProcess() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE_ENABLE) return 'on';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });
        Pipeline::reset();
        FiftyoneService::fiftyonedegrees_init();
        $this->assertNotNull(Pipeline::$data);
    }

    /**
     * The pipeline_autoenable_notice action must be registered.
     */
    public function testPipelineAutoEnableNoticeActionRegistered() {
        (new FiftyoneService())->setup_wp_actions();
        self::assertNotFalse(has_action(
            'admin_notices',
            'FiftyoneService->fiftyonedegrees_pipeline_autoenable_notice()'));
    }

    /**
     * When ROBOTS_ENFORCE is saved as 'on' and PIPELINE_ENABLE is 'off',
     * fiftyonedegrees_update_option() must set PIPELINE_ENABLE to 'on'
     * and set the auto-enable transient.
     */
    public function testRobotsEnforceOnAutoEnablesPipeline() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE_ENABLE) return 'off';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });

        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $transientKey = null;
        Functions\when('set_transient')->alias(function($key) use (&$transientKey) {
            $transientKey = $key;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::ROBOTS_ENFORCE, 'off', 'on');

        $this->assertArrayHasKey(Options::PIPELINE_ENABLE, $updated);
        $this->assertEquals('on', $updated[Options::PIPELINE_ENABLE]);
        $this->assertEquals(
            'fiftyonedegrees_pipeline_auto_enabled', $transientKey);
    }

    /**
     * When ROBOTS_ENFORCE is saved as 'on' but PIPELINE_ENABLE is already 'on',
     * no redundant update or transient should be written.
     */
    public function testRobotsEnforceOnDoesNotAutoEnableIfAlreadyOn() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE_ENABLE) return 'on';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });

        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::ROBOTS_ENFORCE, 'on', 'on');

        $this->assertArrayNotHasKey(Options::PIPELINE_ENABLE, $updated);
    }

    /**
     * When ROBOTS_ENFORCE is saved as 'off', no auto-enable should happen.
     */
    public function testRobotsEnforceOffDoesNotAutoEnable() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE_ENABLE) return 'off';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });

        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::ROBOTS_ENFORCE, 'on', 'off');

        $this->assertArrayNotHasKey(Options::PIPELINE_ENABLE, $updated);
    }

    /**
     * When PIPELINE_ENABLE is set to 'off' while ROBOTS_ENFORCE is set to 'on',
     * fiftyonedegrees_update_option() must disable the ROBOTS_ENFORCE
     */
    public function testPipelineEnableOffAutoReenabledWhenRobotsEnforceOn() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::ROBOTS_ENFORCE) return 'on';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });

        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $transientKey = null;
        Functions\when('set_transient')->alias(function($key) use (&$transientKey) {
            $transientKey = $key;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::PIPELINE_ENABLE, 'on', 'off');

        $this->assertArrayHasKey(Options::ROBOTS_ENFORCE, $updated);
        $this->assertEquals('off', $updated[Options::ROBOTS_ENFORCE]);
    }

    /**
     * When PIPELINE_ENABLE is set to 'off' and ROBOTS_ENFORCE is also 'off',
     * no auto-enable should happen.
     */
    public function testPipelineEnableOffDoesNotAutoReenableWhenRobotsEnforceOff() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::ROBOTS_ENFORCE) return 'off';
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });

        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::PIPELINE_ENABLE, 'on', 'off');

        $this->assertArrayNotHasKey(Options::PIPELINE_ENABLE, $updated);
    }

    public function testResourceKeyUpdateDoesNotClobberCachedPipelineOnCloudFailure() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            if ($arg === Options::PIPELINE) return HookTests::$pipeline;
            return $default;
        });
        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });
        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        Patchwork\redefine(
            'Pipeline::make_pipeline',
            Patchwork\always([
                'pipeline' => null,
                'available_engines' => null,
                'error' => 'Cloud unreachable: simulated',
            ])
        );

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::RESOURCE_KEY, 'old-key', 'new-key');

        $this->assertArrayNotHasKey(
            Options::PIPELINE,
            $updated,
            'Error pipeline must not overwrite the cached pipeline'
        );
        $this->assertContains(
            Options::ROBOTS_LAST_REFRESH,
            $deleted,
            'Stale last-refresh against the previous key must be cleared'
        );
        $this->assertArrayHasKey(
            Options::PIPELINE_VALIDATION_ERROR,
            $updated,
            'Validation error message must be surfaced to setup.php'
        );
        $this->assertSame(
            'Cloud unreachable: simulated',
            $updated[Options::PIPELINE_VALIDATION_ERROR]
        );
    }

    public function testResourceKeyUpdateClearsValidationErrorOnSuccessfulBuild() {
        Functions\when('get_option')->alias(function($arg, $default = null) {
            return $default;
        });
        $updated = [];
        Functions\when('update_option')->alias(function($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });
        $deleted = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        Patchwork\redefine(
            'Pipeline::make_pipeline',
            Patchwork\always(HookTests::$pipeline)
        );

        $service = new FiftyoneService();
        $service->fiftyonedegrees_update_option(
            Options::RESOURCE_KEY, 'old-key', 'new-key');

        $this->assertArrayHasKey(Options::PIPELINE, $updated);
        $this->assertContains(Options::PIPELINE_VALIDATION_ERROR, $deleted);
    }

    /**
     * fiftyonedegrees_pipeline_autoenable_notice() outputs a notice when the
     * transient is set, then deletes it.
     */
    public function testAutoEnableNoticeOutputsWhenTransientSet() {
        Functions\when('get_transient')->alias(function($key) {
            if ($key === 'fiftyonedegrees_pipeline_auto_enabled') {
                return 'Robots Enforce';
            }
            return false;
        });
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('esc_html')->returnArg();

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_pipeline_autoenable_notice();
        $output = ob_get_clean();

        $this->assertStringContainsString('Device detection was automatically enabled', $output);
        $this->assertStringContainsString('Robots Enforce', $output);
    }

    /**
     * fiftyonedegrees_pipeline_autoenable_notice() outputs nothing when the
     * transient is not set.
     */
    public function testAutoEnableNoticeOutputsNothingWhenTransientAbsent() {
        Functions\when('get_transient')->justReturn(false);

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_pipeline_autoenable_notice();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }
}
?>
