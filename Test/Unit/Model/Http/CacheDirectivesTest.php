<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Http;

use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Cache-Control value object.
 */
class CacheDirectivesTest extends TestCase
{
    /**
     * The expression Magento's built-in full page cache uses to decide whether a response is storable and
     * for how long. Asserted against directly, because the rendered order only matters through it.
     */
    private const MAGENTO_STORABILITY_EXPRESSION = '/public.*s-maxage=(\d+)/';

    /**
     * A response destined for a shared cache carries both lifetimes and stays public.
     *
     * @return void
     */
    public function testRendersSharedCacheCase(): void
    {
        $directives = new CacheDirectives(true, 120, 86400, true);

        self::assertSame('public, must-revalidate, max-age=120, s-maxage=86400', $directives->render());
    }

    /**
     * A response written after a cache already took its copy carries only the browser lifetime.
     *
     * @return void
     */
    public function testRendersBrowserOnlyCase(): void
    {
        $directives = new CacheDirectives(false, 120, null, true);

        self::assertSame('private, must-revalidate, max-age=120', $directives->render());
    }

    /**
     * Revalidation is omitted entirely when it was not asked for.
     *
     * @return void
     */
    public function testOmitsMustRevalidateWhenNotRequested(): void
    {
        self::assertSame('public, max-age=60, s-maxage=600', (new CacheDirectives(true, 60, 600))->render());
        self::assertSame('private, max-age=60', (new CacheDirectives(false, 60))->render());
    }

    /**
     * The public token precedes the shared lifetime, so Magento still recognises the response as storable
     * and still reads the right lifetime out of it.
     *
     * @return void
     */
    public function testPublicPrecedesSharedMaxAgeSoMagentoStillDetectsStorability(): void
    {
        $rendered = (new CacheDirectives(true, 120, 86400, true))->render();

        self::assertSame(1, preg_match(self::MAGENTO_STORABILITY_EXPRESSION, $rendered, $matches));
        self::assertSame('86400', $matches[1]);
    }

    /**
     * A private rendering cannot be recognised as storable, which is what makes a second pass through
     * Magento's built-in cache structurally incapable of storing the page again.
     *
     * @return void
     */
    public function testPrivateRenderingIsNotDetectedAsStorable(): void
    {
        $rendered = (new CacheDirectives(false, 120, null, true))->render();

        self::assertSame(0, preg_match(self::MAGENTO_STORABILITY_EXPRESSION, $rendered));
    }

    /**
     * A lifetime of zero or less means the caller wanted no header at all and is refused.
     *
     * @param int $maxAge
     * @return void
     * @dataProvider nonPositiveMaxAgeDataProvider
     */
    public function testRejectsNonPositiveMaxAge(int $maxAge): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A browser lifetime of at least one second is required');

        new CacheDirectives(true, $maxAge, 600);
    }

    /**
     * Lifetimes that carry no instruction to a browser.
     *
     * @return array<string, array{0: int}>
     */
    public static function nonPositiveMaxAgeDataProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    /**
     * A shared cache is never told to hold a page for less time than the browser.
     *
     * @return void
     */
    public function testRejectsSharedLifetimeShorterThanBrowserLifetime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be told to hold a page for less time than the browser');

        new CacheDirectives(true, 120, 60);
    }

    /**
     * A shared lifetime alongside private visibility is a contradiction and is refused.
     *
     * @return void
     */
    public function testRejectsSharedLifetimeOnPrivateResponse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined with private visibility');

        new CacheDirectives(false, 120, 86400);
    }

    /**
     * A shared lifetime equal to the browser lifetime is the shortest one still allowed.
     *
     * @return void
     */
    public function testAcceptsSharedLifetimeEqualToBrowserLifetime(): void
    {
        self::assertSame('public, max-age=120, s-maxage=120', (new CacheDirectives(true, 120, 120))->render());
    }

    /**
     * Nothing this object renders ever tells a cache to store nothing at all.
     *
     * @return void
     */
    public function testNeverEmitsNoStore(): void
    {
        self::assertStringNotContainsString('no-store', (new CacheDirectives(true, 120, 86400, true))->render());
        self::assertStringNotContainsString('no-store', (new CacheDirectives(false, 120, null, true))->render());
    }
}
