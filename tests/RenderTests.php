<?php
/* *********************************************************************
 * This Original Work is copyright of 51 Degrees Mobile Experts Limited.
 * Copyright 2026 51 Degrees Mobile Experts Limited, Davidson House,
 * Forbury Square, Reading, Berkshire, United Kingdom RG1 3EU.
 *
 * This Original Work is licensed under the European Union Public Licence
 * (EUPL) v.1.2 and is subject to its terms as set out below.
 *
 * If a copy of the EUPL was not distributed with this file, You can obtain
 * one at https://opensource.org/licenses/EUPL-1.2.
 *
 * The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
 * amended by the European Commission) shall be deemed incompatible for
 * the purposes of the Work and the provisions of the compatibility
 * clause in Article 5 of the EUPL shall not apply.
 *
 * If using the Work as, or as part of, a network application, by
 * including the attribution notice(s) required under Article 5 of the EUPL
 * in the end user terms of the application under an appropriate heading,
 * such notice(s) shall fulfill the requirements of that article.
 * ********************************************************************* */

require_once(__DIR__ . "/../includes/fiftyone-service.php");
require_once(__DIR__ . "/TestFlowElement.php");

use fiftyone\pipeline\core\PipelineBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use \Brain\Monkey\Functions;
use \Brain\Monkey\Actions;
use \Brain\Monkey\Filters;
use \Brain\Monkey;


class RenderTests extends TestCase {

	public function set_up() {
        Pipeline::reset();
		parent::set_up();
		Brain\Monkey\setUp();
        $mock_pipeline = (new PipelineBuilder())
            ->add(new TestFlowElement())
            ->build();
        $pipeline = array(
            "pipeline" => $mock_pipeline,
            "available_engines" => ["testElement"],
            "error" => null);
        Functions\expect('get_option')
            ->once()
            ->with(Options::PIPELINE)
            ->andReturn($pipeline);
        Pipeline::process();
    }

	public function tear_down() {
		Brain\Monkey\tearDown();
		parent::tear_down();
	}

    /**
     * Test that a token in a block is replaced with the correct value.
     */
    public function testBlockRender() {
        $service = new FiftyoneService();
        $content = "{Pipeline::get(\"testElement\", \"availableProperty\")}";
        $rendered = $service->fiftyonedegrees_block_filter($content, null);
        $this->assertEquals("Value", $rendered);
    }
    
    /**
     * Test that when a token is replaced in a block, only the token is replaced,
     * and the rest of the block is untouched.
     */
    public function testBlockRender_retainsOtherText() {
        $service = new FiftyoneService();
        $content = "text ... {Pipeline::get(\"testElement\", \"availableProperty\")} some more text...";
        $rendered = $service->fiftyonedegrees_block_filter($content, null);
        $this->assertEquals("text ... Value some more text...", $rendered);
    }

    /**
     * Test that for a token with an unknown property, the token is replaced
     * with null.
     */
    public function testBlockRender_unknownProperty() {
        $service = new FiftyoneService();
        $content = "text ... {Pipeline::get(\"testElement\", \"noproperty\")}";
        $rendered = $service->fiftyonedegrees_block_filter($content, null);
        $this->assertEquals("text ... null", $rendered);
    }
    
    /**
     * Test that for a token with no value, the token is replaced with null.
     */
    public function testBlockRender_noValue() {
        $service = new FiftyoneService();
        $content = "text ... {Pipeline::get(\"testElement\", \"noValueProperty\")}";
        $rendered = $service->fiftyonedegrees_block_filter($content, null);
        $this->assertEquals("text ... null", $rendered);
    }

    /**
     * Test that for a token with an unknown element, the token is replaced with
     * null.
     */
    public function testBlockRender_unknownElement() {
        $service = new FiftyoneService();
        $content = "text ... {Pipeline::get(\"noelement\", \"noproperty\")}";
        $rendered = $service->fiftyonedegrees_block_filter($content, null);
        $this->assertEquals("text ... null", $rendered);
    }

    /**
     * Test that the conditinal block determines the content correctly
     * using the "is" operator.
     */
    public function testConditionalBlock_is() {
        $service = new FiftyoneService();
        $content = "some test content...";
        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "is",
                "value" => "Value"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertEquals($content, $rendered);

        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "is",
                "value" => "Not Value"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertNull($rendered);
    }
    
    /**
     * Test that the conditinal block determines the content correctly
     * using the "not" operator.
     */
    public function testConditionalBlock_not() {
        $service = new FiftyoneService();
        $content = "some test content...";
        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "not",
                "value" => "Value"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertNull($rendered);

        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "not",
                "value" => "Not Value"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertEquals($content, $rendered);
    }
    
    /**
     * Test that the conditinal block determines the content correctly
     * using the "contains" operator.
     */
    public function testConditionalBlock_contains() {
        $service = new FiftyoneService();
        $content = "some test content...";
        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "contains",
                "value" => "al"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertEquals($content, $rendered);

        $block = array(
            "blockName" => "fiftyonedegrees/conditional-group-block",
            "attrs" => array(
                "property" => "testElement|availableProperty",
                "operator" => "contains",
                "value" => "Not"
            )
        );
        $rendered = $service->fiftyonedegrees_render_block($content, $block);
        $this->assertNull($rendered);
    }
}
