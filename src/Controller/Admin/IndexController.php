<?php

namespace ClassicImporter\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use ClassicImporter\Form\ImportForm;
use ClassicImporter\Form\MappingForm;
use RuntimeException;

class IndexController extends AbstractActionController
{
    protected $serviceLocator;

    public function __construct($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function indexAction()
    {
        $form = $this->getForm(ImportForm::class);

        $view = new ViewModel();

        $view->setVariable('form', $form);

        return $view;
    }

    public function importAction()
    {
        $view = new ViewModel;
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $post = $request->getPost()->toArray();

        $form = $this->getForm(ImportForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $sqlFilePath = $post['source'];

        $dumpManager = $this->serviceLocator->get('ClassicImporter\DumpManager');

        if (empty($dumpManager))
        {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        try {
            $dumpManager->createDumpDatabase($sqlFilePath, $post['db_admin'], $post['db_psk'], $post['db_host']);
        } catch (\RuntimeException $e) {
            $this->messenger()->addError(sprintf('Error creating dump database. %s', $e->getMessage()));
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $sql =
            <<<'SQL'
            SELECT DISTINCT
                element_sets.name AS element_set_name,
                elements.name AS element_name,
                elements.id AS element_id
            FROM element_texts
                LEFT JOIN elements ON elements.id = element_texts.element_id
                LEFT JOIN element_sets ON elements.element_set_id = element_sets.id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $properties = $stmt->fetchAllAssociative();

        $sql =
            <<<'SQL'
            SELECT * FROM item_types;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties);
        $form->addResourceClassMappings($resourceClasses);

        $view->setVariable('form', $form);
        $view->setVariable('resourceClasses', $resourceClasses);
        $view->setVariable('properties', $properties);

        return $view; // return $view;
    }

    public function mapAction()
    {
        $view = new ViewModel;

        $dumpManager = $this->serviceLocator->get('ClassicImporter\DumpManager');

        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $post = $request->getPost()->toArray();

        if (empty($dumpManager))
        {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $sql =
            <<<'SQL'
            SELECT DISTINCT
                element_sets.name AS element_set_name,
                elements.name AS element_name,
                elements.id AS element_id
            FROM element_texts
                LEFT JOIN elements ON elements.id = element_texts.element_id
                LEFT JOIN element_sets ON elements.element_set_id = element_sets.id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $properties = $stmt->fetchAllAssociative();

        $sql =
            <<<'SQL'
            SELECT * FROM item_types;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties);
        $form->addResourceClassMappings($resourceClasses);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $this->importResourcesFromDump($dumpManager->getConn(), $properties, $resourceClasses, $post);
        
        return $this->redirect()->toRoute('admin/classicimporter');
    }

    public function importResourcesFromDump($dumpConn, $properties, $resourceClasses, $formData)
    {
        if ($formData['import_collections'] == '1')
            $this->importItemSetsFromDump($dumpConn, $properties, $resourceClasses, $formData);

        $this->importItemsFromDump($dumpConn, $properties, $resourceClasses, $formData);
    }

    public function importItemSetsFromDump($dumpConn, $properties, $resourceClasses, $formData)
    {
        $sql =
            <<<'SQL'
            SELECT * FROM collections;
            SQL;

        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();
        
        foreach ($itemSets as $itemSet) {
            $sql = sprintf(
            <<<'SQL'
            SELECT element_texts.element_id, element_texts.text, elements.name FROM collections
                LEFT JOIN element_texts AS element_texts ON element_texts.record_id = collections.id
                LEFT JOIN elements AS elements ON elements.id = element_texts.element_id
                WHERE element_texts.record_type = 'Collection'
                AND collections.id = %s;
            SQL, $itemSet['id']
            );

            $stmt = $dumpConn->executeQuery($sql);
            $propertyValues = $stmt->fetchAllAssociative();

            $itemSetData = [
                // collections don't have classes in Omeka
                'o:is_open' => '1', // @TODO check if this is column 'featured'
                'o:is_public' => $itemSet['public'],
            ];

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (isset($formData['elements_properties'][$property['element_id']]))
                {
                    $itemSetData[$property['name']] = [ //for each of the values
                        'property_id' => $formData['elements_properties'][$property['element_id']],
                        'type' => 'literal',
                        'is_public' => '1',
                        '@annotation' => null,
                        '@language' => null,
                        '@value' => $property['text'], // @TODO handle case when property has "html" in Omeka
                    ];
                }
            }

            $response = $this->serviceLocator->get('Omeka\ApiManager')->create('item_sets', $itemSetData)->getContent();
            var_dump($response);
            // @TODO : get the id of the created item_set to push it into a mapping table
        }
        $this->messenger()->addSuccess('Item sets successfully imported.');
    }

    public function importItemsFromDump($dumpConn, $properties, $resourceClasses, $formData)
    {
        $sql =
            <<<'SQL'
            SELECT * FROM items;
            SQL;

        $stmt = $dumpConn->executeQuery($sql);
        $items = $stmt->fetchAllAssociative();
        
        foreach ($items as $item) {
            $sql = sprintf(
            <<<'SQL'
            SELECT element_texts.element_id, element_texts.text, elements.name FROM items
                LEFT JOIN element_texts AS element_texts ON element_texts.record_id = items.id
                LEFT JOIN elements AS elements ON elements.id = element_texts.element_id
                WHERE element_texts.record_type = 'Item'
                AND items.id = %s;
            SQL, $item['id']
            );

            $stmt = $dumpConn->executeQuery($sql);
            $propertyValues = $stmt->fetchAllAssociative();

            $itemData = [
                 // @TODO check if this is column 'featured'
                'o:is_public' => $item['public'],

                // important so API doesn't add one automatically
                'o:site' => [],

            ];

            if (isset($formData['types_classes'][$item['item_type_id']]))
            {
                $itemData['o:resource_class'] = [ 'o:id' => $formData['types_classes'][$item['item_type_id']] ];
            }

            if ($formData['import_collections'] == '1') {
                $itemData['o:item_set'] = null; // @TODO
            }

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (isset($formData['elements_properties'][$property['element_id']]))
                {
                    $itemData[$property['name']] = [ //for each of the values
                        'property_id' => $formData['elements_properties'][$property['element_id']],
                        'type' => 'literal',
                        'is_public' => '1',
                        '@annotation' => null,
                        '@language' => null,
                        '@value' => $property['text'], // @TODO handle case when property has "html" in Omeka
                    ];
                }
            }

            $response = $this->serviceLocator->get('Omeka\ApiManager')->create('items', $itemData)->getContent();
            var_dump($response);
            // @TODO : get the id of the created item to push it into a mapping table
        }
        $this->messenger()->addSuccess('Items successfully imported.');
    }
}
