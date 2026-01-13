<?php
namespace ClassicImporter\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class TempDBRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'tempdb';
    }

    public function getJsonLd()
    {
        return [
            'username' => $this->username(),
            'password' => $this->password(),
            'host' => $this->host(),
            'dbname' => $this->dbname(),
        ];
    }

    public function getJsonLdType()
    {
        return 'o:ClassicImporterTempDB';
    }

    public function username()
    {
        return $this->resource->getUsername();
    }

    public function password()
    {
        return $this->resource->getPassword();
    }

    public function host()
    {
        return $this->resource->getHost();
    }

    public function dbname()
    {
        return $this->resource->getDbname();
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/classicimporter/tempdb',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}
