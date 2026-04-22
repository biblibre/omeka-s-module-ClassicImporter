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

    protected $tablePrefix;

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
                    'dbname' => $tempdb["database"],
                    'user' => $tempdb["username"],
                    'password' => $tempdb["password"],
                    'host' => $tempdb["hostname"],
                ], $this->serviceLocator->get('Omeka\Connection')->getDriver());
            } catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
                $this->dumpConn = null;
                $this->errorMessage = $e->getMessage();
            }

            $this->tempUsername = $tempdb["username"];
            $this->tempHostname = $tempdb["hostname"];
            $this->tempPassword = $tempdb["password"];
            $this->tempDbName = $tempdb["database"];

            $this->tablePrefix = $tempdb["table_prefix"] ?? 'omeka_';
        } else {
            $this->dumpConn = null;
            $this->errorMessage = "Invalid dump credentials config."; // @translate
        }
    }

    public function t(string $tableName): string
    {
        return $this->tablePrefix . $tableName;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix ?? 'omeka_';
    }

    public function getConn(): \Doctrine\DBAL\Connection|null
    {
        return $this->dumpConn;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage ?? '';
    }

    // Add column checks to verify column existence in the dump database (old Omeka Classic versions)
    public function hasColumn(string $table, string $column): bool
    {
        if (empty($this->dumpConn)) {
            return false;
        }

        try {
            $stmt = $this->dumpConn->executeQuery(
                'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?',
                [$table, $column]
            );
            return (int) $stmt->fetchOne() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
