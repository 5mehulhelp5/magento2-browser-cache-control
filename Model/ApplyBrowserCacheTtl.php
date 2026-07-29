<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

use Hryvinskyi\BrowserCacheControl\Api\BrowserCacheTtlApplierInterface;
use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Api\EligibilityRuleInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyResolverInterface;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;

/**
 * Decides whether a response may be handed a browser lifetime, and what to publish when it may.
 *
 * The whole class is a sequence of reasons to say no, each answered by returning nothing at all -- which
 * always means the same thing: leave the response exactly as Magento built it. Saying no is the safe answer
 * and the default one, and it is expressed by walking out early rather than by nesting, so a reader can read
 * the conditions for saying yes as a list.
 *
 * Nowhere in here is there a test for which caching application is configured or which moment of the request
 * this is. Those two questions are asked once, of the strategy, and their answers arrive as the visibility
 * and the shared lifetime to publish. Keeping them out of this class is what stops the decision growing a
 * branch every time a caching application is added.
 */
final class ApplyBrowserCacheTtl implements BrowserCacheTtlApplierInterface
{
    /**
     * Reasons a response must keep Magento's stock headers.
     *
     * All of them have to agree, so the order only decides how quickly a rejection is reached, never what is
     * decided. It is set in configuration, where the cheap checks are placed ahead of the one that reads
     * settings.
     *
     * @var EligibilityRuleInterface[]
     */
    private readonly array $rules;

    /**
     * @param ConfigInterface $config Store-scoped settings for this module
     * @param PageCacheStrategyResolverInterface $strategyResolver Source of the rules the caching application
     *        imposes
     * @param EligibilityRuleInterface[] $rules Reasons to leave a response alone
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly PageCacheStrategyResolverInterface $strategyResolver,
        array $rules = []
    ) {
        $this->rules = array_values($rules);
    }

    /**
     * @inheritDoc
     */
    public function resolve(ResponseSnapshot $snapshot, WritePoint $point): ?CacheDirectives
    {
        if (!$this->config->isEnabled($snapshot->storeId)) {
            return null;
        }

        $browserTtl = $this->config->getBrowserTtl($snapshot->storeId);

        // A lifetime of zero seconds is not a short lifetime, it is the absence of one; there would be
        // nothing to tell the browser.
        if ($browserTtl < 1) {
            return null;
        }

        $strategy = $this->strategyResolver->resolve();

        if ($strategy === null || !$strategy->ownsWritePoint($point)) {
            return null;
        }

        foreach ($this->rules as $rule) {
            if (!$rule->isEligible($snapshot)) {
                return null;
            }
        }

        $declaredSharedTtl = $snapshot->declaredSharedTtl;

        // A browser must never be allowed to hold a page for longer than the cache in front of it, so a
        // configured lifetime longer than the one Magento announced is cut down to it. The test is for a
        // value being present rather than for it being non-zero, and that distinction carries real weight:
        // an announced lifetime of zero seconds is a genuine announcement -- it is exactly what Magento
        // publishes for an embedded fragment no block claimed -- and it has to cut the browser lifetime to
        // nothing. Reading zero as "nothing was announced" would hand such a fragment a lifetime of its own.
        if ($declaredSharedTtl !== null && $declaredSharedTtl < $browserTtl) {
            $browserTtl = $declaredSharedTtl;
        }

        if ($browserTtl < 1) {
            return null;
        }

        $sharedTtlToPublish = $strategy->sharedTtlToPublish($declaredSharedTtl);

        // A shared lifetime only means something on a response shared caches are allowed to store, and it
        // can never be shorter than the browser lifetime. A strategy asking for either has contradicted
        // itself, and the honest answer to a contradiction is to publish nothing rather than half of it.
        if ($sharedTtlToPublish !== null && (!$strategy->isPublic() || $sharedTtlToPublish < $browserTtl)) {
            return null;
        }

        return new CacheDirectives(
            $strategy->isPublic(),
            $browserTtl,
            $sharedTtlToPublish,
            $this->config->isMustRevalidate($snapshot->storeId)
        );
    }
}
