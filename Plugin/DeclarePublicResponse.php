<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Plugin;

use Hryvinskyi\BrowserCacheControl\Api\BrowserCacheTtlApplierInterface;
use Hryvinskyi\BrowserCacheControl\Api\PublicResponseStateInterface;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectivesWriter;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotReader;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Watches the moment Magento declares a response publicly cacheable, and does two things with it.
 *
 * The first is to remember the declaration and the lifetime it carried. Later moments in the same request need
 * that fact and cannot recover it, because Magento has by then replaced Cache-Control with its own no-cache
 * set, leaving a page the full page cache stored indistinguishable from a page it refused.
 *
 * The second is to publish, when a shared cache in front of Magento is what will store the page. Nothing has
 * stored the response yet at this point, so what is written here is what that cache keeps and obeys -- which
 * is why this is the only moment at which a lifetime meant for a shared cache may be announced at all.
 *
 * This runs well before the response is sent, and that is deliberate. Magento re-checks, at send time, whether
 * the visitor's cached context still matches the one the page was rendered for, and wipes the caching headers
 * when it does not. Writing earlier leaves that check the last word, so a page rendered for a context the
 * browser does not know is never handed a lifetime of its own.
 */
class DeclarePublicResponse
{
    /**
     * @param PublicResponseStateInterface $publicResponseState Record of the declaration, for later moments
     * @param ResponseSnapshotReader $snapshotReader Source of the facts the decision works from
     * @param BrowserCacheTtlApplierInterface $applier Decision about what the browser may be told
     * @param CacheDirectivesWriter $writer Publisher of a decided answer
     */
    public function __construct(
        private readonly PublicResponseStateInterface $publicResponseState,
        private readonly ResponseSnapshotReader $snapshotReader,
        private readonly BrowserCacheTtlApplierInterface $applier,
        private readonly CacheDirectivesWriter $writer
    ) {
    }

    /**
     * Record the declaration, and publish a browser lifetime when a shared cache is the one about to store.
     *
     * @param HttpResponse $subject Response Magento has just declared publicly cacheable
     * @param mixed $result Outcome of the declaration, which carries no value of its own
     * @param mixed $ttl Lifetime Magento announced for a shared cache, as the framework passed it
     * @return mixed Outcome unchanged
     */
    public function afterSetPublicHeaders(HttpResponse $subject, mixed $result, mixed $ttl): mixed
    {
        $this->publicResponseState->declarePublic($this->toAnnouncedSeconds($ttl));

        $directives = $this->applier->resolve(
            $this->snapshotReader->read($subject),
            WritePoint::SharedCacheDeclaration
        );

        if ($directives !== null) {
            $this->writer->write($subject, $directives);
        }

        return $result;
    }

    /**
     * Read the announced lifetime out of whatever the framework passed.
     *
     * The parameter is untyped where Magento declares it, so anything can arrive and nothing may be assumed.
     * A value that is not a number is treated as no announcement rather than as an error, because refusing a
     * page render over a cache header would be a worse answer than leaving the page alone.
     *
     * A numeric zero is not that case, and the difference matters more than it looks. Zero is a lifetime that
     * was genuinely announced -- Magento publishes exactly that for an embedded fragment when no block claimed
     * it -- and carrying it forward as zero is what cuts such a fragment's browser lifetime to nothing. Read
     * loosely as "no lifetime", it would instead fall back to the configured one and hand every unclaimed
     * fragment a lifetime in the visitor's browser.
     *
     * @param mixed $ttl Value the framework passed as the announced lifetime
     * @return int|null Announced seconds, or null when nothing usable was announced
     */
    private function toAnnouncedSeconds(mixed $ttl): ?int
    {
        return is_numeric($ttl) ? (int)$ttl : null;
    }
}
