<?php

namespace ClassicImporter\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use Omeka\Service\Exception\ConfigException;
use ClassicImporter\Form\ImportForm;
use ClassicImporter\Form\MappingForm;
use RuntimeException;

class IndexController extends AbstractActionController
{

    /**
     * @var string
     */

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

        $this->messenger()->addSuccess('Dump import job successfully started.');
        return $this->redirect()->toRoute('admin/classicimporter');
    }
}
