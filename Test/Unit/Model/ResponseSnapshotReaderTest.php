<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Hryvinskyi\BrowserCacheControl\Model\PublicResponseState;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotReader;
use Hryvinskyi\BrowserCacheControl\Test\Unit\Stub\GeneratedFactoriesTrait;
use Magento\Framework\App\Http\Context as HttpContext;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\File as FileResponse;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the translation from Magento's request and response objects into the facts a decision needs.
 */
class ResponseSnapshotReaderTest extends TestCase
{
    use GeneratedFactoriesTrait;

    /**
     * @var HttpRequest&MockObject
     */
    private $requestMock;

    /**
     * @var PageCacheEnvironmentInterface&MockObject
     */
    private $environmentMock;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManagerMock;

    /**
     * @var HttpContext&MockObject
     */
    private $httpContextMock;

    private PublicResponseState $publicResponseState;

    /**
     * Build the collaborators shared by every case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(HttpRequest::class);
        $this->environmentMock = $this->createMock(PageCacheEnvironmentInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->httpContextMock = $this->createMock(HttpContext::class);
        $this->publicResponseState = new PublicResponseState();

        $this->requestMock->method('getMethod')->willReturn('GET');
        $this->requestMock->method('getPathInfo')->willReturn('/women/tops.html');
        $this->requestMock->method('getFullActionName')->willReturn('catalog_category_view');
        $this->givenStoreId(3);
    }

    /**
     * Every fact the decision works from is taken from the place that actually holds it.
     *
     * @return void
     */
    public function testGathersEveryFactFromTheRequestAndTheResponse(): void
    {
        $this->publicResponseState->declarePublic(86400);

        $snapshot = $this->createReader()->read(
            $this->createResponse(200, 'public, max-age=86400, s-maxage=86400')
        );

        self::assertSame('GET', $snapshot->httpMethod);
        self::assertSame(200, $snapshot->statusCode);
        self::assertSame('/women/tops.html', $snapshot->requestPath);
        self::assertSame('catalog_category_view', $snapshot->fullActionName);
        self::assertSame('public, max-age=86400, s-maxage=86400', $snapshot->currentCacheControl);
        self::assertFalse($snapshot->markedNotCacheable);
        self::assertSame(86400, $snapshot->declaredSharedTtl);
        self::assertSame(3, $snapshot->storeId);
    }

    /**
     * The path always begins with a slash, whichever shape it arrived in, so that an exclusion anchored at the
     * start of a path means one thing rather than two.
     *
     * @param string $pathInfo Path as the request reports it
     * @param string $expected Path the snapshot must carry
     * @return void
     * @dataProvider requestPathDataProvider
     */
    public function testAlwaysReportsAPathBeginningWithASlash(string $pathInfo, string $expected): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getFullActionName')->willReturn('catalog_category_view');
        $request->method('getPathInfo')->willReturn($pathInfo);

        $reader = new ResponseSnapshotReader(
            $request,
            $this->publicResponseState,
            $this->environmentMock,
            $this->storeManagerMock,
            $this->httpContextMock,
            $this->snapshotFactoryStub()
        );

