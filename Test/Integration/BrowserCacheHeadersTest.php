<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Integration;

use Hryvinskyi\BrowserCacheControl\Api\BrowserCacheTtlApplierInterface;
use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Model\Eligibility\ExcludedActionRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * What a real storefront request actually ends up telling the visitor's browser.
 *
 * Everything below drives a genuine dispatch through the whole application and then reads the three headers
 * that decide how long a browser may reuse a page: the directives themselves, the legacy header an old
 * intermediary still acts on, and the absolute moment of expiry. Nothing is mocked; the point of these tests
 * is precisely the parts a unit test has to stand in for -- which seam fires, in which order, and what is
 * left on the response once Magento has had its own last word on it.
 *
 * Three properties of the framework decide whether any of this means anything, and each is honoured
 * deliberately rather than by accident.
 *
 * Whole-page caching has to be switched on as a *cache type*, which is a different setting from the caching
 * application. Magento only declares a response publicly cacheable when that type is enabled, and the
 * integration installation runs with every cache type off, so without the annotation on this class no
 * response would ever be declared cacheable, this module would resolve nothing, and every "the headers were
 * left alone" assertion would pass for the wrong reason.
 *
 * The caching application and the shared lifetime are read without a scope, so a store-scoped fixture is
 * invisible to them; both are therefore set at default scope. This module's own settings are store scoped
 * and read through the ordinary fallback, so setting those at default scope reaches them too.
 *
 * The rendered order of the directives is not the order they were written in. The header object sorts its
 * directives alphabetically before rendering, so a response this module marks public leaves as
 * "max-age=120, must-revalidate, public, s-maxage=86400" whatever order it was assembled in. That is
 * harmless -- Magento's own detection of a storable response only requires the word public to appear ahead
 * of the shared lifetime, which alphabetical order happens to preserve -- but it does mean these assertions
 * are written as regular expressions and per-directive containment. Anyone tempted to replace them with one
 * comparison against a literal string will be writing a test that fails for a reason that has nothing to do
 * with this module.
 *
 * NOT EXECUTED. The integration suite on this installation cannot run at all: the framework's bootstrap dies
 * while installing, on a database left in a partial state. This file is written to the conventions the
 * suite expects and is meant to run once that database is rebuilt; nothing here has been proven by an actual
 * run.
 *
 * @magentoAppIsolation enabled
 * @magentoCache full_page enabled
 */
class BrowserCacheHeadersTest extends AbstractController
{
    /**
     * A page every installation ships, which needs no fixture and which Magento declares fully cacheable.
     */
    private const CACHEABLE_PAGE = '/enable-cookies';

    /**
     * A page carrying a block declared uncacheable, so nothing ever declares the response publicly cacheable.
     */
    private const NON_CACHEABLE_PAGE = '/checkout/cart';

    /**
     * The endpoint a shared cache calls back for one embedded fragment rather than for a page.
     */
    private const ESI_ENDPOINT = '/page_cache/block/esi';

    /**
     * The full action name that endpoint really reports.
     *
     * It is built from the route id and not from the front name the URL shows, which is why this reads
     * magento_pagecache and not page_cache. One of the tests below asserts that the framework still reports
     * exactly this, because the exclusion keyed on it is worthless the moment the two drift apart.
     */
    private const ESI_ACTION_NAME = 'magento_pagecache_block_esi';

    /**
     * The action name of the ordinary page used as the control in those same probes.
     */
    private const PAGE_ACTION_NAME = 'cms_page_view';

    /**
     * How Magento's built-in cache recognises a response it is allowed to store, and for how long.
     *
     * A response that does not match this is one the built-in cache is structurally incapable of storing.
     */
    private const STORABLE_RESPONSE_PATTERN = '/public.*s-maxage=(\d+)/';

    /**
     * Header carrying the directives.
     */
    private const CACHE_CONTROL = 'Cache-Control';

    /**
     * Legacy caching header, which has to agree with the directives or it contradicts them.
     */
    private const PRAGMA = 'Pragma';

