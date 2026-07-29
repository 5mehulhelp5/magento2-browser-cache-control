<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

use Hryvinskyi\BrowserCacheControl\Model\WritePoint;

/**
 * What one caching application allows this module to write, and when.
 *
 * Magento can have whole pages cached by one of two applications, and the safe moment to rewrite the cache
 * headers is not the same in each. One of them stores the response after it leaves Magento, so the rewrite
 * has to happen while Magento is still declaring the page cacheable and has to keep announcing the shared
 * lifetime that application reads. The other stores the response inside Magento, before it is sent, so the
 * rewrite has to happen after the copy has been taken and must not announce a shared lifetime at all.
 *
 * Those differences are the whole reason this abstraction exists. Every one of them is expressed as an answer
 * to one of the three questions below, so that the code deciding what to emit asks the questions and never
 * asks which caching application is configured. Adding support for a third one is a new implementation and a
 * line of configuration -- no existing class changes, and no conditional chain grows.
 *
 * Implementations answer from what the caching application is, not from what the response is, so they are
 * pure and carry no dependencies.
 */
interface PageCacheStrategyInterface
{
    /**
     * Whether this is the moment at which this caching application may have its headers rewritten.
     *
     * Exactly one moment belongs to each caching application. Writing at the other one is not merely
     * ineffective: under the application that stores the response inside Magento, rewriting at the
     * declaration moment changes what gets stored, and dropping the shared lifetime there switches whole
     * page caching off without any other symptom.
     *
     * @param WritePoint $point Moment in the request the caller has reached
     * @return bool True when this strategy claims that moment
     */
    public function ownsWritePoint(WritePoint $point): bool;

    /**
     * The shared lifetime to publish alongside the browser lifetime, if any.
     *
     * @param int|null $declaredSharedTtl Lifetime Magento announced for a shared cache, or null when the
     *        caller does not know one
     * @return int|null Seconds to publish for shared caches, or null to publish no shared lifetime at all
     */
    public function sharedTtlToPublish(?int $declaredSharedTtl): ?int;

    /**
     * Whether shared caches are still allowed to store the response.
     *
     * @return bool True to describe the response as public, false to describe it as private
     */
    public function isPublic(): bool;
}
