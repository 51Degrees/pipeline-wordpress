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

require_once(__DIR__ . "/../includes/pipeline.php");
require_once(__DIR__ . "/../includes/fiftyone-service.php");
require_once(__DIR__ . "/TestFlowElement.php");

use fiftyone\pipeline\core\PipelineBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use \Brain\Monkey\Functions;
use \Brain\Monkey\Filters;


class PipelineTests extends TestCase {

    private $serverBackup;
    private $getBackup;

    public function set_up() {
        Pipeline::reset();
        FiftyOneDegreesStrings::reset();
        parent::set_up();
        Brain\Monkey\setUp();
        // Stub the upstream header() call — output-buffer state from the prior
        // test suite makes header() throw "headers already sent" otherwise.
        Patchwork\redefine(
            'fiftyone\pipeline\core\Utils::setResponseHeader',
            Patchwork\always(null)
        );
        // Default home_url stub: Pipeline::getRestEndpoint() now reads
        // home_url() instead of rest_url(). Individual tests can override
        // via Functions\when('home_url') for permalink-agnostic assertions.
        Functions\when('home_url')->justReturn('http://localhost/');
        // Default safe stubs for WP-Cron + add_option used by the pipeline
        // cache migration and the rebuild lock. Tests that assert on these
        // override locally.
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('add_option')->justReturn(true);
        $_SESSION = null;
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
    }

    public function tear_down() {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // Data Provider for testGetAppContext
	public static function provider_testGetAppContext() {
        return array(
            array("http://localhost/testsite", "/testsite"),
            array("https://test.domain.com", ""),
        );
    }

    /**
     * Test to check appContext from URLs
     * @dataProvider provider_testGetAppContext
     */
    public function testGetAppContext($url, $expectedValue) {

        $result = Pipeline::getAppContext($url);
        $this->assertEquals($expectedValue, $result);
    }

    /**
     * Test that a pipeline is successfully created for a valid
     * Resource Key.
     */
    public function testMakePipeline_ValidResourceKey() {

        //A fake get_site_url() that always return 'http://localhost/testsite'
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = $_ENV["RESOURCEKEY"];
        if ($resourceKey === "!!YOUR_RESOURCE_KEY!!") {
            $this->fail("You need to create a Resource Key at " .
            "https://configure.51degrees.com and paste it into the " .
            "phpunit.xml config file, " .
            "replacing !!YOUR_RESOURCE_KEY!!.");
        }

        $result = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$result) {
            if ($name === Options::PIPELINE) return $result;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $result = Pipeline::make_pipeline($resourceKey);
        $this->assertInstanceOf(\fiftyone\pipeline\core\Pipeline::class, $result['pipeline']);

        Pipeline::process();
        $this->assertArrayHasKey('device', Pipeline::$data['flowData']->pipeline->flowElementsList["cloud"]->flowElementProperties);
    }

    /** Test that an invalid Resource Key surfaces the friendly cloud-rejected message and the raw SDK detail goes to the PHP error log. */
    public function testMakePipeline_InValidResourceKey() {
        // TODO(cloud-regression 2026-05-18,
        // https://github.com/51Degrees/cloud/issues/111): re-enable when
        // the cloud is fixed. The cloud regressed around 2026-05-15 and
        // now answers a malformed key like "XXXXXXXXXXXXXX" with a
        // generic "Invalid request" that no longer echoes the key —
        // which this test asserts ends up in the PHP error log.
        $this->markTestSkipped(
            'Cloud regression: response no longer echoes the invalid '
            . 'resource key — re-enable after cloud fix.'
        );

        //A fake get_site_url() that always return 'http://localhost/testsite'
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = "XXXXXXXXXXXXXX";

        $capture = tmpfile();
        $saved = ini_set('error_log', stream_get_meta_data($capture)['uri']);

        $result = Pipeline::make_pipeline($resourceKey);

        ini_set('error_log', $saved);

        // make_pipeline should catch the error and return it in the result
        $this->assertNull($result['pipeline']);
        $this->assertNull($result['available_engines']);
        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString('XXXXXXXXXXXXXX', $result['error']);
        $this->assertStringContainsString('Cloud', $result['error']);
        $logContents = stream_get_contents($capture);
        $this->assertStringContainsString('XXXXXXXXXXXXXX', $logContents);
        fclose($capture);

        Functions\expect('get_option')
            ->once()
            ->with(Options::PIPELINE)
            ->andReturn($result);

        // process() should handle the error gracefully (log and return)
        Pipeline::process();
        $this->assertNull(Pipeline::$data);
    }

    /**
     * Test that make_pipeline catches \Error (not just \Exception).
     * This covers PHP errors like TypeError from curl failures or
     * calling a private method on a version-mismatched class.
     */
    public function testMakePipeline_CatchesThrowable()
    {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        // Use a resource key that will trigger a cloud request error.
        // The key here is that the catch block must handle \Throwable,
        // not just \Exception. We verify the existing error-handling
        // path works for any throwable by testing with an invalid key
        // (which throws CloudRequestException, a subclass of \Exception)
        // and confirming the result structure.
        $result = Pipeline::make_pipeline('THROWABLE_TEST_KEY');

        $this->assertNull($result['pipeline']);
        $this->assertNull($result['available_engines']);
        $this->assertNotNull($result['error']);
        $this->assertIsString($result['error']);
    }

    /**
     * Test that a TypeError from the SDK is translated to the friendly
     * cloud-unreachable message and the raw exception is logged.
     */
    public function testMakePipeline_TypeErrorBecomesUnreachableMessage()
    {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function () {
                throw new \TypeError(
                    'HttpClient::validateResponse(): Argument #1 ($cloudResponse) '
                    . 'must be of type string, bool given, called in /home/x/HttpClient.php on line 61'
                );
            }
        );

        $capture = tmpfile();
        $saved = ini_set('error_log', stream_get_meta_data($capture)['uri']);

        $result = Pipeline::make_pipeline('TEST_KEY');

        ini_set('error_log', $saved);

        $this->assertNull($result['pipeline']);
        $this->assertIsString($result['error']);
        $this->assertStringNotContainsString('HttpClient', $result['error']);
        $this->assertStringNotContainsString('validateResponse', $result['error']);
        $this->assertStringNotContainsString('/home/', $result['error']);
        $this->assertStringContainsString('Cloud unreachable', $result['error']);

        $logContents = stream_get_contents($capture);
        $this->assertStringContainsString('TypeError', $logContents);
        $this->assertStringContainsString('validateResponse', $logContents);
        fclose($capture);
    }

