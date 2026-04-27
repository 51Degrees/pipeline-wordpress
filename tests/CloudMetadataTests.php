<?php

require_once(__DIR__ . "/../options.php");

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

class CloudMetadataTests extends TestCase {

    private $originalServer;

    public function set_up() {
        parent::set_up();
        Brain\Monkey\setUp();
        $this->originalServer = $_SERVER;

        Functions\when('add_filter')->returnArg();
        Functions\when('add_action')->returnArg();
        require_once(__DIR__ . "/../includes/cloud-metadata.php");
    }

    public function tear_down() {
        $_SERVER = $this->originalServer;
        Patchwork\undoAll();
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    private function mockCrawlerUsageApiResponse() {
        return [
            [
                'PropertyName' => 'CrawlerUsage',
                'Name' => 'Train',
                'Description' => 'Indicates that the crawler is used to train AI models.',
                'Url' => 'Lite',
            ],
            [
                'PropertyName' => 'CrawlerUsage',
                'Name' => 'Search',
                'Description' => 'Indicates that the crawler is used to build search indexes and provide search results.',
                'Url' => 'Lite',
            ],
            [
                'PropertyName' => 'CrawlerUsage',
                'Name' => '',
                'Description' => 'Indicates that the crawler has unknown use.',
                'Url' => 'Lite',
            ],
            [
                'PropertyName' => 'CrawlerUsage',
                'Name' => 'N/A',
                'Description' => 'Not Applicable',
                'Url' => 'Lite',
            ],
        ];
    }

    public function testFetchCrawlerUsageValuesReturnsMap() {
        $apiData = $this->mockCrawlerUsageApiResponse();

        Functions\when('get_transient')->alias(function($key, $default = false) {
            return false;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            Patchwork\always(json_encode($apiData))
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');

        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertArrayHasKey('Train', $result);
        $this->assertArrayHasKey('Search', $result);
        $this->assertArrayNotHasKey('', $result);
        $this->assertArrayNotHasKey('N/A', $result);
        $this->assertEquals('Indicates that the crawler is used to train AI models.', $result['Train']);
        $this->assertEquals('Indicates that the crawler is used to build search indexes and provide search results.', $result['Search']);
    }

    public function testFetchCrawlerUsageValuesReturnsFromCache() {
        $cached = ['Analytics' => 'gather data for marketing analytics'];
        Functions\when('get_transient')->alias(function($key) use ($cached) {
            if ($key === 'fiftyonedegrees_crawler_usage_values') {
                return $cached;
            }
            return false;
        });

        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertEquals($cached, $result);
    }

    public function testFetchCrawlerUsageValuesReturnsEmptyOnApiFailure() {
        Functions\when('get_transient')->justReturn(false);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            function (string $url) {
                throw new \fiftyone\pipeline\cloudrequestengine\CloudRequestException('API error', 503, []);
            }
        );
        Functions\when('set_transient')->justReturn(true);

        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertEquals([], $result);
    }

    public function testFetchCrawlerUsageValuesReturnsEmptyOnFailureCircuitBreaker() {
        Functions\when('get_transient')->alias(function($key) {
            if ($key === 'fiftyonedegrees_crawler_usage_values') {
                return false;
            }
            if ($key === 'fiftyonedegrees_crawler_usage_fail') {
                return ['n' => 2];
            }
            return false;
        });

        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertEquals([], $result);
    }

    public function testSupportsRobotsTxtReturnsTrueWhenPlainTextPresent() {
        Functions\when('get_transient')->justReturn(false);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            Patchwork\always(json_encode([
                'Products' => [
                    'robotstxt' => [
                        'Properties' => [
                            ['Name' => 'PlainText'],
                            ['Name' => 'AnnotatedText'],
                        ],
                    ],
                ],
            ]))
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');

        $this->assertTrue(FiftyOneDegreesCloudMetadata::supports_robots_txt());
    }

    public function testSupportsRobotsTxtReturnsFalseWhenPropertiesMissing() {
        Functions\when('get_transient')->justReturn(false);
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            Patchwork\always(json_encode([
                'Products' => [
                    'device' => [
                        'Properties' => [
                            ['Name' => 'IsCrawler'],
                            ['Name' => 'CrawlerUsage'],
                        ],
                    ],
                ],
            ]))
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');

        $this->assertFalse(FiftyOneDegreesCloudMetadata::supports_robots_txt());
    }

    public function testInvalidateAllClearsBothCaches() {
        $deleted = [];
        Functions\when('delete_transient')->alias(function($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        FiftyOneDegreesCloudMetadata::invalidate_all();

        $this->assertContains('fiftyonedegrees_metadata', $deleted);
        $this->assertContains('fiftyonedegrees_metadata_fail', $deleted);
        $this->assertContains('fiftyonedegrees_crawler_usage_values', $deleted);
        $this->assertContains('fiftyonedegrees_crawler_usage_fail', $deleted);
    }

    public function testInvalidateCrawlerUsageClearsBothTransients() {
        $deleted = [];
        Functions\when('delete_transient')->alias(function($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        FiftyOneDegreesCloudMetadata::invalidate_crawler_usage();

        $this->assertContains('fiftyonedegrees_crawler_usage_values', $deleted);
        $this->assertContains('fiftyonedegrees_crawler_usage_fail', $deleted);
    }

    public function testFetchCrawlerUsageAfterInvalidateCallsApi() {
        $apiData = $this->mockCrawlerUsageApiResponse();

        Functions\when('get_transient')->alias(function($key, $default = false) {
            return false;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            Patchwork\always(json_encode($apiData))
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('some-key');

        FiftyOneDegreesCloudMetadata::invalidate_crawler_usage();
        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertArrayHasKey('Train', $result);
        $this->assertArrayHasKey('Search', $result);
    }

    public function testFetchCrawlerUsageAfterInvalidateSetsFailureTransientOnApiFailure() {
        $attempts = [];
        Functions\when('get_transient')->alias(function($key) {
            return false;
        });
        Patchwork\redefine(
            'FiftyOneDegreesCloudMetadata::do_cloud_request',
            function (string $url) {
                throw new \fiftyone\pipeline\cloudrequestengine\CloudRequestException('API error', 503, []);
            }
        );
        Functions\when('set_transient')->alias(function($key, $value, $ttl) use (&$attempts) {
            if ($key === 'fiftyonedegrees_crawler_usage_fail') {
                $attempts[] = $value;
            }
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        FiftyOneDegreesCloudMetadata::invalidate_crawler_usage();
        $result = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertEquals([], $result);
        $this->assertNotEmpty($attempts);
        $this->assertTrue(is_array($attempts[0]));
        $this->assertArrayHasKey('n', $attempts[0]);
    }

    public function testDoCloudRequestAddsClientIpWhenRemoteAddrPresent() {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        Functions\when('get_transient')->justReturn(false);
        $capturedUrl = null;
        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function (string $type, string $url, ?string $content, ?string $originHeader) use (&$capturedUrl): string {
                $capturedUrl = $url;
                return json_encode([]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);

        FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('client-ip=1.2.3.4', $capturedUrl);
    }

    public function testDoCloudRequestOmitsClientIpWhenRemoteAddrAbsent() {
        unset($_SERVER['REMOTE_ADDR']);
        Functions\when('get_transient')->justReturn(false);
        $capturedUrl = null;
        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function (string $type, string $url, ?string $content, ?string $originHeader) use (&$capturedUrl): string {
                $capturedUrl = $url;
                return json_encode([]);
            }
        );
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('set_transient')->justReturn(true);

        FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

        $this->assertNotNull($capturedUrl);
        $this->assertStringNotContainsString('client-ip', $capturedUrl);
    }
}
