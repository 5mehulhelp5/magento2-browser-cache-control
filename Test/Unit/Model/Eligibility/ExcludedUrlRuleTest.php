<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Model\Eligibility\ExcludedUrlRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the excluded URL rule.
 */
class ExcludedUrlRuleTest extends TestCase
{
    /**
     * @var ConfigInterface&MockObject
     */
    private $configMock;

    private ExcludedUrlRule $rule;

    /**
     * Build the subject over a mocked configuration source.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigInterface::class);
        $this->rule = new ExcludedUrlRule($this->configMock);
    }

    /**
     * A path covered by a configured pattern keeps the stock headers.
     *
     * @return void
     */
    public function testRejectsMatchingPath(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/(media|static)/']);

        self::assertFalse($this->rule->isEligible($this->snapshotForPath('/media/catalog/product/x.jpg')));
    }

    /**
     * A path no pattern covers is allowed through.
     *
     * @return void
     */
    public function testAcceptsNonMatchingPath(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/(media|static)/', '^/checkout']);

        self::assertTrue($this->rule->isEligible($this->snapshotForPath('/women/tops.html')));
    }

    /**
     * Only one pattern out of several needs to cover the path.
     *
     * @return void
     */
    public function testRejectsPathMatchedByLaterPattern(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/(media|static)/', '^/checkout']);

        self::assertFalse($this->rule->isEligible($this->snapshotForPath('/checkout/cart/')));
    }

    /**
     * Casing in either the pattern or the path does not defeat an exclusion.
     *
     * @return void
     */
    public function testMatchesCaseInsensitively(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/CHECKOUT']);

        self::assertFalse($this->rule->isEligible($this->snapshotForPath('/checkout/cart/')));
    }

    /**
     * A pattern containing a hash is usable, which is the reason the published delimiter is not a hash.
     *
     * @return void
     */
    public function testMatchesPatternContainingAHash(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/anchor#top']);

        self::assertFalse($this->rule->isEligible($this->snapshotForPath('/anchor#top')));
        self::assertTrue($this->rule->isEligible($this->snapshotForPath('/anchor')));
    }

    /**
     * A pattern that cannot be compiled excludes nothing rather than breaking the page.
     *
     * @return void
     */
    public function testTreatsUncompilablePatternAsNoMatch(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/(unclosed']);

        self::assertTrue($this->rule->isEligible($this->snapshotForPath('/unclosed')));
    }

    /**
     * A broken pattern does not stop the patterns after it from being applied.
     *
     * @return void
     */
    public function testKeepsApplyingPatternsAfterAnUncompilableOne(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn(['^/(unclosed', '^/checkout']);

        self::assertFalse($this->rule->isEligible($this->snapshotForPath('/checkout/cart/')));
    }

    /**
     * With nothing configured every path qualifies.
     *
     * @return void
     */
    public function testAcceptsEveryPathWhenNothingIsConfigured(): void
    {
        $this->configMock->method('getExcludedUrlPatterns')->willReturn([]);

        self::assertTrue($this->rule->isEligible($this->snapshotForPath('/media/catalog/product/x.jpg')));
    }

    /**
     * The patterns are resolved for the store the response is being rendered for.
     *
     * @return void
     */
    public function testResolvesPatternsForTheStoreOnTheSnapshot(): void
    {
        $this->configMock->expects(self::once())
            ->method('getExcludedUrlPatterns')
            ->with(7)
            ->willReturn([]);

        self::assertTrue($this->rule->isEligible($this->snapshotForPath('/women/tops.html', 7)));
    }

    /**
     * Build a snapshot that differs from its siblings only in the path and the store.
     *
     * @param string $requestPath Path to place on the snapshot
     * @param int|null $storeId Store to place on the snapshot
     * @return ResponseSnapshot
     */
    private function snapshotForPath(string $requestPath, ?int $storeId = 1): ResponseSnapshot
    {
        return new ResponseSnapshot('GET', 200, $requestPath, 'catalog_category_view', null, false, 86400, $storeId);
    }
}
