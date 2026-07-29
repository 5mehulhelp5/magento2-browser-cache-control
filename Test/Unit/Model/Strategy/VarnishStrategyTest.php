<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Model\Strategy\VarnishStrategy;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the strategy that applies when Varnish caches whole pages.
 */
class VarnishStrategyTest extends TestCase
{
    private VarnishStrategy $strategy;

    /**
     * Build the subject. It is pure and takes nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->strategy = new VarnishStrategy();
    }

    /**
     * Only the moment before the response leaves Magento belongs to this strategy, because that is the last
     * one before Varnish reads the headers.
     *
     * @return void
     */
    public function testOwnsOnlyTheSharedCacheDeclaration(): void
    {
        self::assertTrue($this->strategy->ownsWritePoint(WritePoint::SharedCacheDeclaration));
        self::assertFalse($this->strategy->ownsWritePoint(WritePoint::BuiltInDelivery));
    }

    /**
     * The lifetime Magento announced is republished untouched, so Varnish keeps the page for exactly as long
     * as it was going to.
     *
     * @param int|null $declaredSharedTtl
     * @return void
     * @dataProvider declaredSharedTtlDataProvider
     */
    public function testRepublishesTheDeclaredSharedLifetime(?int $declaredSharedTtl): void
    {
        self::assertSame($declaredSharedTtl, $this->strategy->sharedTtlToPublish($declaredSharedTtl));
    }

    /**
     * Announced lifetimes, including none at all.
     *
     * @return array<string, array{0: int|null}>
     */
    public static function declaredSharedTtlDataProvider(): array
    {
        return [
            'a day' => [86400],
            'shortest usable' => [1],
            'announced as nothing' => [0],
            'nothing announced' => [null],
        ];
    }

    /**
     * The response stays storable by shared caches, because Varnish refuses to store anything else and
     * marking it private would stop pages being cached rather than restrict who sees them.
     *
     * @return void
     */
    public function testKeepsTheResponsePublic(): void
    {
        self::assertTrue($this->strategy->isPublic());
    }
}
