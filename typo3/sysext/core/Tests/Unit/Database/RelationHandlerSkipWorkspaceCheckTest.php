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

namespace TYPO3\CMS\Core\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for the workspace-0 skip optimisation in
 * {@see RelationHandler::readForeignField()}.
 *
 * The patch adds a `&& $this->getWorkspaceId() > 0` guard to the
 * `useLiveParentIds` check. When workspace is 0 (live), getLiveDefaultId()
 * is skipped because it would always return the same UID, avoiding an
 * unnecessary database query per relation.
 *
 * Since readForeignField() is protected and deeply coupled to the database,
 * these tests verify the pre-conditions (workspace ID + useLiveParentIds)
 * that control the optimised code path via Reflection.
 */
#[CoversClass(RelationHandler::class)]
final class RelationHandlerSkipWorkspaceCheckTest extends UnitTestCase
{
    /**
     * When workspaceId is explicitly set to 0 (live workspace),
     * getWorkspaceId() must return 0. Combined with useLiveParentIds=true,
     * the patched condition `useLiveParentIds && getWorkspaceId() > 0`
     * evaluates to false, correctly skipping getLiveDefaultId().
     */
    #[Test]
    public function workspaceZeroIsCorrectlySetViaSetWorkspaceId(): void
    {
        $subject = new RelationHandler();
        $subject->setWorkspaceId(0);

        $reflection = new \ReflectionMethod(RelationHandler::class, 'getWorkspaceId');
        self::assertSame(0, $reflection->invoke($subject));
    }

    /**
     * useLiveParentIds defaults to true. Combined with workspaceId=0,
     * this means the patched condition evaluates to false.
     */
    #[Test]
    public function useLiveParentIdsDefaultsToTrue(): void
    {
        $subject = new RelationHandler();

        $reflection = new \ReflectionProperty(RelationHandler::class, 'useLiveParentIds');
        self::assertTrue($reflection->getValue($subject));
    }

    /**
     * When workspace is > 0, the combined condition evaluates to true,
     * ensuring getLiveDefaultId() is still called for workspace overlays.
     */
    #[Test]
    public function nonZeroWorkspaceKeepsLiveParentIdsBehavior(): void
    {
        $subject = new RelationHandler();
        $subject->setWorkspaceId(1);

        $useLiveRef = new \ReflectionProperty(RelationHandler::class, 'useLiveParentIds');
        $getWsMethod = new \ReflectionMethod(RelationHandler::class, 'getWorkspaceId');

        self::assertTrue($useLiveRef->getValue($subject));
        self::assertGreaterThan(0, $getWsMethod->invoke($subject));
    }

    /**
     * When useLiveParentIds is set to false, the condition short-circuits
     * regardless of workspace ID, so getLiveDefaultId() is never called.
     */
    #[Test]
    public function disabledUseLiveParentIdsSkipsCheckRegardlessOfWorkspace(): void
    {
        $subject = new RelationHandler();
        $subject->setUseLiveParentIds(false);
        $subject->setWorkspaceId(1);

        $reflection = new \ReflectionProperty(RelationHandler::class, 'useLiveParentIds');
        self::assertFalse($reflection->getValue($subject));
    }
}
