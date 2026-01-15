<?php
namespace ClassicImporter\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use \Omeka\Api\Representation\ItemRepresentation;
use \Omeka\Api\Representation\ItemSetRepresentation;
use \Omeka\Api\Representation\MediaRepresentation;

class ResourceMapRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLd()
    {
        return [
            'resource_id' => $this->resource()->id(),
            'classic_resource_id' => $this->classicResourceId(),
            'resource_name' => $this->mappedResourceName(),
        ];
    }

    public function getJsonLdType()
    {
        return 'o:ClassicImporterResourceMap';
    }

    /**
     * Return the resource representation .
     *
     * @return ItemRepresentation | ItemSetRepresentation | MediaRepresentation | null
     */
    public function resource()
    {
        $resourceName = $this->mappedResourceName();
        $adapterName = '';
        switch ($resourceName) {
            case 'item':
                $adapterName = 'items';
                break;
            case 'item_set':
                $adapterName = 'item_sets';
                break;
            case 'media':
                $adapterName = 'media';
                break;
            default:
                return null;
        }
        return $this->getAdapter($adapterName)
            ->getRepresentation($this->resource->getResource());
    }

    public function mappedResourceName()
    {
        return $this->resource->getMappedResourceName();
    }

    public function classicResourceId()
    {
        return $this->resource->getClassicResourceId();
    }

    /* no admin url needed
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
    }*/
}
