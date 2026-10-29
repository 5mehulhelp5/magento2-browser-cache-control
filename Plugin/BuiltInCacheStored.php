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
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Publishes a browser lifetime on a freshly rendered page that Magento's own full page cache has just stored.
 *
 * By the time this runs the page is in storage and the outgoing response has been stripped of every caching
 * header and told never to be kept. That is Magento being deliberate rather than careless -- it has no way to
 * purge a browser -- and it is the whole reason this module exists: the stored copy keeps its long lifetime,
 * while the visitor is handed a short one.
 *
 * Whether the page was stored at all cannot be read back off the response, since the same stripping happens to
 * pages the cache refused. The declaration made earlier in the request answers it instead, and without one
 * nothing is published: a page Magento marked uncacheable for its own reasons must keep the headers Magento
 * gave it.
 *
 * Nothing is re-checked here about method, status or cacheability. Those are decided in one place, by the
 * eligibility rules, and they already describe exactly the set of pages the full page cache is willing to keep.
 *
 * This runs before the response is sent, leaving Magento's own check that the visitor's cached context still
 * matches the rendered page as the last word on the headers.
 */
class BuiltInCacheStored
{
    /**
     * @param PublicResponseStateInterface $publicResponseState Record of the declaration made earlier
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
     * Publish a browser lifetime on a page the built-in cache has just taken its copy of.
     *
     * Publishing replaces rather than appends, so reaching this more than once in a request would leave the
     * same headers as reaching it once. That property is worth having on its own terms, independently of how
     * many times the surrounding code happens to call through.
     *
     * @param Kernel $subject Built-in full page cache that has just processed the response
     * @param mixed $result Outcome of the processing, which carries no value of its own
     * @param HttpResponse $response Response on its way to the visitor
     * @return mixed Outcome unchanged
     */
    public function afterProcess(Kernel $subject, mixed $result, HttpResponse $response): mixed
    {
        if (!$this->publicResponseState->isDeclared()) {
            return $result;
        }

        $directives = $this->applier->resolve(
            $this->snapshotReader->read($response),
            WritePoint::BuiltInDelivery
        );

        if ($directives !== null) {
            $this->writer->write($response, $directives);
        }

        return $result;
    }
}