    /**
     * Absolute expiry, which this module removes rather than recomputing.
     */
    private const EXPIRES = 'Expires';

    /**
     * Start every test with an empty page store.
     *
     * Two of these tests turn on whether a page was served fresh or replayed from storage, and the store is a
     * real cache backend that outlives a single test. Left alone, a page one test stored would silently turn
     * the next test's first visit into a repeat visit.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->_objectManager->get(FullPageCache::class)->clean();
    }

    /**
     * A page behind a shared cache keeps the lifetime that cache was promised and gains a short browser one.
     *
     * Both lifetimes have to travel together. Publishing only the short one would hand the shared cache the
     * browser's lifetime as its own and collapse a day of caching into two minutes, and dropping the word
     * public would stop the response being storable at all.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testAPageBehindASharedCacheKeepsItsSharedLifetimeAndGainsAShortBrowserOne(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        $response = $this->dispatchedResponse();
        $directives = $this->directivesOf($response);

        self::assertMatchesRegularExpression(
            self::STORABLE_RESPONSE_PATTERN,
            $directives,
            'The response no longer reads as storable to a shared cache. Either the word public is gone or it '
            . 'now falls after the shared lifetime, and both of those stop the page being cached at all.'
        );

        preg_match(self::STORABLE_RESPONSE_PATTERN, $directives, $matches);
        self::assertSame(
            '86400',
            $matches[1],
            'The shared cache is being promised a different lifetime from the one configured, so this module '
            . 'has changed how long the page is kept rather than only how long the browser may reuse it.'
        );

        self::assertStringContainsString(
            'max-age=120',
            $directives,
            'The browser was not handed the configured lifetime, which is the entire point of the rewrite.'
        );
        self::assertStringContainsString(
            'must-revalidate',
            $directives,
            'The configured revalidation directive did not survive into the header.'
        );
        self::assertStringNotContainsString(
            'no-store',
            $directives,
            'A browser told no-store keeps nothing at all, which cancels the lifetime beside it.'
        );

        self::assertSame(
            'cache',
            $this->headerOf($response, self::PRAGMA),
            'The legacy caching header still contradicts the directives beside it.'
        );
        self::assertNull(
            $this->headerOf($response, self::EXPIRES),
            'An absolute expiry stamped while the page was rendered is already wrong by the time a shared '
            . 'cache hands that same stored page to somebody hours later, so it has to be removed rather '
            . 'than recomputed.'
        );
    }

    /**
     * A page the built-in cache has just stored is offered to the visitor's browser and to nothing else.
     *
     * The two halves of this are what the whole feature rests on. What was stored still carries the headers
     * Magento wrote before saving -- never keep this -- so the stored copy is untouched and keeps its full
     * lifetime; what leaves carries a short browser lifetime marked private, so no proxy between Magento and
     * the visitor may keep a copy of its own and hand it to the next person. That the outgoing header cannot
     * be read as storable is not a nicety either: it is what makes a second pass through the built-in cache
     * structurally incapable of storing the page again.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 1
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testAFreshlyStoredPageIsOfferedToTheBrowserAloneAndNeverToAProxy(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        $storedHeaders = $this->storedPageHeaders();
        self::assertNotNull(
            $storedHeaders,
            'Nothing was stored for this page, so there is no fresh-visit-then-store to say anything about. '
            . 'Find out why the page was not cached before reading anything else here as a failure.'
        );
        self::assertStringContainsString(
            'no-store',
            (string)($storedHeaders[self::CACHE_CONTROL] ?? ''),
            'What was written into the page store no longer carries the headers Magento puts on a page before '
            . 'saving it. This module must not reach the response until after the copy has been taken.'
        );

        $response = $this->dispatchedResponse();
        $directives = $this->directivesOf($response);

        self::assertStringContainsString(
            'private',
            $directives,
            'Without this the page is offered to every proxy between Magento and the visitor, with nothing in '
            . 'the response telling such a proxy to keep one copy per customer context and no way to purge '
            . 'the copies it makes.'
        );
        self::assertStringContainsString(
            'max-age=120',
            $directives,
            'The visitor was not handed the configured lifetime, so a repeat request still reaches the origin.'
        );
        self::assertStringContainsString('must-revalidate', $directives, 'The revalidation directive is gone.');
        self::assertStringNotContainsString(
            's-maxage',
            $directives,
            'A lifetime addressed to a shared cache means nothing here: the only cache entitled to keep '
            . 'another copy at this point is the visitor\'s own browser.'
        );
        self::assertStringNotContainsString(
            'public',
            $directives,
            'The literal word public is the one thing the built-in cache looks for before storing a response. '
            . 'While it is absent, a second pass cannot store this page however it is reached.'
        );
        self::assertDoesNotMatchRegularExpression(
            self::STORABLE_RESPONSE_PATTERN,
            $directives,
            'The outgoing response reads as storable, which both invites a downstream proxy to keep it and '
            . 'lets the built-in cache store it a second time.'
        );

        self::assertSame(
            'cache',
            $this->headerOf($response, self::PRAGMA),
            'The legacy header still says no-cache, which tells an old intermediary the opposite of the '
            . 'directives beside it.'
        );
        self::assertNull(
            $this->headerOf($response, self::EXPIRES),
            'The expiry Magento stamped a year in the past is still on the response, which tells any cache '
            . 'the page is already stale and defeats the lifetime just written.'
        );
    }

    /**
     * A page replayed out of the built-in cache carries the browser lifetime too.
     *
     * This is the visit that matters most and the one that gains nothing without a rewrite. What was stored
     * was deliberately stripped of every caching header before it was saved, so every replay would otherwise
     * hand the visitor who has already proved they come back a response saying never keep this.
     *
     * The replay is asked for directly rather than by dispatching a second time, because the response a
     * replay produces is a newly built object that never becomes the request's own response -- so reading the
     * request's response afterwards would be reading the fresh visit again and proving nothing. The visitor
     * is a guest throughout: a signed-in visitor's replay is always stripped again by Magento's own check
     * that the cached context still matches, so a browser lifetime could never survive one.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 1
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testAPageReplayedFromTheBuiltInCacheCarriesTheBrowserLifetimeAsWell(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        $replayed = $this->_objectManager->get(Kernel::class)->load();
        self::assertInstanceOf(
            HttpResponse::class,
            $replayed,
            'There was nothing in the page store to replay, so this test says nothing about a repeat visit. '
            . 'The first request has to have stored the page before any of the rest of this means anything.'
        );

        $directives = $this->directivesOf($replayed);

        self::assertStringNotContainsString(
            'no-store',
            $directives,
            'The repeat visitor is still being told to keep nothing, which is exactly the header the stored '
            . 'copy carries and exactly what this rewrite exists to replace.'
        );
        self::assertStringContainsString(
            'private',
            $directives,
            'The replayed page is offered to proxies as well as to the visitor.'
        );
        self::assertStringContainsString(
            'max-age=120',
            $directives,
            'The repeat visitor gained no browser lifetime at all, so returning to the page still costs a '
            . 'request to the origin.'
        );
        self::assertStringContainsString('must-revalidate', $directives, 'The revalidation directive is gone.');
        self::assertStringNotContainsString(
            's-maxage',
            $directives,
            'A shared lifetime on a replayed page addresses a cache that already has its copy.'
        );

        self::assertSame(
            'cache',
            $this->headerOf($replayed, self::PRAGMA),
            'The stored no-cache value of the legacy header survived the rewrite and contradicts it.'
        );
        self::assertNull(
            $this->headerOf($replayed, self::EXPIRES),
            'The stored past expiry survived the rewrite, which marks the page stale on arrival.'
        );
    }

    /**
     * With the feature switched off nothing about the response differs from stock Magento.
     *
     * Installing the module is not meant to change a single header until somebody turns it on, so this is
     * both the off switch and the description of what every other test here is a departure from.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 0
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testSwitchingTheFeatureOffLeavesMagentosOwnHeadersUntouched(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        $response = $this->dispatchedResponse();

        $this->assertStockPublicHeaders(
            $response,
            86400,
            'A response was rewritten while the feature was switched off.'
        );
    }

    /**
     * A page Magento never declared cacheable is not handed a browser lifetime either.
     *
     * This module only ever relaxes what the browser is told about a page Magento was already willing to
     * cache. It never makes an uncacheable page cacheable, and a page carrying customer-specific content is
     * the case where getting that wrong would show one visitor another's basket.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testAPageMagentoNeverDeclaredCacheableIsLeftAlone(): void
    {
        $this->dispatch(self::NON_CACHEABLE_PAGE);

        $response = $this->dispatchedResponse();

        self::assertSame(
            200,
            $response->getHttpResponseCode(),
            'The page did not render, so its headers say nothing about how an uncacheable page is treated.'
        );

        $directives = (string)$this->headerOf($response, self::CACHE_CONTROL);

        self::assertDoesNotMatchRegularExpression(
            self::STORABLE_RESPONSE_PATTERN,
            $directives,
            'Magento declared this page storable after all, so it is not the uncacheable page this test '
            . 'needs and nothing below it is meaningful.'
        );
        self::assertStringNotContainsString(
            'max-age=120',
            $directives,
            'A page holding customer-specific content was handed a browser lifetime, so a visitor may be '
            . 'shown a page rendered for somebody else.'
        );
        self::assertStringNotContainsString(
            'must-revalidate',
            $directives,
            'The revalidation directive appeared on a page nothing should have written to.'
        );
        self::assertNotSame(
            'cache',
            $this->headerOf($response, self::PRAGMA),
            'The legacy header was set to a cacheable value on a page that must not be cached.'
        );
        self::assertNull(
            $this->headerOf($response, self::EXPIRES),
            'An expiry appeared on a page nothing declared cacheable, so something published a lifetime for '
            . 'it after all.'
        );
    }

    /**
     * The endpoint that answers a shared cache's request for one embedded fragment keeps its own lifetime.
     *
     * That endpoint is not a page view. It publishes the individual block's lifetime, which is frequently
     * zero, and it exists to be called back by the cache in front rather than navigated to. Two independent
     * things keep this module away from it, and both are probed here rather than assumed: the endpoint is
     * excluded by name, and a declared lifetime of zero seconds leaves no browser lifetime to grant. Each
     * probe changes exactly one thing away from a control that does produce a rewrite, so neither can pass
     * by the module simply being inert.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testTheEmbeddedFragmentEndpointKeepsTheLifetimeItPublishedForItself(): void
    {
        $this->dispatch(self::ESI_ENDPOINT);

        self::assertSame(
            self::ESI_ACTION_NAME,
            $this->dispatchedRequest()->getFullActionName(),
            'The endpoint reports a different name from the one it is excluded under, so the exclusion is '
            . 'keyed on a name that never occurs and excludes nothing.'
        );
        self::assertFalse(
            $this->_objectManager->get(ExcludedActionRule::class)->isEligible(
                $this->snapshotWith(self::ESI_ACTION_NAME, 86400)
            ),
            'The exclusion as it is actually wired does not recognise the name the endpoint reports.'
        );

        $applier = $this->_objectManager->get(BrowserCacheTtlApplierInterface::class);

        self::assertNotNull(
            $applier->resolve($this->snapshotWith(self::PAGE_ACTION_NAME, 86400), WritePoint::SharedCacheDeclaration),
            'An ordinary page with an ordinary declared lifetime produced no rewrite either, so the two '
            . 'refusals below prove nothing: everything is being refused.'
        );
        self::assertNull(
            $applier->resolve($this->snapshotWith(self::ESI_ACTION_NAME, 86400), WritePoint::SharedCacheDeclaration),
            'Changing only the action name to the fragment endpoint no longer refuses the rewrite.'
        );
        self::assertNull(
            $applier->resolve($this->snapshotWith(self::PAGE_ACTION_NAME, 0), WritePoint::SharedCacheDeclaration),
            'Changing only the declared lifetime to zero seconds no longer refuses the rewrite, so a fragment '
            . 'no block claimed would be handed a browser lifetime Magento never offered it.'
        );

        $this->assertStockPublicHeaders(
            $this->dispatchedResponse(),
            0,
            'The fragment endpoint\'s own published lifetime was overwritten.'
        );
    }

    /**
     * A request path an administrator excluded keeps Magento's own headers.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 2
     * @magentoConfigFixture default/system/full_page_cache/ttl 86400
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 120
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/excluded_url_patterns ^/enable-cookies
     * @return void
     */
    public function testAnExcludedRequestPathKeepsMagentosOwnHeaders(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        self::assertContains(
            '^/enable-cookies',
            $this->_objectManager->get(ConfigInterface::class)->getExcludedUrlPatterns($this->currentStoreId()),
            'The configured exclusion never reached the module -- it was either not read or dropped as '
            . 'unusable -- so the headers below would have been left alone for some entirely other reason.'
        );

        $this->assertStockPublicHeaders(
            $this->dispatchedResponse(),
            86400,
            'A request path matching a configured exclusion was rewritten anyway.'
        );
    }

