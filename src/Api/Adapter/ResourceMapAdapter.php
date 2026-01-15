<?php
namespace ClassicImporter\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use ClassicImporter\Api\Representation\ResourceMapRepresentation;
use ClassicImporter\Entity\ClassicImporterResourceMap;

class ResourceMapAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'classicimporter_resource_map';
    }

    public function getEntityClass()
    {
        return ClassicImporterResourceMap::class;
    }

    public function getRepresentationClass()
    {
        return ResourceMapRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['mapped_resource_name'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.mapped_resource_name',
                $this->createNamedParameter($qb, $query['mapped_resource_name']))
            );
        }
        if (isset($query['classic_resource_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.classic_resource_id',
                $this->createNamedParameter($qb, $query['classic_resource_id']))
            );
        }
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if (isset($data['resource_id'])) {
            $entity->setResource($data['resource_id']);
        }

        if (isset($data['mapped_resource_name'])) {
            $entity->setMappedResourceName($data['mapped_resource_name']);
        }

        if (isset($data['classic_resource_id'])) {
            $entity->setClassicResourceId($data['classic_resource_id']);
        }
        // @TODO invalidate hydration if one of fields is missing?
    }
}
