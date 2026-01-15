<?php

namespace ClassicImporter;

use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Composer\Semver\Comparator;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $sql = <<<'SQL'
CREATE TABLE classic_importer_temp_db (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, dbname VARCHAR(255) NOT NULL, hostname VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
SQL;
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $dumpManager = $serviceLocator->get('ClassicImporter\DumpManager');
        $dumpManager->deleteDumpDatabase();

        $connection = $serviceLocator->get('Omeka\Connection');

        $sql = <<<'SQL'
DROP TABLE IF EXISTS classic_importer_temp_db;
SQL;

        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }
};

?>