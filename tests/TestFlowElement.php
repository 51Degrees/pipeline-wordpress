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