        self::assertSame($expected, $reader->read($this->createResponse(200, null))->requestPath);
    }

    /**
     * Shapes a path can arrive in, and the one shape it leaves in.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requestPathDataProvider(): array
    {
        return [
            'already anchored' => ['/women/tops.html', '/women/tops.html'],
            'not anchored' => ['women/tops.html', '/women/tops.html'],
            'the home page' => ['/', '/'],
            'nothing at all' => ['', '/'],
        ];
    }

    /**
     * A page replayed from storage has not been routed yet, so it names no controller action. That is the
     * normal state of affairs at that moment and not a fault to be corrected.
     *
     * @return void
     */
    public function testCarriesTheEmptyActionNameOfAnUnroutedRequest(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/women/tops.html');
        $request->method('getFullActionName')->willReturn('__');

        $reader = new ResponseSnapshotReader(
            $request,
            $this->publicResponseState,
            $this->environmentMock,
            $this->storeManagerMock,
            $this->httpContextMock,
            $this->snapshotFactoryStub()
        );

        self::assertSame('__', $reader->read($this->createResponse(200, null))->fullActionName);
    }

    /**
     * A response carrying no Cache-Control at all leaves the question unanswered rather than answered with
     * whatever Magento returns in place of a header.
     *
     * @return void
     */
    public function testReportsNoCacheControlWhenTheResponseCarriesNone(): void
    {
        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertNull($snapshot->currentCacheControl);
    }

    /**
     * A response whose class says it may not be cached is recognised without anything having to be set on it.
     * The class used here is the one core hands back for a file download, which carries that marker.
     *
     * @return void
     */
    public function testRecognisesAResponseWhoseClassRefusesCaching(): void
    {
        $response = $this->createMock(FileResponse::class);
        $response->method('getHttpResponseCode')->willReturn(200);
        $response->method('getHeader')->willReturn(false);
        $response->method('getMetadata')->willReturn(null);

        self::assertTrue($this->createReader()->read($response)->markedNotCacheable);
    }

    /**
     * A response flagged uncacheable in its metadata is recognised too, which is the way core refuses a
     * response without changing what class it is.
     *
     * @return void
     */
    public function testRecognisesAResponseFlaggedUncacheableInItsMetadata(): void
    {
        $response = $this->createResponse(200, null);
        $response->method('getMetadata')->with('NotCacheable')->willReturn(true);

        self::assertTrue($this->createReader()->read($response)->markedNotCacheable);
    }

    /**
     * An ordinary response is not treated as refused, so the flag means what it says rather than being set by
     * default.
     *
     * @return void
     */
    public function testTreatsAnOrdinaryResponseAsCacheable(): void
    {
        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertFalse($snapshot->markedNotCacheable);
    }

    /**
     * An announced lifetime of zero seconds reaches the snapshot as zero. Falling back to the configured
     * lifetime here would hand an embedded fragment, which is exactly what Magento announces zero for, a
     * browser lifetime and a shared lifetime nobody ever offered it.
     *
     * @return void
     */
    public function testCarriesAnAnnouncedNothingThroughRatherThanFallingBack(): void
    {
        $this->publicResponseState->declarePublic(0);
        $this->environmentMock->expects(self::never())->method('getSharedTtl');

        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertSame(0, $snapshot->declaredSharedTtl);
    }

    /**
     * A declaration that carried no lifetime is still a declaration, so the configured lifetime is not
     * substituted for it.
     *
     * @return void
     */
    public function testDoesNotSubstituteAConfiguredLifetimeForADeclarationThatCarriedNone(): void
    {
        $this->publicResponseState->declarePublic(null);
        $this->environmentMock->expects(self::never())->method('getSharedTtl');

        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertNull($snapshot->declaredSharedTtl);
    }

    /**
     * Where nothing was announced during the request, the configured lifetime is the best answer available.
     *
     * @return void
     */
    public function testFallsBackToTheConfiguredLifetimeWhenNothingWasAnnounced(): void
    {
        $this->environmentMock->method('getSharedTtl')->willReturn(86400);

        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertSame(86400, $snapshot->declaredSharedTtl);
    }

    /**
     * With neither an announcement nor a usable configured lifetime, the question is left unanswered.
     *
     * @return void
     */
    public function testLeavesTheLifetimeUnknownWhenNeitherSourceHasOne(): void
    {
        $this->environmentMock->method('getSharedTtl')->willReturn(null);

        $snapshot = $this->createReader()->read($this->createResponse(200, null));

        self::assertNull($snapshot->declaredSharedTtl);
    }

    /**
     * A store that cannot be resolved leaves the store unknown instead of failing the page. The settings are
     * then read at default scope, which is a safe reading rather than a guessed one.
     *
     * @return void
     */
    public function testLeavesTheStoreUnknownRatherThanFailingWhenNoneResolves(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());

        $reader = new ResponseSnapshotReader(
            $this->requestMock,
            $this->publicResponseState,
            $this->environmentMock,
            $storeManager,
            $this->httpContextMock,
            $this->snapshotFactoryStub()
        );

        self::assertNull($reader->read($this->createResponse(200, null))->storeId);
    }

    /**
     * A visitor's vary cookie names the variant the cache in front of Magento serves, so it is taken first, as
     * Magento's own page cache identifier does.
     *
     * @return void
     */
    public function testTakesTheVariantFromTheVisitorsVaryCookieFirst(): void
    {
        $this->requestMock->method('get')->with('X-Magento-Vary')->willReturn('cookie-vary');
        $this->httpContextMock->expects(self::never())->method('getVaryString');

        self::assertSame('cookie-vary', $this->createReader()->read($this->createResponse(200, null))->contextVary);
    }

    /**
     * Without a cookie, the context the page is being rendered for decides, as it does for a visitor who has just
     * signed in and does not hold the cookie yet.
     *
     * @param string|null $cookie Value of the vary cookie the request carries
     * @return void
     * @dataProvider missingCookieDataProvider
     */
    public function testTakesTheVariantFromTheRenderingContextWithoutACookie(?string $cookie): void
    {
        $this->requestMock->method('get')->willReturn($cookie);
        $this->httpContextMock->method('getVaryString')->willReturn('context-vary');

        self::assertSame('context-vary', $this->createReader()->read($this->createResponse(200, null))->contextVary);
    }

    /**
     * Ways a request can carry no vary cookie.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function missingCookieDataProvider(): array
    {
        return [
            'no cookie' => [null],
            'empty cookie' => [''],
        ];
    }

    /**
     * Neither a cookie nor a context of its own: the default page every visitor without a context receives.
     *
     * @return void
     */
    public function testReportsTheDefaultVariantWhenNothingSetsTheVisitorApart(): void
    {
        $this->requestMock->method('get')->willReturn(null);
        $this->httpContextMock->method('getVaryString')->willReturn(null);

        self::assertNull($this->createReader()->read($this->createResponse(200, null))->contextVary);
    }

    /**
     * Build the subject over the shared collaborators.
     *
     * @return ResponseSnapshotReader
     */
    private function createReader(): ResponseSnapshotReader
    {
        return new ResponseSnapshotReader(
            $this->requestMock,
            $this->publicResponseState,
            $this->environmentMock,
            $this->storeManagerMock,
            $this->httpContextMock,
            $this->snapshotFactoryStub()
        );
    }

    /**
     * Build a response that answers the three questions the translation asks of it.
     *
     * @param int $statusCode Status the response carries
     * @param string|null $cacheControl Cache-Control value it carries, or null for a response without one
     * @return HttpResponse&MockObject
     */
    private function createResponse(int $statusCode, ?string $cacheControl)
    {
        $header = false;

        if ($cacheControl !== null) {
            $header = $this->createMock(HeaderInterface::class);
            $header->method('getFieldValue')->willReturn($cacheControl);
        }

        $response = $this->createMock(HttpResponse::class);
        $response->method('getHttpResponseCode')->willReturn($statusCode);
        $response->method('getHeader')->with('Cache-Control')->willReturn($header);

        return $response;
    }

    /**
     * Make the store manager answer with a store of the given identifier.
     *
     * @param int $storeId Identifier the resolved store reports
     * @return void
     */
    private function givenStoreId(int $storeId): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $this->storeManagerMock->method('getStore')->willReturn($store);
    }
}
