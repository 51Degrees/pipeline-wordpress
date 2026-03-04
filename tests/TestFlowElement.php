<?php

use fiftyone\pipeline\core\FlowData;
use fiftyone\pipeline\core\FlowElement;
use fiftyone\pipeline\core\AspectPropertyValue;
use fiftyone\pipeline\core\ElementDataDictionary;
use fiftyone\pipeline\core\BasicListEvidenceKeyFilter;

class TestFlowElement extends FlowElement
{
    public string $dataKey;
    public array $properties;

    public function __construct()
    {
        // List of Pipelines the FlowElement has been added to
        $this->pipelines = [];
        $this->dataKey = "testElement";
        $this->properties = array(
            "availableProperty" => array(
                "name" => "AvailableProperty",
                "type" => "string",
                "category" => "testCategory"
            ),
            "noValueProperty" => array(
                "name" => "NoValueProperty",
                "type" => "string",
                "category" => "testCategory"
            ),
            "testProperty" => array(
                "name" => "TestProperty",
                "type" => "int",
                "category" => "otherCategory"
            )
        );
    }

    public function processInternal(FlowData $flowData): void
    {
		
        $contents = [];

        $contents["javascript"] = "console.log('hello world')";
        $contents["normal"] = true;

        $contents["availableProperty"] = new AspectPropertyValue(null, "Value");
        $contents["noValueProperty"] = new AspectPropertyValue("Property is not available.", null);

        $data = new ElementDataDictionary($this, $contents);
		
        $flowData->setElementData($data);
    }
    
    public function getEvidenceKeyFilter(): BasicListEvidenceKeyFilter
    {
        return new BasicListEvidenceKeyFilter(["header.user-agent"]);
    }

    public function filterEvidenceKey($key): bool
    {
        return true;
    }
}

/**
 * A FlowElement that always throws during processing.
 * Used to test that Pipeline::process() handles errors gracefully.
 */
class ThrowingFlowElement extends FlowElement
{
    public string $dataKey = 'throwingElement';
    public array $properties = [];

    public function processInternal(FlowData $flowData): void
    {
        throw new \RuntimeException('Simulated processing failure');
    }

    public function getEvidenceKeyFilter(): BasicListEvidenceKeyFilter
    {
        return new BasicListEvidenceKeyFilter([]);
    }
}
