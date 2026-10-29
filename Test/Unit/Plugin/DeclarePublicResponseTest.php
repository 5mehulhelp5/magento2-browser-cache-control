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
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotReader;
use Hryvinskyi\BrowserCacheControl\Test\Unit\Stub\GeneratedFactoriesTrait;
use Magento\Framework\App\Http\Context as HttpContext;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Hryvinskyi\BrowserCacheControl\Plugin\DeclarePublicResponse;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the moment Magento declares a response publicly cacheable.
 */
class DeclarePublicResponseTest extends TestCase
{
    use GeneratedFactoriesTrait;

    /**
     * @var BrowserCacheTtlApplierInterface&MockObject
     */
    private $applierMock;

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

    private DeclarePublicResponse $plugin;

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
        $this->publicResponseState = new PublicResponseState();
        $this->responseMock = $this->createRecordingResponse();

        $this->plugin = new DeclarePublicResponse(
            $this->publicResponseState,
            $this->createSnapshotReader(),
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
        $this->applierMock->expects(self::once())
            ->method('resolve')
            ->with(self::isInstanceOf(ResponseSnapshot::class), WritePoint::SharedCacheDeclaration)
            ->willReturn(new CacheDirectives(true, 120, 86400, true));

        $this->plugin->afterSetPublicHeaders($this->responseMock, null, 86400);

        self::assertSame(
            [
                ['Cache-Control', 'public, must-revalidate, max-age=120, s-maxage=86400', true],
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
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterSetPublicHeaders($this->responseMock, null, 86400);

        self::assertSame([], $this->publishedHeaders);
        self::assertSame([], $this->clearedHeaders);
    }

    /**
     * The declaration and the lifetime it carried are recorded, because the moments that follow need the fact
     * and cannot recover it from a response Magento has since rewritten.
     *
     * @param mixed $ttl Lifetime as the framework passes it
     * @param int|null $expected Lifetime that must be remembered
     * @return void
     * @dataProvider announcedLifetimeDataProvider
     */
    public function testRecordsTheDeclarationAndTheLifetimeItCarried(mixed $ttl, ?int $expected): void
    {
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterSetPublicHeaders($this->responseMock, null, $ttl);

        self::assertTrue($this->publicResponseState->isDeclared());
        self::assertSame($expected, $this->publicResponseState->getSharedTtl());
    }

    /**
     * Shapes an announced lifetime arrives in, and what each of them means.
     *
     * A numeric zero is the case that carries weight: it is a lifetime that was genuinely announced, which is
     * what Magento publishes for an embedded fragment no block claimed, and it has to survive as zero. Read as
     * an absent lifetime it would fall back to the configured one and hand that fragment a browser lifetime.
     *
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function announcedLifetimeDataProvider(): array
    {
        return [
            'a whole number of seconds' => [86400, 86400],
            'seconds as text, which is how configuration holds them' => ['86400', 86400],
            'an announced nothing' => [0, 0],
            'an announced nothing, as text' => ['0', 0],
            'nothing announced at all' => [null, null],
            'something that is not a lifetime' => ['forever', null],
            'a shape no lifetime ever takes' => [[], null],
        ];
    }

    /**
     * The declaration is recorded even where nothing is published, because it belongs to the later moments
     * rather than to this one.
     *
     * @return void
     */
    public function testRecordsTheDeclarationEvenWhenNothingIsPublished(): void
    {
        $this->applierMock->method('resolve')->willReturn(null);

        $this->plugin->afterSetPublicHeaders($this->responseMock, null, 86400);

        self::assertTrue($this->publicResponseState->isDeclared());
    }

    /**
     * Whatever the framework was going to hand back is handed back, so nothing about the response's own
     * behaviour changes by this plugin being present.
     *
     * @return void
     */
    public function testHandsBackTheOutcomeItWasGiven(): void
    {
        $this->applierMock->method('resolve')->willReturn(new CacheDirectives(true, 120, 86400, true));

        self::assertNull($this->plugin->afterSetPublicHeaders($this->responseMock, null, 86400));
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
     * @return ResponseSnapshotReader
     */
    private function createSnapshotReader(): ResponseSnapshotReader
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/women/tops.html');
        $request->method('getFullActionName')->willReturn('catalog_category_view');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ResponseSnapshotReader(
            $request,
            $this->publicResponseState,
            $this->createMock(PageCacheEnvironmentInterface::class),
            $storeManager,
            $this->createMock(HttpContext::class),
            $this->snapshotFactoryStub()
        );
    }
}
