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
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotFactory;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Publishes a browser lifetime on a page replayed out of Magento's own full page cache.
 *
 * This is the moment that carries the feature. A stored page was stripped of its caching headers before it was
 * saved, so every replay hands the visitor a response that says never to keep it -- which is precisely the
 * visitor who has already proved they come back. Without a rewrite here, repeat visits gain nothing at all.
 *
 * A page that came out of the full page cache is publicly cacheable by the only evidence that counts: Magento
 * stored it. So the declaration is made here rather than looked for, and it is made without a lifetime, because
 * how much of the stored copy's lifetime remains is not something this moment can know.
 *
 * Two peculiarities of this moment shape what may be relied on. It happens before the request has been routed,
 * so no controller or action has been chosen yet and nothing may depend on knowing which one it would have
 * been. And the response handed over is a newly built object carrying the stored headers, not the response the
 * rest of the request will otherwise be holding -- so the rewrite belongs on the object passed here, and
 * anything that later inspects the request's own response object is looking somewhere else entirely.
 *
 * A miss is answered with the same nothing it arrived as, untouched and unquestioned.
 */
final class BuiltInCacheServed
{
    /**
     * @param PublicResponseStateInterface $publicResponseState Record of the declaration, for later moments
     * @param ResponseSnapshotFactory $snapshotFactory Source of the facts the decision works from
     * @param BrowserCacheTtlApplierInterface $applier Decision about what the browser may be told
     * @param CacheDirectivesWriter $writer Publisher of a decided answer
     */
    public function __construct(
        private readonly PublicResponseStateInterface $publicResponseState,
        private readonly ResponseSnapshotFactory $snapshotFactory,
        private readonly BrowserCacheTtlApplierInterface $applier,
        private readonly CacheDirectivesWriter $writer
    ) {
    }

    /**
     * Publish a browser lifetime on a page that came out of storage.
     *
     * @param Kernel $subject Built-in full page cache that was asked for a stored page
     * @param mixed $result Stored page rebuilt as a response, or something else entirely when there was none
     * @return mixed The response, rewritten when there was one, and otherwise exactly what arrived
     */
    public function afterLoad(Kernel $subject, mixed $result): mixed
    {
        if (!$result instanceof HttpResponse) {
            return $result;
        }

        $this->publicResponseState->declarePublic(null);

        $directives = $this->applier->resolve(
            $this->snapshotFactory->create($result),
            WritePoint::BuiltInDelivery
        );

        if ($directives !== null) {
            $this->writer->write($result, $directives);
        }

        return $result;
    }
}