    /**
     * A browser lifetime longer than the one the page is kept for is cut down to it, never published as-is.
     *
     * A browser holding a page for longer than the cache in front of it holds the same page would keep
     * showing a version the origin has already replaced and has no way to reach. The cut is made on the
     * lifetime Magento actually announced for this response, which is why this is asserted on a first visit:
     * on a replay there is no way to know how much of the stored copy's lifetime is left, so there is nothing
     * honest to cut against.
     *
     * @magentoConfigFixture default/system/full_page_cache/caching_application 1
     * @magentoConfigFixture default/system/full_page_cache/ttl 60
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/enabled 1
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/browser_ttl 300
     * @magentoConfigFixture default/hryvinskyi_browser_cache_control/general/must_revalidate 1
     * @return void
     */
    public function testABrowserLifetimeLongerThanThePagesOwnIsCutDownToIt(): void
    {
        $this->dispatch(self::CACHEABLE_PAGE);

        self::assertSame(
            300,
            $this->_objectManager->get(ConfigInterface::class)->getBrowserTtl($this->currentStoreId()),
            'The configured browser lifetime is not the longer one this test needs, so a short lifetime in '
            . 'the header below would say nothing about anything being cut down.'
        );

        $response = $this->dispatchedResponse();
        $directives = $this->directivesOf($response);

        self::assertStringContainsString(
            'max-age=60',
            $directives,
            'The browser lifetime was not cut down to the lifetime the page itself is kept for.'
        );
        self::assertStringNotContainsString(
            'max-age=300',
            $directives,
            'The configured lifetime was published whole, so the browser may hold the page long after the '
            . 'copy behind it has been replaced.'
        );
        self::assertStringContainsString(
            'private',
            $directives,
            'The response is offered to proxies as well as to the visitor.'
        );
        self::assertStringNotContainsString(
            's-maxage',
            $directives,
            'A lifetime addressed to a shared cache appeared on a page that has already been stored.'
        );

        self::assertSame(
            'cache',
            $this->headerOf($response, self::PRAGMA),
            'The legacy header still contradicts the directives beside it.'
        );
        self::assertNull(
            $this->headerOf($response, self::EXPIRES),
            'A past absolute expiry survived and marks the page stale on arrival.'
        );
    }

