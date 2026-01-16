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
            'name' => 'db_admin',
            'type' => 'text',
            'options' => [
                'label' => 'Database admin username',
                'info' => 'Database admin username to create the new DB that will be used for dump. Dump will NOT be executed as admin, rather as a freshly created user with permissions only on the dump DB. Very safe! Do NOT USE \'root\'. It will not work. See ReadME for more info.', // @translate
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'db_psk',
            'type' => 'password',
            'options' => [
                'label' => 'Database admin password',
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);
    }
}
