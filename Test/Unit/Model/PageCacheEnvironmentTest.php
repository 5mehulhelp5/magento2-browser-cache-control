<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model;

use Hryvinskyi\BrowserCacheControl\Model\PageCacheEnvironment;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the adapter over Magento's full page cache settings.
 */
class PageCacheEnvironmentTest extends TestCase
{
    /**
     * @var PageCacheConfig&MockObject
     */
    private $pageCacheConfigMock;

    private PageCacheEnvironment $environment;

    /**
     * Build the subject over a mocked copy of Magento's own settings class.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->pageCacheConfigMock = $this->createMock(PageCacheConfig::class);
        $this->environment = new PageCacheEnvironment($this->pageCacheConfigMock);
    }

    /**
     * The configured caching application is handed on as the number Magento already made of it.
     *
     * @param int $configured
     * @return void
     * @dataProvider cachingApplicationDataProvider
     */
    public function testReturnsConfiguredCachingApplication(int $configured): void
    {
        $this->pageCacheConfigMock->method('getType')->willReturn($configured);

        self::assertSame($configured, $this->environment->getCachingApplication());
    }

    /**
     * Caching application identifiers, including one no strategy covers.
     *
     * @return array<string, array{0: int}>
     */
    public static function cachingApplicationDataProvider(): array
    {
        return [
            'built in' => [PageCacheConfig::BUILT_IN],
            'varnish' => [PageCacheConfig::VARNISH],
            'never configured' => [0],
            'unknown to this module' => [7],
        ];
    }

    /**
     * Whole-page caching being switched on is reported straight from Magento's cache type toggle.
     *
     * @param bool $enabled
     * @return void
     * @dataProvider fullPageCacheStateDataProvider
     */
    public function testReportsWhetherFullPageCacheIsEnabled(bool $enabled): void
    {
        $this->pageCacheConfigMock->expects(self::once())->method('isEnabled')->willReturn($enabled);

        self::assertSame($enabled, $this->environment->isFullPageCacheEnabled());
    }

    /**
     * Both states of the Cache Management toggle.
     *
     * @return array<string, array{0: bool}>
     */
    public static function fullPageCacheStateDataProvider(): array
    {
        return [
            'switched on' => [true],
            'switched off' => [false],
        ];
    }

    /**
     * A lifetime that describes real seconds is normalised into a number, whatever shape it arrived in.
     *
     * @param mixed $configured
     * @param int $expected
     * @return void
     * @dataProvider usableSharedTtlDataProvider
     */
    public function testNormalisesUsableSharedTtl($configured, int $expected): void
    {
        $this->pageCacheConfigMock->method('getTtl')->willReturn($configured);

        self::assertSame($expected, $this->environment->getSharedTtl());
    }

    /**
     * Configured values that describe a lifetime something can be stored for.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function usableSharedTtlDataProvider(): array
    {
        return [
            'string as stored by configuration' => ['86400', 86400],
            'already an integer' => [86400, 86400],
            'numeric string with decimals' => ['120.9', 120],
            'shortest usable lifetime' => ['1', 1],
        ];
    }

    /**
     * Anything that is not a lifetime is reported as unknown rather than as a lifetime of zero.
     *
     * @param mixed $configured
     * @return void
     * @dataProvider unusableSharedTtlDataProvider
     */
    public function testReportsUnusableSharedTtlAsUnknown($configured): void
    {
        $this->pageCacheConfigMock->method('getTtl')->willReturn($configured);

        self::assertNull($this->environment->getSharedTtl());
    }

    /**
     * Configured values that describe no usable lifetime at all.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unusableSharedTtlDataProvider(): array
    {
        return [
            'never saved' => [null],
            'zero as a string' => ['0'],
            'zero as an integer' => [0],
            'negative' => ['-30'],
            'empty' => [''],
            'not a number at all' => ['forever'],
        ];
    }
}
