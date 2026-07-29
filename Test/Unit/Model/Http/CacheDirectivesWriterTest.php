<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Http;

use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectivesWriter;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the only class that changes a response.
 */
class CacheDirectivesWriterTest extends TestCase
{
    private CacheDirectivesWriter $writer;

    /**
     * Build the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->writer = new CacheDirectivesWriter();
    }

    /**
     * The directives are published, and the two headers that would otherwise argue with them are brought into
     * line in the same breath: the legacy caching header is made to agree, and the absolute expiry is removed
     * rather than restated.
     *
     * @return void
     */
    public function testPublishesTheDirectivesAndSettlesTheHeadersThatWouldContradictThem(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $setCalls = [];
        $clearedHeaders = [];

        $response->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $replace) use (&$setCalls, $response): HttpResponse {
                $setCalls[] = [$name, $value, $replace];

                return $response;
            }
        );
        $response->method('clearHeader')->willReturnCallback(
            function (string $name) use (&$clearedHeaders, $response): HttpResponse {
                $clearedHeaders[] = $name;

                return $response;
            }
        );

        $this->writer->write($response, new CacheDirectives(false, 120, null, true));

        self::assertSame(
            [
                ['Cache-Control', 'private, must-revalidate, max-age=120', true],
                ['Pragma', 'cache', true],
            ],
            $setCalls
        );
        self::assertSame(['Expires'], $clearedHeaders);
    }

    /**
     * A shared lifetime reaches the response, so that the cache in front keeps the long copy the browser is
     * not being offered.
     *
     * The value read back is not written character for character. The framework parses Cache-Control into
     * directives and re-emits them in alphabetical order, so the order any writer chooses is discarded on the
     * way out -- as it is for the header Magento itself writes for a cacheable page. What matters is that the
     * same directives survive, which they do.
     *
     * @return void
     */
    public function testPublishesASharedLifetimeWhenTheDirectivesCarryOne(): void
    {
        $response = $this->createRealBehaviourResponse();

        $this->writer->write($response, new CacheDirectives(true, 120, 86400, true));

        self::assertSame(
            'max-age=120, must-revalidate, public, s-maxage=86400',
            $this->headerValue($response, 'Cache-Control')
        );
    }

    /**
     * Magento's built-in full page cache decides whether it may store a page, and for how long, by looking for
     * the public visibility token followed by a shared lifetime in this header. That test is applied to the
     * value the framework re-emits, not to the one that was written, so it has to keep matching after the
     * directives have been reordered alphabetically -- which it does, since the visibility token sorts ahead
     * of the shared lifetime. A page published for a shared cache therefore remains storable.
     *
     * @return void
     */
    public function testAPagePublishedForASharedCacheStaysStorableByMagento(): void
    {
        $response = $this->createRealBehaviourResponse();

        $this->writer->write($response, new CacheDirectives(true, 120, 86400, true));

        self::assertSame(
            1,
            preg_match(
                '/public.*s-maxage=(\d+)/',
                (string)$this->headerValue($response, 'Cache-Control'),
                $matches
            )
        );
        self::assertSame('86400', $matches[1]);
    }

    /**
     * The same test finds nothing on a page published for the browser alone, so a page the built-in cache has
     * already stored cannot be picked up and stored a second time. That property comes from the absence of the
     * public token and survives the reordering along with everything else.
     *
     * @return void
     */
    public function testAPagePublishedForTheBrowserAloneCannotBeStoredAgain(): void
    {
        $response = $this->createRealBehaviourResponse();

        $this->writer->write($response, new CacheDirectives(false, 120, null, true));

        self::assertSame(
            0,
            preg_match('/public.*s-maxage=(\d+)/', (string)$this->headerValue($response, 'Cache-Control'))
        );
    }

    /**
     * Publishing over Magento's no-cache set leaves nothing of it behind: the directives replace the old ones,
     * the legacy header stops saying the opposite, and the expiry stamped in the past is gone rather than
     * merely outvoted.
     *
     * @return void
     */
    public function testLeavesNothingOfTheNoCacheHeadersBehind(): void
    {
        $response = $this->createRealBehaviourResponse();
        $this->applyNoCacheHeaders($response);

        $this->writer->write($response, new CacheDirectives(false, 120, null, true));

        self::assertSame(
            'max-age=120, must-revalidate, private',
            $this->headerValue($response, 'Cache-Control')
        );
        self::assertSame('cache', $this->headerValue($response, 'Pragma'));
        self::assertFalse($response->getHeaders()->has('Expires'));
    }

    /**
     * Publishing twice in one request leaves exactly what publishing once would have. Nothing accumulates,
     * because every header is written by replacement, so no caller has to know how many times it was reached.
     *
     * @return void
     */
    public function testPublishingTwiceLeavesTheSameHeadersAsPublishingOnce(): void
    {
        $directives = new CacheDirectives(false, 120, null, true);

        $writtenOnce = $this->createRealBehaviourResponse();
        $this->applyNoCacheHeaders($writtenOnce);
        $this->writer->write($writtenOnce, $directives);

        $writtenTwice = $this->createRealBehaviourResponse();
        $this->applyNoCacheHeaders($writtenTwice);
        $this->writer->write($writtenTwice, $directives);
        $this->writer->write($writtenTwice, $directives);

        self::assertSame(
            $writtenOnce->getHeaders()->toString(),
            $writtenTwice->getHeaders()->toString()
        );
    }

    /**
     * Build a response whose header handling is the framework's own, so that what is asserted is the state the
     * headers end up in rather than the calls that were made to get there.
     *
     * @return HttpResponse
     */
    private function createRealBehaviourResponse(): HttpResponse
    {
        return $this->getMockBuilder(HttpResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Put the headers Magento writes when it wants nothing kept onto a response.
     *
     * They are written by hand rather than by asking the response for them, because Magento builds the expiry
     * out of collaborators a unit test has no reason to assemble. The values are the ones core publishes.
     *
     * @param HttpResponse $response Response to seed
     * @return void
     */
    private function applyNoCacheHeaders(HttpResponse $response): void
    {
        $response->setHeader('Pragma', 'no-cache', true);
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $response->setHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT', true);
    }

    /**
     * Read one header value back off a response.
     *
     * @param HttpResponse $response Response to read
     * @param string $name Header to look for
     * @return string|null Value carried, or null when the header is absent
     */
    private function headerValue(HttpResponse $response, string $name): ?string
    {
        $header = $response->getHeader($name);

        return $header instanceof HeaderInterface ? $header->getFieldValue() : null;
    }
}
