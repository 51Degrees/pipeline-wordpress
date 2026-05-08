<?php

require_once(__DIR__ . "/../options.php");
require_once(__DIR__ . "/../includes/wp-http-client.php");
require_once(__DIR__ . "/../includes/pipeline.php");
require_once(__DIR__ . "/../includes/cloud-metadata.php");
require_once(__DIR__ . "/../includes/robots-txt.php");

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;

class CloudOriginHeaderTests extends TestCase {

    public function set_up() {
        parent::set_up();
        Brain\Monkey\setUp();
        Functions\when('add_filter')->returnArg();
        Functions\when('add_action')->returnArg();
    }

    public function tear_down() {
        Patchwork\undoAll();
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Helper that captures the 4th arg passed to makeCloudRequest.
     * Throws a CloudRequestException so callers' error paths fall through
     * cleanly without us having to mock realistic response bodies.
     */
    private function captureOrigin(&$captured) {
        Patchwork\redefine(
            'FiftyOneDegreesWpHttpClient::makeCloudRequest',
            function ($type, $url, $content, $originHeader) use (&$captured) {
                $captured = $originHeader;
                throw new \fiftyone\pipeline\cloudrequestengine\CloudRequestException('capture', 0, []);
            }
        );
    }

    /**
     * Test that home_url with no path returns the URL unchanged.
     */
    public function testDefaultOrigin_ReturnsSchemeHostForSimpleUrl() {
        Functions\when('home_url')->justReturn('https://example.com');
        $this->assertEquals('https://example.com', FiftyOneDegreesWpHttpClient::defaultOrigin());
    }

    /**
     * Test that home_url with a path is reduced to scheme+host (RFC 6454).
     */
    public function testDefaultOrigin_StripsPath() {
        Functions\when('home_url')->justReturn('https://example.com/wp/blog');
        $this->assertEquals('https://example.com', FiftyOneDegreesWpHttpClient::defaultOrigin());
    }

    /**
     * Test that a non-default port is preserved in the Origin value.
     */
    public function testDefaultOrigin_PreservesPort() {
        Functions\when('home_url')->justReturn('http://localhost:8080');
        $this->assertEquals('http://localhost:8080', FiftyOneDegreesWpHttpClient::defaultOrigin());
    }

    /**
     * Test that an unparseable home_url yields null (not a malformed Origin).
     */
    public function testDefaultOrigin_ReturnsNullForInvalidHomeUrl() {
        Functions\when('home_url')->justReturn('');
        $this->assertNull(FiftyOneDegreesWpHttpClient::defaultOrigin());
    }

    /**
     * Test that Pipeline::make_pipeline passes Origin to the cloud HTTP layer.
     */
    public function testMakePipeline_PassesOriginToHttpClient() {
        Functions\when('home_url')->justReturn('https://wp.example.com');
        Functions\when('rest_url')->justReturn('https://wp.example.com/wp-json/fiftyonedegrees/v4/json');

        $captured = null;
        $this->captureOrigin($captured);

        Pipeline::make_pipeline('TEST_KEY');

        $this->assertEquals('https://wp.example.com', $captured);
    }

    /**
     * Test that FiftyOneDegreesCloudMetadata::fetch_accessible_properties passes Origin.
     */
    public function testFetchAccessibleProperties_PassesOriginToHttpClient() {
        Functions\when('home_url')->justReturn('https://wp.example.com');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');
        $captured = null;
        $this->captureOrigin($captured);

        FiftyOneDegreesCloudMetadata::fetch_accessible_properties();

        $this->assertEquals('https://wp.example.com', $captured);
    }

    /**
     * Test that FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values passes Origin.
     */
    public function testFetchCrawlerUsageValues_PassesOriginToHttpClient() {
        Functions\when('home_url')->justReturn('https://wp.example.com');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');
        $captured = null;
        $this->captureOrigin($captured);

        FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertEquals('https://wp.example.com', $captured);
    }

    /**
     * Test that FiftyOneDegreesRobotsTxt::fetch_from_cloud passes Origin.
     */
    public function testRobotsTxtFetchFromCloud_PassesOriginToHttpClient() {
        Functions\when('home_url')->justReturn('https://wp.example.com');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === Options::RESOURCE_KEY) {
                return 'some-key';
            }
            return $default;
        });

        // Short-circuit the crawler-usage cloud call so the only
        // makeCloudRequest invocation captured is the robots-txt-specific one.
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values',
            Patchwork\always([])
        );

        $captured = null;
        $this->captureOrigin($captured);

        FiftyOneDegreesRobotsTxt::fetch_from_cloud();

        $this->assertEquals('https://wp.example.com', $captured);
    }
}
