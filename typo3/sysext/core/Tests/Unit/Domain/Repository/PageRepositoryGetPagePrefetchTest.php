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

namespace TYPO3\CMS\Core\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for the getPage() runtime cache pre-population added to
 * {@see PageRepository::getSubpagesForPages()}.
 *
 * Verifies that:
 * - getSubpagesForPages() populates the getPage() runtime cache for each result page
 * - Cache population is skipped when $fields !== '*'
 * - Cache population is skipped when $disableGroupAccessCheck is true
 * - Existing cache entries are not overwritten by the prefetch
 */
#[CoversClass(PageRepository::class)]
final class PageRepositoryGetPagePrefetchTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /**
     * Create a PageRepository mock with constructor disabled and essential
     * methods stubbed. The DB layer (ConnectionPool/QueryBuilder) is mocked
     * via GeneralUtility::addInstance() so that getSubpagesForPages() can
     * execute without a real database connection.
     *
     * @param array<int, array<string, mixed>> $dbRows    Rows returned by the mocked DB query
     * @param array<int, array<string, mixed>> $overlayResult Rows returned by getPagesOverlay()
     * @param VariableFrontend                 $cache     The runtime cache mock
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&PageRepository
     */
    private function createSubject(array $dbRows, array $overlayResult, VariableFrontend $cache): PageRepository
    {
        // Set up TCA required by getSubpagesForPages()
        $GLOBALS['TCA']['pages']['ctrl']['languageField'] = 'sys_language_uid';

        // Mock ConnectionPool + QueryBuilder chain
        $connectionPool = $this->createMock(ConnectionPool::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $expressionBuilder->method('in')->willReturn('1=1');
        $expressionBuilder->method('eq')->willReturn('1=1');
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static fn(mixed $value): string => is_array($value) ? implode(',', $value) : (string)$value
        );

        // Mock the DB result to return page rows one at a time via fetchAssociative()
        $statement = $this->createMock(Result::class);
        $queryBuilder->method('executeQuery')->willReturn($statement);
        $returnSequence = array_merge($dbRows, [false]);
        $statement->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$returnSequence);

        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);
        GeneralUtility::addInstance(WorkspaceRestriction::class, $this->createMock(WorkspaceRestriction::class));

        $subject = $this->getAccessibleMock(
            PageRepository::class,
            ['getMultipleGroupsWhereClause', 'getRuntimeCache', 'getPagesOverlay', 'versionOL', 'addMountPointParameterToPage', 'checkValidShortcutOfPage'],
            [],
            '',
            false
        );
        $subject->_set('context', new Context());
        $subject->_set('where_hid_del', ' AND pages.deleted=0');
        $subject->_set('where_groupAccess', '');
        $subject->_set('sys_language_uid', 0);
        $subject->_set('versioningWorkspaceId', 0);
        $subject->method('getMultipleGroupsWhereClause')->willReturn(' AND 1=1');
        $subject->method('getRuntimeCache')->willReturn($cache);
        $subject->method('getPagesOverlay')->willReturn($overlayResult);

        // versionOL does nothing (no workspace overlay needed)
        $subject->method('versionOL');

        // addMountPointParameterToPage and checkValidShortcutOfPage pass through
        $subject->method('addMountPointParameterToPage')->willReturnArgument(0);
        $subject->method('checkValidShortcutOfPage')->willReturnArgument(0);

        return $subject;
    }

    /**
     * After getSubpagesForPages() runs with fields='*' and group access enabled,
     * the runtime cache must contain entries for each returned page UID using
     * the same cache key format as getPage().
     */
    #[Test]
    public function getSubpagesForPagesPopulatesGetPageCache(): void
    {
        $cache = $this->createMock(VariableFrontend::class);

        $pages = [
            ['uid' => 10, 'pid' => 1, 'title' => 'Page 10'],
            ['uid' => 20, 'pid' => 1, 'title' => 'Page 20'],
        ];

        $subject = $this->createSubject($pages, $pages, $cache);

        // Cache returns false (no existing entry) so set() should be called
        $cache->method('get')->willReturn(false);

        $setCalls = [];
        $cache->method('set')->willReturnCallback(function (string $id, mixed $data) use (&$setCalls): void {
            $setCalls[$id] = $data;
        });

        $result = $subject->_call('getSubpagesForPages', [1], '*', 'sorting', '', true, true, false);

        self::assertSame($pages, $result);

        // Verify cache was populated for page UIDs
        $prefetchKeys = array_filter(
            array_keys($setCalls),
            static fn(string $key): bool => str_starts_with($key, 'PageRepository_getPage_')
        );
        self::assertCount(2, $prefetchKeys);
    }

    /**
     * When getSubpagesForPages() is called with a specific field list (not '*'),
     * cache population must be skipped because the cached data shape would not
     * match what getPage() expects (full row with all columns).
     */
    #[Test]
    public function getSubpagesForPagesSkipsCacheWhenFieldsAreNotWildcard(): void
    {
        $cache = $this->createMock(VariableFrontend::class);

        $pages = [['uid' => 10, 'title' => 'Page 10']];
        $subject = $this->createSubject($pages, $pages, $cache);

        // cache->set must NOT be called for prefetch when fields != '*'
        $cache->expects(self::never())->method('set');
        $cache->method('get')->willReturn(false);

        $subject->_call('getSubpagesForPages', [1], 'uid,title', 'sorting', '', true, true, false);
    }

    /**
     * When getSubpagesForPages() is called with disableGroupAccessCheck=true,
     * cache population must be skipped because the access level would not match
     * the default getPage() call which enforces group access.
     */
    #[Test]
    public function getSubpagesForPagesSkipsCacheWhenGroupAccessCheckDisabled(): void
    {
        $cache = $this->createMock(VariableFrontend::class);

        $pages = [['uid' => 10, 'title' => 'Page 10']];
        $subject = $this->createSubject($pages, $pages, $cache);

        // cache->set must NOT be called for prefetch when groupAccessCheck is disabled
        $cache->expects(self::never())->method('set');
        $cache->method('get')->willReturn(false);

        $subject->_call('getSubpagesForPages', [1], '*', 'sorting', '', true, true, true);
    }

    /**
     * When a cache entry already exists for a page UID, getSubpagesForPages()
     * must not overwrite it. This prevents stale data from a bulk query
     * overriding a more specific result from an earlier getPage() call.
     */
    #[Test]
    public function getSubpagesForPagesDoesNotOverwriteExistingCacheEntries(): void
    {
        $cache = $this->createMock(VariableFrontend::class);

        $pages = [['uid' => 10, 'title' => 'Page 10']];
        $subject = $this->createSubject($pages, $pages, $cache);

        // Simulate existing cache entry — get returns array (truthy)
        $cache->method('get')->willReturn(['uid' => 10, 'title' => 'Already cached']);

        // set() must NOT be called since entry already exists
        $cache->expects(self::never())->method('set');

        $subject->_call('getSubpagesForPages', [1], '*', 'sorting', '', true, true, false);
    }
}
