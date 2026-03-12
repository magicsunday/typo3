<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Resource;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Repository for accessing files
 * it also serves as the public API for the indexing part of files in general
 * @extends AbstractRepository<File>
 */
class FileRepository extends AbstractRepository
{
    /**
     * The main object type of this class. In some cases (fileReference) this
     * repository can also return FileReference objects, implementing the
     * common FileInterface.
     *
     * @var string
     */
    protected $objectType = File::class;

    /**
     * Runtime cache for findByRelation() results, also populated by findByRelationBatch().
     *
     * Stores previously resolved FileReference arrays keyed by a composite string
     * of table name, field name, record UID, and workspace ID (format:
     * "{tableName}_{fieldName}_{uid}_{workspaceId}"). This avoids redundant DB
     * queries when the same relation is requested multiple times during a single
     * request — e.g. when FilesProcessor and RelationHandler both resolve the
     * same tt_content media field.
     *
     * @var array<string, FileReference[]>
     */
    protected array $findByRelationCache = [];

    /**
     * Main File object storage table. Note that this repository also works on
     * the sys_file_reference table when returning FileReference objects.
     *
     * @var string
     */
    protected $table = 'sys_file';

    /**
     * Creates an object managed by this repository.
     *
     * @return File
     */
    protected function createDomainObject(array $databaseRow)
    {
        return $this->factory->getFileObject($databaseRow['uid'], $databaseRow);
    }

    /**
     * Find FileReference objects by relation to other records
     *
     * @param string $tableName Table name of the related record
     * @param string $fieldName Field name of the related record
     * @param int $uid The UID of the related record (needs to be the localized uid, as translated IRRE elements relate to them)
     * @param int|null $workspaceId
     * @return array An array of objects, empty if no objects found
     * @throws \InvalidArgumentException
     */
    public function findByRelation($tableName, $fieldName, $uid, ?int $workspaceId = null)
    {
        $cacheKey = $tableName . '_' . $fieldName . '_' . $uid . '_' . ($workspaceId ?? -1);
        if (isset($this->findByRelationCache[$cacheKey])) {
            return $this->findByRelationCache[$cacheKey];
        }

        $itemList = [];
        if (!MathUtility::canBeInterpretedAsInteger($uid)) {
            throw new \InvalidArgumentException(
                'UID of related record has to be an integer. UID given: "' . $uid . '"',
                1316789798
            );
        }
        $referenceUids = [];
        if ($this->getEnvironmentMode() === 'FE') {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_file_reference');

            $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $res = $queryBuilder
                ->select('uid')
                ->from('sys_file_reference')
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid_foreign',
                        $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'tablenames',
                        $queryBuilder->createNamedParameter($tableName)
                    ),
                    $queryBuilder->expr()->eq(
                        'fieldname',
                        $queryBuilder->createNamedParameter($fieldName)
                    )
                )
                ->orderBy('sorting_foreign')
                ->executeQuery();

