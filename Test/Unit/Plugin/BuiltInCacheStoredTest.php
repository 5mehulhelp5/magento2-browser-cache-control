<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Plugin;

use Hryvinskyi\BrowserCacheControl\Api\BrowserCacheTtlApplierInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectivesWriter;
use Hryvinskyi\BrowserCacheControl\Model\PublicResponseState;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotFactory;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Hryvinskyi\BrowserCacheControl\Plugin\BuiltInCacheStored;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the moment a freshly rendered page has just been put into the built-in full page cache.
 */
class BuiltInCacheStoredTest extends TestCase
{
    /**
     * @var BrowserCacheTtlApplierInterface&MockObject
     */
    private $applierMock;

    /**
     * @var Kernel&MockObject
     */
    private $kernelMock;

    /**
     * @var HttpResponse&MockObject
     */
    private $responseMock;

    /**
     * Headers the response was asked to publish, as name, value and whether it replaces.
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private array $publishedHeaders = [];

    /**
     * Headers the response was asked to drop.
     *
     * @var string[]
     */
    private array $clearedHeaders = [];

    private PublicResponseState $publicResponseState;

    private BuiltInCacheStored $plugin;

    /**
     * Build a plugin over real collaborators, so that what is asserted is the headers a response ends up with
     * rather than the calls made on the way there. Only the decision itself is stood in for, because it has
     * a test of its own.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->applierMock = $this->createMock(BrowserCacheTtlApplierInterface::class);
        $this->kernelMock = $this->createMock(Kernel::class);
        $this->publicResponseState = new PublicResponseState();
        $this->responseMock = $this->createRecordingResponse();

        $this->plugin = new BuiltInCacheStored(
            $this->publicResponseState,
            $this->createSnapshotFactory(),
            $this->applierMock,
            new CacheDirectivesWriter()
        );
    }

    /**
     * A decision to publish reaches the response as the headers it renders to, and the moment the decision was
     * asked about is the one this seam owns.
     *
     * @return void
     */
    public function testPublishesWhatWasDecidedAtTheMomentThisSeamOwns(): void
    {
        $this->publicResponseState->declarePublic(86400);
        $this->applierMock->expects(self::once())
            ->method('resolve')
            ->with(self::isInstanceOf(ResponseSnapshot::class), WritePoint::BuiltInDelivery)
            ->willReturn(new CacheDirectives(false, 120, null, true));

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);

        self::assertSame(
            [
                ['Cache-Control', 'private, must-revalidate, max-age=120', true],
                ['Pragma', 'cache', true],
            ],
            $this->publishedHeaders
        );
        self::assertSame(['Expires'], $this->clearedHeaders);
    }

    /**
     * A decision to publish nothing leaves the response exactly as Magento built it.
     *
     * @return void
     */
    public function testLeavesTheResponseAloneWhenNothingIsToBePublished(): void
    {
        $this->publicResponseState->declarePublic(86400);
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);

        self::assertSame([], $this->publishedHeaders);
        self::assertSame([], $this->clearedHeaders);
    }

    /**
     * Without a declaration made earlier in the request there is no evidence the page was stored at all, and
     * the headers are left alone without even asking. Every response reaching this moment has been stripped of
     * its caching headers, so the ones Magento refused look exactly like the ones it kept.
     *
     * @return void
     */
    public function testAsksNothingAndPublishesNothingWhenNoDeclarationWasMade(): void
    {
        $this->applierMock->expects(self::never())->method('resolve');

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);

        self::assertSame([], $this->publishedHeaders);
        self::assertSame([], $this->clearedHeaders);
    }

    /**
     * A page declared publicly cacheable with a lifetime of nothing is still a declaration, so the decision is
     * asked about it rather than the moment being skipped. Cutting such a page's browser lifetime to nothing
     * belongs to the decision, which knows why zero means what it does.
     *
     * @return void
     */
    public function testStillAsksWhenTheDeclarationCarriedNoLifetime(): void
    {
        $this->publicResponseState->declarePublic(0);
        $this->applierMock->expects(self::once())->method('resolve')->willReturn(null);

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);
    }

    /**
     * Reaching this moment twice in one request leaves the same headers as reaching it once, so a header never
     * depends on how the surrounding control flow happened to run.
     *
     * @return void
     */
    public function testPublishingTwiceInOneRequestLeavesTheSameHeaders(): void
    {
        $this->publicResponseState->declarePublic(86400);
        $this->applierMock->method('resolve')->willReturn(new CacheDirectives(false, 120, null, true));

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);
        $publishedOnce = $this->publishedHeaders;
        $clearedOnce = $this->clearedHeaders;

        $this->plugin->afterProcess($this->kernelMock, null, $this->responseMock);

        self::assertSame(array_merge($publishedOnce, $publishedOnce), $this->publishedHeaders);
        self::assertSame(array_merge($clearedOnce, $clearedOnce), $this->clearedHeaders);

        foreach ($this->publishedHeaders as $published) {
            self::assertTrue($published[2], 'Every header must be published by replacement.');
        }
    }

    /**
     * Whatever the framework was going to hand back is handed back, so nothing about the cache's own behaviour
     * changes by this plugin being present.
     *
     * @return void
     */
    public function testHandsBackTheOutcomeItWasGiven(): void
    {
        $this->publicResponseState->declarePublic(86400);
        $this->applierMock->method('resolve')->willReturn(null);

        self::assertNull($this->plugin->afterProcess($this->kernelMock, null, $this->responseMock));
    }

    /**
     * Build a response that records what it was asked to do to its headers.
     *
     * @return HttpResponse&MockObject
     */
    private function createRecordingResponse()
    {
        $response = $this->createMock(HttpResponse::class);
        $response->method('getHttpResponseCode')->willReturn(200);
        $response->method('getHeader')->willReturn(false);
        $response->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $replace) use ($response): HttpResponse {
                $this->publishedHeaders[] = [$name, $value, $replace];

                return $response;
            }
        );
        $response->method('clearHeader')->willReturnCallback(
            function (string $name) use ($response): HttpResponse {
                $this->clearedHeaders[] = $name;

                return $response;
            }
        );

        return $response;
    }

    /**
     * Build the real translation from Magento's objects into the facts a decision works from.
     *
     * @return ResponseSnapshotFactory
     */
    private function createSnapshotFactory(): ResponseSnapshotFactory
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/women/tops.html');
        $request->method('getFullActionName')->willReturn('catalog_category_view');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ResponseSnapshotFactory(
            $request,
            $this->publicResponseState,
            $this->createMock(PageCacheEnvironmentInterface::class),
            $storeManager
        );
    }
}