    /**
     * Assert a response carries exactly what Magento publishes for a cacheable page, and nothing of this
     * module's.
     *
     * The three fingerprints checked for are the ones the module leaves and Magento never does: a revalidation
     * directive, a browser lifetime shorter than the declared one, and an absent expiry.
     *
     * @param HttpResponse $response Response to inspect
     * @param int $declaredTtl Lifetime Magento announced for this response
     * @param string $because What it would mean for this to fail
     * @return void
     */
    private function assertStockPublicHeaders(HttpResponse $response, int $declaredTtl, string $because): void
    {
        $directives = $this->directivesOf($response);

        self::assertStringContainsString('public', $directives, $because);
        self::assertStringContainsString('max-age=' . $declaredTtl, $directives, $because);
        self::assertStringContainsString('s-maxage=' . $declaredTtl, $directives, $because);
        self::assertStringNotContainsString('must-revalidate', $directives, $because);
        self::assertStringNotContainsString('max-age=120', $directives, $because);

        self::assertSame(
            'cache',
            $this->headerOf($response, self::PRAGMA),
            $because . ' The legacy caching header is not what Magento leaves on a cacheable page.'
        );
        self::assertNotNull(
            $this->headerOf($response, self::EXPIRES),
            $because . ' Magento stamps an absolute expiry on a cacheable page and this module removes it, so '
            . 'its absence is itself the evidence of a rewrite.'
        );
    }

