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

use fiftyone\pipeline\cloudrequestengine\CloudEngine;
use fiftyone\pipeline\cloudrequestengine\CloudRequestEngine;
use fiftyone\pipeline\core\PipelineBuilder;
use fiftyone\pipeline\core\Utils;

require_once __DIR__ . '/../options.php';

class Pipeline
{
    /**
     * Single instance of processed flow data.
     * This is populated by the process() method, and is only populated
     * once per request.
     */
    public static $data;

    /**
     * Resets the processed data to null. This is primarily used in tests
     * to simulate a fresh web request.
     */
    public static function reset()
    {
        Pipeline::$data = null;
    }

    /**
     * Makes a pipeline from a Resource Key that
     * can be serialized to the database.
     *
     * @param string $resourceKey Resource Key
     * @return array an array containing pipeline and engines
     */
    public static function make_pipeline($resourceKey)
    {
        // Get App Context from the URL.
        $url = get_site_url();
        $appContext = Pipeline::getAppContext($url);

        // Prepare PipelineBuilder and add the JavaScript settings for the
        // JavaScriptBuilder, in this case an endpoint to call back to
        // retrieve additional properties populated by client side evidence
        // this ?json endpoint is used later to serve results from a special
        // json engine automatically included in the pipeline
        $builder = new PipelineBuilder([
            'javascriptBuilderSettings' => [
                'endpoint' => $appContext . '/wp-json/fiftyonedegrees/v4/json',
                'host' => isset($_SERVER['HTTP_HOST'])
                    ? sanitize_text_field($_SERVER['HTTP_HOST'])
                    : $url,
                'protocol' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
                    ? 'https'
                    : 'http',
                'minify' => false
            ]
        ]);

        $error = null;

        try {
            $cloud = new CloudRequestEngine(['resourceKey' => $resourceKey]);
            // Get engines available with the Resource Key
            $engines = array_keys($cloud->getEngineProperties());
        } catch (Exception $e) {
            $error = $e->getMessage();

            return [
                'pipeline' => null,
                'available_engines' => null,
                'error' => $error
            ];
        }

        // Add CloudRequestEngine to the pipeline.
        $builder->add($cloud);

        // Add all the engines, accessible via provided
        // resourceKey, to the pipeline
        foreach ($engines as $engine) {
            $cloudEngine = new CloudEngine();
            $cloudEngine->dataKey = $engine;
            $builder->add($cloudEngine);
        }

        // Build the pipeline
        $pipeline = $builder->build();

        return [
            'pipeline' => $pipeline,
            'available_engines' => $engines,
            'error' => $error
        ];
    }

    /**
     * Process function sets the evidence from web request in flowData and
     * runs the process function on each attached FlowElement.
     *
     * @return void
     */
    public static function process()
    {
        // Only process if the data has not already been populated.
        if (Pipeline::$data === null) {
            // Fetch the data from the session if it's enabled and is already
            // there.
            if (
                session_status() === PHP_SESSION_ACTIVE &&
                isset($_SESSION['fiftyonedegrees_data']) &&
                Pipeline::session_is_invalidated() === false
            ) {
                Pipeline::$data = $_SESSION['fiftyonedegrees_data'];

                return;
            }

            require_once dirname(__DIR__) . '/lib/vendor/autoload.php';

            // Get the preconstructed pipeline from the cached option.
            $cachedPipeline = get_option(Options::PIPELINE);

            if (!$cachedPipeline) {
                // There is no pipeline, so return null.
                return;
            }

            if (isset($cachedPipeline['error'])) {
                error_log('Error occurred while initializing the 51Degrees ' .
                    "plugin: '" . $cachedPipeline['error'] . "'");

                return;
            }

            // Get pipeline and available engines from cache
            $pipeline = $cachedPipeline['pipeline'];
            $engines = $cachedPipeline['available_engines'];

            // Create flowData object in the pipeline.
            $flowData = $pipeline->createFlowData();

            // Set evidence from web request.
            $flowData->evidence->setFromWebRequest();

            // Process flowData with evidence supplied
            $flowData->process();

            // Some browsers require that extra HTTP headers are explicitly
            // requested. So set whatever headers are required by the browser
            // in order to return the evidence needed by the pipeline.
            // More info on this can be found at
            // https://51degrees.com/blog/user-agent-client-hints
            Utils::setResponseHeader($flowData);

            // Get properties for each engine from pipeline.
            $properties = [];
            foreach ($engines as $engine) {
                $properties[$engine] =
                    $pipeline->getElement($engine)->getProperties();
            }

            Pipeline::$data = [
                'flowData' => $flowData,
                'properties' => $properties,
                'errors' => $flowData->errors,
                'createdAt' => time()
            ];

            // If session cache is enabled then store the result in it.
            if (session_status() == PHP_SESSION_ACTIVE) {
                $_SESSION['fiftyonedegrees_data'] = Pipeline::$data;
            }
        }
    }

