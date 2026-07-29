<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Hryvinskyi\BrowserCacheControl\Api\PublicResponseStateInterface;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Translates Magento's request and response objects into the handful of facts a browser-lifetime decision needs.
 *
 * This translation is the boundary of the module. Everything on the far side of it -- the eligibility rules and
 * the decision they feed -- works on plain values and can be exercised without Magento present; everything that
 * knows how to ask a Magento response what status it carries or what its Cache-Control header says lives here,
 * once. Each reading below is one line in the plainest sense and one framework quirk in practice, which is the
 * reason for gathering them rather than letting each caller do its own.
 */
final class ResponseSnapshotFactory
{
    /**
     * Header the decision reads to see what the response is currently telling caches.
     */
    private const CACHE_CONTROL_HEADER = 'Cache-Control';

    /**
     * Response metadata key Magento sets to refuse caching without changing the response class.
     */
    private const NOT_CACHEABLE_METADATA_KEY = 'NotCacheable';

    /**
     * @param HttpRequest $request Request being served, for the facts that belong to it rather than to the
     *        response
     * @param PublicResponseStateInterface $publicResponseState Record of what Magento announced earlier in
     *        this request
     * @param PageCacheEnvironmentInterface $environment Magento's own full page cache settings
     * @param StoreManagerInterface $storeManager Source of the store the response is being rendered for
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly PublicResponseStateInterface $publicResponseState,
        private readonly PageCacheEnvironmentInterface $environment,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Gather everything worth knowing about one response.
     *
     * @param HttpResponse $response Response about to be decided on
     * @return ResponseSnapshot Facts the decision works from
     */
    public function create(HttpResponse $response): ResponseSnapshot
    {
        return new ResponseSnapshot(
            $this->request->getMethod(),
            (int)$response->getHttpResponseCode(),
            $this->resolveRequestPath(),
            $this->request->getFullActionName(),
            $this->resolveCurrentCacheControl($response),
            $this->resolveMarkedNotCacheable($response),
            $this->resolveDeclaredSharedTtl(),
            $this->resolveStoreId()
        );
    }

    /**
     * Path of the request, always with a leading slash.
     *
     * Magento hands the path over without a query string already, so nothing is stripped here; only the
     * leading slash is guaranteed, so that an exclusion pattern anchored at the start of a path means the
     * same thing however the path arrived.
     *
     * @return string Request path beginning with a slash
     */
    private function resolveRequestPath(): string
    {
        return '/' . ltrim($this->request->getPathInfo(), '/');
    }

    /**
     * Cache-Control value the response carries at this moment, if any.
     *
     * Magento answers a request for a missing header with false rather than with nothing, so the two shapes
     * the answer can take are reconciled into one here.
     *
     * @param HttpResponse $response Response to read
     * @return string|null Header value, or null when the response carries no Cache-Control
     */
    private function resolveCurrentCacheControl(HttpResponse $response): ?string
    {
        $header = $response->getHeader(self::CACHE_CONTROL_HEADER);

        return $header instanceof HeaderInterface ? $header->getFieldValue() : null;
    }

    /**
     * Whether Magento itself has ruled the response out of any cache.
     *
     * Core expresses that refusal in two ways -- the response class implementing an interface that says so,
     * and a flag in the response metadata -- and it consults both wherever it decides whether to store or to
     * send a page. Both are honoured here, because a response marked either way still reaches the moments at
     * which this module writes: the built-in cache lays down its no-cache headers before it tests them.
     *
     * @param HttpResponse $response Response to read
     * @return bool True when Magento has declared this response uncacheable
     */
    private function resolveMarkedNotCacheable(HttpResponse $response): bool
    {
        if ($response instanceof NotCacheableInterface) {
            return true;
        }

        return (bool)$response->getMetadata(self::NOT_CACHEABLE_METADATA_KEY);
    }

    /**
     * Lifetime a shared cache has been told it may keep this page for.
     *
     * An announcement made during this request is the better answer, because it describes this very response;
     * the configured lifetime is a fallback for the moments no announcement was witnessed. Whether an
     * announcement happened is asked directly, and never inferred from the lifetime it carried: an announced
     * zero seconds is a real announcement, and letting it fall through to the configured lifetime is exactly
     * how an embedded fragment would end up published with a browser lifetime and a shared lifetime neither
     * of which Magento ever offered.
     *
     * @return int|null Announced or configured seconds, or null when neither is known
     */
    private function resolveDeclaredSharedTtl(): ?int
    {
        if ($this->publicResponseState->isDeclared()) {
            return $this->publicResponseState->getSharedTtl();
        }

        return $this->environment->getSharedTtl();
    }

    /**
     * Store the response is being rendered for.
     *
     * This module's settings are store scoped, so the store has to be known to read them -- but not knowing it
     * is not a reason to fail a page render. An unresolvable store leaves the question unanswered, and the
     * settings are then read at default scope, which is the safe reading rather than a guessed one.
     *
     * @return int|null Store identifier, or null when no store can be resolved
     */
    private function resolveStoreId(): ?int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }
}
