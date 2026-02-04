<?php

namespace ClassicImporter\Job;

use Omeka\Job\AbstractJob;
use ClassicImporter\Entity\ClassicImporterImport;

class ImportFromDumpJob extends AbstractJob
{
    /**
     * @var array
     */
    protected $propertiesToAddLater;

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
    protected $stats;

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

        if (empty($dumpManager))
        {
            $logger->error('Could not find Dump Manager service.');
            $this->hasErr = true;
            $this->endJob();
            return;
        }

        $sql =
            <<<'SQL'
            SELECT DISTINCT
                element_sets.name AS element_set_name,
                elements.name AS element_name,
                elements.id AS element_id
            FROM element_texts
                LEFT JOIN elements ON elements.id = element_texts.element_id
                LEFT JOIN element_sets ON elements.element_set_id = element_sets.id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $properties = $stmt->fetchAllAssociative();

        $sql =
            <<<'SQL'
            SELECT item_types.id, item_types.name, item_types.description FROM items
                INNER JOIN item_types ON items.item_type_id = item_types.id;
            SQL;

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        try {
            $this->importResourcesFromDump($dumpManager->getConn(), $properties, $resourceClasses);
        }
        catch (\Exception $e) {
            $logger->err(sprintf("Error: %s", $e->getMessage()));
            $this->hasErr = true;
        }
        
        $dumpManager->deleteDumpDatabase();

        $logger->info('Dump database deleted.');

        $logger->info('Job ended');

