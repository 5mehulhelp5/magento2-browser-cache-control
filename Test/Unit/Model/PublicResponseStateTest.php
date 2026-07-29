<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model;

use Hryvinskyi\BrowserCacheControl\Model\PublicResponseState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the fact carried from the moment Magento declares a response cacheable to the moment it is
 * delivered.
 */
class PublicResponseStateTest extends TestCase
{
    private PublicResponseState $state;

    /**
     * Build a state that has been told nothing yet.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->state = new PublicResponseState();
    }

    /**
     * Until something says otherwise, nothing has been declared and no lifetime is known.
     *
     * @return void
     */
    public function testStartsWithNothingDeclared(): void
    {
        self::assertFalse($this->state->isDeclared());
        self::assertNull($this->state->getSharedTtl());
    }

    /**
     * A declaration carrying a lifetime is remembered as both facts, and they stay separable.
     *
     * @return void
     */
    public function testRemembersADeclarationAndItsLifetime(): void
    {
        $this->state->declarePublic(86400);

        self::assertTrue($this->state->isDeclared());
        self::assertSame(86400, $this->state->getSharedTtl());
    }

    /**
     * A declaration can be made without a lifetime, which is the honest answer wherever the remaining
     * lifetime of a stored page cannot be known.
     *
     * @return void
     */
    public function testRemembersADeclarationThatCarriedNoLifetime(): void
    {
        $this->state->declarePublic(null);

        self::assertTrue($this->state->isDeclared());
        self::assertNull($this->state->getSharedTtl());
    }

    /**
     * A lifetime of zero seconds is a lifetime that was announced, and it is kept apart from having announced
     * nothing. Collapsing the two is exactly how an embedded fragment would acquire a lifetime in a browser.
     *
     * @return void
     */
    public function testKeepsAnAnnouncedNothingApartFromNothingAnnounced(): void
    {
        $this->state->declarePublic(0);

        self::assertTrue($this->state->isDeclared());
        self::assertSame(0, $this->state->getSharedTtl());
    }

    /**
     * A second declaration replaces the first rather than being ignored or added to it.
     *
     * @return void
     */
    public function testTheLatestDeclarationIsTheOneRemembered(): void
    {
        $this->state->declarePublic(86400);
        $this->state->declarePublic(0);

        self::assertTrue($this->state->isDeclared());
        self::assertSame(0, $this->state->getSharedTtl());

        $this->state->declarePublic(null);

        self::assertTrue($this->state->isDeclared());
        self::assertNull($this->state->getSharedTtl());
    }

    /**
     * Clearing the state puts it back exactly where it started, so a declaration made while serving one
     * request cannot be read by the next one served from the same process.
     *
     * @return void
     */
    public function testClearingTheStateReturnsItToKnowingNothing(): void
    {
        $this->state->declarePublic(86400);
        $this->state->_resetState();

        self::assertFalse($this->state->isDeclared());
        self::assertNull($this->state->getSharedTtl());
    }

    /**
     * Clearing a state that announced a lifetime of zero forgets the announcement too, rather than leaving
     * behind a value that already looks like the default.
     *
     * @return void
     */
    public function testClearingTheStateForgetsAnAnnouncedNothing(): void
    {
        $this->state->declarePublic(0);
        $this->state->_resetState();

        self::assertFalse($this->state->isDeclared());
        self::assertNull($this->state->getSharedTtl());
    }
}
