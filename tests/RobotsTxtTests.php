<?php

require_once(__DIR__ . "/../options.php");
require_once(__DIR__ . "/../includes/pipeline.php");
require_once(__DIR__ . "/../includes/fiftyone-service.php");

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;

class RobotsTxtTests extends TestCase {

    private $originalServer;

    public function set_up() {
        parent::set_up();
        Brain\Monkey\setUp();
        $this->originalServer = $_SERVER;

        Functions\when('add_filter')->returnArg();
        Functions\when('add_action')->returnArg();
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('url_to_postid')->justReturn(0);
        require_once(__DIR__ . "/../includes/cloud-metadata.php");
        require_once(__DIR__ . "/../includes/standard-tdls.php");
        require_once(__DIR__ . "/../includes/robots-txt.php");
        FiftyOneDegreesRobotsTxt::init();
    }

    public function tear_down() {
        $_SERVER = $this->originalServer;
        FiftyOneDegreesStandardTdls::reset();
        Patchwork\undoAll();
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    private const ALL_TEST_CATEGORIES = ['Index', 'Train', 'Input', 'Search', 'Monitor',
                                          'Archiving', 'Preview', 'Security', 'Analytics',
                                          'Feed', 'Discovery'];

    private function mockOptions($opts) {
        $defaults = [
            Options::ROBOTS_ENABLE => 'off',
            Options::ROBOTS_ENFORCE => 'off',
            Options::ROBOTS_CUSTOM_TOP => '',
            Options::ROBOTS_CUSTOM_BOTTOM => '',
            Options::ROBOTS_ALLOWED_CATEGORIES => null,
            Options::ROBOTS_REDIRECT_URL => '',
            Options::ROBOTS_STANDARD_TDL_SELECTED => [],
            Options::ROBOTS_CUSTOM_TDL => [],
            Options::RESOURCE_KEY => '',
            Options::ROBOTS_PLAINTEXT_CACHE => '',
        ];
        $all = array_merge($defaults, $opts);
        Functions\when('get_option')->alias(function($key, $default = '') use ($all) {
            return isset($all[$key]) ? $all[$key] : $default;
        });
    }

    public function testDisabledReturnsDefault() {
        $this->mockOptions([Options::ROBOTS_ENABLE => 'off']);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("User-agent: *\nDisallow:", true);
        $this->assertEquals("User-agent: *\nDisallow:", $result);
    }

    public function testCustomTopPrepended() {
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_CUSTOM_TOP => "User-agent: GoogleBot\nDisallow: /admin/",
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("", true);
        $this->assertStringContainsString('GoogleBot', $result);
    }

    public function testCustomBottomAppended() {
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_CUSTOM_BOTTOM => "Sitemap: https://example.com/sitemap.xml",
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("", true);
        $this->assertStringContainsString('Sitemap:', $result);
    }

    public function testFullOutput() {
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_CUSTOM_TOP => "User-agent: *\nAllow: /",
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: Googlebot\nDisallow: /private/\n",
            Options::ROBOTS_CUSTOM_BOTTOM => "Sitemap: https://example.com/sitemap.xml",
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("", true);

        $topPos = strpos($result, 'Allow: /');
        $cachePos = strpos($result, 'Googlebot');
        $sitemapPos = strpos($result, 'Sitemap:');

        $this->assertLessThan($cachePos, $topPos);
        $this->assertLessThan($sitemapPos, $cachePos);
    }

    public function testEnforceOnEnableOffNoRobotsTxt() {
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'off',
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Index']),
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("User-agent: *\nDisallow:", true);
        $this->assertEquals("User-agent: *\nDisallow:", $result);
    }

    public function testSanitizeRobotsTextareaCallsWordPressFunction() {
        Functions\when('sanitize_textarea_field')->alias(function($v) {
            return strip_tags($v);
        });
        $result = FiftyoneService::sanitize_robots_textarea("User-agent: *\n<script>alert(1)</script>");
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('User-agent: *', $result);
    }

    public function testSanitizeRobotsTextareaPassesThroughValidContent() {
        Functions\when('sanitize_textarea_field')->returnArg();
        $valid = "User-agent: *\nDisallow: /private/";
        $result = FiftyoneService::sanitize_robots_textarea($valid);
        $this->assertEquals($valid, $result);
    }

    public function testSanitizeRobotsRedirectUrlCallsEscUrlRaw() {
        Functions\when('esc_url_raw')->alias(function($v) {
            return filter_var($v, FILTER_SANITIZE_URL);
        });
        $result = FiftyoneService::sanitize_robots_redirect_url('https://example.com/bot-landing');
        $this->assertEquals('https://example.com/bot-landing', $result);
    }

    public function testSanitizeRobotsRedirectUrlRejectsMalformed() {
        Functions\when('esc_url_raw')->justReturn('');
        $result = FiftyoneService::sanitize_robots_redirect_url('not a url at all!@#');
        $this->assertEquals('', $result);
    }

    public function testPrivateSiteWithAllCategoriesAllowedPreservesDefaultOutput() {
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt("User-agent: *\nDisallow: /", '0');
        $this->assertEquals("User-agent: *\nDisallow: /", $result);
    }

    private function mockGuardsPassed() {
        Functions\when('is_admin')->justReturn(false);
        Patchwork\redefine('php_sapi_name', Patchwork\always('apache2handler'));
    }

    public function testEnforceSkippedWhenPipelineDisabled() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::PIPELINE_ENABLE => 'off',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected, 'Enforcement must be skipped when device detection is disabled');
    }

    public function testEnforceOffDoesNotRedirect() {
        $this->mockOptions([Options::ROBOTS_ENFORCE => 'off']);
        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected);
    }

    public function testIsAdminDoesNotRedirect() {
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
        ]);
        Functions\when('is_admin')->justReturn(true);
        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected);
    }

    public function testCliDoesNotRedirect() {
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
        ]);
        Functions\when('is_admin')->justReturn(false);
        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        // php_sapi_name() naturally returns 'cli' in the test runner — no patching needed
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected);
    }

    public function testNonCrawlerDoesNotRedirect() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', Patchwork\always(false));
        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected);
    }

    public function testCrawlerInDeniedCategoryRedirects() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Search'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );
        Patchwork\redefine('exit', Patchwork\always(null));

        $_SERVER['REQUEST_URI'] = '/some-page';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com/some-page');
        Functions\when('trailingslashit')->alias(function ($u) {
            return rtrim($u, '/') . '/';
        });

        $redirectedTo = null;
        Functions\when('wp_redirect')->alias(function ($url) use (&$redirectedTo) {
            $redirectedTo = $url;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertEquals('https://example.com/denied', $redirectedTo);
    }

    public function testCrawlerOnStaticFrontPageDoesNotRedirect() {
        // Loop prevention: when the redirect target page is also the WP
        // static front page, the home request (/) and the page slug URL
        // (/denied/) resolve to the same post ID. A plain URL string
        // comparison would treat them as different and loop.
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied/',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Search'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );

        $_SERVER['REQUEST_URI'] = '/';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com/');
        Functions\when('trailingslashit')->alias(function ($u) {
            return rtrim($u, '/') . '/';
        });
        Functions\when('url_to_postid')->justReturn(4);

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertFalse($redirected);
    }

    public function testCrawlerNotInAllowedCategoryDoesNotRedirect() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Analytics'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertFalse($redirected);
    }

    public function testIsCrawlerTrueWithoutCrawlerUsageSupportDoesNotRedirect() {
        // Without CrawlerUsage on the resource key the plugin has no basis to
        // deny a category-by-category check, so enforcement falls through.
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(false)
        );

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertFalse($redirected);
    }

    public function testRedirectLoopPreventionSkipsRedirect() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Search'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );

        $_SERVER['REQUEST_URI'] = '/denied';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com/denied');
        Functions\when('trailingslashit')->alias(function ($u) {
            return rtrim($u, '/') . '/';
        });

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertFalse($redirected);
    }

    public function testEnforcementSkippedWhenOptionNeverSaved() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => null,
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Index'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();

        $this->assertFalse($redirected, 'Enforcement must be skipped when option has never been saved');
    }

    public function testFetchFromCloudReturnsFalseWithNoResourceKey() {
        $this->mockOptions([Options::RESOURCE_KEY => '']);
        $result = FiftyOneDegreesRobotsTxt::fetch_from_cloud();
        $this->assertFalse($result);
    }

    public function testFetchFromCloudBuildsQueryStringWithAllCategories() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key-abc',
            Options::ROBOTS_ALLOWED_CATEGORIES => ['Index', 'Train', 'Input', 'Analytics'],
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always(['Search' => 'Search engines', 'Analytics' => 'Analytics crawlers'])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('test-key-abc', $capturedUrl);
        $this->assertStringContainsString('robotstxt.search=disallow', $capturedUrl);
        $this->assertStringContainsString('robotstxt.analytics=allow', $capturedUrl);
    }

    public function testFetchFromCloudFallsBackToDefaultsWhenNoCategoryListAndNeverSaved() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => null,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertStringContainsString('robotstxt.train=disallow', $capturedUrl);
        $this->assertStringNotContainsString('robotstxt.train=allow', $capturedUrl);
    }

    public function testFetchFromCloudStoresCacheOnSuccess() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            Patchwork\always(json_encode([
                'robotstxt' => [
                    'plaintext' => "User-agent: *\nDisallow: /\n",
                    'annotatedtext' => "# Annotated\nUser-agent: *\nDisallow: /\n",
                ],
            ]))
        );
        Functions\when('delete_transient')->justReturn(true);

        $updated = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $result = FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertTrue($result);
        $this->assertArrayHasKey(Options::ROBOTS_PLAINTEXT_CACHE, $updated);
        $this->assertArrayHasKey(Options::ROBOTS_ANNOTATEDTEXT_CACHE, $updated);
        $this->assertStringContainsString('User-agent: *', $updated[Options::ROBOTS_PLAINTEXT_CACHE]);
        $this->assertStringContainsString('Annotated', $updated[Options::ROBOTS_ANNOTATEDTEXT_CACHE]);
    }

    public function testFetchFromCloudPreservesCacheOnCloudRequestException() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) {
                throw new \fiftyone\pipeline\cloudrequestengine\CloudRequestException('API error', 503, []);
            }
        );
        Functions\when('set_transient')->justReturn(true);

        $updated = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $result = FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertFalse($result);
        $this->assertArrayNotHasKey(Options::ROBOTS_PLAINTEXT_CACHE, $updated);
        $this->assertArrayNotHasKey(Options::ROBOTS_ANNOTATEDTEXT_CACHE, $updated);
    }

    public function testFetchFromCloudPreservesCacheOnInvalidJson() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            Patchwork\always('not-json')
        );
        Functions\when('set_transient')->justReturn(true);

        $updated = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$updated) {
            $updated[$key] = $value;
            return true;
        });

        $result = FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertFalse($result);
        $this->assertArrayNotHasKey(Options::ROBOTS_PLAINTEXT_CACHE, $updated);
    }

    public function testGenerateRobotsTxtContentUsesCloudCacheWhenNonEmpty() {
        $cloudContent = "# Cloud generated\nUser-agent: Googlebot\nDisallow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_PLAINTEXT_CACHE => $cloudContent,
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt('', true);
        $this->assertStringContainsString('# Cloud generated', $result);
        $this->assertStringContainsString('User-agent: Googlebot', $result);
    }

    public function testGenerateRobotsTxtContentCloudCacheWithCustomTopAndBottom() {
        $cloudContent = "User-agent: *\nDisallow: /private/\n";
        $this->mockOptions([
            Options::ROBOTS_ENABLE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
            Options::ROBOTS_PLAINTEXT_CACHE => $cloudContent,
            Options::ROBOTS_CUSTOM_TOP => "# Custom top",
            Options::ROBOTS_CUSTOM_BOTTOM => "Sitemap: https://example.com/sitemap.xml",
        ]);
        $result = FiftyOneDegreesRobotsTxt::generate_robots_txt('', true);
        $topPos = strpos($result, '# Custom top');
        $cloudPos = strpos($result, 'User-agent: *');
        $bottomPos = strpos($result, 'Sitemap:');
        $this->assertLessThan($cloudPos, $topPos);
        $this->assertLessThan($bottomPos, $cloudPos);
    }

    public function testFetchFromCloudUsesEnvVarEndpoint() {
        putenv('FOD_CLOUD_API_URL=https://custom-cloud.example.com/api/v4/');
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();
        putenv('FOD_CLOUD_API_URL');

        $this->assertStringContainsString('custom-cloud.example.com', $capturedUrl);
    }

    public function testFetchFromCloudIncludesTdlQueryParam() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
            Options::ROBOTS_CUSTOM_TDL => ['https://example.com/tdl/1.txt', 'https://example.org/tdl/2.txt'],
            Options::ROBOTS_STANDARD_TDL_SELECTED => [],
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('robotstxt.tdl=', $capturedUrl);
        $this->assertStringContainsString('example.com', $capturedUrl);
        $this->assertStringContainsString('example.org', $capturedUrl);
    }

    public function testFetchFromCloudOmitsTdlParamWhenEmpty() {
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
            Options::ROBOTS_CUSTOM_TDL => [],
            Options::ROBOTS_STANDARD_TDL_SELECTED => [],
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::do_cloud_request',
            function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertNotNull($capturedUrl);
        $this->assertStringNotContainsString('robotstxt.tdl', $capturedUrl);
    }

    public function testRefreshCronCallsFetchWhenRobotsEnabled() {
        $this->mockOptions([Options::ROBOTS_ENABLE => 'on']);
        $called = false;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            function () use (&$called) {
                $called = true;
                return true;
            }
        );
        FiftyOneDegreesRobotsTxt::refresh_robots_txt_cron();
        $this->assertTrue($called);
    }

    public function testRefreshCronSkipsWhenRobotsDisabled() {
        $this->mockOptions([Options::ROBOTS_ENABLE => 'off']);
        $called = false;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            function () use (&$called) {
                $called = true;
                return true;
            }
        );
        FiftyOneDegreesRobotsTxt::refresh_robots_txt_cron();
        $this->assertFalse($called);
    }

    public function testReGenerateCallsFetchOnRobotsTabSettingsUpdated() {
        $_GET['settings-updated'] = 'true';
        $_GET['page'] = '51Degrees';
        $_GET['tab']  = 'robots';

        $called = false;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            function () use (&$called) {
                $called = true;
                return true;
            }
        );
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('set_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_re_generate_robots();

        unset($_GET['settings-updated'], $_GET['page'], $_GET['tab']);

        $this->assertTrue($called);
    }

    public function testReGenerateSkipsWhenNotOnRobotsTab() {
        $_GET['settings-updated'] = 'true';
        $_GET['page'] = '51Degrees';
        $_GET['tab']  = 'setup';

        $called = false;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            function () use (&$called) {
                $called = true;
                return true;
            }
        );
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $service = new FiftyoneService();
        $service->fiftyonedegrees_re_generate_robots();

        unset($_GET['settings-updated'], $_GET['page'], $_GET['tab']);

        $this->assertFalse($called);
    }

    public function testReGenerateSkipsWithoutSettingsUpdatedParam() {
        unset($_GET['settings-updated']);
        $_GET['page'] = '51Degrees';
        $_GET['tab']  = 'robots';

        $called = false;
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            function () use (&$called) {
                $called = true;
                return true;
            }
        );

        $service = new FiftyoneService();
        $service->fiftyonedegrees_re_generate_robots();

        unset($_GET['page'], $_GET['tab']);

        $this->assertFalse($called);
    }

    public function testReGenerateSetsSuccessTransientOnSuccess() {
        $_GET['settings-updated'] = 'true';
        $_GET['page'] = '51Degrees';
        $_GET['tab']  = 'robots';

        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            Patchwork\always(true)
        );
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $transientKey = null;
        Functions\when('set_transient')->alias(function ($key) use (&$transientKey) {
            $transientKey = $key;
            return true;
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_re_generate_robots();

        unset($_GET['settings-updated'], $_GET['page'], $_GET['tab']);

        $this->assertEquals('fiftyonedegrees_robots_generate_success', $transientKey);
    }

    public function testReGenerateDoesNotSetTransientOnFailure() {
        $_GET['settings-updated'] = 'true';
        $_GET['page'] = '51Degrees';
        $_GET['tab']  = 'robots';

        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            Patchwork\always(false)
        );
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $transientSet = false;
        Functions\when('set_transient')->alias(function () use (&$transientSet) {
            $transientSet = true;
            return true;
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_re_generate_robots();

        unset($_GET['settings-updated'], $_GET['page'], $_GET['tab']);

        $this->assertFalse($transientSet);
    }

    private function mockStandardTdlsConfig(array $entries) {
        Patchwork\redefine(
            'FiftyOneDegreesStandardTdls::load',
            Patchwork\always($entries)
        );
    }

    public function testAnnotatedRobotsEndpointReturns404WhenCacheEmpty() {
        $this->mockOptions([Options::ROBOTS_ANNOTATEDTEXT_CACHE => '']);
        $service = new FiftyoneService();
        $result = $service->fiftyonedegrees_annotated_robots_callback();
        $this->assertInstanceOf('WP_REST_Response', $result);
        $this->assertEquals(404, $result->get_status());
    }

    public function testAnnotatedRobotsEndpointServesContentWhenCacheNonEmpty() {
        $content = "# Annotated\nUser-agent: Googlebot\nDisallow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $content,
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_annotated_robots_callback();
        $output = ob_get_clean();

        $this->assertEquals($content, $output);
    }

    public function testAnnotatedRobotsEndpointDoesNotReturnResponseWhenCacheNonEmpty() {
        $content = "User-agent: *\nDisallow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $content,
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $result = $service->fiftyonedegrees_annotated_robots_callback();
        ob_end_clean();

        $this->assertNull($result, 'Callback should not return a response when serving content directly');
    }

    public function testAnnotatedRobotsCallbackPrependsCustomTop() {
        $annotated = "# N: SomeBot\nUser-agent: somebot\nAllow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $annotated,
            Options::ROBOTS_CUSTOM_TOP => "User-agent: MyBot\nDisallow: /private/",
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_annotated_robots_callback();
        $output = ob_get_clean();

        $customTopPos = strpos($output, 'MyBot');
        $annotatedPos = strpos($output, '# N: SomeBot');
        $this->assertNotFalse($customTopPos);
        $this->assertLessThan($annotatedPos, $customTopPos);
    }

    public function testAnnotatedRobotsCallbackAppendsCustomBottom() {
        $annotated = "# N: SomeBot\nUser-agent: somebot\nAllow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $annotated,
            Options::ROBOTS_CUSTOM_BOTTOM => "Sitemap: https://example.com/sitemap.xml",
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_annotated_robots_callback();
        $output = ob_get_clean();

        $annotatedPos = strpos($output, '# N: SomeBot');
        $bottomPos = strpos($output, 'Sitemap:');
        $this->assertNotFalse($bottomPos);
        $this->assertLessThan($bottomPos, $annotatedPos);
    }

    public function testAnnotatedRobotsCallbackWithNoTDLsServesAnnotatedCacheDirectly() {
        $annotated = "# N: SomeBot\nUser-agent: somebot\nAllow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $annotated,
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
            Options::ROBOTS_STANDARD_TDL_SELECTED => [],
            Options::ROBOTS_CUSTOM_TDL => [],
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_annotated_robots_callback();
        $output = ob_get_clean();

        $this->assertEquals($annotated, $output);
    }

    public function testAnnotatedRobotsCallbackFullAssembly() {
        $annotated = "# N: SomeBot\nUser-agent: somebot\nAllow: /\n";
        $this->mockOptions([
            Options::ROBOTS_ANNOTATEDTEXT_CACHE => $annotated,
            Options::ROBOTS_CUSTOM_TOP => "User-agent: *\nAllow: /",
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Archiving']),
            Options::ROBOTS_CUSTOM_TDL => ['https://example.com/tdl/1.txt'],
            Options::ROBOTS_CUSTOM_BOTTOM => "Sitemap: https://example.com/sitemap.xml",
        ]);
        Functions\when('nocache_headers')->justReturn(null);
        Patchwork\redefine('header', Patchwork\always(null));
        Patchwork\redefine('exit', Patchwork\always(null));

        $service = new FiftyoneService();
        ob_start();
        $service->fiftyonedegrees_annotated_robots_callback();
        $output = ob_get_clean();

        $topPos = strpos($output, 'Allow: /');
        $annotatedPos = strpos($output, '# N: SomeBot');
        $bottomPos = strpos($output, 'Sitemap:');

        $this->assertLessThan($annotatedPos, $topPos);
        $this->assertLessThan($bottomPos, $annotatedPos);
    }

    public function testEmptyRedirectUrlServesBotDeniedPage() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => '',
            Options::ROBOTS_PLAINTEXT_CACHE => "User-agent: *\nDisallow: /\n",
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Search'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );
        Patchwork\redefine('exit', Patchwork\always(null));

        $_SERVER['REQUEST_URI'] = '/some-page';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('esc_html')->returnArg();

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });

        ob_start();
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $output = ob_get_clean();

        $this->assertFalse($redirected, 'wp_redirect should not be called when URL is empty');
        $this->assertStringContainsString('Access denied for crawlers', $output);
    }

    public function testRobotsGroupKeyDiffersFromMainGroupKey() {
        $this->assertNotEquals(
            Options::GROUP_KEY,
            Options::ROBOTS_GROUP_KEY,
            'ROBOTS_GROUP_KEY must be a distinct constant from GROUP_KEY to prevent Settings API group collision'
        );
    }

    public function testRobotsSettingsRegisteredUnderDedicatedGroupKey() {
        $registered = [];
        Functions\when('add_option')->justReturn(null);
        Functions\when('register_setting')->alias(function ($group, $option) use (&$registered) {
            $registered[] = [$group, $option];
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_register_settings();

        $robotsOptions = [
            Options::ROBOTS_ENABLE,
            Options::ROBOTS_ENFORCE,
            Options::ROBOTS_REDIRECT_URL,
            Options::ROBOTS_CUSTOM_TOP,
            Options::ROBOTS_CUSTOM_BOTTOM,
            Options::ROBOTS_ALLOWED_CATEGORIES,
            Options::ROBOTS_STANDARD_TDL_SELECTED,
            Options::ROBOTS_CUSTOM_TDL,
        ];

        foreach ($robotsOptions as $opt) {
            $found = false;
            foreach ($registered as [$group, $option]) {
                if ($option === $opt && $group === Options::ROBOTS_GROUP_KEY) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "$opt must be registered under ROBOTS_GROUP_KEY");
        }

        // RESOURCE_KEY must stay under the main GROUP_KEY
        $resourceKeyGroup = null;
        foreach ($registered as [$group, $option]) {
            if ($option === Options::RESOURCE_KEY) {
                $resourceKeyGroup = $group;
                break;
            }
        }
        $this->assertEquals(Options::GROUP_KEY, $resourceKeyGroup, 'RESOURCE_KEY must stay under GROUP_KEY');

        // Programmatic cache/URL-map fields must NOT be registered at all
        $programmaticFields = [
            Options::ROBOTS_PLAINTEXT_CACHE,
            Options::ROBOTS_ANNOTATEDTEXT_CACHE,
        ];
        foreach ($programmaticFields as $opt) {
            foreach ($registered as [$group, $option]) {
                $this->assertNotEquals($opt, $option, "$opt must not be registered via register_setting (written only by direct update_option calls)");
            }
        }
    }

    public function testResourceKeyNotRegisteredUnderRobotsGroupKey() {
        $registered = [];
        Functions\when('add_option')->justReturn(null);
        Functions\when('register_setting')->alias(function ($group, $option) use (&$registered) {
            $registered[] = [$group, $option];
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_register_settings();

        foreach ($registered as [$group, $option]) {
            if ($option === Options::RESOURCE_KEY) {
                $this->assertNotEquals(
                    Options::ROBOTS_GROUP_KEY,
                    $group,
                    'RESOURCE_KEY must never be registered under ROBOTS_GROUP_KEY — doing so would wipe it on every robots form save'
                );
            }
        }
    }

    public function testSanitizeTdlPreservesValidUrlString() {
        Functions\when('esc_url_raw')->returnArg();
        $input = "https://example.com/terms/v1\nhttps://other.com/tdl";
        $result = FiftyoneService::sanitize_tdl($input);
        $this->assertContains('https://example.com/terms/v1', $result);
        $this->assertContains('https://other.com/tdl', $result);
    }

    public function testSanitizeTdlFiltersEmptyLines() {
        Functions\when('esc_url_raw')->returnArg();
        $input = "https://example.com/terms/v1\n\n";
        $result = FiftyoneService::sanitize_tdl($input);
        $this->assertCount(1, $result);
        $this->assertContains('https://example.com/terms/v1', $result);
    }

    public function testSanitizeTdlDeduplicatesLines() {
        Functions\when('esc_url_raw')->returnArg();
        $url = 'https://example.com/terms/v1';
        $result = FiftyoneService::sanitize_tdl("$url\n$url");
        $this->assertCount(1, $result);
    }

    public function testSanitizeTdlRejectsInvalidType() {
        $result = FiftyoneService::sanitize_tdl(42);
        $this->assertSame([], $result);
    }

    public function testSanitizeTdlTrimsWhitespaceFromLines() {
        Functions\when('esc_url_raw')->returnArg();
        $result = FiftyoneService::sanitize_tdl("  https://example.com/terms/v1  \n");
        $this->assertCount(1, $result);
        $this->assertContains('https://example.com/terms/v1', $result);
    }

    public function testSanitizeTdlHandlesCRLFLineEndings() {
        Functions\when('esc_url_raw')->returnArg();
        $result = FiftyoneService::sanitize_tdl("https://example.com/terms/v1\r\nhttps://other.com/tdl");
        $this->assertCount(2, $result);
        $this->assertContains('https://example.com/terms/v1', $result);
        $this->assertContains('https://other.com/tdl', $result);
    }

    public function testSanitizeTdlAcceptsArrayForBackwardCompat() {
        Functions\when('esc_url_raw')->returnArg();
        $result = FiftyoneService::sanitize_tdl(['https://example.com/terms/v1']);
        $this->assertSame(['https://example.com/terms/v1'], $result);
    }

    public function testEnforceCategoryDeniedRedirects() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return ['Search'];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );
        Patchwork\redefine('exit', Patchwork\always(null));
        $_SERVER['REQUEST_URI'] = '/some-page';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com/some-page');
        Functions\when('trailingslashit')->alias(function ($u) {
            return rtrim($u, '/') . '/';
        });

        $redirectedTo = null;
        Functions\when('wp_redirect')->alias(function ($url) use (&$redirectedTo) {
            $redirectedTo = $url;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertEquals('https://example.com/denied', $redirectedTo);
    }

    public function testEnforceEmptyCrawlerUsageAllows() {
        $this->mockGuardsPassed();
        $this->mockOptions([
            Options::ROBOTS_ENFORCE => 'on',
            Options::ROBOTS_ALLOWED_CATEGORIES => array_diff(self::ALL_TEST_CATEGORIES, ['Search']),
            Options::ROBOTS_REDIRECT_URL => 'https://example.com/denied',
        ]);
        Patchwork\redefine('Pipeline::get', function ($engine, $prop) {
            if ($prop === 'iscrawler') return true;
            if ($prop === 'crawlerusage') return [];
            return null;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::supports_crawler_usage',
            Patchwork\always(true)
        );

        $redirected = false;
        Functions\when('wp_redirect')->alias(function () use (&$redirected) {
            $redirected = true;
        });
        FiftyOneDegreesRobotsTxt::enforce_crawler_redirect();
        $this->assertFalse($redirected, 'Empty crawler_usage → no basis to deny → not redirected');
    }


    public function testStandardTdlsConfigLoadsFromFile() {
        FiftyOneDegreesStandardTdls::reset();
        $entries = FiftyOneDegreesStandardTdls::load();
        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries, 'Config file must have at least one entry');
        $ids = array_column($entries, 'id');
        $this->assertContains('socw', $ids, 'Config file must include the SOCW entry');
    }

    public function testStandardTdlsConfigUnreadableFileReturnsEmptyArray() {
        FiftyOneDegreesStandardTdls::reset();
        Patchwork\redefine('file_get_contents', Patchwork\always(false));
        $result = FiftyOneDegreesStandardTdls::load();
        $this->assertSame([], $result);
    }

    public function testStandardTdlsGetByIdReturnsEntry() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => 'Test', 'macro' => 'MOW-SOCW'],
        ]);
        $entry = FiftyOneDegreesStandardTdls::get_by_id('socw');
        $this->assertNotNull($entry);
        $this->assertEquals('socw', $entry['id']);
        $this->assertEquals('MOW-SOCW', $entry['macro']);
    }

    public function testStandardTdlsGetByIdReturnsNullForUnknownId() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyOneDegreesStandardTdls::get_by_id('nonexistent');
        $this->assertNull($result);
    }

    /** Selected standard-TDL id resolves to the macro from config. */
    public function testGetEffectiveTdlValuesSelectedIdResolvesToConfigMacro() {
        $this->mockOptions([
            Options::ROBOTS_STANDARD_TDL_SELECTED => ['socw'],
            Options::ROBOTS_CUSTOM_TDL => [],
        ]);
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyOneDegreesRobotsTxt::get_effective_tdl_values();
        $this->assertEquals(['MOW-SOCW'], $result);
    }

    /** Unknown selected ids are silently skipped. */
    public function testGetEffectiveTdlValuesSkipsUnknownId() {
        $this->mockOptions([
            Options::ROBOTS_STANDARD_TDL_SELECTED => ['does-not-exist'],
            Options::ROBOTS_CUSTOM_TDL => [],
        ]);
        $this->mockStandardTdlsConfig([]);
        $result = FiftyOneDegreesRobotsTxt::get_effective_tdl_values();
        $this->assertSame([], $result);
    }

    /** Standard-TDL macros and custom URLs are merged into one list. */
    public function testGetEffectiveTdlValuesMergesCustomUrls() {
        $this->mockOptions([
            Options::ROBOTS_STANDARD_TDL_SELECTED => ['socw'],
            Options::ROBOTS_CUSTOM_TDL => ['https://example.com/terms/v1'],
        ]);
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyOneDegreesRobotsTxt::get_effective_tdl_values();
        $this->assertContains('MOW-SOCW', $result);
        $this->assertContains('https://example.com/terms/v1', $result);
        $this->assertCount(2, $result);
    }

    /** No selections and no custom entries → empty list. */
    public function testGetEffectiveTdlValuesEmptySelectionsAndNoCustomReturnsEmpty() {
        $this->mockOptions([
            Options::ROBOTS_STANDARD_TDL_SELECTED => [],
            Options::ROBOTS_CUSTOM_TDL => [],
        ]);
        $this->mockStandardTdlsConfig([]);
        $result = FiftyOneDegreesRobotsTxt::get_effective_tdl_values();
        $this->assertSame([], $result);
    }

    public function testSanitizeStandardTdlSelectedAcceptsValidId() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyoneService::sanitize_standard_tdl_selected(['socw']);
        $this->assertSame(['socw'], $result);
    }

    public function testSanitizeStandardTdlSelectedRejectsUnknownId() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyoneService::sanitize_standard_tdl_selected(['socw', 'invalid-id']);
        $this->assertSame(['socw'], $result);
    }

    public function testSanitizeStandardTdlSelectedRejectsNonArray() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyoneService::sanitize_standard_tdl_selected('socw');
        $this->assertSame([], $result);
    }

    public function testSanitizeStandardTdlSelectedDeduplicates() {
        $this->mockStandardTdlsConfig([
            ['id' => 'socw', 'label' => 'SOCW', 'description' => '', 'macro' => 'MOW-SOCW'],
        ]);
        $result = FiftyoneService::sanitize_standard_tdl_selected(['socw', 'socw']);
        $this->assertSame(['socw'], $result);
    }

    public function testDoCloudRequestAddsClientIpWhenRemoteAddrPresent() {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function (string $type, string $url, ?string $content, ?string $originHeader) use (&$capturedUrl): string {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('client-ip=1.2.3.4', $capturedUrl);
    }

    public function testDoCloudRequestOmitsClientIpWhenRemoteAddrAbsent() {
        unset($_SERVER['REMOTE_ADDR']);
        $this->mockOptions([
            Options::RESOURCE_KEY => 'test-key',
            Options::ROBOTS_ALLOWED_CATEGORIES => self::ALL_TEST_CATEGORIES,
        ]);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );
        $capturedUrl = null;
        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function (string $type, string $url, ?string $content, ?string $originHeader) use (&$capturedUrl): string {
                $capturedUrl = $url;
                return json_encode(['robotstxt' => ['plaintext' => '', 'annotatedtext' => '']]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertNotNull($capturedUrl);
        $this->assertStringNotContainsString('client-ip', $capturedUrl);
    }

    public function testRefreshCronLogsOnFetchFailure() {
        $this->mockOptions([Options::ROBOTS_ENABLE => 'on']);
        Patchwork\redefine(
            'FiftyOneDegreesRobotsTxt::fetch_from_cloud',
            Patchwork\always(false)
        );
        $logged = [];
        Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged[] = $message;
        });

        FiftyOneDegreesRobotsTxt::refresh_robots_txt_cron();

        $this->assertNotEmpty($logged, 'Cron refresh failure must be logged');
        $this->assertStringContainsString('51Degrees', $logged[0]);
    }

}
