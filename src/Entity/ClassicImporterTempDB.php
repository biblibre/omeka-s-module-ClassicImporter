<?php
namespace ClassicImporter\Entity;

use Omeka\Entity\AbstractEntity;

/**
 * @Entity
 */
class ClassicImporterTempDB extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @Column(type="string")
     */
    protected $username;

    /**
     * Password
     * @Column(type="string")
     */
    protected $password;

    /**
     * DB name
     * @Column(type="string")
     */
    protected $dbname;

    /**
     * Hostname
     * @Column(type="string")
     */
    protected $hostname;

    public function getId()
    {
        return $this->id;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function setUsername(string $username)
    {
        $this->username = $username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function setPassword(string $password)
    {
        $this->password = $password;
    }
    
    public function getHost()
    {
        return $this->hostname;
    }

    public function setHost(string $hostname)
    {
        $this->hostname = $hostname;
    }

    public function getDbname()
    {
        return $this->dbname;
    }

    public function setDbname(string $dbname)
    {
        $this->dbname = $dbname;
    }
}