    /**
     * Facts about a response, differing from a control in exactly one respect.
     *
     * Everything except the action name and the declared lifetime is held at a value that passes every rule,
     * so that a refusal can only have come from the one thing that was changed.
     *
     * @param string $fullActionName Route, controller and action joined by underscores
     * @param int $declaredSharedTtl Lifetime Magento announced for this response
     * @return ResponseSnapshot
     */
    private function snapshotWith(string $fullActionName, int $declaredSharedTtl): ResponseSnapshot
    {
        return new ResponseSnapshot(
            'GET',
            200,
            self::CACHEABLE_PAGE,
            $fullActionName,
            null,
            false,
            $declaredSharedTtl,
            $this->currentStoreId()
        );
    }

    /**
     * The dispatched request, as the concrete type that knows which action answered it.
     *
     * @return HttpRequest
     */
    private function dispatchedRequest(): HttpRequest
    {
        $request = $this->getRequest();
        self::assertInstanceOf(HttpRequest::class, $request, 'The request is not the HTTP one that was routed.');

        return $request;
    }

    /**
     * The response the dispatched request produced, as the concrete type whose headers can be read.
     *
     * @return HttpResponse
     */
    private function dispatchedResponse(): HttpResponse
    {
        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response, 'The request produced no HTTP response.');

