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


class EditorTests extends TestCase {

	public function set_up() {
        // Reset the pipeline so there is nothing from a previous test.
        Pipeline::reset();
		parent::set_up();
		Brain\Monkey\setUp();
        // Mock the pipeline that the plugin uses so we don't need a
        // license key.
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
        Functions\when("wp_list_pluck")->alias(function($arg1, $arg2) {
            return array_column($arg1, $arg2);
        });
        Functions\stubs([
            '__' => null,
        ]);
        // Process the pipeline.
        Pipeline::process();
    }

	public function tear_down() {
		Brain\Monkey\tearDown();
		parent::tear_down();
	}

    /**
     * Test that a new block category is added to the existing categories.
     */
    public function testAddBlockCategory() {
        $service = new FiftyoneService();
        $categories = array();
        $updated = $service->fiftyonedegrees_block_categories($categories);
        $this->assertNotNull($updated);
        $this->assertEquals(1, count($updated));
        $this->assertEquals("51Degrees", $updated[0]["slug"]);
        $this->assertEquals("51Degrees", $updated[0]["title"]);
        $this->assertNull($updated[0]["icon"]);
    }

    /**
     * Test that exising block categories are not altered when adding
     * our new block category.
     */
    public function testAddBlockCategory_retainExisting() {
        $service = new FiftyoneService();
        $categories = array(
            array(
                "title" => "existing",
                "slug" => "existing",
                "icon" => null
            )
        );
        $updated = $service->fiftyonedegrees_block_categories($categories);
        $this->assertNotNull($updated);
        $this->assertEquals(2, count($updated));
        
        $this->assertEquals("existing", $updated[0]["slug"]);
        $this->assertEquals("existing", $updated[0]["title"]);
        $this->assertNull($updated[0]["icon"]);
        $this->assertEquals("51Degrees", $updated[1]["slug"]);
        $this->assertEquals("51Degrees", $updated[1]["title"]);
        $this->assertNull($updated[1]["icon"]);
    }
}
?>
