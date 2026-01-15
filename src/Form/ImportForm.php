<?php

namespace ClassicImporter\Form;

use Laminas\Form\Form;

class ImportForm extends Form
{
    public function init()
    {
        $this->setAttribute('action', 'classicimporter/import');
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'source',
            'type' => 'text',
            'options' => [
                'label' => 'File path to the dump', //@translate
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'db_admin',
            'type' => 'text',
            'options' => [
                'label' => 'Database admin username',
                'info' => 'Database host name to safely temporarily receive dump. New DB will be safely created for that.',
            ],
            'attributes' => [
                'placeholder' => 'omekas',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'db_psk',
            'type' => 'password',
            'options' => [
                'label' => 'Database admin password',
                'info' => 'Database admin password to be used to use the DB to safely receive the dump.',
            ],
            'attributes' => [
                'placeholder' => 'omekas',
                'required' => true,
            ],
        ]);
    }
}