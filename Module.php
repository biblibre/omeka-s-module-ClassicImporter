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
CREATE TABLE classic_importer_resource_map (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    classic_resource_id INT NOT NULL,
    mapped_resource_name VARCHAR(255) NOT NULL,
    INDEX IDX_10D9435789329D25 (resource_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE classic_importer_resource_map
    ADD CONSTRAINT FK_10D9435789329D25
        FOREIGN KEY (resource_id)
        REFERENCES resource (id)
        ON DELETE CASCADE
;
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
ALTER TABLE classic_importer_resource_map DROP FOREIGN KEY FK_10D9435789329D25;
DROP TABLE IF EXISTS classic_importer_resource_map;
SQL;

        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }
};

?>