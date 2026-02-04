<?php
namespace ClassicImporter;

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => sprintf('%s/../language', __DIR__),
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            sprintf('%s/../view', __DIR__),
        ],
    ],
    'controllers' => [
        'factories' => [
            'ClassicImporter\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            'ClassicImporter\Form\ImportForm' => Form\ImportForm::class,
            'ClassicImporter\Form\MappingForm' => Form\MappingForm::class,
            'ClassicImporter\Form\Element\OptionalCheckbox' => Form\Element\OptionalCheckbox::class,
        ],
        'factories' => [
            'ClassicImporter\Form\Element\OptionalResourceClassSelect' => Service\Form\Element\OptionalResourceClassSelectFactory::class,
            'ClassicImporter\Form\Element\OptionalPropertySelect' => Service\Form\Element\OptionalPropertySelectFactory::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'classicimporter_resource_maps' => Api\Adapter\ResourceMapAdapter::class, 
            'classicimporter_imports' => Api\Adapter\ImportAdapter::class, 
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'classicimporter_local' => Service\Media\Ingester\LocalFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'ClassicImporter\DumpManager' => Service\DumpManagerFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'ClassicImporter',
                'route' => 'admin/classicimporter',
                'resource' => 'ClassicImporter\Controller\Admin\Index',
            ],
        ],
    ],
   'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'classicimporter' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/classicimporter',
                            'defaults' => [
                                '__NAMESPACE__' => 'ClassicImporter\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'import' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ClassicImporter\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'import',
                                    ],
                                ],
                            ],
                            'map' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/map',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ClassicImporter\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'map',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'classicimporter' => [
        'tempdb_credentials' => [
            "username" => "tempdb",
            "password" => "password",
            "hostname" => "localhost",
            "database" => "tempdb",
        ]
    ]
];