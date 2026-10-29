<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Hryvinskyi\BrowserCacheControl\Model\Strategy\BuiltInStrategy;
use Hryvinskyi\BrowserCacheControl\Model\Strategy\PageCacheStrategyResolver;
use Hryvinskyi\BrowserCacheControl\Model\Strategy\VarnishStrategy;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the lookup from the configured caching application to its strategy.
 */
class PageCacheStrategyResolverTest extends TestCase
{
    /**
     * @var PageCacheEnvironmentInterface&MockObject
     */
    private $environmentMock;

    /**
     * @var LoggerInterface&MockObject
     */
    private $loggerMock;

    private BuiltInStrategy $builtInStrategy;

    private VarnishStrategy $varnishStrategy;

    /**
     * Build the collaborators shared by every case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->environmentMock = $this->createMock(PageCacheEnvironmentInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->builtInStrategy = new BuiltInStrategy();
        $this->varnishStrategy = new VarnishStrategy();
    }

    /**
     * Every caching application the module ships a strategy for is found.
     *
     * The pool is built exactly as configuration hands it over: the entry names are text, while the value
     * being looked up is the number Magento stores. That the two meet at all is the point of the case.
     *
     * @param int $cachingApplication
     * @param class-string<object> $expectedStrategy
     * @return void
     * @dataProvider knownCachingApplicationDataProvider
     */
    public function testResolvesEveryKnownCachingApplication(
        int $cachingApplication,
        string $expectedStrategy
    ): void {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn($cachingApplication);
        $this->loggerMock->expects(self::never())->method('warning');

        $resolver = $this->createResolver();

        self::assertInstanceOf($expectedStrategy, $resolver->resolve());
    }

    /**
     * Caching applications declared in configuration, and the strategy each must produce.
     *
     * @return array<string, array{0: int, 1: class-string<object>}>
     */
    public static function knownCachingApplicationDataProvider(): array
    {
        return [
            'built in' => [PageCacheConfig::BUILT_IN, BuiltInStrategy::class],
            'varnish' => [PageCacheConfig::VARNISH, VarnishStrategy::class],
        ];
    }

    /**
     * A pool whose entries are named as text is still searchable by the number Magento configured, which is
     * the whole reason the lookup can be a plain array read.
     *
     * @return void
     */
    public function testLooksUpTextNamedEntriesByTheConfiguredNumber(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn(2);

        $resolver = new PageCacheStrategyResolver(
            $this->environmentMock,
            $this->loggerMock,
            ['2' => $this->varnishStrategy]
        );

        self::assertSame($this->varnishStrategy, $resolver->resolve());
    }

    /**
     * A caching application no strategy was declared for leaves the response alone, and says so once.
     *
     * @return void
     */
    public function testReportsAnUnknownCachingApplicationOnceAcrossSeveralLookups(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn(7);
        $this->loggerMock->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('"7"'));

        $resolver = $this->createResolver();

        self::assertNull($resolver->resolve());
        self::assertNull($resolver->resolve());
        self::assertNull($resolver->resolve());
    }

    /**
     * A caching application that was never configured at all is unknown like any other value.
     *
     * @return void
     */
    public function testTreatsAnUnconfiguredCachingApplicationAsUnknown(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn(0);
        $this->loggerMock->expects(self::once())->method('warning');

        self::assertNull($this->createResolver()->resolve());
    }

    /**
     * Clearing the per-request state lets the next request be told about the misconfiguration again, instead
     * of the warning being written once for the lifetime of the process.
     *
     * @return void
     */
    public function testResettingStateArmsTheWarningAgain(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn(7);
        $this->loggerMock->expects(self::exactly(2))->method('warning');

        $resolver = $this->createResolver();

        $resolver->resolve();
        $resolver->resolve();
        $resolver->_resetState();
        $resolver->resolve();
        $resolver->resolve();
    }

    /**
     * With whole-page caching switched off there is nothing to resolve, and nothing worth saying about it.
     *
     * @return void
     */
    public function testResolvesNothingAndSaysNothingWhenFullPageCacheIsDisabled(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(false);
        $this->environmentMock->expects(self::never())->method('getCachingApplication');
        $this->loggerMock->expects(self::never())->method('warning');

        self::assertNull($this->createResolver()->resolve());
    }

    /**
     * An empty pool resolves nothing rather than raising, so a shop cannot be taken down by configuration
     * that failed to declare a strategy.
     *
     * @return void
     */
    public function testResolvesNothingWhenNoStrategyIsDeclaredAtAll(): void
    {
        $this->environmentMock->method('isFullPageCacheEnabled')->willReturn(true);
        $this->environmentMock->method('getCachingApplication')->willReturn(PageCacheConfig::BUILT_IN);
        $this->loggerMock->expects(self::once())->method('warning');

        $resolver = new PageCacheStrategyResolver($this->environmentMock, $this->loggerMock);

        self::assertNull($resolver->resolve());
    }

    /**
     * Build the subject over the pool the module declares in configuration.
     *
     * The keys are the literal text `di.xml` declares, not Magento's constants, so a case resolving Magento's
     * constants against them also proves the two still name the same caching applications.
     *
     * @return PageCacheStrategyResolver
     */
    private function createResolver(): PageCacheStrategyResolver
    {
        return new PageCacheStrategyResolver(
            $this->environmentMock,
            $this->loggerMock,
            [
                '1' => $this->builtInStrategy,
                '2' => $this->varnishStrategy,
            ]
        );
    }
}
