<?php

namespace ClassicImporter\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use Omeka\Service\Exception\ConfigException;
use ClassicImporter\Form\ImportForm;
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
            SELECT vocabulary.prefix, property.local_name, property.id FROM value
                LEFT JOIN property ON property.id = value.property_id
                LEFT JOIN vocabulary ON vocabulary.id = property.vocabulary_id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $rows = $stmt->fetchAllAssociative();

        var_dump($rows);

        /*$sql_content = $source->getRows(0);
        if (empty($sql_content)) {
            $message = $source->getErrorMessage() ?: 'The file has no headers.'; // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $importForm = $this->getForm(MappingForm::class);

        $importName = $data['import_name'];

        $view->setVariable('form', $importForm);
        $view->setVariable('sqlFilePath', $sqlFilePath);
        $view->setVariable('importName', $importName);*/

        return $view; // return $view;
    }

    public function mapAction()
    {

    }
}
