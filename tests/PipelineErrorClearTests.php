<?php

require_once(__DIR__ . "/../includes/pipeline.php");
require_once(__DIR__ . "/../includes/fiftyone-service.php");
require_once(__DIR__ . "/TestFlowElement.php");

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;

class PipelineErrorClearTests extends TestCase {

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
     * Test that build_and_save_pipeline() short-circuits cleanly when the
     * resource key is empty: it must not call Pipeline::make_pipeline (no
     * cloud round-trip), and it must clear any leftover error state.
     */
    public function testBuildAndSavePipeline_EmptyKeySkipsValidationAndClearsState() {
        $optionStore = [
            Options::PIPELINE => ['error' => 'old failure'],
            Options::PIPELINE_VALIDATION_ERROR => 'old validation error',
        ];

        Functions\when('get_option')->alias(function($k, $default = false) use (&$optionStore) {
            return array_key_exists($k, $optionStore) ? $optionStore[$k] : $default;
        });
        Functions\when('update_option')->alias(function($k, $v) use (&$optionStore) {
            $optionStore[$k] = $v;
            return true;
        });
        Functions\when('delete_option')->alias(function($k) use (&$optionStore) {
            unset($optionStore[$k]);
            return true;
        });

        $makePipelineCalled = false;
        Patchwork\redefine(
            'Pipeline::make_pipeline',
            function($key) use (&$makePipelineCalled) {
                $makePipelineCalled = true;
                return ['pipeline' => null, 'available_engines' => null, 'error' => 'unexpected'];
            }
        );

        $reflection = new \ReflectionMethod(FiftyoneService::class, 'build_and_save_pipeline');
        $reflection->setAccessible(true);
        $reflection->invoke(null, '');

        $this->assertFalse(
            $makePipelineCalled,
            'Empty resource key must not trigger a cloud call via make_pipeline.'
        );
        $this->assertArrayNotHasKey(
            Options::PIPELINE,
            $optionStore,
            'Empty key must clear Options::PIPELINE so no stale error survives.'
        );
        $this->assertArrayNotHasKey(
            Options::PIPELINE_VALIDATION_ERROR,
            $optionStore,
            'Empty key must clear Options::PIPELINE_VALIDATION_ERROR.'
        );
    }

    /**
     * Test that setup.php does not render any error or success box when
     * the resource key is empty, even if stale option state would
     * otherwise produce one. Defense-in-depth render-side guard.
     */
    public function testSetupTab_NoStatusBoxWhenResourceKeyEmpty() {
        $optionStore = [
            Options::RESOURCE_KEY              => '',
            Options::PIPELINE                  => null,
            Options::PIPELINE_VALIDATION_ERROR => 'stale validation error',
            Options::PIPELINE_ENABLE           => 'on',
        ];

        Functions\when('get_option')->alias(function($k, $default = false) use (&$optionStore) {
            return array_key_exists($k, $optionStore) ? $optionStore[$k] : $default;
        });
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('checked')->justReturn('checked="checked"');

        ob_start();
        include __DIR__ . '/../setup.php';
        $output = ob_get_clean();

        $this->assertStringNotContainsString(
            'fod-pipeline-status error',
            $output,
            'No red status box may render when resource key is empty.'
        );
        $this->assertStringNotContainsString(
            'fod-pipeline-status good',
            $output,
            'No green status box may render when resource key is empty.'
        );
    }
}
?>