        return $response;
    }

    /**
     * The Cache-Control value a response carries, failing loudly rather than silently asserting on nothing.
     *
     * @param HttpResponse $response Response to read
     * @return string
     */
    private function directivesOf(HttpResponse $response): string
    {
        $directives = $this->headerOf($response, self::CACHE_CONTROL);
        self::assertNotNull(
            $directives,
            'The response carries no Cache-Control header at all, so every assertion about its directives '
            . 'would be answering a question that was never asked.'
        );

        return $directives;
    }

    /**
     * One header's value, or null when the response does not carry it.
     *
     * @param HttpResponse $response Response to read
     * @param string $name Header name
     * @return string|null
     */
    private function headerOf(HttpResponse $response, string $name): ?string
    {
        $header = $response->getHeader($name);

        return $header instanceof HeaderInterface ? $header->getFieldValue() : null;
    }

    /**
     * The headers of the copy the built-in cache holds for the request just dispatched.
     *
     * The entry is addressed with the same identifier the cache saved it under, so this reads the very copy
     * a repeat visitor would be served rather than an approximation of it.
     *
     * @return array<string, string>|null Headers as stored, or null when nothing was stored
     */
    private function storedPageHeaders(): ?array
    {
        $identifier = $this->_objectManager->get(IdentifierInterface::class)->getValue();
        $stored = $this->_objectManager->get(FullPageCache::class)->load($identifier);

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        $data = $this->_objectManager->get(SerializerInterface::class)->unserialize($stored);

        if (!is_array($data) || !isset($data['headers']) || !is_array($data['headers'])) {
            return null;
        }

        $headers = [];

        foreach ($data['headers'] as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }

        return $headers;
    }

    /**
     * The store the dispatched request was served for.
     *
     * @return int
     */
    private function currentStoreId(): int
    {
        return (int)$this->_objectManager->get(StoreManagerInterface::class)->getStore()->getId();
    }
}