    /**
     * Retrieves property by engine and property key. If there is no flow data
     * available, or it contains errors, then null is returned.
     *
     * @param string $engine FlowElementDataKey e.g. device
     * @param string $key Property Key e.g. browsername
     * @return null|string Property Value
     */
    public static function get($engine, $key)
    {
        $data = Pipeline::$data;

        if (!$data) {
            // There is no processed flow data.
            return null;
        }

        if (isset($data['errors']) && count($data['errors'])) {
            // There were errors from processing.
            error_log('Errors processing Flow Data' . $data['errors']);

            return null;
        }

        $flowData = $data['flowData'];

        if ($flowData->{$engine}->{$key}->hasValue ?? false) {
            return $flowData->{$engine}->{$key}->value;
        }

        if ($flowData->{$engine}->{$key}->noValueMessage ?? false) {
            error_log($flowData->{$engine}->{$key}->noValueMessage);
        }

        return null;
    }

    /**
     * Retrieves processed flow data as a JSON object.
     *
     * @return null|object flow data as a JSON Object
     */
    public static function getJSON()
    {
        $data = Pipeline::$data;

        if (!$data) {
            return null;
        }
        if (isset($data['errors']) && count($data['errors'])) {
            error_log('Errors processing Flow Data' . $data['errors']);

            return null;
        }

        $flowData = $data['flowData'];

        try {
            return $flowData->jsonbundler->json;
        } catch (\Exception $e) {
            error_log($e->getMessage());

            return null;
        }
    }

    /**
     * Retrieves a properties list for the specified category.
     *
     * @param string $category the category name to get properties for
     * @return null|array the list of properties
     */
    public static function getCategory($category)
    {
        $data = Pipeline::$data;

        if (!$data) {
            return null;
        }

        if (isset($data['errors']) && count($data['errors'])) {
            error_log('Errors processing Flow Data' . $data['errors']);

            return null;
        }

        $flowData = $data['flowData'];

        $categoryResults = $flowData->getWhere('category', $category);
        $output = [];

        foreach ($categoryResults as $key => $property) {
            $value = null;

            if ($property->hasValue) {
                $value = $property->value;
            }

            $output[$key] = $value;
        }

        return $output;
    }

    /**
     * Gets client side javascript from FlowData.
     *
     * @return null|string the Javascript for the requesting device
     */
    public static function getJavaScript()
    {
        $data = Pipeline::$data;

        if (!$data) {
            return null;
        }

        if (isset($data['errors']) && count($data['errors'])) {
            error_log('Errors processing Flow Data' . $data['errors']);

            return null;
        }

        $flowData = $data['flowData'];

        try {
            return $flowData->javascriptbuilder->javascript;
        } catch (\Exception $e) {
            error_log($e->getMessage());

            return '';
        }
    }

    /**
     * Gets AppContext from the URL.
     *
     * @param string $url
     * @return string the app context
     */
    public static function getAppContext($url)
    {
        $urlParts = explode(
            '/',
            str_replace('https://', '', str_replace('http://', '', $url))
        );

        if (count($urlParts) > 1) {
            return '/' . end($urlParts);
        }

        return '';
    }

    /**
     * Returns true if the data in the session has been invalidated by
     * another process updating the pipeline.
     *
     * @return bool
     */
    public static function session_is_invalidated()
    {
        $createdAt = $_SESSION['fiftyonedegrees_data']['createdAt'];
        $invalidatedAt = get_option(Options::SESSION_INVALIDATED);

        return $createdAt < $invalidatedAt;
    }
}
