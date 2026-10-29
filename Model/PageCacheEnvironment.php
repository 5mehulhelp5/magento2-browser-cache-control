<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;

/**
 * Reads Magento's full page cache settings, and is the only class in this module that names the Magento class
 * they come from.
 *
 * Keeping that name in one file is not tidiness. The class it wraps ships without an interface, so every
 * other class that touched it would be depending on a concrete framework type; and two of its three readings
 * mislead. The enabled flag is the Cache Management toggle for the page cache type and says nothing about
 * which caching application is selected, while the lifetime getter is annotated as returning a number but
 * hands back whatever configuration held -- a string, or nothing. Both are corrected here, once.
 */
class PageCacheEnvironment implements PageCacheEnvironmentInterface
{
    /**
     * @param PageCacheConfig $pageCacheConfig Magento's own full page cache settings
     */
    public function __construct(
        private readonly PageCacheConfig $pageCacheConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCachingApplication(): int
    {
        return $this->pageCacheConfig->getType();
    }

    /**
     * @inheritDoc
     */
    public function isFullPageCacheEnabled(): bool
    {
        return $this->pageCacheConfig->isEnabled();
    }

    /**
     * @inheritDoc
     */
    public function getSharedTtl(): ?int
    {
        /**
         * Annotated as an integer by Magento, but returned straight out of configuration with no cast, so it
         * arrives as a string on a configured store and as null on one that has never saved the field. It is
         * treated as unknown here rather than trusted.
         *
         * @var mixed $configured
         */
        $configured = $this->pageCacheConfig->getTtl();

        if (!is_numeric($configured)) {
            return null;
        }

        $seconds = (int)$configured;

        return $seconds > 0 ? $seconds : null;
    }
}
