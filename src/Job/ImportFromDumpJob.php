<?php

namespace ClassicImporter\Job;

use Omeka\Job\AbstractJob;
use ClassicImporter\Entity\ClassicImporterImport;

class ImportFromDumpJob extends AbstractJob
{
    /**
     * @var array
     */
    protected $propertiesToAddLater = [];

    /**
     * @var ClassicImporterImport
     */
    protected $importRecord;

    /**
     * @var bool
     */
    protected $hasErr = false;

    /**
     * @var array
     */
    protected $stats = [];

    /**
     * @var array
     */
    protected $propertyTermCache = [];

    /**
     * @var int
     */
    protected $updatedJobId;

    /**
     * @var array
     */
    protected $updatedResources = ['items' => [], 'item_sets' => []];

    public function perform()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Job started');

        $importJson = [
            'o:job' => ['o:id' => $this->job->getId()],
            'has_err' => false,
            'stats' => [],
        ];
        $response = $this->serviceLocator->get('Omeka\ApiManager')->create('classicimporter_imports', $importJson);
        $this->importRecord = $response->getContent();

        $dumpManager = $this->serviceLocator->get('ClassicImporter\DumpManager');

        if ($dumpManager->getConn() === null) {
            $logger->err('Could not connect to the Omeka Classic database: ' . $dumpManager->getErrorMessage());
            $this->hasErr = true;
            $this->endJob();
            return;
        }

        $p = $dumpManager->getTablePrefix();

        $sql = sprintf(
            'SELECT DISTINCT
                %1$selement_sets.name AS element_set_name,
                %1$selements.name AS element_name,
                %1$selements.id AS element_id
            FROM %1$selement_texts
                LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                LEFT JOIN %1$selement_sets ON %1$selements.element_set_id = %1$selement_sets.id',
            $p
        );

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $properties = $stmt->fetchAllAssociative();

        $sql = sprintf(
            'SELECT %1$sitem_types.id, %1$sitem_types.name, %1$sitem_types.description
            FROM %1$sitems
                INNER JOIN %1$sitem_types ON %1$sitems.item_type_id = %1$sitem_types.id',
            $p
        );

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        if ($this->getArg('update') == '1') {
            if (empty($this->getArg('updated_job_id'))) {
                $logger->err(("Error: no previous imports found.")); // @translate
                $this->hasErr = true;

                $logger->info('Job ended');

                $this->endJob();

                return;
            } else {
                $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
                    ->search('classicimporter_imports', ['job_id' => $this->getArg('updated_job_id')])->getContent();

                if (empty($updatedJob) || empty($updatedJob[0])) {
                    $this->hasErr = true;
                    $logger->err(sprintf('Invalid import job id \'%s\'.', $this->getArg('updated_job_id'))); // @ translate
                    $this->endJob();
                    return;
                }

                $this->updatedJobId = $updatedJob[0]->job()->id();
            }
        }

        try {
            $this->importResourcesFromDump($dumpManager, $properties, $resourceClasses);
        } catch (\Exception $e) {
            $logger->err(sprintf("Error: %s", $e->getMessage()));
            $this->hasErr = true;
        }

        $logger->info('Job ended');

