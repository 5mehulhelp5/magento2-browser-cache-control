<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

/**
 * Typed, store-scoped read access to the browser cache control configuration.
 *
 * Every getter resolves at store scope, so a store view may run a different browser lifetime from its
 * siblings. Passing null resolves against the current scope.
 */
interface ConfigInterface
{
    public const XML_PATH_ENABLED = 'hryvinskyi_browser_cache_control/general/enabled';
    public const XML_PATH_BROWSER_TTL = 'hryvinskyi_browser_cache_control/general/browser_ttl';
    public const XML_PATH_MUST_REVALIDATE = 'hryvinskyi_browser_cache_control/general/must_revalidate';
    public const XML_PATH_EXCLUDED_URL_PATTERNS = 'hryvinskyi_browser_cache_control/general/excluded_url_patterns';

    /**
     * Delimiter the excluded URL patterns are validated with, and the only one they are guaranteed to
     * compile under. Whoever matches a request path against them must use this same delimiter: accepting a
     * pattern under one delimiter and matching it under another is how an exclusion silently stops
     * excluding anything.
     */
    public const PATTERN_DELIMITER = '~';

    /**
     * Whether the browser lifetime may be written at all for the given store.
     *
     * @param int|null $storeId Store to resolve against, or null for the current scope
     * @return bool True when an administrator switched the feature on
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * How many seconds the visitor's browser may reuse a page without contacting the origin.
     *
     * Zero means the feature is off for this store, whatever the enabled flag says: there is no lifetime
     * to grant.
     *
     * @param int|null $storeId Store to resolve against, or null for the current scope
     * @return int Seconds, never negative
     */
    public function getBrowserTtl(?int $storeId = null): int;

    /**
     * Whether the browser must re-check with the server once the lifetime expires instead of serving a
     * stale copy.
     *
     * @param int|null $storeId Store to resolve against, or null for the current scope
     * @return bool True when the must-revalidate directive is wanted
     */
    public function isMustRevalidate(?int $storeId = null): bool;

    /**
     * Request paths that must keep Magento's stock cache headers.
     *
     * Only patterns that actually compile are returned; a malformed administrator-entered line is dropped
     * rather than allowed to raise a warning on every storefront page render.
     *
     * @param int|null $storeId Store to resolve against, or null for the current scope
     * @return string[] PCRE patterns, without delimiters, one per configured line
     */
    public function getExcludedUrlPatterns(?int $storeId = null): array;
}
