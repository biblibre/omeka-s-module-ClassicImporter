<?php

namespace ClassicImporter\Form;

use Laminas\Form\Form;

class ImportForm extends Form
{
    public function init()
    {
        $this->setAttribute('action', 'classicimporter/map');
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'source',
            'type' => 'text',
            'options' => [
                'label' => 'File path to the dump', //@translate
                'info' => 'The dump.sql file must be uploaded beforehand to the instance. Once done, write the path to the file.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'placeholder' => '/home/omekas/dump.sql'
            ],
        ]);

        $this->add([
            'name' => 'files_source',
            'type' => 'text',
            'options' => [
                'label' => 'File path to the original media files', //@translate
                'info' => 'If not set, media will NOT be imported. The \'files\' file must be uploaded beforehand to the instance. It can be found in the Omeka instance in omeka/files/original. Once done, write the path to the directoy.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'placeholder' => '/home/omekas/classic_files/original/'
            ],
        ]);

        $this->add([
            'name' => 'domain_name',
            'type' => 'text',
            'options' => [
                'label' => 'What used to be the url of the Omeka instance', // @translate
                'info' => 'If you used to connect to \'https://myomeka.com/\', then put myomeka.com (or https://myomeka.com, it does not matter). It is only for text replacement purpose, it does not matter if the url still works anymore or not.', // @translate
            ]
        ]);
    }
}
