<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model;

use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the browser cache control configuration reader.
 */
class ConfigTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfigMock;

    private Config $config;

    /**
     * Build the subject over a mocked scope configuration.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfigMock);
    }

    /**
     * The enabled flag is read as a flag, at store scope, for the store that was asked for.
     *
     * @return void
     */
    public function testIsEnabledReadsFlagAtStoreScope(): void
    {
        $this->scopeConfigMock->expects(self::once())
            ->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        self::assertTrue($this->config->isEnabled(7));
    }

    /**
     * The revalidation flag is read as a flag, at store scope, for the store that was asked for.
     *
     * @return void
     */
    public function testIsMustRevalidateReadsFlagAtStoreScope(): void
    {
        $this->scopeConfigMock->expects(self::once())
            ->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_MUST_REVALIDATE, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(false);

        self::assertFalse($this->config->isMustRevalidate(7));
    }

    /**
     * A null store id is handed to the scope configuration untouched, so it resolves the current scope.
     *
     * @return void
     */
    public function testIsEnabledPassesNullStoreIdThrough(): void
    {
        $this->scopeConfigMock->expects(self::once())
            ->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        self::assertTrue($this->config->isEnabled());
    }

    /**
     * The lifetime is read from its own path at store scope.
     *
     * @return void
     */
    public function testGetBrowserTtlReadsValueAtStoreScope(): void
    {
        $this->scopeConfigMock->expects(self::once())
            ->method('getValue')
            ->with(ConfigInterface::XML_PATH_BROWSER_TTL, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('120');

        self::assertSame(120, $this->config->getBrowserTtl(7));
    }

    /**
     * Anything that is not a positive number means there is no lifetime to grant.
     *
     * @param mixed $storedValue
     * @param int $expected
     * @return void
     * @dataProvider browserTtlDataProvider
     */
    public function testGetBrowserTtlNormalisesStoredValue($storedValue, int $expected): void
    {
        $this->scopeConfigMock->method('getValue')->willReturn($storedValue);

        self::assertSame($expected, $this->config->getBrowserTtl(1));
    }

    /**
     * Stored values and the lifetime each one yields.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function browserTtlDataProvider(): array
    {
        return [
            'numeric string' => ['120', 120],
            'integer' => [300, 300],
            'blank' => ['', 0],
            'null' => [null, 0],
            'non numeric' => ['soon', 0],
            'negative' => ['-5', 0],
            'negative integer' => [-1, 0],
        ];
    }

    /**
     * The pattern textarea is read from its own path at store scope.
     *
     * @return void
     */
    public function testGetExcludedUrlPatternsReadsValueAtStoreScope(): void
    {
        $this->scopeConfigMock->expects(self::once())
            ->method('getValue')
            ->with(ConfigInterface::XML_PATH_EXCLUDED_URL_PATTERNS, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('^/(media|static)/');

        self::assertSame(['^/(media|static)/'], $this->config->getExcludedUrlPatterns(7));
    }

    /**
     * Lines are trimmed, blanks are dropped, an uncompilable line is dropped, and the order survives.
     *
     * @return void
     */
    public function testGetExcludedUrlPatternsFiltersAndPreservesOrder(): void
    {
        $stored = "  ^/(media|static)/  \n"
            . "\n"
            . "^/checkout\n"
            . "^/(unclosed\n"
            . "   \n"
            . "\t^/customer/\t\n";

        $this->scopeConfigMock->method('getValue')->willReturn($stored);

        self::assertSame(
            ['^/(media|static)/', '^/checkout', '^/customer/'],
            $this->config->getExcludedUrlPatterns(1)
        );
    }

    /**
     * A line that cannot be compiled under the published delimiter is not returned.
     *
     * @return void
     */
    public function testGetExcludedUrlPatternsDropsPatternBreakingThePublishedDelimiter(): void
    {
        $this->scopeConfigMock->method('getValue')->willReturn("^/safe\n^/un~terminated");

        self::assertSame(['^/safe'], $this->config->getExcludedUrlPatterns(1));
    }

    /**
     * An empty or absent textarea yields no patterns at all.
     *
     * @return void
     */
    public function testGetExcludedUrlPatternsReturnsEmptyListWhenNothingIsStored(): void
    {
        $this->scopeConfigMock->method('getValue')->willReturn(null);

        self::assertSame([], $this->config->getExcludedUrlPatterns(1));
    }

    /**
     * The textarea is parsed once per store, however often the getter runs during a page render.
     *
     * @return void
     */
    public function testGetExcludedUrlPatternsIsReadOncePerStore(): void
    {
        $this->scopeConfigMock->expects(self::exactly(2))
            ->method('getValue')
            ->willReturnCallback(
                static fn (string $path, string $scope, $scopeCode): string => $scopeCode === 1
                    ? '^/one'
                    : '^/two'
            );

        self::assertSame(['^/one'], $this->config->getExcludedUrlPatterns(1));
        self::assertSame(['^/one'], $this->config->getExcludedUrlPatterns(1));
        self::assertSame(['^/two'], $this->config->getExcludedUrlPatterns(2));
        self::assertSame(['^/two'], $this->config->getExcludedUrlPatterns(2));
    }
}