    /** Test that a CloudRequestException with non-zero httpStatusCode is translated to the friendly cloud-rejected message. */
    public function testMakePipeline_CloudRejectedBecomesRejectedMessage()
    {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\HttpClient::makeCloudRequest',
            function () {
                throw new \fiftyone\pipeline\cloudrequestengine\CloudRequestException(
                    'Cloud Service: invalid resource key', 403, []
                );
            }
        );

        $result = Pipeline::make_pipeline('BAD_KEY');

        $this->assertNull($result['pipeline']);
        $this->assertStringContainsString('Cloud rejected', $result['error']);
        $this->assertStringContainsString('configure.51degrees.com', $result['error']);
    }

    /**
     * Test that process() handles FlowElement errors gracefully
     * instead of letting exceptions crash the request with a 500.
     */
    public function testProcess_HandlesFlowElementError()
    {
        $mock_pipeline = (new PipelineBuilder())
            ->add(new ThrowingFlowElement())
            ->build();
        $pipeline = [
            'pipeline' => $mock_pipeline,
            'available_engines' => [],
            'error' => null
        ];

        Functions\expect('get_option')
            ->once()
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);

        // Capture error_log output
        $capture = tmpfile();
        $saved = ini_set('error_log', stream_get_meta_data($capture)['uri']);

        Pipeline::process();

        ini_set('error_log', $saved);

        // process() should NOT throw — it should catch the error and
        // return gracefully with $data still null
        $this->assertNull(Pipeline::$data);

        // Verify the error was logged
        $logContents = stream_get_contents($capture);
        $this->assertStringContainsString('Simulated processing failure', $logContents);

        fclose($capture);
    }

    /**
     * Test that make_pipeline drops fodid from the cached engine list when
     * suspicious activity detection is off — preventing the upstream
     * CloudEngine isset() crash for a missing per-request fodid block.
     */
    public function testMakePipeline_ExcludesFodidWhenSuspiciousDisabled() {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');
        Functions\when('home_url')->justReturn('http://localhost/testsite');
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });

        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\CloudRequestEngine::getEngineProperties',
            Patchwork\always(['device' => [], 'fodid' => [], 'robotstxt' => []])
        );

        $result = Pipeline::make_pipeline('AQS5-test');

        $this->assertSame(['device'], $result['available_engines']);
        $this->assertInstanceOf(\fiftyone\pipeline\core\Pipeline::class, $result['pipeline']);
    }

    /**
     * Test that make_pipeline keeps fodid in the cached engine list when
     * suspicious activity detection is enabled — robotstxt is still excluded
     * because it has its own direct-HTTP fetcher.
     */
    public function testMakePipeline_IncludesFodidWhenSuspiciousEnabled() {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');
        Functions\when('home_url')->justReturn('http://localhost/testsite');
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::SUSPICIOUS_ENABLE) return 'on';
            return $default;
        });

        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\CloudRequestEngine::getEngineProperties',
            Patchwork\always(['device' => [], 'fodid' => [], 'robotstxt' => []])
        );

        $result = Pipeline::make_pipeline('AQS5-test');

        $this->assertContains('device', $result['available_engines']);
        $this->assertContains('fodid', $result['available_engines']);
        $this->assertNotContains('robotstxt', $result['available_engines']);
    }

    /**
     * Test that id.usage IS set when the cached pipeline contains the fodid
     * engine — the production-shape pipeline built when SUSPICIOUS_ENABLE was
     * 'on' at make_pipeline time.
     */
    public function testProcess_SetsIdUsageWhenSuspiciousEnabled() {
        $stub_fodid = new TestFlowElement();
        $stub_fodid->dataKey = 'fodid';
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->add($stub_fodid)
            ->build();
        $pipeline = [
            'pipeline' => $mock_pipeline,
            'available_engines' => ['testElement', 'fodid'],
            'error' => null,
        ];

        Functions\when('get_option')->alias(function ($name, $default = null) use ($pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'on';
            return $default;
        });

        Pipeline::reset();
        Pipeline::process();

        $this->assertSame(
            'non-marketing',
            Pipeline::$data['flowData']->evidence->get('query.id.usage')
        );
    }

    /**
     * Test that id.usage is NOT set when the cached pipeline has no fodid
     * element. The option no longer drives this decision; pipeline state does.
     */
    public function testProcess_DoesNotSetIdUsage_WhenPipelineHasNoFodid() {
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = [
            'pipeline' => $mock_pipeline,
            'available_engines' => ['testElement'],
            'error' => null,
        ];

        Functions\when('get_option')->alias(function ($name, $default = null) use ($pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            return $default;
        });

        Pipeline::reset();
        Pipeline::process();

        $this->assertNull(
            Pipeline::$data['flowData']->evidence->get('query.id.usage')
        );
    }

    /**
     * Test that id.usage IS set when the cached pipeline contains a fodid
     * element, even when SUSPICIOUS_ENABLE is 'off' — the decision derives
     * from pipeline state, not option state.
     */
    public function testProcess_SetsIdUsage_WhenPipelineHasFodid_RegardlessOfOption() {
        $stub_fodid = new TestFlowElement();
        $stub_fodid->dataKey = 'fodid';
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->add($stub_fodid)
            ->build();
        $pipeline = [
            'pipeline' => $mock_pipeline,
            'available_engines' => ['testElement', 'fodid'],
            'error' => null,
        ];

        Functions\when('get_option')->alias(function ($name, $default = null) use ($pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });

        Pipeline::reset();
        Pipeline::process();

        $this->assertSame(
            'non-marketing',
            Pipeline::$data['flowData']->evidence->get('query.id.usage')
        );
    }

    /**
     * Regression guard for the fodid crash path. Cloud advertises fodid in
     * getEngineProperties() but the per-request response omits the fodid
     * block. With SUSPICIOUS_ENABLE off, make_pipeline must exclude fodid
     * from the cached engine list so CloudEngine::processInternal is never
     * called for it, and process() returns Pipeline::$data populated.
     */
    public function testRepro_FodidCrashWhenCloudOmitsBlock() {
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('rest_url')->justReturn('https://example.com/wp-json/fiftyonedegrees/v4/json');

        Patchwork\redefine(
            'fiftyone\pipeline\cloudrequestengine\CloudRequestEngine::getEngineProperties',
            Patchwork\always(['device' => [], 'fodid' => []])
        );
        Patchwork\redefine(
            'FiftyOneDegreesWpHttpClient::makeCloudRequest',
            function ($method, $url) {
                // Cloud advertises a fodid block in evidencekeys but omits it
                // from the per-request response — the exact crash shape.
                if (strpos($url, 'evidencekeys') !== false) {
                    return '["query.user-agent"]';
                }
                return '{"device":{"hardwarename":["Test"]}}';
            }
        );

        $built = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$built) {
            if ($name === Options::PIPELINE) return $built;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $built = Pipeline::make_pipeline('TEST_KEY');

        Pipeline::reset();
        Pipeline::process();

        $this->assertNotNull(
            Pipeline::$data,
            'Regression guard: cloud advertises fodid but omits its block — '
            . 'pipeline must not crash when SUSPICIOUS_ENABLE is off.'
        );
        $this->assertInstanceOf(
            \fiftyone\pipeline\core\FlowData::class,
            Pipeline::$data['flowData']
        );
    }

    public function testProcess_BroadenedTryCatchSwallowsSetResponseHeaderException() {
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = [
            'pipeline' => $mock_pipeline,
            'available_engines' => ['testElement'],
            'error' => null,
        ];

        Functions\when('get_option')->alias(function ($name, $default = null) use ($pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });

        // Override the set_up's null-stub so setResponseHeader throws.
        Patchwork\redefine(
            'fiftyone\pipeline\core\Utils::setResponseHeader',
            function () {
                throw new \RuntimeException('Simulated setResponseHeader failure');
            }
        );

        $capture = tmpfile();
        $saved = ini_set('error_log', stream_get_meta_data($capture)['uri']);

        try {
            Pipeline::reset();
            // Must not throw — broadened try/catch catches it.
            Pipeline::process();

            $this->assertNull(Pipeline::$data);
            $logContents = stream_get_contents($capture);
            $this->assertStringContainsString('Simulated setResponseHeader failure', $logContents);
        } finally {
            ini_set('error_log', $saved);
            fclose($capture);
        }
    }

    /**
     * Test that the process method returns the expected result.
     */
    public function testProcess() {

        //A fake get_site_url() that always return 'http://localhost/testsite'
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = $_ENV["RESOURCEKEY"];
        if ($resourceKey === "!!YOUR_RESOURCE_KEY!!") {
            $this->fail("You need to create a Resource Key at " .
            "https://configure.51degrees.com and paste it into the " .
            "phpunit.xml config file, " .
            "replacing !!YOUR_RESOURCE_KEY!!.");
        }

        $pipeline = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $pipeline = Pipeline::make_pipeline($resourceKey);

        Pipeline::process();
        $result = Pipeline::$data;
        $this->assertEquals(
            get_class($result["flowData"]),
            "fiftyone\pipeline\core\FlowData");
        $this->assertTrue(isset($result["properties"]));
        $this->assertTrue(count($result["errors"]) == 0);

    }

    /**
     * Test the methods of getting values from the pipeline.
     */
    // TODO: fix the test
    public function __SKIP__testGet() {

        // Create a tmpfile to write errors to.
        $capture = tmpfile();
        $saved = ini_set('error_log', stream_get_meta_data($capture)['uri']);

        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" => $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);

        Functions\expect('get_option')
            ->times(1)
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);
        Functions\when('plugin_dir_path')->justReturn(getcwd(). "/");

        Pipeline::reset();
        Pipeline::process();

        // Tests Pipeline::get Function.
        $result1 = Pipeline::get("testElement", "availableProperty");
        $this->assertEquals("Value", $result1);

        $result2 = Pipeline::get("testElement", "noValueProperty");
        $this->assertTrue(strpos(
            stream_get_contents($capture),
            "Property is not available.") !== false);
        $this->assertNull($result2);

        $result3 = Pipeline::get("testElement", "notAvailableProperty");
        $this->assertTrue(strpos(
            stream_get_contents($capture),
            "Trying to get property") !== false);
        $this->assertNull($result3);

        $result4 = Pipeline::get("notAvailableElement", "availableProperty");
        $this->assertTrue(strpos(
            stream_get_contents($capture),
            "There is no element data for 'notAvailableElement' against this " .
            "flow data. Available element data keys are: " .
            "'testElement,jsonbundler,javascriptbuilder,set-headers") !== false);
        $this->assertNull($result4);

        // Tests Pipeline::getCategory Function.
        $expectedResult = array(
            'availableProperty' => "Value",
            'noValueProperty' => null);
        $categoryResult = Pipeline::getCategory("testCategory");
        $this->assertEquals($expectedResult, $categoryResult);

    }

    /**
     * Test that when a request is processed by the pipeline, it is added
     * to the session if there is one active.
     */
    public function testStoredInSession() {
        // Setup the session.
        Patchwork\redefine('session_status', Patchwork\always(PHP_SESSION_ACTIVE));
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" => $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);
        
        $_SESSION = array();
        Functions\expect('get_option')
            ->times(1)
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);

        Pipeline::reset();
        Pipeline::process();

        $this->assertNotnull(Pipeline::$data);
        $this->assertEquals(Pipeline::$data, $_SESSION["fiftyonedegrees_data"]);
    }
    
    /**
     * Test that if there is a processed request in the session, then that is
     * used instead of processing again.
     */
    public function testFetchedFromSession() {
        // Setup the session.
        Patchwork\redefine('session_status', Patchwork\always(PHP_SESSION_ACTIVE));
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" => $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);
        
        $_SESSION = array();
        Functions\expect('get_option')
            ->times(1)
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);

        Pipeline::reset();
        Pipeline::process();
        $createdAt = Pipeline::$data['createdAt'];
        // Check everything is set up as expected.
        $this->assertTrue(session_status() == PHP_SESSION_ACTIVE);
        $this->assertTrue(isset($_SESSION["fiftyonedegrees_data"]));
        $this->assertFalse(Pipeline::session_is_invalidated());

        Pipeline::reset();
        Pipeline::process();

        $this->assertNotnull(Pipeline::$data);
        $this->assertEquals($createdAt, Pipeline::$data['createdAt']);
        $this->assertEquals(Pipeline::$data, $_SESSION["fiftyonedegrees_data"]);

    }

    /**
     * Test that if there is a processed request in the session, but it has been
     * invalidated, then the request is processed again and stored in the session.
     */
    public function testClearedFromSession() {
        // Setup the session.
        Patchwork\redefine('session_status', Patchwork\always(PHP_SESSION_ACTIVE));
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" =>  $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);
        
        $_SESSION = array();
        Functions\expect('get_option')
            ->times(2)
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);

        Pipeline::reset();
        Pipeline::process();

        $createdAt = Pipeline::$data['createdAt'];
        // Resolution of time() is 1 second. So sleep for 1 second to ensure
        // the value has changed.
        sleep(1);
        Functions\expect('get_option')
            ->times(2)
            ->with(Options::SESSION_INVALIDATED)
            ->andReturn(time());

        // Check everything is set up as expected.
        $this->assertTrue(session_status() == PHP_SESSION_ACTIVE);
        $this->assertTrue(isset($_SESSION["fiftyonedegrees_data"]));
        $this->assertTrue(Pipeline::session_is_invalidated());
  
        Pipeline::reset();
        Pipeline::process();

        $this->assertNotnull(Pipeline::$data);
        $this->assertTrue($createdAt < Pipeline::$data['createdAt']);
        $this->assertEquals(Pipeline::$data, $_SESSION["fiftyonedegrees_data"]);

    }

    // Data Provider for testGetRestEndpoint
    // getRestEndpoint is now permalink-agnostic: the same `?rest_route=` form
    // is returned regardless of permalink_structure. Only the home_url() path
    // matters (root vs subdirectory install).
    public static function provider_testGetRestEndpoint() {
        return array(
            'root install' => array(
                'http://localhost/',
                '/?rest_route=/fiftyonedegrees/v4/json'
            ),
            'subdirectory install' => array(
                'http://localhost/blog/',
                '/blog/?rest_route=/fiftyonedegrees/v4/json'
            ),
            'subdirectory install without trailing slash' => array(
                'http://localhost/blog',
                '/blog/?rest_route=/fiftyonedegrees/v4/json'
            ),
            'multisite subsite path' => array(
                'http://network.example.com/site1/',
                '/site1/?rest_route=/fiftyonedegrees/v4/json'
            ),
        );
    }

    /**
     * Test that getRestEndpoint returns the permalink-agnostic
     * `?rest_route=` form, derived from home_url() rather than rest_url().
     * @dataProvider provider_testGetRestEndpoint
     */
    public function testGetRestEndpoint($homeUrl, $expectedEndpoint) {
        Functions\when('home_url')->justReturn($homeUrl);

        $result = Pipeline::getRestEndpoint();
        $this->assertEquals($expectedEndpoint, $result);
    }

    /**
     * Test that a permalink_structure change invalidates the session-cached
     * evidence but does NOT trigger a synchronous pipeline rebuild (issue
     * #62). Pipeline::make_pipeline must never be invoked from this hook.
     */
    public function testPermalinkChange_InvalidatesSessionWithoutRebuild() {
        // Sentinel: if make_pipeline is called, fail loudly.
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return [
                    'pipeline' => null,
                    'available_engines' => null,
                    'engine_properties' => null,
                    'error' => null,
                ];
            }
        );
        // Simulate an active session without actually opening one (PHPUnit
        // env has output buffering already started so session_start fails).
        Patchwork\redefine('session_status', Patchwork\always(PHP_SESSION_ACTIVE));
        $_SESSION["fiftyonedegrees_data"] = ['stale' => true];

        $writes = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_updated_option('permalink_structure', '/%postname%/', '');

        $this->assertSame(0, $rebuildCalls, 'Pipeline::make_pipeline must not be called from updated_option hook');
        $this->assertArrayNotHasKey(Options::PIPELINE, $writes, 'Options::PIPELINE must not be written by updated_option hook');
        $this->assertArrayHasKey(Options::SESSION_INVALIDATED, $writes, 'Session-cached evidence must be invalidated');
        $this->assertFalse(isset($_SESSION["fiftyonedegrees_data"]), 'Session data must be cleared');
    }

    /**
     * Test that the updated_option hook is a no-op when there is no active
     * session (most front-end requests).
     */
    public function testPermalinkChange_NoSessionActive_NoOp() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );

        // Ensure no active session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = null;

        $writes = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_updated_option('permalink_structure', '/%postname%/', '');

        $this->assertSame(0, $rebuildCalls);
        $this->assertSame([], $writes, 'No writes when there is no active session');
    }

    /**
     * Test cache migration: when stored version is below the current
     * PIPELINE_CACHE_VERSION, a rebuild-pending flag is set and the
     * version option is bumped, but the cached pipeline is NOT deleted
     * (so visitors keep getting the old baked endpoint until the next
     * admin visit triggers the deferred rebuild).
     */
    public function testMigratePipelineCache_OldVersion_SchedulesRebuildWithoutDeletingCache() {
        $writes = [];
        $deletes = [];
        $scheduled = null;
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_CACHE_VERSION) {
                return 1; // older than current (2)
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function ($time, $hook) use (&$scheduled) {
            $scheduled = $hook;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->maybe_migrate_pipeline_cache();

        $this->assertNotContains(Options::PIPELINE, $deletes, 'Cached pipeline must not be deleted during migration -- it keeps serving visitors until the deferred admin rebuild');
        $this->assertSame(1, $writes[Options::PIPELINE_REBUILD_PENDING] ?? null, 'Rebuild-pending flag must be set');
        $this->assertSame(
            FiftyoneService::PIPELINE_CACHE_VERSION,
            $writes[Options::PIPELINE_CACHE_VERSION] ?? null,
            'Version option must be bumped to the current constant'
        );
        $this->assertSame(
            FiftyoneService::PIPELINE_REBUILD_CRON_ACTION,
            $scheduled,
            'Cron fallback rebuild must be scheduled so admin-less sites still rebuild'
        );
    }

    /**
     * Test that a new schedule_pipeline_rebuild call (e.g. admin saves a
     * valid key on top of a previously invalid one) clears any active
     * backoff transient. Without this, the second save would be
     * suppressed for up to PIPELINE_REBUILD_BACKOFF_TTL seconds and the
     * Setup tab would never flip from the red box to the green box.
     */
    public function testSchedulePipelineRebuild_ClearsBackoffTransient() {
        $deletedTransients = [];
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_CACHE_VERSION) return 1;
            return $default;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('delete_transient')->alias(function ($key) use (&$deletedTransients) {
            $deletedTransients[] = $key;
            return true;
        });

        $service = new FiftyoneService();
        // Invoke through the public migration entry point -- exercises
        // schedule_pipeline_rebuild without requiring private-method access.
        $service->maybe_migrate_pipeline_cache();

        $this->assertContains(
            FiftyoneService::PIPELINE_REBUILD_BACKOFF_TRANSIENT,
            $deletedTransients,
            'A fresh rebuild request must clear any prior cloud-failure backoff so a new key is not silently suppressed'
        );
    }

    /**
     * Test that the rebuild-pending flag picked up on admin_init triggers
     * Pipeline::make_pipeline and clears the flag when rebuild succeeds
     * (no PIPELINE_VALIDATION_ERROR option present after the call).
     */
    public function testMaybeRebuildPending_FlagSet_RebuildsAndClearsFlag() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return [
                    'pipeline' => 'rebuilt-stub',
                    'available_engines' => [],
                    'engine_properties' => [],
                    'error' => null,
                ];
            }
        );
        $writes = [];
        $deletes = [];
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            // No PIPELINE_VALIDATION_ERROR after rebuild — success.
            return $default;
        });
        Functions\when('add_option')->justReturn(true); // lock acquired
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $deletedTransients = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$deletedTransients) {
            $deletedTransients[] = $key;
            return true;
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(1, $rebuildCalls, 'Pipeline::make_pipeline must be invoked exactly once');
        $this->assertContains(Options::PIPELINE_REBUILD_PENDING, $deletes, 'Flag must be cleared after successful rebuild');
        $this->assertContains(Options::PIPELINE_REBUILD_LOCK, $deletes, 'Lock must be released');
        $this->assertArrayHasKey(Options::SESSION_INVALIDATED, $writes, 'Session cache must be invalidated so active visitors pick up the rebuilt pipeline');
        $this->assertContains(
            FiftyoneService::PIPELINE_REBUILD_BACKOFF_TRANSIENT,
            $deletedTransients,
            'Backoff transient must be cleared on success so the next legitimate failure is not suppressed'
        );
    }

    /**
     * Test that on cloud failure -- build_and_save_pipeline left a
     * PIPELINE_VALIDATION_ERROR option -- the rebuild-pending flag is
     * NOT cleared so the next admin_init or scheduled cron retries.
     */
    public function testMaybeRebuildPending_CloudFailure_RetainsFlagForRetry() {
        // Pipeline::make_pipeline returns an error envelope (cloud
        // unreachable). build_and_save_pipeline writes
        // PIPELINE_VALIDATION_ERROR.
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            Patchwork\always([
                'pipeline' => null,
                'available_engines' => null,
                'engine_properties' => null,
                'error' => 'Cloud unreachable',
            ])
        );
        $writes = [];
        $deletes = [];
        // Simulate validation error appearing AFTER build_and_save_pipeline
        // ran (the post-call success-check reads this).
        $errorWritten = false;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$errorWritten) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            if ($name === Options::PIPELINE_VALIDATION_ERROR) return $errorWritten ? 'Cloud unreachable' : false;
            return $default;
        });
        Functions\when('add_option')->justReturn(true);
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes, &$errorWritten) {
            $writes[] = [$key, $value];
            if ($key === Options::PIPELINE_VALIDATION_ERROR) {
                $errorWritten = true;
            }
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes, &$errorWritten) {
            $deletes[] = $key;
            if ($key === Options::PIPELINE_VALIDATION_ERROR) {
                $errorWritten = false;
            }
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        $setTransients = [];
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$setTransients) {
            $setTransients[$key] = ['value' => $value, 'ttl' => $ttl];
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        // Flag must remain so the next admin_init or cron run retries.
        $this->assertNotContains(
            Options::PIPELINE_REBUILD_PENDING,
            $deletes,
            'On cloud failure, the rebuild flag must remain set so retry happens on the next admin_init or scheduled cron'
        );
        // Lock must still be released so the next attempt can acquire it.
        $this->assertContains(
            Options::PIPELINE_REBUILD_LOCK,
            $deletes,
            'Lock must be released even on failure (finally block)'
        );
        $this->assertArrayHasKey(
            FiftyoneService::PIPELINE_REBUILD_BACKOFF_TRANSIENT,
            $setTransients,
            'Cloud-failure backoff transient must be set so we stop hammering the cloud each admin pageload'
        );
        $this->assertArrayHasKey(
            FiftyoneService::PIPELINE_REBUILD_FAILED_NOTICE_TRANSIENT,
            $setTransients,
            'One-shot admin notice transient must be set so the failure surfaces on the redirected pageload'
        );
        $this->assertSame(
            'Cloud unreachable',
            $setTransients[FiftyoneService::PIPELINE_REBUILD_FAILED_NOTICE_TRANSIENT]['value'],
            'Notice transient must carry the cloud error message'
        );
    }

    /**
     * Test that when another request holds the rebuild lock, the second
     * caller short-circuits without invoking the cloud.
     */
    public function testMaybeRebuildPending_LockHeld_ShortCircuits() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            return $default;
        });
        // add_option returns false when the option already exists (lock
        // held by another request).
        Functions\when('add_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(0, $rebuildCalls, 'When lock is held by another request, this caller must not invoke the cloud');
    }

    /**
     * Test that when the backoff transient is set (recent cloud failure),
     * the handler short-circuits before acquiring the lock or hitting the
     * cloud -- prevents the per-admin-pageload retry storm against a
     * permanently invalid resource key.
     */
    public function testMaybeRebuildPending_BackoffActive_SkipsRebuild() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );
        $addOptionCalls = 0;
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            return $default;
        });
        Functions\when('add_option')->alias(function () use (&$addOptionCalls) {
            $addOptionCalls++;
            return true;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        // Backoff transient present (recent failure within the window).
        Functions\when('get_transient')->alias(function ($key) {
            return $key === FiftyoneService::PIPELINE_REBUILD_BACKOFF_TRANSIENT
                ? 1 : false;
        });
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(0, $rebuildCalls, 'Backoff active -- must not call the cloud');
        $this->assertSame(0, $addOptionCalls, 'Backoff active -- must not even attempt to acquire the lock');
    }

    /**
     * Test that a leaked PIPELINE_REBUILD_LOCK (older than the recovery
     * window) is force-reclaimed instead of blocking rebuilds forever
     * after a PHP fatal mid-rebuild.
     */
    public function testMaybeRebuildPending_StaleLock_IsReclaimed() {
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            Patchwork\always([
                'pipeline' => 'rebuilt-stub',
                'available_engines' => [],
                'engine_properties' => [],
                'error' => null,
            ])
        );
        $deletes = [];
        $addOptionCalls = 0;
        $staleLockTs = time() - (FiftyoneService::PIPELINE_REBUILD_LOCK_TTL + 60);
        Functions\when('get_option')->alias(function ($name, $default = null) use ($staleLockTs) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::PIPELINE_REBUILD_LOCK) return $staleLockTs;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            return $default;
        });
        Functions\when('add_option')->alias(function () use (&$addOptionCalls) {
            $addOptionCalls++;
            return true;
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertContains(
            Options::PIPELINE_REBUILD_LOCK,
            $deletes,
            'Stale lock must be force-deleted before the add_option re-acquire'
        );
        $this->assertSame(1, $addOptionCalls, 'Lock must be re-acquired after stale-lock recovery');
    }

    /**
     * Test that a fresh PIPELINE_REBUILD_LOCK (within the recovery
     * window) is honored -- another worker is legitimately rebuilding,
     * we must not stomp on it.
     */
    public function testMaybeRebuildPending_FreshLockHeldByPeer_DoesNotReclaim() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );
        $deletes = [];
        $freshLockTs = time() - 5; // 5 seconds ago -- well under the TTL
        Functions\when('get_option')->alias(function ($name, $default = null) use ($freshLockTs) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::PIPELINE_REBUILD_LOCK) return $freshLockTs;
            if ($name === Options::RESOURCE_KEY) return 'VALID-KEY';
            return $default;
        });
        // Lock already held by peer -- add_option returns false.
        Functions\when('add_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(0, $rebuildCalls, 'Fresh peer-held lock must short-circuit this caller');
        $this->assertNotContains(
            Options::PIPELINE_REBUILD_LOCK,
            $deletes,
            'Fresh lock must NOT be force-deleted -- would corrupt the peer rebuild'
        );
    }

    /**
     * Test that the rebuild-pending path short-circuits cleanly when the
     * resource key is empty: clear the flag, don't try to call the cloud
     * with a null key, don't loop forever on every admin request.
     */
    public function testMaybeRebuildPending_NoResourceKey_ClearsFlagWithoutRebuild() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );
        $writes = [];
        $deletes = [];
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_REBUILD_PENDING) return 1;
            if ($name === Options::RESOURCE_KEY) return '';
            return $default;
        });
        Functions\when('add_option')->justReturn(true);
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(0, $rebuildCalls, 'Pipeline::make_pipeline must not be called when resource key is empty');
        $this->assertContains(Options::PIPELINE_REBUILD_PENDING, $deletes, 'Flag must still be cleared so admin_init does not loop');
    }

    /**
     * Test that the rebuild-pending path is a no-op when no flag is set.
     */
    public function testMaybeRebuildPending_FlagUnset_NoOp() {
        $rebuildCalls = 0;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function () use (&$rebuildCalls) {
                $rebuildCalls++;
                return ['pipeline' => null, 'available_engines' => null, 'engine_properties' => null, 'error' => null];
            }
        );
        $writes = [];
        $deletes = [];
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_maybe_rebuild_pipeline();

        $this->assertSame(0, $rebuildCalls);
        $this->assertSame([], $deletes);
        $this->assertSame([], $writes);
    }

    /**
     * Test that migration is a no-op when the stored version already
     * matches the current PIPELINE_CACHE_VERSION.
     */
    public function testMigratePipelineCache_CurrentVersion_NoOp() {
        $writes = [];
        $deletes = [];
        Functions\when('get_option')->alias(function ($name, $default = null) {
            if ($name === Options::PIPELINE_CACHE_VERSION) {
                return FiftyoneService::PIPELINE_CACHE_VERSION;
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });

        $service = new FiftyoneService();
        $service->maybe_migrate_pipeline_cache();

        $this->assertSame([], $deletes, 'No deletes when version is current');
        $this->assertSame([], $writes, 'No writes when version is current');
    }

    /**
     * Test that an installation without the version option (legacy 4.5.16
     * upgrade) is treated as version 1 and migrated -- rebuild flag set,
     * version bumped, no cache deletion.
     */
    public function testMigratePipelineCache_NoStoredVersion_TreatedAsOld() {
        $writes = [];
        $deletes = [];
        // get_option returns the default (1) when no value is stored.
        Functions\when('get_option')->alias(function ($name, $default = null) {
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes) {
            $writes[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->maybe_migrate_pipeline_cache();

        $this->assertNotContains(Options::PIPELINE, $deletes, 'Cache must not be deleted -- rebuild is deferred to admin_init');
        $this->assertSame(1, $writes[Options::PIPELINE_REBUILD_PENDING] ?? null);
        $this->assertSame(
            FiftyoneService::PIPELINE_CACHE_VERSION,
            $writes[Options::PIPELINE_CACHE_VERSION] ?? null
        );
    }

    /**
     * Test concurrency safety: a second invocation in the same request
     * (post-bump) must short-circuit without re-setting the rebuild flag
     * or re-bumping the version.
     */
    public function testMigratePipelineCache_DoubleInvocation_Idempotent() {
        $writes = [];
        $deletes = [];

        // Mutable stored version: starts at 1, jumps to current after first
        // call's update_option mocks it being persisted.
        $stored = 1;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$stored) {
            if ($name === Options::PIPELINE_CACHE_VERSION) {
                return $stored;
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$writes, &$stored) {
            $writes[] = [$key, $value];
            if ($key === Options::PIPELINE_CACHE_VERSION) {
                $stored = $value;
            }
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deletes) {
            $deletes[] = $key;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);

        $service = new FiftyoneService();
        $service->maybe_migrate_pipeline_cache(); // runs migration
        $service->maybe_migrate_pipeline_cache(); // must short-circuit

        $bumps = array_filter(
            $writes,
            fn($w) => $w[0] === Options::PIPELINE_CACHE_VERSION
        );
        $this->assertCount(
            1,
            $bumps,
            'Version option must be bumped exactly once across two calls'
        );
        $flags = array_filter(
            $writes,
            fn($w) => $w[0] === Options::PIPELINE_REBUILD_PENDING
        );
        $this->assertCount(
            1,
            $flags,
            'Rebuild-pending flag must be set exactly once across two calls'
        );
    }

    /**
     * Test that Pipeline::process sets query.client-ip from
     * REMOTE_ADDR when no proxy headers are present.
     */
    public function testProcess_SetsResolvedClientIpAsQueryEvidence() {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = $_ENV["RESOURCEKEY"];
        if ($resourceKey === "!!YOUR_RESOURCE_KEY!!") {
            $this->fail("Resource Key required; set RESOURCEKEY env or .env");
        }

        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $pipeline = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $pipeline = Pipeline::make_pipeline($resourceKey);

        Pipeline::process();

        $flowData = Pipeline::$data['flowData'];
        $this->assertSame('203.0.113.1', $flowData->evidence->get('query.client-ip'));
        $this->assertSame('203.0.113.1', $flowData->evidence->get('server.client-ip'));
    }

    /**
     * Test that a URL-supplied ?client-ip is overwritten by the
     * resolver, preventing visitors from spoofing the cloud-detected IP.
     */
    public function testProcess_QueryStringClientIpIsOverriddenByResolver() {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = $_ENV["RESOURCEKEY"];
        if ($resourceKey === "!!YOUR_RESOURCE_KEY!!") {
            $this->fail("Resource Key required; set RESOURCEKEY env or .env");
        }

        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_GET['client-ip'] = 'attacker.ip.spoof';
        $pipeline = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $pipeline = Pipeline::make_pipeline($resourceKey);

        Pipeline::process();

        $flowData = Pipeline::$data['flowData'];
        $this->assertSame(
            '203.0.113.1',
            $flowData->evidence->get('query.client-ip'),
            'URL-supplied client-ip must not reach the cloud'
        );
    }

    /**
     * Test that when the resolver returns '' (REMOTE_ADDR absent),
     * Pipeline::process does not set query.client-ip — the cloud
     * treats an empty client-ip as broken input.
     */
    public function testProcess_EmptyResolvedIpDoesNotPollute() {
        Functions\when('get_site_url')->justReturn('http://localhost/testsite');
        Functions\when('rest_url')->justReturn('http://localhost/testsite/wp-json/fiftyonedegrees/v4/json');

        $resourceKey = $_ENV["RESOURCEKEY"];
        if ($resourceKey === "!!YOUR_RESOURCE_KEY!!") {
            $this->fail("Resource Key required; set RESOURCEKEY env or .env");
        }

        $_SERVER = [];
        $_GET = [];
        $pipeline = null;
        Functions\when('get_option')->alias(function ($name, $default = null) use (&$pipeline) {
            if ($name === Options::PIPELINE) return $pipeline;
            if ($name === Options::SUSPICIOUS_ENABLE) return 'off';
            return $default;
        });
        $pipeline = Pipeline::make_pipeline($resourceKey);

        Pipeline::process();

        $flowData = Pipeline::$data['flowData'];
        $this->assertNull($flowData->evidence->get('query.client-ip'));
    }

}
