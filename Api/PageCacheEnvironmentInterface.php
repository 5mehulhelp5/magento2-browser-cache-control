<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

/**
 * The facts about Magento's own full page cache that a browser-lifetime decision depends on.
 *
 * Magento exposes these through a class that has no interface of its own, returns one of them untyped, and
 * gives another a name that reads as something it is not. This port exists so those three problems are solved
 * once, behind a contract the rest of the module can inject, rather than being rediscovered at every call
 * site.
 *
 * Every value here is global. Magento reads both the caching application and the shared lifetime with no
 * scope argument, so they resolve at default scope and are the same whichever store view is being rendered --
 * unlike this module's own settings, which are store scoped. That asymmetry is Magento's, and no method here
 * takes a store id precisely because offering one would promise a per-store answer that does not exist.
 */
interface PageCacheEnvironmentInterface
{
    /**
     * Which application Magento is configured to have cache whole pages.
     *
     * The value is Magento's own identifier, so it can be compared against Magento\PageCache\Model\Config
     * BUILT_IN and VARNISH. It is whatever is configured, including a value neither of those constants
     * covers; deciding what to do with an unrecognised one is the caller's business, not this port's.
     *
     * @return int Configured caching application identifier
     */
    public function getCachingApplication(): int;

    /**
     * Whether Magento's full page cache is switched on in Cache Management.
     *
     * This is the cache type toggle and nothing else. It says neither that this module is enabled nor which
     * caching application is selected -- the two things the name most invites a reader to assume. With it
     * off, nothing in Magento declares a response publicly cacheable, so there is no shared lifetime to work
     * alongside and no page for a browser to be handed a lifetime for.
     *
     * @return bool True when whole-page caching is switched on
     */
    public function isFullPageCacheEnabled(): bool;

    /**
     * How long Magento is configured to let a cache keep a whole page.
     *
     * Magento hands this value over exactly as it came out of configuration -- a string, or nothing at all --
     * so it is normalised here into either a usable positive number of seconds or null. Zero, a negative
     * number and unparseable text all become null, because none of them describes a lifetime anything can
     * be stored for.
     *
     * @return int|null Seconds a shared cache is configured to keep a page, or null when there is no usable
     *         value
     */
    public function getSharedTtl(): ?int;
}
