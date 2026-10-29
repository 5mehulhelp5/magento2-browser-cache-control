<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyInterface;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;

/**
 * What may be written when Varnish is the application caching whole pages.
 *
 * Varnish is a client of Magento: the response leaves the process and Varnish decides from the headers on it
 * how long to keep the page, reading the shared lifetime first and only falling back to the browser lifetime
 * when none was published. Everything this strategy allows follows from that one fact.
 *
 * The rewrite happens at the moment Magento declares the page cacheable, because that is the last moment
 * before Varnish sees the response. Both lifetimes are published together -- the short one for the visitor's
 * browser, and the full one Magento announced for Varnish -- because publishing only the short one would hand
 * Varnish the browser lifetime as its own and quietly collapse the shared cache to a couple of minutes.
 *
 * The response stays public for the same reason: Varnish refuses to store a response marked private, so
 * marking it private would not restrict the page to one visitor, it would stop the page being cached at all.
 */
class VarnishStrategy implements PageCacheStrategyInterface
{
    /**
     * @inheritDoc
     */
    public function ownsWritePoint(WritePoint $point): bool
    {
        return $point === WritePoint::SharedCacheDeclaration;
    }

    /**
     * @inheritDoc
     */
    public function sharedTtlToPublish(?int $declaredSharedTtl): ?int
    {
        return $declaredSharedTtl;
    }

    /**
     * @inheritDoc
     */
    public function isPublic(): bool
    {
        return true;
    }
}
