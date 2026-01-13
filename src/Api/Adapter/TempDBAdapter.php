<?php
namespace ClassicImporter\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use ClassicImporter\Api\Representation\TempDBRepresentation;
use ClassicImporter\Entity\ClassicImporterTempDBEntity;

class TempDBAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'classicimporter_tempdb';
    }

    public function getEntityClass()
    {
        return ClassicImporterTempDBEntity::class;
    }

    public function getRepresentationClass()
    {
        return TempDBRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        /*if (isset($query['job_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.job',
                $this->createNamedParameter($qb, $query['job_id']))
            );
        }
        if (isset($query['site_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.site',
                $this->createNamedParameter($qb, $query['site_id']))
            );
        }
        if (isset($query['entity_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.entity_id',
                $this->createNamedParameter($qb, $query['entity_id']))
            );
        }
        if (isset($query['resource_type'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.resource_type',
                $this->createNamedParameter($qb, $query['resource_type']))
            );
        }*/
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if (isset($data['username'])) {
            $entity->setUsername($data['username']);
        }

        if (isset($data['password'])) {
            $entity->setPassword($data['password']);
        }

        if (isset($data['host'])) {
            $entity->setHost($data['host']);
        }

        if (isset($data['dbname'])) {
            $entity->setDbname($data['dbname']);
        }
    }
}
