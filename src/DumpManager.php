<?php

namespace ClassicImporter;

class DumpManager
{
    protected $serviceLocator;

    protected $dumpConn;

    protected $tempUsername;

    protected $tempHostname;

    protected $tempDbName;

    protected $tempPassword;

    protected $errorMessage;

    public function __construct($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;

        if (empty($serviceLocator->get('Config')['classicimporter']['tempdb_credentials'])) {
            $this->dumpConn = null;
            $this->errorMessage = "Credential files could not be found in config."; // @translate
            return;
        }

        $tempdb = $serviceLocator->get('Config')['classicimporter']['tempdb_credentials'];
        if (!empty($tempdb)
            && array_key_exists("password", $tempdb)
            && array_key_exists("username", $tempdb)
            && array_key_exists("hostname", $tempdb)
            && array_key_exists("database", $tempdb)
        ) {
            try {
                $this->dumpConn = new \Doctrine\DBAL\Connection([
                    'dbname'   => $tempdb["database"],
                    'user'     => $tempdb["username"],
                    'password' => $tempdb["password"],
                    'host'     => $tempdb["hostname"],
                ], $this->serviceLocator->get('Omeka\Connection')->getDriver());
            } catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
                $this->dumpConn = null;
                $this->errorMessage = $e->getMessage();
            }

            $this->tempUsername = $tempdb["username"];
            $this->tempHostname = $tempdb["hostname"];
            $this->tempPassword = $tempdb["password"];
            $this->tempDbName   = $tempdb["database"];
        } else {
            $this->dumpConn = null;
            $this->errorMessage = "Invalid dump credentials config."; // @translate
        }
    }

    public function getConn(): \Doctrine\DBAL\Connection|null
    {
        return $this->dumpConn;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage ?? '';
    }

    public function deleteDumpDatabase(): void
    {
        if (empty($this->dumpConn)) {
            return;
        }

        $this->dumpConn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        $stmt  = $this->dumpConn->executeQuery('SHOW TABLES');
        $tables = $stmt->fetchAllAssociative();

        if (!empty($tables)) {
            $tableNames = [];
            foreach ($tables as $table) {
                $tableNames[] = '`' . $table[array_key_first($table)] . '`';
            }

            $this->dumpConn->executeQuery(sprintf(
                'DROP TABLE IF EXISTS %s',
                implode(', ', $tableNames)
            ));
        }

        $this->dumpConn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}