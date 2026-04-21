<?php

namespace ClassicImporter\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use ClassicImporter\Form\ImportForm;
use ClassicImporter\Form\MappingForm;
use ClassicImporter\Job\ImportFromDumpJob;
use ClassicImporter\Job\UndoImportJob;
use Omeka\Module\Manager as ModuleManager;

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

        $request = $this->getRequest();
        $get = $request->getQuery()->toArray();

        // are we trying to update from a previous job?
        if (empty($get['jobId'])) {
            $view->setVariable('form', $form);

            return $view;
        }

        // check for safety
        if (!is_numeric($get['jobId']) || $get['jobId'] <= 0) {
            $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $get['jobId'])); // @translate
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
            ->search('classicimporter_imports', ['job_id' => $get['jobId']])->getContent();

        if (empty($updatedJob) || empty($updatedJob[0])) {
            $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $get['jobId'])); // @translate
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $jobArgs = $updatedJob[0]->job()->args();
        $form->setUpdatedJob($get['jobId']);
        $form->get('files_source')->setValue($jobArgs['files_source'] ?? '');
        $form->get('domain_name')->setValue($jobArgs['domain_name'] ?? '');

        $view->setVariable('update', true);
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

        $dumpManager = $this->serviceLocator->get('ClassicImporter\DumpManager');
        if (empty($dumpManager)) {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        if ($dumpManager->getConn() === null) {
            $this->messenger()->addError('Could not connect to the temporary dump database. Check the credentials in config/local.config.php under classicimporter.tempdb_credentials.'); // @translate
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        try {
            $stmt = $dumpManager->getConn()->executeQuery('SHOW TABLES');
            $tables = $stmt->fetchAllAssociative();

            if (empty($tables)) {
                $this->messenger()->addError('The temporary dump database is empty. Please import the SQL dump directly into the database configured under classicimporter.tempdb_credentials before proceeding.'); // @translate
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
        } catch (\Exception $e) {
            $this->messenger()->addError(sprintf('Error: %s. Check if your dump database is a valid Omeka Classic SQL dump.', $e->getMessage())); // @translate
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->addResourceClassMappings($resourceClasses, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->setFilesSource($post['files_source']);
        $form->setDomainName($post['domain_name']);

        foreach ($tables as $table) {
            if (in_array('collection_trees', $table)) { // @todo add minimum version
                if ($this->checkModuleActiveVersion('ItemSetsTree')) {
                    $form->addCollectionsTreeCheckbox();
                } else {
                    $this->messenger()->addWarning(sprintf('Dump database has a collections tree but Omeka-S does not have ItemSetsTree installed. Item sets tree will not be imported.'));
                }
                break;
            }
        }

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

        if (empty($dumpManager)) {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        try {
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

            $sql =
                <<<'SQL'
                SHOW TABLES;
                SQL;

            $stmt = $dumpManager->getConn()->executeQuery($sql);
            $tables = $stmt->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->messenger()->addError(sprintf('Error: %s. Check if your dump database is a valid Omeka Classic SQL dump.', $e->getMessage())); // @translate
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties);
        $form->addResourceClassMappings($resourceClasses);

        foreach ($tables as $table) {
            if (in_array('collection_trees', $table)) { // @todo add minimum version
                if ($this->checkModuleActiveVersion('ItemSetsTree')) {
                    $form->addCollectionsTreeCheckbox();
                }
                break;
            }
        }

        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/classicimporter');
        }

        // It is optional. Won't import media if not set.
        if (!empty($post['files_source'])) {
            $post['files_source'] = trim($post['files_source']);
            if ($post['files_source'][strlen($post['files_source']) - 1] != '/') {
                $post['files_source'] = $post['files_source'] . '/';
            }
            if (!is_dir($post['files_source'])) {
                $this->messenger()->addError('Given media folders does not exist on disk.'); // @translate
                return $this->redirect()->toRoute('admin/classicimporter');
            }
        }

        if (!empty($post['domain_name'])) {
            $post['domain_name'] = trim($post['domain_name']);
            if (str_contains($post['domain_name'], '://')) {
                $domainName = explode('://', $post['domain_name']);
                if (count($domainName) == 2) {
                    $post['domain_name'] = trim($domainName[1], '/');
                } else {
                    $this->messenger()->addError(sprintf('Given url \'%s\' for old omeka instance is invalid.', $post['domain_name'])); // @translate
                    return $this->redirect()->toRoute('admin/classicimporter');
                }
            } else {
                $post['domain_name'] = trim($post['domain_name'], '/');
            }
        }

        if (isset($post['updated_job_id'])) {
            $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
                ->search('classicimporter_imports', ['job_id' => $post['updated_job_id']])->getContent();

            if (empty($updatedJob) || empty($updatedJob[0])) {
                $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $post['updated_job_id'])); // @translate
                return $this->redirect()->toRoute('admin/classicimporter');
            }
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

    /**
     * Check if a module is active and optionally its minimum version.
     */
    protected function checkModuleActiveVersion(string $module, ?string $version = null): bool
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);
        if (!$module
            || $module->getState() !== ModuleManager::STATE_ACTIVE
        ) {
            return false;
        }

        if (!$version) {
            return true;
        }

        $moduleVersion = $module->getIni('version');
        return $moduleVersion
            && version_compare($moduleVersion, $version, '>=');
    }

    public function pastImportsAction()
    {
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $undoJobIds = [];
            foreach ($data['jobs'] as $jobId) {
                $undoJob = $this->undoJob($jobId);
                $undoJobIds[] = $undoJob->getId();
            }
            $message = new Message(
                'Undo in progress in the following jobs: %s', // @translate
                implode(', ', $undoJobIds));
            $this->messenger()->addSuccess($message);
        }
        $view = new ViewModel;
        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery() + [
            'page' => $page,
            'sort_by' => $this->params()->fromQuery('sort_by', 'id'),
            'sort_order' => $this->params()->fromQuery('sort_order', 'desc'),
        ];
        $response = $this->api()->search('classicimporter_imports', $query);
        $this->paginator($response->getTotalResults(), $page);
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    protected function undoJob($jobId)
    {
        $response = $this->api()->search('classicimporter_imports', ['job_id' => $jobId]);
        $classicImport = $response->getContent()[0];
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(UndoImportJob::class, ['jobId' => $jobId]);
        $response = $this->api()->update('classicimporter_imports',
            $classicImport->id(),
            [
                'o:undo_job' => ['o:id' => $job->getId()],
            ]
        );
        return $job;
    }
}