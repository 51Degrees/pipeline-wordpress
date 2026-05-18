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
    public static function provider_testGetRestEndpoint() {
        return array(
            'pretty permalinks' => array(
                'http://localhost/wp-json/fiftyonedegrees/v4/json',
                '/wp-json/fiftyonedegrees/v4/json'
            ),
            'pretty permalinks with subdirectory' => array(
                'http://localhost/blog/wp-json/fiftyonedegrees/v4/json',
                '/blog/wp-json/fiftyonedegrees/v4/json'
            ),
            'plain permalinks' => array(
                'http://localhost/?rest_route=/fiftyonedegrees/v4/json',
                '/?rest_route=/fiftyonedegrees/v4/json'
            ),
            'plain permalinks with subdirectory' => array(
                'http://localhost/blog/?rest_route=/fiftyonedegrees/v4/json',
                '/blog/?rest_route=/fiftyonedegrees/v4/json'
            ),
        );
    }

    /**
     * Test that getRestEndpoint extracts the correct path from rest_url()
     * for various permalink configurations.
     * @dataProvider provider_testGetRestEndpoint
     */
    public function testGetRestEndpoint($restUrl, $expectedEndpoint) {
        Functions\when('rest_url')->justReturn($restUrl);

        $result = Pipeline::getRestEndpoint();
        $this->assertEquals($expectedEndpoint, $result);
    }

    /**
     * Test that changing the permalink structure triggers a pipeline rebuild.
     */
    public function testPermalinkChangeRebuildsPipeline() {
        Functions\when('get_site_url')->justReturn('http://localhost');
        Functions\when('rest_url')->justReturn('http://localhost/wp-json/fiftyonedegrees/v4/json');

        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $built = [
            'pipeline' => $mock_pipeline,
            'available_engines' => ['testElement'],
            'error' => null,
        ];
        Patchwork\redefine('Pipeline::make_pipeline', Patchwork\always($built));

        Functions\when('get_option')->alias(function ($name, $default = null) {
            return $name === Options::RESOURCE_KEY ? 'XXXXXXXXXXXXXX' : $default;
        });

        $captured = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$captured) {
            if ($key === Options::PIPELINE) {
                $captured = $value;
            }
            return true;
        });
        Functions\when('delete_option')->justReturn(true);

        $service = new FiftyoneService();
        $service->fiftyonedegrees_updated_option('permalink_structure', '/%postname%/', '');

        $this->assertNotNull($captured);
        $this->assertArrayHasKey('pipeline', $captured);
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
