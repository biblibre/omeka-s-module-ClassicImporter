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
            'name' => 'files_source',
            'type' => 'hidden',
        ]);
        $this->add([
            'name' => 'domain_name',
            'type' => 'hidden',
        ]);

        $this->add([
            'name' => 'import_collections',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Import collections?', //@translate
            ],
        ]);
    }

    public function addPropertyMappings($properties, $api = null)
    {
        foreach ($properties as $property) {
            $defaultMapping = null;

            if (!empty($api)) {
                // keep only alphanumeric characters
                $propertyName = preg_replace("/[^a-zA-Z0-9 ]+/", "", strtolower($property['element_name']));

                $nextShouldBeUpper = false;
                for ($i = 0; $i < strlen($propertyName); $i++) {
                    $char = $propertyName[$i];
                    if ($nextShouldBeUpper) {
                        $propertyName[$i] == strtoupper($char);
                        $nextShouldBeUpper = false;
                    }
                    if ($char == ' ') {
                        $nextShouldBeUpper = true;
                    }
                }

                // remove spaces
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
                    elseif ($property['element_set_name'] == 'Dublin Core') {
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
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'value' => [ $defaultMapping->id() ],
                        'multiple' => true,
                    ],
                ]);
            } else {
                $this->add([
                    'name' => 'elements_properties[' . $property['element_id'] . ']',
                    'type' => OptionalPropertySelect::class,
                    'options' => [
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'multiple' => true,
                    ],
                ]);
            }

            $this->add([
                'name' => 'preserve_html[' . $property['element_id'] . ']',
                'type' => OptionalCheckbox::class,
                'options' => [
                    'label' => 'Preserve html of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                ],
            ]);

            $this->add([
                'name' => 'transform_uris[' . $property['element_id'] . ']',
                'type' => OptionalCheckbox::class,
                'options' => [
                    'label' => 'Transform URIs of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    'value' => '1',
                ],
            ]);
        }
    }

    public function addResourceClassMappings($resourceClasses, $api = null)
    {
        foreach ($resourceClasses as $resourceClass) {
            $defaultMapping = null;

            if (!empty($api)) {
                $className = preg_replace('/\s+/', '', $resourceClass['name']);
                $omekasClasses = $api->search('resource_classes',
                    ['local_name' => $className]
                )->getContent();

                if (!empty($omekasClasses) && count($omekasClasses) == 1) {
                    $defaultMapping = $omekasClasses[0];
                }
            }

            if (!empty($defaultMapping)) {
                $this->add([
                    'name' => 'types_classes[' . $resourceClass['id'] . ']',
                    'type' => OptionalResourceClassSelect::class,
                    'options' => [
                        'label' => 'Mapping of class ' . $resourceClass['name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'value' => $defaultMapping->id(),
                    ],
                ]);
            } else {
                $this->add([
                    'name' => 'types_classes[' . $resourceClass['id'] . ']',
                    'type' => OptionalResourceClassSelect::class,
                    'options' => [
                        'label' => 'Mapping of class ' . $resourceClass['name'],
                    ],
                    'attributes' => [
                        'required' => false,
                    ],
                ]);
            }
        }
    }

    public function setFilesSource($filesSource)
    {
        $this->get('files_source')->setValue($filesSource);
    }
    public function setDomainName($domainName)
    {
        $this->get('domain_name')->setValue($domainName);
    }

    public function addCollectionsTreeCheckbox()
    {
        $this->add([
            'name' => 'import_collections_tree',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Import CollectionsTree\'s tree?', // @translate
            ],
        ]);
    }

    public function setUpdatedJob($jobId)
    {
        $this->add([
            'name' => 'updated_job_id',
            'type' => 'hidden',
            'attributes' => [
                'value' => $jobId,
            ],
        ]);
        $this->add([
            'name' => 'update',
            'type' => 'hidden',
            'attributes' => [
                'value' => '1',
            ],
        ]);
    }
}
