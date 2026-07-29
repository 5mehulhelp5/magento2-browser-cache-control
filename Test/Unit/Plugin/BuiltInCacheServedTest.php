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
use Hryvinskyi\BrowserCacheControl\Plugin\BuiltInCacheServed;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the moment a page is replayed out of the built-in full page cache.
 */
class BuiltInCacheServedTest extends TestCase
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

    private BuiltInCacheServed $plugin;

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

        $this->plugin = new BuiltInCacheServed(
            $this->publicResponseState,
            $this->createSnapshotFactory(),
            $this->applierMock,
            new CacheDirectivesWriter()
        );
    }

    /**
     * A decision to publish reaches the replayed page as the headers it renders to, and the moment the decision
     * was asked about is the one this seam owns.
     *
     * @return void
     */
    public function testPublishesWhatWasDecidedAtTheMomentThisSeamOwns(): void
    {
        $this->applierMock->expects(self::once())
            ->method('resolve')
            ->with(self::isInstanceOf(ResponseSnapshot::class), WritePoint::BuiltInDelivery)
            ->willReturn(new CacheDirectives(false, 120, null, true));

        $this->plugin->afterLoad($this->kernelMock, $this->responseMock);

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
     * A decision to publish nothing leaves the replayed page exactly as it came out of storage.
     *
     * @return void
     */
    public function testLeavesTheResponseAloneWhenNothingIsToBePublished(): void
    {
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterLoad($this->kernelMock, $this->responseMock);

        self::assertSame([], $this->publishedHeaders);
        self::assertSame([], $this->clearedHeaders);
    }

    /**
     * A page that came out of storage was, by the only evidence that counts, publicly cacheable -- Magento
     * stored it. The declaration is therefore made here rather than looked for, and it carries no lifetime,
     * because how much of the stored copy's lifetime remains is not knowable at this moment.
     *
     * @return void
     */
    public function testDeclaresTheReplayedPagePubliclyCacheableWithoutALifetime(): void
    {
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterLoad($this->kernelMock, $this->responseMock);

        self::assertTrue($this->publicResponseState->isDeclared());
        self::assertNull($this->publicResponseState->getSharedTtl());
    }

    /**
     * Nothing having been found in storage is answered with the same nothing, unchanged and unquestioned.
     *
     * @param mixed $result Outcome of a lookup that produced no page
     * @return void
     * @dataProvider absentPageDataProvider
     */
    public function testHandsBackAnythingThatIsNotAPageWithoutAskingAnything(mixed $result): void
    {
        $this->applierMock->expects(self::never())->method('resolve');

        self::assertSame($result, $this->plugin->afterLoad($this->kernelMock, $result));
        self::assertFalse($this->publicResponseState->isDeclared());
        self::assertSame([], $this->publishedHeaders);
        self::assertSame([], $this->clearedHeaders);
    }

    /**
     * Outcomes that are not a page. The first is what a lookup that found nothing produces; the rest are there
     * because this plugin sits at the end of a chain anything could have handed something to.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function absentPageDataProvider(): array
    {
        return [
            'nothing was stored' => [false],
            'nothing at all' => [null],
            'something that is not a response' => ['a page, but not as an object'],
        ];
    }

    /**
     * The page that came out of storage is the object handed back, so the rewrite lands on the response the
     * visitor is actually going to receive.
     *
     * @return void
     */
    public function testHandsBackThePageItWasGiven(): void
    {
        $this->applierMock->method('resolve')->willReturn(new CacheDirectives(false, 120, null, true));

        self::assertSame(
            $this->responseMock,
            $this->plugin->afterLoad($this->kernelMock, $this->responseMock)
        );
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
     * The request reports no controller action, which is the state it is genuinely in at this moment: a page
     * is fetched from storage before the request has been routed at all.
     *
     * @return ResponseSnapshotFactory
     */
    private function createSnapshotFactory(): ResponseSnapshotFactory
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/women/tops.html');
        $request->method('getFullActionName')->willReturn('__');

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
