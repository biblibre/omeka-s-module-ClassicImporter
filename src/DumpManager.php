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
        if (!empty($tempdb) && $tempdb
            && array_key_exists("password", $tempdb)
            && array_key_exists("username", $tempdb)
            && array_key_exists("hostname", $tempdb)
            && array_key_exists("database", $tempdb)) {

            try {
                $this->dumpConn = new \Doctrine\DBAL\Connection([
                    'dbname'   => $tempdb["database"],
                    'user'     => $tempdb["username"],
                    'password' => $tempdb["password"],
                    'host'     => $tempdb["hostname"],
                ], $this->serviceLocator->get('Omeka\Connection')->getDriver());
            }

            catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
                $this->dumpConn = null;
                $this->errorMessage = $e->getMessage();
            }

            $this->tempUsername = $tempdb["username"];
            $this->tempHostname = $tempdb["hostname"];
            $this->tempPassword = $tempdb["password"];
            $this->tempDbName = $tempdb["database"];
        }
        else {
            $this->dumpConn = null;
            $this->errorMessage = "Invalid dump credentials config."; // @translate
        }
    }

    /**
     * Execute safely by creating a new tempuser a dump.
     * Only done once by controller
     */
    public function createDumpDatabase(string $filepath)
    {
        if (!file_exists($filepath) || !filesize($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException(sprintf('Failed to read file %s.', $filepath));
        }

        if ($this->dumpConn == null) {
            throw new \RuntimeException(sprintf("Connection to the dump database failed: %s", // @tanslate
             $this->errorMessage)); 
        }

        $sql = <<<SQL
            SHOW TABLES;
        SQL;

        $stmt = $this->dumpConn->executeQuery($sql);
        $tables = $stmt->fetchAllAssociative();

        /* WARNING DEBUGGING ONLY */
        // $this->deleteDumpDatabase();
        if (!empty($tables)) {
            throw new \RuntimeException("Dump database is not empty."); // @translate
        }
        

        $this->getConn()->executeQuery(file_get_contents($filepath));
    }

    public function getConn(): \Doctrine\DBAL\Connection | null
    {
        return $this->dumpConn;
    }

    public function deleteDumpDatabase()
    {
        if (empty($this->dumpConn))
            return;

        $this->dumpConn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        $sql = <<<SQL
            SHOW TABLES;
        SQL;

        $stmt = $this->dumpConn->executeQuery($sql);
        $tables = $stmt->fetchAllAssociative();

        if (!empty($tables))
        {
            $tableNames = [];

            foreach ($tables as $table) {
                $tableNames[] = '`' . $table[array_key_first($table)] . '`';
            }

            $sql = sprintf(<<<SQL
                DROP TABLE IF EXISTS %s;
            SQL, implode(', ',$tableNames));

            $this->dumpConn->executeQuery(sprintf($sql, $this->tempDbName));
        }

        $this->dumpConn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}