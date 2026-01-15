<?php
namespace ClassicImporter\Entity;

use Omeka\Entity\AbstractEntity;

/**
 * @Entity
 */
class ClassicImporterResourceMap extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @OneToOne(
     *     targetEntity="\Omeka\Entity\Resource",
     *     orphanRemoval=true,
     * )
     */
    protected $resource;

    /**
     * @Column(type="integer")
     */
    protected $classicResourceId;

    /**
     * @Column(type="string")
     */
    protected $mappedResourceName;

    public function getResourceName()
    {
        return 'classicimporter_resource_maps';
    }

    public function getId()
    {
        return $this->id;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setResource($resource)
    {
        $this->resource = $resource;
    }

    public function getClassicResourceId()
    {
        return $this->classicResourceId;
    }

    public function setClassicResourceId($classicResourceId)
    {
        $this->classicResourceId = $classicResourceId;
    }

    public function getMappedResourceName()
    {
        return $this->mappedResourceName;
    }

    public function setMappedResourceName($mappedResourceName)
    {
        $this->mappedResourceName = $mappedResourceName;
    }
}
