<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;

/**
 * Decides whether a response may be handed a browser lifetime, and what that lifetime is expressed as.
 *
 * The decision is the whole of this module's behaviour, and it is deliberately expressed as a value returned
 * rather than a header written. Nothing behind this contract knows what a Magento response object is, which
 * is what lets the entire decision be exercised with plain PHP values; writing the answer onto a response is
 * somebody else's job, done in one place.
 */
interface BrowserCacheTtlApplierInterface
{
    /**
     * Work out the cache directives this response should carry, if any.
     *
     * @param ResponseSnapshot $snapshot Facts about the response under consideration
     * @param WritePoint $point Moment in the request the caller has reached
     * @return CacheDirectives|null Directives to publish, or null to leave Magento's own headers untouched
     */
    public function resolve(ResponseSnapshot $snapshot, WritePoint $point): ?CacheDirectives;
}
