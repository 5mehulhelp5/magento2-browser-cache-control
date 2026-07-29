<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheEnvironmentInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyResolverInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Picks the strategy that matches the configured caching application out of a pool declared in configuration.
 *
 * The pool is a lookup rather than a chain of comparisons, so support for another caching application is
 * added by declaring one more entry and one more class -- this file does not grow.
 */
final class PageCacheStrategyResolver implements PageCacheStrategyResolverInterface, ResetAfterRequestInterface
{
    /**
     * Strategies by the caching application they belong to.
     *
     * Configuration names its array entries, and a name is always text; the identifier being looked up is a
     * number. The two are reconciled once, here, so that the lookup below can be a plain array read.
     *
     * @var array<int, PageCacheStrategyInterface>
     */
    private readonly array $strategies;

    /**
     * Whether an unrecognised caching application has already been reported during this request.
     *
     * Several moments in a single request can ask for a strategy, and a misconfiguration is one fact however
     * many of them ask, so it is reported once and not once per lookup.
     */
    private bool $unknownApplicationReported = false;

    /**
     * @param PageCacheEnvironmentInterface $environment Source of the configured caching application
     * @param LoggerInterface $logger Destination for the misconfiguration warning
     * @param array<array-key, PageCacheStrategyInterface> $strategies Strategies keyed by caching application
     */
    public function __construct(
        private readonly PageCacheEnvironmentInterface $environment,
        private readonly LoggerInterface $logger,
        array $strategies = []
    ) {
        $pool = [];

        foreach ($strategies as $cachingApplication => $strategy) {
            $pool[(int)$cachingApplication] = $strategy;
        }

        $this->strategies = $pool;
    }

    /**
     * @inheritDoc
     */
    public function resolve(): ?PageCacheStrategyInterface
    {
        // With whole-page caching switched off nothing declares a response publicly cacheable, so there is
        // no page to shorten a browser lifetime on. That is a supported way to run a shop rather than a
        // mistake, so it is answered silently.
        if (!$this->environment->isFullPageCacheEnabled()) {
            return null;
        }

        $cachingApplication = $this->environment->getCachingApplication();

        if (isset($this->strategies[$cachingApplication])) {
            return $this->strategies[$cachingApplication];
        }

        $this->reportUnknownCachingApplication($cachingApplication);

        return null;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->unknownApplicationReported = false;
    }

    /**
     * Warn once about a caching application no strategy was declared for.
     *
     * The warning names the value, because the only useful thing to say about an unrecognised identifier is
     * what it was. Nothing is thrown: this is reached on every storefront page render, and a shop must not go
     * white because a cache-header module met a setting it did not know.
     *
     * @param int $cachingApplication Configured identifier no strategy matched
     * @return void
     */
    private function reportUnknownCachingApplication(int $cachingApplication): void
    {
        if ($this->unknownApplicationReported) {
            return;
        }

        $this->unknownApplicationReported = true;

        $this->logger->warning(
            sprintf(
                'Hryvinskyi_BrowserCacheControl: no browser cache strategy is declared for full page cache '
                . 'caching application "%d", so responses keep Magento\'s own cache headers.',
                $cachingApplication
            )
        );
    }
}