        $this->endJob();
    }

    public function importResourcesFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        if ($this->getArg('import_collections') == '1') {
            $this->importItemSetsFromDump($dumpManager, $properties, $resourceClasses);
        }

        $this->importItemsFromDump($dumpManager, $properties, $resourceClasses);

        $this->importUrisFromDump();

        if ($this->getArg('update') == '1') {
            $this->cleanMissingResources();
        }
    }

    protected function cleanMissingResources()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        $resources = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
            ['job_id' => $this->updatedJobId])->getContent();

        foreach ($resources as $resource) {
            if ($resource->mappedResourceName() == 'item') {
                if (!in_array($resource->classicResourceId(), $this->updatedResources['items'])) {
                    $this->getServiceLocator()->get('Omeka\ApiManager')->delete('classicimporter_resource_maps', $resource->id());
                    $this->getServiceLocator()->get('Omeka\ApiManager')->delete('items', $resource->resource()->id());
                }
            }
            if ($resource->mappedResourceName() == 'item_set') {
                if (!in_array($resource->classicResourceId(), $this->updatedResources['item_sets'])) {
                    $this->getServiceLocator()->get('Omeka\ApiManager')->delete('classicimporter_resource_maps', $resource->id());
                    $this->getServiceLocator()->get('Omeka\ApiManager')->delete('item_sets', $resource->resource()->id());
                }
            }
        }

        $logger->info('Deleted resources not present in update database anymore.');
    }

    protected function importCollectionsTreeFromDump($dumpManager)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $sql = sprintf(
            'SELECT %1$scollections.id, %1$scollection_trees.parent_collection_id
            FROM %1$scollections
            LEFT JOIN %1$scollection_trees ON %1$scollections.id = %1$scollection_trees.collection_id',
            $p
        );

        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();

        // If we're updating from a previous import,
        // we must delete every item sets tree branch before to reset it from scratch.
        // This is to avoid bugs and impossible trees.
        if ($this->getArg('update') == '1') {
            foreach ($itemSets as $itemSet) {
                $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                [
                    'mapped_resource_name' => 'item_set',
                    'classic_resource_id' => $itemSet['id'],
                    'job_id' => $this->updatedJobId,
                ]
                )->getContent();

                if (!empty($matchingResource)) {
                    $treeEdges = $this->getServiceLocator()->get('Omeka\ApiManager')->search('item_sets_tree_edges',
                    ['item_set_id' => $matchingResource[0]->resource()->id()])->getContent();

                    if (!empty($treeEdges)) {
                        foreach ($treeEdges as $treeEdge) {
                            $this->getServiceLocator()->get('Omeka\ApiManager')->delete('item_sets_tree_edges', $treeEdge->id());
                        }
                    }
                }
            }
        }

        foreach ($itemSets as $itemSet) {
            if (!$itemSet['parent_collection_id']) {
                continue;
            }

            $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
            [
                'mapped_resource_name' => 'item_set',
                'classic_resource_id' => $itemSet['id'],
                'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
            ]
            )->getContent();

            $matchingTargetResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
            [
                'mapped_resource_name' => 'item_set',
                'classic_resource_id' => $itemSet['parent_collection_id'],
                'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
            ]
            )->getContent();

            if (!empty($matchingResource) && !empty($matchingTargetResource)) {
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

                $parentItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingTargetResource[0]->resource()->id());
                $childItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingResource[0]->resource()->id());

                $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets_tree_edges',
                ['o:item_set' => $childItemSet, 'o:parent_item_set' => $parentItemSet]);

                $this->stats['item_sets_tree_edges'] = ($this->stats['item_sets_tree_edges'] ?? 0) + 1;
            }
        }
    }

    protected function importUrisFromDump()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        foreach ($this->propertiesToAddLater as $id => $properties) {
            foreach ($properties as $property) {
                if ($property['type'] != 'resource') {
                    continue;
                }
                $targetId = $property['value_resource_id'];

                $propertyRep = $this->getServiceLocator()->get('Omeka\ApiManager')->read('properties', $id)->getContent();
                if (empty($propertyRep)) {
                    continue;
                }
                $this->propertyTermCache[$id] = $propertyRep->term();

                $matchingTargetResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => $property['target_resource_name'],
                        'classic_resource_id' => $targetId,
                        'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
                    ]
                )->getContent();

                $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => $property['resource_name'],
                        'classic_resource_id' => $id,
                        'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
                    ]
                )->getContent();

                $mappedresourceName = $property['resource_name'];

                if (!empty($matchingResource) && !empty($matchingTargetResource)) {
                    unset($property['mapped_resource_name']);
                    unset($property['o:label']);
                    unset($property['@id']);
                    $property['value_resource_id'] = $matchingTargetResource[0]->resource()->id();

                    if ($mappedresourceName == 'item_set') {
                        $this->clearResourcePropertyValues($matchingResource[0], $propertyRep);

                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingResource[0]->resource()->id(),
                        [ $propertyRep->term() => [ $property ] ], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = ($this->stats['uris'] ?? 0) + 1;
                    } elseif ($mappedresourceName == 'item') {
                        $this->clearResourcePropertyValues($matchingResource[0], $propertyRep);

                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingResource[0]->resource()->id(),
                        [ $propertyRep->term() => [ $property ] ], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = ($this->stats['uris'] ?? 0) + 1;
                    } else {
                        $logger = $this->getServiceLocator()->get('Omeka\Logger')->warn(
                            sprintf('Invalid target resource type for URI : \'%s.\'', $mappedresourceName));
                    }
                }

                // target resource is invalid so we just register it as a random url.
                elseif (!empty($matchingResource)) {
                    $property =
                    [
                        'property_id' => $property['property_id'],
                        'is_public' => '1',
                        'type' => 'uri',
                        '@annotation' => null,
                        'o:lang' => '',
                        '@id' => $property['@id'],
                        'o:label' => $property['o:label'],
                    ];
                    if ($mappedresourceName == 'item_set') {
                        $this->clearResourcePropertyValues($matchingResource[0], $propertyRep);

                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingResource[0]->resource()->id(),
                        [ $propertyRep->term() => [ $property ] ], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = ($this->stats['uris'] ?? 0) + 1;
                    } elseif ($mappedresourceName == 'item') {
                        $this->clearResourcePropertyValues($matchingResource[0], $propertyRep);

                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingResource[0]->resource()->id(),
                        [ $propertyRep->term() => [ $property ] ], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = ($this->stats['uris'] ?? 0) + 1;
                    }
                }
            }
        }

        if (!empty($this->propertiesToAddLater)) {
            $logger->info('URIs towards resources successfully imported.');
        }
    }

    protected function clearResourcePropertyValues($resource, $property)
    {
        $em = $this->getServiceLocator()->get('Omeka\EntityManager');

        $builder = $em->createQueryBuilder();
        $builder->delete(\Omeka\Entity\Value::class, 'root')
            ->where('root.resource = :resource_id')
            ->andWhere('root.property = :property_id')
            ->setParameter('resource_id', $resource->id())
            ->setParameter('property_id', $property->id())
            ->getQuery()
            ->execute();

        $em->flush();
    }

    protected function importItemSetsFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $sql = sprintf('SELECT * FROM %scollections', $p);

        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();

        foreach ($itemSets as $itemSet) {
            $sql = sprintf(
                'SELECT %1$selement_texts.element_id, %1$selement_texts.text, %1$selements.name
                FROM %1$scollections
                    LEFT JOIN %1$selement_texts ON %1$selement_texts.record_id = %1$scollections.id
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                WHERE %1$selement_texts.record_type = \'Collection\'
                AND %1$scollections.id = ?',
                $p
            );

            $stmt = $dumpConn->executeQuery($sql, [$itemSet['id']]);
            $propertyValues = $stmt->fetchAllAssociative();

            $itemSetData = [
                // collections don't have classes in Omeka so don't try to map any
                // no owner to be set either
                'o:is_public' => strval($itemSet['public']),
            ];

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']])) {
                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];
                    if (!is_array($propertyIds)) {
                        $propertyIds = [ $propertyIds ];
                    }

                    $transformedProperty = [];
                    if (($this->getArg('transform_uris') ?? [])[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }

                    foreach ($propertyIds as $propertyId) {
                        $term = $this->getPropertyTerm($propertyId);

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemSetData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => (($this->getArg('preserve_html') ?? [])[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        } else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($itemSet['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item_set',
                                    ],
                                    $transformedProperty);
                            } else {
                                $itemSetData[$term][] =
                                array_merge(
                                [
                                    'property_id' => intval($propertyId),
                                    'is_public' => '1',
                                ],
                                $transformedProperty);
                            }
                        }
                    }
                }
            }

            $couldUpdate = false;
            if ($this->getArg('update') == '1') {
                $this->updatedResources['item_sets'][] = $itemSet['id'];
                $matchingItemSets = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'classic_resource_id' => $itemSet['id'],
                        'job_id' => $this->updatedJobId,
                    ]
                )->getContent();
                if (!empty($matchingItemSets)) {
                    $couldUpdate = true;

                    /* @var \Omeka\Api\Representation\ItemSetRepresentation $matchingItemSet */
                    $matchingItemSet = $matchingItemSets[0]->resource();

                    $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingItemSet->id(),
                        $itemSetData);

                    $this->stats['item_sets'] = ($this->stats['item_sets'] ?? 0) + 1;
                }
            }

            if (!$couldUpdate) {
                /* @var \Omeka\Api\Representation\ItemSetRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets', $itemSetData)->getContent();

                $this->getServiceLocator()->get('Omeka\ApiManager')->create('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $itemSet['id'],
                        'o:job' => ['o:id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId()],
                    ]
                );

                $this->stats['item_sets'] = ($this->stats['item_sets'] ?? 0) + 1;
            }
        }

        $logger->info('Item sets successfully imported.');
        if ($this->getArg('import_collections_tree', '0') == '1') {
            $this->importCollectionsTreeFromDump($dumpManager);
            $logger->info('Item sets tree successfully imported.');
        }
    }

    protected function importItemsFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $hasAltText = $dumpManager->hasColumn($p . 'files', 'alt_text');

        $sql = sprintf('SELECT * FROM %sitems', $p);

        $stmt = $dumpConn->executeQuery($sql);
        $items = $stmt->fetchAllAssociative();

        foreach ($items as $item) {
            $sql = sprintf(
                'SELECT %1$selement_texts.element_id, %1$selement_texts.text, %1$selements.name
                FROM %1$sitems
                    LEFT JOIN %1$selement_texts ON %1$selement_texts.record_id = %1$sitems.id
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                WHERE %1$selement_texts.record_type = \'Item\'
                AND %1$sitems.id = ?',
                $p
            );

            $stmt = $dumpConn->executeQuery($sql, [$item['id']]);
            $propertyValues = $stmt->fetchAllAssociative();

            $altTextCol = $hasAltText ? ', %1$sfiles.alt_text' : '';
            $sql = sprintf(
                'SELECT %1$sfiles.id, %1$sfiles.mime_type, %1$sfiles.filename,
                    %1$sfiles.original_filename, %1$sfiles.size' . $altTextCol . '
                FROM %1$sfiles
                WHERE %1$sfiles.stored = 1
                AND %1$sfiles.item_id = ?',
                $p
            ); // @TODO check what happens when files.stored is 0.

            $stmt = $dumpConn->executeQuery($sql, [$item['id']]);
            $files = $stmt->fetchAllAssociative();

            $itemData = [
                'o:is_public' => strval($item['public']),

                // important so API doesn't add one automatically
                'o:site' => [],

            ];

            if (!empty($this->getArg('types_classes')[$item['item_type_id']])) {
                $itemData['o:resource_class'] = [ 'o:id' => $this->getArg('types_classes')[$item['item_type_id']] ];
            }

            if ($this->getArg('import_collections') == '1' && isset($item['collection_id'])) {

                /* @var \Omeka\Api\Representation\ItemSetRepresentation[] | null $response*/
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'classic_resource_id' => $item['collection_id'],
                        'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
                    ]
                )->getContent();

                if (!empty($response)) {
                    $itemData['o:item_set'] = [['o:id' => intval($response[0]->resource()->id())]];
                }
            }

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']])) {
                    $transformedProperty = [];
                    if (($this->getArg('transform_uris') ?? [])[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }

                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];

                    if (!is_array($propertyIds)) {
                        $propertyIds = [ $propertyIds ];
                    }

                    foreach ($propertyIds as $propertyId) {
                        $term = $this->getPropertyTerm($propertyId);

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => (($this->getArg('preserve_html') ?? [])[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        } else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($item['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item',
                                    ],
                                    $transformedProperty);
                            } else {
                                $itemData[$term][] =
                                array_merge(
                                [
                                    'property_id' => intval($propertyId),
                                    'is_public' => '1',
                                ],
                                $transformedProperty);
                            }
                        }
                    }
                }
            }

            // importing media is optional
            if (!empty($this->getArg('files_source'))) {
                // intialization just in case
                if (!empty($files)) {
                    $itemData['o:media'] = [];
                }

                foreach ($files as $file) {
                    $itemData['o:media'][] = [
                        'o:is_public' => '1',
                        'o:ingester' => 'classicimporter_local',
                        'original_file_action' => 'keep',
                        'ingest_filename' => $this->getArg('files_source') . $file['filename'],
                        'original_filename' => $file['original_filename'],
                    ];
                }
            }

            $couldUpdate = false;
            if ($this->getArg('update') == '1') {
                $this->updatedResources['items'][] = $item['id'];
                $matchingItems = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'classic_resource_id' => $item['id'],
                        'job_id' => $this->updatedJobId,
                    ]
                )->getContent();
                if (!empty($matchingItems)) {
                    $couldUpdate = true;

                    /* @var \Omeka\Api\Representation\ItemSetRepresentation $matchingItem */
                    $matchingItem = $matchingItems[0]->resource();

                    // From [] to unset.
                    // So that we just don't touch the item_sets when updating.
                    // Normally, item_sets are [] when empty
                    // but if we update witout importing item_sets, we don't want to update with [],
                    // we want NOT to touch the item_sets, so we remove the key.
                    if (empty($itemData['o:item_set'])) {
                        unset($itemData['o:item_set']);
                    }

                    $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingItem->id(),
                        $itemData);

                    $this->stats['items'] = ($this->stats['items'] ?? 0) + 1;
                }
            }

            if (!$couldUpdate) {
                /* @var \Omeka\Api\Representation\ItemRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('items', $itemData)->getContent();
                $this->getServiceLocator()->get('Omeka\ApiManager')->create('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $item['id'],
                        'o:job' => ['o:id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId()],
                    ]
                );

                $this->stats['items'] = ($this->stats['items'] ?? 0) + 1;
            }
        }

        if (!empty($this->getArg('files_source'))) {
            $logger->info('Media succesfully imported.');
        }
        $logger->info('Items successfully imported.');
    }

    protected function getPropertyTerm($propertyId): string
    {
        if (!isset($this->propertyTermCache[$propertyId])) {
            $this->propertyTermCache[$propertyId] = $this->getServiceLocator()
                ->get('Omeka\ApiManager')
                ->read('properties', $propertyId)
                ->getContent()
                ->term();
        }

        return $this->propertyTermCache[$propertyId];
    }

    protected function cleanTextFromHTML($text)
    {
        // remove html elements from the text
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    protected function transformValue($value)
    {
        // it's <a ... href="...." ...>...</a> ?
        $matches = [];

        // black magic
        $isHref = preg_match('/^<a\s+(?:[^>]*?\s+)?href="([^"]+)"[^>]*>([^<]*?)<\/a>$/', $value, $matches);

        if ($isHref && count($matches) == 3) {
            $label = $matches[2];
            $url = $matches[1];
            return $this->transformUrl($url, $label);
        }

        $text = $this->cleanTextFromHTML($value);

        // it's a link?
        $words = explode(' ', $value);
        $label = '';
        if (count($words) > 1) {
            $label = implode(' ', array_slice($words, 0, count($words) - 1));
        }
        $potentialUri = $words[count($words) - 1];

        if (filter_var($potentialUri, FILTER_VALIDATE_URL)) {
            return $this->transformUrl($potentialUri, $label);
        }

        return [];
    }

    protected function transformUrl($value, $label = '')
    {
        $urlParsed = parse_url($value);

        if ($urlParsed === false || empty($urlParsed)) {
            return [];
        }

        if (empty($urlParsed['path']) || empty($urlParsed['host'])) {
            return [
                'type' => 'uri',
                '@annotation' => null,
                'o:lang' => '',
                '@id' => $value,
                'o:label' => $label,
            ];
        }

        $urlPath = explode('/', $urlParsed['path']);

        if (count($urlPath) == 4 && $urlParsed['host'] == $this->getArg('domain_name') && $urlPath[2] == 'show') {
            switch ($urlPath[1]) {
                case 'items':
                    return [
                        'type' => 'resource',
                        '@annotation' => null,
                        'value_resource_id' => $urlPath[3],
                        'target_resource_name' => 'item',
                        'o:label' => $label, // in case the item was not a valid id
                        '@id' => $value, // same
                    ];
                case 'collections':
                    return [
                        'type' => 'resource',
                        '@annotation' => null,
                        'value_resource_id' => $urlPath[3],
                        'target_resource_name' => 'item_set',
                        'o:label' => $label, // same
                        '@id' => $value, // same
                    ];
                default:
                    break;
            }
        }

        return [
          'type' => 'uri',
          '@annotation' => null,
          'o:lang' => '',
          '@id' => $value,
          'o:label' => $label,
        ];
    }

    protected function endJob()
    {
        $classicImportJson = [
            'has_err' => $this->hasErr,
            'stats' => $this->stats,
        ];
        $this->serviceLocator->get('Omeka\ApiManager')->update('classicimporter_imports',
            $this->importRecord->id(), $classicImportJson);
    }
}