            while ($row = $res->fetchAssociative()) {
                $referenceUids[] = $row['uid'];
            }
        } else {
            $workspaceId ??= GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'id', 0);
            $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
            $relationHandler->setWorkspaceId($workspaceId);
            $relationHandler->start(
                '',
                'sys_file_reference',
                '',
                $uid,
                $tableName,
                BackendUtility::getTcaFieldConfiguration($tableName, $fieldName)
            );
            if (!empty($relationHandler->tableArray['sys_file_reference'])) {
                $relationHandler->processDeletePlaceholder();
                $referenceUids = $relationHandler->tableArray['sys_file_reference'];
            }
        }
        if (!empty($referenceUids)) {
            foreach ($referenceUids as $referenceUid) {
                try {
                    // Just passing the reference uid, the factory is doing workspace
                    // overlays automatically depending on the current environment
                    $itemList[] = $this->factory->getFileReferenceObject($referenceUid);
                } catch (ResourceDoesNotExistException $exception) {
                    // No handling, just omit the invalid reference uid
                }
            }
            $itemList = $this->reapplySorting($itemList);
        }

        $this->findByRelationCache[$cacheKey] = $itemList;

        return $itemList;
    }

    /**
     * Batch-load sys_file_reference records for multiple parent UIDs in a single query.
     *
     * Pre-populates {@see $findByRelationCache} so that subsequent calls to
     * {@see findByRelation()} return cached results without additional DB queries.
     * UIDs that are already present in the cache are skipped automatically.
     *
     * In frontend context, {@see FrontendRestrictionContainer} is applied so that
     * hidden, deleted, and access-restricted references are excluded — matching
     * the behaviour of findByRelation() in FE mode.
     *
     * Each matching row is converted to a {@see FileReference} object via the
     * ResourceFactory. References pointing to non-existing files are silently
     * discarded (same as findByRelation()).
     *
     * @param string $tableName Parent table (e.g. 'pages', 'tt_content')
     * @param string $fieldName Field name (e.g. 'media', 'image', 'assets')
     * @param int[]  $uids      Parent record UIDs to batch-load references for
     */
    public function findByRelationBatch(string $tableName, string $fieldName, array $uids): void
    {
        if ($uids === []) {
            return;
        }

        // Filter out UIDs that are already cached
        $uncachedUids = [];
        foreach ($uids as $uid) {
            $cacheKey = $tableName . '_' . $fieldName . '_' . $uid . '_-1';
            if (!isset($this->findByRelationCache[$cacheKey])) {
                $uncachedUids[] = (int)$uid;
            }
        }

        if ($uncachedUids === []) {
            return;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        if ($this->getEnvironmentMode() === 'FE') {
            $queryBuilder->setRestrictions(
                GeneralUtility::makeInstance(FrontendRestrictionContainer::class)
            );
        }

        $rows = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($uncachedUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter($tableName)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter($fieldName)
                )
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()
            ->fetchAllAssociative();

        // Initialize all requested UIDs with empty arrays
        foreach ($uncachedUids as $uid) {
            $cacheKey = $tableName . '_' . $fieldName . '_' . $uid . '_-1';
            $this->findByRelationCache[$cacheKey] = [];
        }

        // Group rows by uid_foreign and create FileReference objects
        foreach ($rows as $row) {
            $cacheKey = $tableName . '_' . $fieldName . '_' . (int)$row['uid_foreign'] . '_-1';
            try {
                $this->findByRelationCache[$cacheKey][] = $this->factory->getFileReferenceObject((int)$row['uid'], $row);
            } catch (ResourceDoesNotExistException $e) {
                // File reference points to non-existing file — skip silently
            }
        }
    }

    /**
     * Find FileReference objects by uid
     *
     * @param int $uid The UID of the sys_file_reference record
     * @return FileReference|bool
     * @throws \InvalidArgumentException
     */
    public function findFileReferenceByUid($uid)
    {
        if (!MathUtility::canBeInterpretedAsInteger($uid)) {
            throw new \InvalidArgumentException('The UID of record has to be an integer. UID given: "' . $uid . '"', 1316889798);
        }
        try {
            $fileReferenceObject = $this->factory->getFileReferenceObject($uid);
        } catch (\InvalidArgumentException $exception) {
            $fileReferenceObject = false;
        }
        return $fileReferenceObject;
    }

    /**
     * As sorting might have changed due to workspace overlays, PHP does the sorting again.
     */
    protected function reapplySorting(array $itemList): array
    {
        uasort(
            $itemList,
            static function (FileReference $a, FileReference $b) {
                $sortA = (int)$a->getReferenceProperty('sorting_foreign');
                $sortB = (int)$b->getReferenceProperty('sorting_foreign');

                if ($sortA === $sortB) {
                    return 0;
                }

                return ($sortA < $sortB) ? -1 : 1;
            }
        );
        return $itemList;
    }
}
