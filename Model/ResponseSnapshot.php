<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

/**
 * Everything a browser-lifetime decision needs to know about one response, and nothing else.
 *
 * Holding the facts rather than the Magento request and response objects is what keeps the decision itself
 * free of the framework: every rule and the use case above them can be exercised with plain PHP values. The
 * translation from Magento objects into this shape happens once, in an adapter, outside this class.
 */
final class ResponseSnapshot
{
    /**
     * @param string $httpMethod HTTP method of the request, for example GET
     * @param int $statusCode HTTP status code the response currently carries
     * @param string $requestPath Path only, with a leading slash and without the query string
     * @param string $fullActionName Route, controller and action joined by underscores. It is "__" on a
     *        built-in cache hit, because that path short-circuits before routing and nothing has set a
     *        route, controller or action yet; no decision may depend on a real action name being present
     * @param string|null $currentCacheControl Cache-Control header value as it stands, or null when absent
     * @param bool $markedNotCacheable Whether Magento itself declared this response uncacheable
     * @param int|null $declaredSharedTtl Lifetime Magento announced for the shared cache when known. Zero
     *        is a genuine announced lifetime and means something different from not knowing, so the two are
     *        kept apart rather than collapsed
     * @param int|null $storeId Store the response is being rendered for, or null when unknown
     */
    public function __construct(
        public readonly string $httpMethod,
        public readonly int $statusCode,
        public readonly string $requestPath,
        public readonly string $fullActionName,
        public readonly ?string $currentCacheControl,
        public readonly bool $markedNotCacheable,
        public readonly ?int $declaredSharedTtl,
        public readonly ?int $storeId
    ) {
    }
}
