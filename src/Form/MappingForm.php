<?php

namespace ClassicImporter\Form;

use Laminas\Form\Form;
use ClassicImporter\Form\Element\OptionalCheckbox;
use ClassicImporter\Form\Element\OptionalPropertySelect;
use ClassicImporter\Form\Element\OptionalResourceClassSelect;

class MappingForm extends Form
{
    public function init()
    {
        $this->setAttribute('action', 'import');
        $this->setAttribute('method', 'post');
        
        $this->add([
            'name' => 'import_collections',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Import collections?', //@translate
            ],
        ]);

        $this->add([
            'name' => 'update',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Update a past import corresponding to this one?', //@translate
            ],
        ]);
    }

    public function addPropertyMappings($properties, $api = null)
    {
        foreach ($properties as $property) {
            $defaultMapping = null;

            if (!empty($api))
            {
                $propertyName = preg_replace("/[^a-zA-Z0-9 ]+/", "", strtolower($property['element_name']));

                $nextShouldBeUpper = false;
                for ($i = 0; $i < strlen($propertyName); $i++) {
                    $char = $propertyName[$i];
                    if ($nextShouldBeUpper)
                    {
                        $propertyName[$i] == strtoupper($char);
                        $nextShouldBeUpper = false;
                    }
                    if ($char == ' ') {
                        $nextShouldBeUpper = true;
                    }
                }
                $propertyName = preg_replace('/\s+/', '', $propertyName);
                
                $omekasProperties = $api->search('properties',
                    ['local_name' => $propertyName]
                )->getContent();

                if (!empty($omekasProperties)) {
                    if (count($omekasProperties) == 1) {
                        $defaultMapping = $omekasProperties[0];
                    }

                    // more than two matches found for the same property.
                    // common for things like dcterms:title and foaf:title
                    // dcterms takes priority if vocab of origin was Dublin Core
                    else if ($property['element_set_name'] == 'Dublin Core') {
                        $dublinCoreProperty = null;

                        foreach ($omekasProperties as $omekasProperty) {
                            if ($omekasProperty->vocabulary()->prefix() == 'dcterms') {
                                // edge case where there are two dcterms matches...
                                if (!empty($dublinCoreProperty)) {
                                    $dublinCoreProperty = null;
                                    break;
                                }

                                $dublinCoreProperty = $omekasProperty;
                            }
                        }

                        $defaultMapping = $dublinCoreProperty;
                    }
                }
            }

            if (!empty($defaultMapping)) {
                $this->add([
                    'name' => 'elements_properties[' . $property['element_id'] . ']',
                    'type' => OptionalPropertySelect::class,
                    'options' => [
                        'empty_option' => 'Do not import', // @translate
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'value' => $defaultMapping->id(),
                    ],
                ]);
            }

            else {
                $this->add([
                    'name' => 'elements_properties[' . $property['element_id'] . ']',
                    'type' => OptionalPropertySelect::class,
                    'options' => [
                        'empty_option' => 'Do not import', // @translate
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                    ],
                ]);
            }
        }
    }

    public function addResourceClassMappings($resourceClasses)
    {
        foreach ($resourceClasses as $resourceClass) {
            $this->add([
                'name' => 'types_classes[' . $resourceClass['id'] . ']',
                'type' => OptionalResourceClassSelect::class,
                'options' => [
                    'empty_option' => 'Do not import', // @translate
                    'label' => 'Mapping of class ' . $resourceClass['name'],
                ],
                'attributes' => [
                    'required' => false,
                ],
            ]);
        }
    }
}