        $this->endJob();
    }

    public function importResourcesFromDump($dumpConn, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        if ($this->getArg('import_collections') == '1')
            $this->importItemSetsFromDump($dumpConn, $properties, $resourceClasses);

        $this->importItemsFromDump($dumpConn, $properties, $resourceClasses);

        $this->importUrisFromDump();
    }

    protected function importCollectionsTreeFromDump($dumpConn)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $sql =
            <<<'SQL'
            SELECT collections.id, collection_trees.parent_collection_id FROM collections
            LEFT JOIN collection_trees ON collections.id = collection_trees.collection_id
            SQL;

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
                ]
                )->getContent();

                if (!empty($matchingResource)) {
                    $treeEdges = $this->getServiceLocator()->get('Omeka\ApiManager')->search('item_sets_tree_edges',
                    ['item_set_id' => $matchingResource[0]->resource()->id()])->getContent();

                    if (!empty($treeEdges)) {
                        foreach($treeEdges as $treeEdge) {
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
                ]
            )->getContent();

            $matchingTargetResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                [
                    'mapped_resource_name' => 'item_set',
                    'classic_resource_id' => $itemSet['parent_collection_id'],
                ]
            )->getContent();

            if (!empty($matchingResource) && !empty($matchingTargetResource)) {
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

                $parentItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingTargetResource[0]->resource()->id());
                $childItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingResource[0]->resource()->id());
                
                $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets_tree_edges',
                ['o:item_set' => $childItemSet, 'o:parent_item_set' => $parentItemSet]);

                $this->stats['item_sets_tree_edges'] = $this->stats['item_sets_tree_edges'] ? $this->stats['item_sets_tree_edges'] + 1 : 1;
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

                $matchingTargetResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                        [
                            'mapped_resource_name' => $property['target_resource_name'],
                            'classic_resource_id' => $targetId,
                        ]
                    )->getContent();

                $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => $property['resource_name'],
                        'classic_resource_id' => $id,
                    ]
                )->getContent();
                
                if (!empty($matchingResource) && !empty($matchingTargetResource)) {
                    $mappedresourceName = $property['resource_name'];
                    unset($property['mapped_resource_name']);
                    unset($property['o:label']);
                    unset($property['@id']);
                    $property['value_resource_id'] = $matchingTargetResource[0]->resource()->id();

                    if ($mappedresourceName == 'item_set') {
                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingResource[0]->resource()->id(),
                        [$propertyRep->term() => [ $property ]], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = $this->stats['uris'] ? $this->stats['uris'] + 1 : 1;
                    }
                    else if ($mappedresourceName == 'item') {
                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingResource[0]->resource()->id(),
                        [$propertyRep->term() => [ $property ]], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = $this->stats['uris'] ? $this->stats['uris'] + 1 : 1;
                    }
                    else {
                        $logger = $this->getServiceLocator()->get('Omeka\Logger')->warn(
                            sprintf('Invalid target resource type for URI : \'%s.\'', $mappedresourceName));
                    }
                }

                // target resource is invalid so we just register it as a random url.
                else if (!empty($matchingResource)) {
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
                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingResource[0]->resource()->id(),
                        [$propertyRep->term() => [ $property ]], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = $this->stats['uris'] ? $this->stats['uris'] + 1 : 1;
                    }
                    else if ($mappedresourceName == 'item') {
                        $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingResource[0]->resource()->id(),
                        [$propertyRep->term() => [ $property ]], [], ['isPartial' => true, 'collectionAction' => 'append']);

                        $this->stats['uris'] = $this->stats['uris'] ? $this->stats['uris'] + 1 : 1;
                    }
                }
            }
        }

        if (!empty($this->propertiesToAddLater)) {
            $logger->info('URIs towards resources successfully imported.');
        }
    }

    protected function importItemSetsFromDump($dumpConn, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        $sql =
            <<<'SQL'
            SELECT * FROM collections;
            SQL;


        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();
        
        foreach ($itemSets as $itemSet) {
            $sql = sprintf(
            <<<'SQL'
            SELECT element_texts.element_id, element_texts.text, elements.name FROM collections
                LEFT JOIN element_texts AS element_texts ON element_texts.record_id = collections.id
                LEFT JOIN elements AS elements ON elements.id = element_texts.element_id
                WHERE element_texts.record_type = 'Collection'
                AND collections.id = %s;
            SQL, $itemSet['id']
            );

            $stmt = $dumpConn->executeQuery($sql);
            $propertyValues = $stmt->fetchAllAssociative();

            $itemSetData = [
                // collections don't have classes in Omeka so don't try to map any
                // no owner to be set either
                'o:is_public' => strval($itemSet['public']),
            ];

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']]))
                {
                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];
                    if (!is_array($propertyIds))
                    {
                        $propertyIds = [ $propertyIds ];
                    }

                    $transformedProperty = [];
                    if ($this->getArg('transform_uris')[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }

                    foreach ($propertyIds as $propertyId)
                    {
                        $term = $this->getServiceLocator()->get('Omeka\ApiManager')->read('properties', $propertyId)->getContent()->term();

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemSetData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => ($this->getArg('preserve_html')[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        }
                        else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($itemSet['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item_set',
                                    ],
                                    $transformedProperty);
                                }
                            else {
                                $itemSetData[$term] =
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
                $matchingItemSets = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'classic_resource_id' => $itemSet['id'],
                    ]
                )->getContent();
                if (!empty($matchingItemSets))
                {
                    $couldUpdate = true;

                    /* @var \Omeka\Api\Representation\ItemSetRepresentation $matchingItemSet */
                    $matchingItemSet = $matchingItemSets[0]->resource();

                    $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingItemSet->id(), 
                        $itemSetData, [], ['isPartial' => true]);

                    $this->stats['item_sets'] = $this->stats['item_sets'] ? $this->stats['item_sets'] + 1 : 1;
                }
            }

            if (!$couldUpdate)
            {
                /* @var \Omeka\Api\Representation\ItemSetRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets', $itemSetData)->getContent();
                
                $this->getServiceLocator()->get('Omeka\ApiManager')->create('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $itemSet['id'],
                        'o:job' => ['o:id' => $this->job->getId()],
                    ]
                );

                $this->stats['item_sets'] = $this->stats['item_sets'] ? $this->stats['item_sets'] + 1 : 1;
            }
        }

        $logger->info('Item sets successfully imported.');
        if ($this->getArg('import_collections_tree', '0') == '1') {
            $this->importCollectionsTreeFromDump($dumpConn);
            $logger->info('Item sets tree successfully imported.');
        }
    }

    protected function importItemsFromDump($dumpConn, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        $sql =
            <<<'SQL'
            SELECT * FROM items;
            SQL;

        $stmt = $dumpConn->executeQuery($sql);
        $items = $stmt->fetchAllAssociative();
        
        foreach ($items as $item) {
            $sql = sprintf(
            <<<'SQL'
            SELECT element_texts.element_id, element_texts.text, elements.name FROM items
                LEFT JOIN element_texts AS element_texts ON element_texts.record_id = items.id
                LEFT JOIN elements AS elements ON elements.id = element_texts.element_id
                WHERE element_texts.record_type = 'Item'
                AND items.id = %s;
            SQL, $item['id']
            );

            $stmt = $dumpConn->executeQuery($sql);
            $propertyValues = $stmt->fetchAllAssociative();

            $sql = sprintf(
            <<<'SQL'
            SELECT files.id, files.mime_type, files.filename, files.original_filename, files.alt_text, files.size FROM files
                WHERE files.stored = 1
                AND files.item_id = %s;
            SQL, $item['id']
            ); // @TODO check what happens when files.stored is 0.

            $stmt = $dumpConn->executeQuery($sql);
            $files = $stmt->fetchAllAssociative();

            $itemData = [
                'o:is_public' => strval($item['public']),

                // important so API doesn't add one automatically
                'o:site' => [],

            ];

            if (!empty($this->getArg('types_classes')[$item['item_type_id']]))
            {
                $itemData['o:resource_class'] = [ 'o:id' => $this->getArg('types_classes')[$item['item_type_id']] ];
            }

            if ($this->getArg('import_collections') == '1' && isset($item['collection_id'])) {

                /* @var \Omeka\Api\Representation\ItemSetRepresentation[] | null $response*/
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'resource_id' => $item['collection_id'],
                    ]
                )->getContent();

                if (!empty($response))
                {
                    $itemData['o:item_set'] = [ strval($response[0]->resource()->id()) ];
                }
            }

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']]))
                {
                    $transformedProperty = [];
                    if ($this->getArg('transform_uris')[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }
                    else {
                        $this->getServiceLocator()->get('Omeka\Logger')->info("transform uri disabled on element.");
                    }

                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];

                    if (!is_array($propertyIds)) {
                        $propertyIds = [ $propertyIds ];
                    }

                    foreach ($propertyIds as $propertyId)
                    {
                        $term = $this->getServiceLocator()->get('Omeka\ApiManager')->read('properties', $propertyId)->getContent()->term();

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => ($this->getArg('preserve_html')[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        }
                        else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($item['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item',
                                    ],
                                    $transformedProperty);
                                }
                            else {
                                $itemData[$term] =
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
            if (!empty($this->getArg('files_source')))
            {
                // intialization just in case
                if (!empty($files)) {
                    $itemData['o:media'] = [];
                }

                foreach ($files as $file) 
                {
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
                $matchingItems = $this->getServiceLocator()->get('Omeka\ApiManager')->search('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'classic_resource_id' => $item['id'],
                    ]
                )->getContent();
                if (!empty($matchingItems))
                {
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
                        $itemData, [], ['isPartial' => true]);
                }
            }

            if (!$couldUpdate)
            {
                /* @var \Omeka\Api\Representation\ItemRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('items', $itemData)->getContent();
                $this->getServiceLocator()->get('Omeka\ApiManager')->create('classicimporter_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $item['id'],
                        'o:job' => ['o:id' => $this->job->getId()],
                    ]
                );

                $this->stats['items'] = $this->stats['items'] ? $this->stats['items'] + 1 : 1;
            }
            
        }

        if (!empty($this->getArg('files_source')))
        {
            $logger->info('Media succesfully imported.');
        }
        $logger->info('Items successfully imported.');
    }

    protected function cleanTextFromHTML($text) {
        // remove html elements from the text
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    protected function transformValue($value) {
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
        if (count($words) > 1)
        {
            $label = implode(' ', array_slice($words, 0, count($words) - 1));
        }
        $potentialUri = $words[count($words) - 1];

        if (filter_var($potentialUri, FILTER_VALIDATE_URL))
        {
            return $this->transformUrl($potentialUri, $label);
        }

        return [];
    }

    protected function transformUrl($value, $label = '') {
        $urlParsed = parse_url($value);

        if ($urlParsed === false || empty($urlParsed)) {
            return [];
        }

        $urlPath = explode('/', $urlParsed['path']);

        if (count($urlPath) == 4 && $urlParsed['hostname'] == $this->getArg('domaine_name') && $urlPath[2] == 'show') {
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
