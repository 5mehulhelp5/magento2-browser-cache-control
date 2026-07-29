<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

/**
 * Finds the strategy that belongs to the caching application Magento is currently configured with.
 */
interface PageCacheStrategyResolverInterface
{
    /**
     * The strategy for the configured caching application, when there is one.
     *
     * Null is a normal answer, not an error, and it means the same thing every time: leave the response
     * exactly as Magento built it. It is returned when whole-page caching is switched off, and when the
     * configured caching application is one this module has no strategy for. Neither case may raise: this
     * runs on every storefront page render, and a cache-header module has no business taking a shop down.
     *
     * @return PageCacheStrategyInterface|null Strategy to obey, or null to leave the response untouched
     */
    public function resolve(): ?PageCacheStrategyInterface;
}
