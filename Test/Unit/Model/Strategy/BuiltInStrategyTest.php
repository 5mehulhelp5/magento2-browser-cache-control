<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Model\Strategy\BuiltInStrategy;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the strategy that applies when Magento's own cache stores whole pages.
 */
class BuiltInStrategyTest extends TestCase
{
    private BuiltInStrategy $strategy;

    /**
     * Build the subject. It is pure and takes nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->strategy = new BuiltInStrategy();
    }

    /**
     * Only the moment after the page has already been stored belongs to this strategy. Claiming the
     * declaration moment as well would change the header Magento's own cache reads to decide whether the page
     * is storable and for how long.
     *
     * @return void
     */
    public function testOwnsOnlyTheDeliveryOfAnAlreadyStoredPage(): void
    {
        self::assertTrue($this->strategy->ownsWritePoint(WritePoint::BuiltInDelivery));
        self::assertFalse($this->strategy->ownsWritePoint(WritePoint::SharedCacheDeclaration));
    }

    /**
     * No shared lifetime is ever published, whatever Magento announced, because by this moment the page has
     * already been stored and there is no shared cache left to address.
     *
     * @param int|null $declaredSharedTtl
     * @return void
     * @dataProvider declaredSharedTtlDataProvider
     */
    public function testNeverPublishesASharedLifetime(?int $declaredSharedTtl): void
    {
        self::assertNull($this->strategy->sharedTtlToPublish($declaredSharedTtl));
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
     * The response is restricted to the one visitor it was built for.
     *
     * Withholding the shared lifetime alone would not do it: a plain public lifetime still invites every
     * proxy between Magento and the visitor to keep the page and hand it to somebody else, with nothing in
     * the response saying whose page it is and no way for Magento to purge those copies.
     *
     * @return void
     */
    public function testMarksTheResponsePrivate(): void
    {
        self::assertFalse($this->strategy->isPublic());
    }

    /**
     * The pair that keeps wrongly shared copies off downstream proxies -- private visibility and no shared
     * lifetime -- holds together for every announced lifetime, not just the convenient one.
     *
     * @param int|null $declaredSharedTtl
     * @return void
     * @dataProvider declaredSharedTtlDataProvider
     */
    public function testStaysPrivateWithoutASharedLifetime(?int $declaredSharedTtl): void
    {
        self::assertFalse($this->strategy->isPublic());
        self::assertNull($this->strategy->sharedTtlToPublish($declaredSharedTtl));
    }
}
