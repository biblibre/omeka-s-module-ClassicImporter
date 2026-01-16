<?php

namespace ClassicImporter\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use ClassicImporter\Form\ImportForm;
use ClassicImporter\Form\MappingForm;
use ClassicImporter\Job\ImportFromDumpJob;
use RuntimeException;

class IndexController extends AbstractActionController
{
    protected $serviceLocator;

    protected $jobId;

    protected $jobUrl;

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

    public function mapAction()
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
            $dumpManager->createDumpDatabase($sqlFilePath, $post['db_admin'], $post['db_psk']);
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
            SELECT item_types.id, item_types.name, item_types.description FROM items
                INNER JOIN item_types ON items.item_type_id = item_types.id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->addResourceClassMappings($resourceClasses, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->setFilesSource($post['files_source']);

        $view->setVariable('form', $form);
        $view->setVariable('resourceClasses', $resourceClasses);
        $view->setVariable('properties', $properties);

        return $view;
    }

    public function importAction()
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
            SELECT item_types.id, item_types.name, item_types.description FROM items
                INNER JOIN item_types ON items.item_type_id = item_types.id;
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

        $post['files_source'] = trim($post['files_source']);
        if ($post['files_source'][strlen($post['files_source']) - 1] != '/') {
            $post['files_source'] = $post['files_source'] . '/';
        }

        unset($post['csrf']);
        $this->sendJob($post);

        $message = new Message(
            'Dump import started in %s job %s%s', // @translate
            sprintf('<a href="%s">', htmlspecialchars(
                $this->getJobUrl(),
            )),
            $this->getJobId(),
            '</a>'
        );

        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        
        return $this->redirect()->toRoute('admin/classicimporter');
    }

    protected function sendJob($args)
    {
        $job = $this->jobDispatcher()->dispatch(ImportFromDumpJob::class, $args);

        $jobUrl = $this->url()->fromRoute('admin/id', [
            'controller' => 'job',
            'action' => 'show',
            'id' => $job->getId(),
        ]);

        $this->setJobId($job->getId());
        $this->setJobUrl($jobUrl);
    }
    protected function getJobId()
    {
        return $this->jobId;
    }

    protected function setJobId($id)
    {
        $this->jobId = $id;
    }

    protected function getJobUrl()
    {
        return $this->jobUrl;
    }

    protected function setJobUrl($url)
    {
        $this->jobUrl = $url;
    }
}
