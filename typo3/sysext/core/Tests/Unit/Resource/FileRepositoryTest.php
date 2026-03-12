<?php

declare(strict_types=1);

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

namespace TYPO3\CMS\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for {@see FileRepository}, specifically for the findByRelationBatch()
 * method and the findByRelationCache integration with findByRelation().
 *
 * Verifies that:
 * - findByRelation() returns cached results when the cache is pre-populated
 * - findByRelationBatch() skips UIDs that are already cached
 * - findByRelationBatch() initialises empty arrays for UIDs without references
 * - findByRelationBatch() groups references by uid_foreign and creates FileReference objects
 * - findByRelationBatch() is a no-op for an empty UID list
 */
#[CoversClass(FileRepository::class)]
final class FileRepositoryTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /**
     * Build a FileRepository mock with getEnvironmentMode() returning 'BE'
     * (backend mode) so that FrontendRestrictionContainer is not applied.
     * The factory property is set to a ResourceFactory mock for object creation.
     */
    private function createSubject(): FileRepository
    {
        $subject = $this->getMockBuilder(FileRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEnvironmentMode'])
            ->getMock();
        $subject->method('getEnvironmentMode')->willReturn('BE');

        // Inject a ResourceFactory mock via reflection since the constructor was disabled
        $factory = $this->createMock(ResourceFactory::class);
        $factoryReflection = new \ReflectionProperty(FileRepository::class, 'factory');
        $factoryReflection->setValue($subject, $factory);

        return $subject;
    }

    /**
     * Create a QueryBuilder mock that returns the given rows from executeQuery()->fetchAllAssociative().
     * All fluent methods (select, from, where, orderBy) return $this for chaining.
     *
     * @param array<int, array<string, mixed>> $resultRows Rows returned by the mocked query
     */
    private function createQueryBuilderMock(array $resultRows): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('setRestrictions')->willReturnSelf();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $expressionBuilder->method('in')->willReturn('1=1');
        $expressionBuilder->method('eq')->willReturn('1=1');
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static fn(mixed $value): string => is_array($value) ? implode(',', $value) : (string)$value
        );

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryBuilder->method('executeQuery')->willReturn($statement);
        $statement->method('fetchAllAssociative')->willReturn($resultRows);

        return $queryBuilder;
    }

    /**
     * When findByRelationCache is pre-populated (e.g. by findByRelationBatch()),
     * findByRelation() must return the cached value without issuing any DB query.
     */
    #[Test]
    public function findByRelationReturnsCachedResultWhenCacheIsPopulated(): void
    {
        $subject = $this->createSubject();

        $fileRef = $this->createMock(FileReference::class);
        $cacheData = [$fileRef];

        // Pre-populate the cache using reflection
        $reflection = new \ReflectionProperty(FileRepository::class, 'findByRelationCache');
        $reflection->setValue($subject, ['tt_content_media_42_-1' => $cacheData]);

        $result = $subject->findByRelation('tt_content', 'media', 42);

        self::assertSame($cacheData, $result);
    }

    /**
     * findByRelationBatch() must be a no-op when called with an empty UID array —
     * no database queries should be executed and no cache entries created.
     */
    #[Test]
    public function findByRelationBatchDoesNothingForEmptyUids(): void
    {
        $subject = $this->createSubject();

        // No ConnectionPool should be instantiated
        $subject->findByRelationBatch('tt_content', 'media', []);

        $reflection = new \ReflectionProperty(FileRepository::class, 'findByRelationCache');
        self::assertSame([], $reflection->getValue($subject));
    }

    /**
     * When all requested UIDs are already in the cache, findByRelationBatch()
     * must skip the DB query entirely and not overwrite existing cache entries.
     */
    #[Test]
    public function findByRelationBatchSkipsAlreadyCachedUids(): void
    {
        $subject = $this->createSubject();

        $fileRef = $this->createMock(FileReference::class);
        $existingCache = ['tt_content_media_10_-1' => [$fileRef]];

        $reflection = new \ReflectionProperty(FileRepository::class, 'findByRelationCache');
        $reflection->setValue($subject, $existingCache);

        // Should not hit DB since UID 10 is already cached
        $subject->findByRelationBatch('tt_content', 'media', [10]);

        // Cache must remain unchanged
        self::assertSame($existingCache, $reflection->getValue($subject));
    }

    /**
     * findByRelationBatch() must initialise cache entries with empty arrays
     * for UIDs that have no matching sys_file_reference rows. This ensures
     * that subsequent findByRelation() calls get a cache hit (empty result)
     * instead of firing another DB query.
     */
    #[Test]
    public function findByRelationBatchInitialisesEmptyArraysForUidsWithoutReferences(): void
    {
        $subject = $this->createSubject();

        $connectionPool = $this->createMock(ConnectionPool::class);
        $queryBuilder = $this->createQueryBuilderMock([]);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);

        $subject->findByRelationBatch('tt_content', 'media', [10, 20]);

        $reflection = new \ReflectionProperty(FileRepository::class, 'findByRelationCache');
        $cache = $reflection->getValue($subject);

        self::assertArrayHasKey('tt_content_media_10_-1', $cache);
        self::assertArrayHasKey('tt_content_media_20_-1', $cache);
        self::assertSame([], $cache['tt_content_media_10_-1']);
        self::assertSame([], $cache['tt_content_media_20_-1']);
    }

    /**
     * findByRelationBatch() must group sys_file_reference rows by uid_foreign
     * and create FileReference objects for each row via ResourceFactory.
     * Verifies that the correct cache keys are populated with the right objects.
     */
    #[Test]
    public function findByRelationBatchGroupsReferencesByUidForeign(): void
    {
        $subject = $this->createSubject();

        $connectionPool = $this->createMock(ConnectionPool::class);
        $queryBuilder = $this->createQueryBuilderMock([
            ['uid' => 100, 'uid_foreign' => 10, 'sorting_foreign' => 1],
            ['uid' => 101, 'uid_foreign' => 10, 'sorting_foreign' => 2],
            ['uid' => 200, 'uid_foreign' => 20, 'sorting_foreign' => 1],
        ]);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);

        $fileRef100 = $this->createMock(FileReference::class);
        $fileRef101 = $this->createMock(FileReference::class);
        $fileRef200 = $this->createMock(FileReference::class);

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileReferenceObject')->willReturnMap([
            [100, ['uid' => 100, 'uid_foreign' => 10, 'sorting_foreign' => 1], false, $fileRef100],
            [101, ['uid' => 101, 'uid_foreign' => 10, 'sorting_foreign' => 2], false, $fileRef101],
            [200, ['uid' => 200, 'uid_foreign' => 20, 'sorting_foreign' => 1], false, $fileRef200],
        ]);

        // Inject factory via reflection (protected property on AbstractRepository)
        $factoryReflection = new \ReflectionProperty(FileRepository::class, 'factory');
        $factoryReflection->setValue($subject, $factory);

        $subject->findByRelationBatch('tt_content', 'media', [10, 20]);

        $reflection = new \ReflectionProperty(FileRepository::class, 'findByRelationCache');
        $cache = $reflection->getValue($subject);

        self::assertCount(2, $cache['tt_content_media_10_-1']);
        self::assertSame($fileRef100, $cache['tt_content_media_10_-1'][0]);
        self::assertSame($fileRef101, $cache['tt_content_media_10_-1'][1]);
        self::assertCount(1, $cache['tt_content_media_20_-1']);
        self::assertSame($fileRef200, $cache['tt_content_media_20_-1'][0]);
    }

    /**
     * After findByRelationBatch() pre-populates the cache, a subsequent
     * findByRelation() call for the same table/field/uid must return the
     * cached FileReference objects without any additional DB query.
     */
    #[Test]
    public function findByRelationReturnsBatchPopulatedCache(): void
    {
        $subject = $this->createSubject();

        $connectionPool = $this->createMock(ConnectionPool::class);
        $queryBuilder = $this->createQueryBuilderMock([
            ['uid' => 100, 'uid_foreign' => 10, 'sorting_foreign' => 1],
        ]);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);

        $fileRef = $this->createMock(FileReference::class);
        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileReferenceObject')->willReturn($fileRef);

        $factoryReflection = new \ReflectionProperty(FileRepository::class, 'factory');
        $factoryReflection->setValue($subject, $factory);

        $subject->findByRelationBatch('tt_content', 'media', [10]);

        // This must return the cached result from batch without DB access
        $result = $subject->findByRelation('tt_content', 'media', 10);

        self::assertCount(1, $result);
        self::assertSame($fileRef, $result[0]);
    }
}
