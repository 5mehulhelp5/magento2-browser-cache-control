<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

/**
 * The moments in a request at which this module may legally rewrite the cache headers.
 *
 * The cases are named for the moment itself rather than for the seam that fires them, so that moving a
 * plugin to a different hook does not turn the vocabulary into a lie. Which moment is live depends on the
 * caching application that is configured, and what may be emitted differs between them: only one of these
 * moments happens before a shared cache has stored the page.
 */
enum WritePoint: string
{
    /**
     * Magento has just declared the response publicly cacheable and announced the lifetime it wants a
     * shared cache to keep. Nothing downstream has stored the response yet, so whatever is written here is
     * what the shared cache will store and obey.
     */
    case SharedCacheDeclaration = 'shared_cache_declaration';

    /**
     * Magento's built-in full page cache has already taken its copy of the response, or replayed a stored
     * one. The response object is now only on its way to the client, so a rewrite here changes what the
     * visitor is told without changing what was stored.
     */
    case BuiltInDelivery = 'built_in_delivery';
}
