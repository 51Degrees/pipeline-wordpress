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
use fiftyone\pipeline\cloudrequestengine\CloudRequestException;
use fiftyone\pipeline\core\PipelineBuilder;
use fiftyone\pipeline\core\Utils;

require_once __DIR__ . '/../options.php';
require_once __DIR__ . '/client-ip.php';
require_once __DIR__ . '/fiftyone-strings.php';
require_once __DIR__ . '/wp-http-client.php';

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
     * Records a runtime failure to the PHP error log with a rich,
     * single-line context block followed by the stack trace.
     */
    public static function record_runtime_error(
        string $context,
        ?\Throwable $e = null,
        ?string $fallbackMessage = null
    ): void {
        if ($e !== null) {
            $class   = get_class($e);
            $message = $e->getMessage();
            $file    = $e->getFile();
            $line    = $e->getLine();
            $code    = $e->getCode();
            $trace   = $e->getTraceAsString();
        } else {
            $class   = 'N/A';
            $message = (string) $fallbackMessage;
            $file    = '';
            $line    = 0;
            $code    = 0;
            $trace   = (new \Exception())->getTraceAsString();
        }

        error_log(sprintf(
            '[51Degrees] %s | %s: %s @ %s:%d (code=%s)%s%s',
            $context,
            $class,
            $message,
            $file,
            $line,
            (string) $code,
            PHP_EOL,
            $trace
        ));
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
        // Prepare PipelineBuilder and add the JavaScript settings for the
        // JavaScriptBuilder, in this case an endpoint to call back to
        // retrieve additional properties populated by client side evidence
        // this ?json endpoint is used later to serve results from a special
        // json engine automatically included in the pipeline.
        // Host and protocol are intentionally left empty so that
        // JavascriptBuilderElement uses per-request evidence (the browser's
        // Host header). This avoids baking in a build-time hostname that
        // may differ from how the browser reaches the site.
        $builder = new PipelineBuilder([
            'javascriptBuilderSettings' => [
                'endpoint' => Pipeline::getRestEndpoint(),
                'minify' => false
            ]
        ]);

        $error = null;

        try {
            $cloud = new CloudRequestEngine([
                'resourceKey'        => $resourceKey,
                'httpClient'         => new FiftyOneDegreesWpHttpClient(),
                'cloudRequestOrigin' => FiftyOneDegreesWpHttpClient::defaultOrigin(),
            ]);
            // Entitlement map advertised by the cloud for this Resource Key:
            // [engine => [propertyName => meta]]. Cached separately because
            // optional engines (e.g. fodid) may be filtered out of the runtime
            // pipeline below, yet callers still need to know whether the key
            // is entitled to their properties.
            $engineProperties = $cloud->getEngineProperties();
            $engines          = array_keys($engineProperties);
        } catch (\Throwable $e) {
            Pipeline::record_runtime_error(
                'Pipeline build failed while contacting the 51Degrees cloud (resource key save).',
                $e
            );

            return [
                'pipeline' => null,
                'available_engines' => null,
                'engine_properties' => null,
                'error' => self::user_facing_pipeline_error($e),
            ];
        }

        // Add CloudRequestEngine to the pipeline.
        $builder->add($cloud);

        // Skip engines: both would crash CloudEngine's missing-isset() path; robotstxt also has a direct-HTTP fetcher.
        $excluded = ['robotstxt'];
        if (get_option(Options::SUSPICIOUS_ENABLE, 'off') !== 'on') {
            $excluded[] = 'fodid';
        }
        $engines = array_values(array_filter(
            $engines,
            fn($e) => !in_array($e, $excluded, true)
        ));
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
            'engine_properties' => $engineProperties,
            'error' => $error
        ];
    }

    private static function user_facing_pipeline_error(\Throwable $e): string
    {
        $unreachable = $e instanceof \TypeError
            || ($e instanceof CloudRequestException && $e->httpStatusCode === 0);
        $key = $unreachable ? 'common.cloud.unreachable' : 'common.cloud.rejected';

        return strip_tags(FiftyOneDegreesStrings::get($key));
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
                Pipeline::record_runtime_error(
                    'Pipeline build failed at activation/save time.',
                    null,
                    $cachedPipeline['error']
                );

                return;
            }

            // Wrap the entire pipeline-processing block in a single
            // try/catch so any failure (createFlowData, evidence setup,
            // process(), setResponseHeader, getProperties) leaves
            // Pipeline::$data null without leaking exceptions to the
            // public traffic path.
            try {
                $pipeline = $cachedPipeline['pipeline'];
                $engines = $cachedPipeline['available_engines'];

                $flowData = $pipeline->createFlowData();
                $flowData->evidence->setFromWebRequest();

                $resolvedIp = ClientIpResolver::resolve();
                if ($resolvedIp !== '') {
                    $flowData->evidence->set('query.client-ip', $resolvedIp);
                }

                // PMP preference (cookie) takes priority over the fodid
                // engine's 'non-marketing' fallback when both features are active.
                if (isset($_COOKIE['51d_pmp_pref'])) {
                    $pref = sanitize_text_field(wp_unslash($_COOKIE['51d_pmp_pref']));
                    if (in_array($pref, ['standard', 'personalized'], true)) {
                        $flowData->evidence->set('query.id.usage', $pref);
                    }
                } elseif (isset($pipeline->flowElementsList['fodid'])) {
                    $flowData->evidence->set('query.id.usage', 'non-marketing');
                }

                $flowData->process();

                // https://51degrees.com/blog/user-agent-client-hints
                Utils::setResponseHeader($flowData);

                // Prefer the cloud's entitlement map cached at build time:
                // it covers optional engines (e.g. fodid) that may be absent
                // from the runtime pipeline yet entitled by the Resource Key.
                // Fall back to live-pipeline introspection for legacy caches
                // built before engine_properties was stored.
                if (!empty($cachedPipeline['engine_properties'])) {
                    $properties = $cachedPipeline['engine_properties'];
                } else {
                    $properties = [];
                    foreach ($engines as $engine) {
                        $properties[$engine] =
                            $pipeline->getElement($engine)->getProperties();
                    }
                }

                Pipeline::$data = [
                    'flowData' => $flowData,
                    'properties' => $properties,
                    'errors' => $flowData->errors,
                    'createdAt' => time()
                ];
            } catch (\Throwable $e) {
                Pipeline::record_runtime_error(
                    'Pipeline processing failed for an incoming request.',
                    $e
                );

                return;
            }

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

        // Wrap the property dereference: AspectData::__get() throws (via
        // MissingPropertyService) when the resource key doesn't include the
        // requested property, which would otherwise short-circuit the
        // ?? false defences and bubble a fatal up to the renderer.
        try {
            $property = $flowData->{$engine}->{$key};
            if ($property->hasValue ?? false) {
                return $property->value;
            }
            if ($property->noValueMessage ?? false) {
                error_log($property->noValueMessage);
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                "[51Degrees] Pipeline::get('%s', '%s') no value: %s",
                $engine,
                $key,
                $e->getMessage()
            ));
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
     * Gets the REST API endpoint path for the 51Degrees JSON callback.
     * Uses WordPress rest_url() to support all permalink structures
     * (pretty permalinks, plain permalinks, subdirectory installs).
     *
     * @return string the endpoint path (e.g. "/wp-json/fiftyonedegrees/v4/json"
     *                or "/?rest_route=/fiftyonedegrees/v4/json")
     */
    public static function getRestEndpoint()
    {
        $restUrl = rest_url('fiftyonedegrees/v4/json');
        $parsed = parse_url($restUrl);
        $endpoint = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $endpoint .= '?' . $parsed['query'];
        }

        return $endpoint;
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
