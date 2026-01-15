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

    public function addPropertyMappings($properties)
    {
        foreach ($properties as $property) {
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
