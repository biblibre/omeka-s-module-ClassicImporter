<?php

namespace ClassicImporter;

class DumpManager
{
    // @var string $tempUser
    protected $tempUser;

    // @var string $tempUser
    protected $tempUsername;

    // @var string $tempUser
    protected $tempPassword;

    // @var string $tempUser
    protected $tempDbName;

    // @var string $tempUser
    protected $tempHost;

    protected $serviceLocator;

    protected $dumpConn;

    public function __construct($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;

        $tempdb = $this->serviceLocator->get('Omeka\ApiManager')->search('classicimporter_tempdb')->getContent();
        if (!empty($tempdb)) {
            $this->dumpConn = new \Doctrine\DBAL\Connection([
                'dbname'   => $tempdb[0]->dbname(),
                'user'     => $tempdb[0]->username(),
                'password' => $tempdb[0]->password(),
                'host'     => $tempdb[0]->host(),
        ], $this->serviceLocator->get('Omeka\Connection')->getDriver());
        }
    }

    /**
     * Execute safely by creating a new tempuser a dump.
     * Only done once by controller
     */
    public function createDumpDatabase(string $filepath, string $adminUser, string $adminPassword, string $host)
    {
        if (!file_exists($filepath) || !filesize($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException(sprintf('Failed to read file %s.', $filepath));
        }

        $tempdb = $this->serviceLocator->get('Omeka\ApiManager')->search('classicimporter_tempdb')->getContent();
        if (!empty($tempdb)) {
            throw new \RuntimeException('Only one temp DB can be created for storage and safety reason. Please remove other classic import temp DB to fix.');
        }

        $userAndDbCreation = <<<'SQL'
        CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

        CREATE USER '%s'@'%s' IDENTIFIED BY '%s';
        GRANT ALL PRIVILEGES ON %s.* TO '%s'@'%s';
        FLUSH PRIVILEGES;
        SQL;
        
        $this->tempHost = $host;
        $this->tempUsername = uniqid('classic_importer_user_');
        $this->tempPassword = uniqid();
        $this->tempDbName = uniqid('classic_importer_tempdb_');

        $userAndDbCreation = sprintf($userAndDbCreation, $this->tempDbName,
            $this->tempUsername, $this->tempHost, $this->tempPassword, $this->tempDbName,
            $this->tempUsername, $this->tempHost);

        $command = sprintf(
    'mysql --user=%s --password=%s -e %s 2>&1',
    escapeshellarg($adminUser),
            escapeshellarg($adminPassword),
            escapeshellarg($userAndDbCreation)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Failed to create temp database:\n" . implode("\n", $output)
            );
        }

        $this->serviceLocator->get('Omeka\ApiManager')->create('classicimporter_tempdb', ['username' => $this->tempUsername,
                                                        'password' => $this->tempPassword,
                                                        'host' => $this->tempHost,
                                                        'dbname' => $this->tempDbName,
                                                    ]);

        $command = sprintf(
    'mysql --user=%s --password=%s %s < %s 2>&1',
    escapeshellarg($this->tempUsername),
            escapeshellarg($this->tempPassword),
            escapeshellarg($this->tempDbName),
            escapeshellarg($filepath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "SQL dump import failed:\n" . implode("\n", $output)
            );
        }

        $this->dumpConn = new \Doctrine\DBAL\Connection([
            'dbname'   => $this->tempDbName,
            'user'     => $this->tempUsername,
            'password' => $this->tempPassword,
            'host'     => $this->tempHost,
        ], $this->serviceLocator->get('Omeka\Connection')->getDriver());
    }

    public function getConn()
    {
        return $this->dumpConn;
    }

    public function deleteDumpDatabase()
    {
        // todo
    }
}