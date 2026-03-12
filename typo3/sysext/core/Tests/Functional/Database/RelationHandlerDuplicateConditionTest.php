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

namespace TYPO3\CMS\Core\Tests\Functional\Database;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional test for the duplicate WHERE condition fix in RelationHandler.
 *
 * When foreign_table_field and foreign_match_fields both reference the same
 * column (e.g. "tablenames"), the old code would produce:
 *   WHERE tablenames = 'x' AND tablenames = 'x'
 *
 * The fix skips the duplicate condition in the foreign_match_fields loop.
 *
 * This is a regression test — the behavioral result is identical with and
 * without the fix (duplicate AND is logically equivalent), but the SQL is
 * cleaner and avoids unnecessary query planner overhead.
 */
final class RelationHandlerDuplicateConditionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/RelationHandler/relation-handler-duplicate-condition.csv');
    }

    /**
     * Create a RelationHandler instance with the given current table set
     * via reflection (the property is not publicly writable).
     */
    private function createSubject(string $currentTable): RelationHandler
    {
        $subject = GeneralUtility::makeInstance(RelationHandler::class);

        $property = new \ReflectionProperty($subject, 'currentTable');
        $property->setValue($subject, $currentTable);

        return $subject;
    }

    /**
     * Verify that readForeignField works correctly when foreign_table_field
     * and foreign_match_fields reference the same column. The fix ensures
     * the duplicate WHERE condition is skipped without affecting results.
     */
    #[Test]
    public function readForeignFieldWithOverlappingFieldsReturnsCorrectRelations(): void
    {
        $subject = $this->createSubject('tt_content');

        $conf = [
            'foreign_table' => 'sys_file_reference',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablenames',
            'foreign_match_fields' => [
                'tablenames' => 'tt_content', // overlaps with foreign_table_field
                'fieldname' => 'image',
            ],
        ];

        $method = new \ReflectionMethod($subject, 'readForeignField');
        $method->invoke($subject, 1, $conf);

        self::assertNotEmpty(
            $subject->tableArray['sys_file_reference'] ?? [],
            'Should find related sys_file_reference records'
        );
        self::assertContains(
            1,
            $subject->tableArray['sys_file_reference'],
            'Should find sys_file_reference uid=1'
        );
    }

    /**
     * Verify that non-overlapping foreign_match_fields still produce
     * correct additional WHERE conditions.
     */
    #[Test]
    public function readForeignFieldWithNonOverlappingFieldsReturnsCorrectRelations(): void
    {
        $subject = $this->createSubject('tt_content');

        $conf = [
            'foreign_table' => 'sys_file_reference',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablenames',
            'foreign_match_fields' => [
                'fieldname' => 'image',
            ],
        ];

        $method = new \ReflectionMethod($subject, 'readForeignField');
        $method->invoke($subject, 1, $conf);

        self::assertNotEmpty(
            $subject->tableArray['sys_file_reference'] ?? [],
            'Should find related sys_file_reference records'
        );
    }

    /**
     * Verify that a wrong fieldname in foreign_match_fields correctly
     * filters out records — no false positives from the fix.
     */
    #[Test]
    public function readForeignFieldWithNonMatchingFieldnameReturnsNoRelations(): void
    {
        $subject = $this->createSubject('tt_content');

        $conf = [
            'foreign_table' => 'sys_file_reference',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablenames',
            'foreign_match_fields' => [
                'tablenames' => 'tt_content',
                'fieldname' => 'nonexistent_field',
            ],
        ];

        $method = new \ReflectionMethod($subject, 'readForeignField');
        $method->invoke($subject, 1, $conf);

        self::assertEmpty(
            $subject->tableArray['sys_file_reference'] ?? [],
            'Non-matching fieldname should return no relations'
        );
    }
}
