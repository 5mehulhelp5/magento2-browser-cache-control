<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Api\EligibilityRuleInterface;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;

/**
 * Keeps the stock headers for a response that has been declared uncacheable.
 *
 * Two separate signals are honoured, and they are not equally important.
 *
 * The first is Magento's own verdict, carried on the snapshot: core marks a response uncacheable either by
 * letting it implement Magento\Framework\App\PageCache\NotCacheableInterface or by setting a NotCacheable
 * flag in the response metadata, and it checks both before storing or before sending. This is the clause
 * that actually fires here, because the built-in cache writes its no-cache headers into the response
 * *before* it tests those two signals -- so a response Magento has ruled out still arrives at the moment
 * this module would otherwise write, and must be recognised.
 *
 * The second is a cheap backstop: a Cache-Control value that already says private. It exists for a
 * third-party plugin that decides after layout generation that a response is customer specific. It cannot
 * fire at either of this module's own write points, because at both of them Magento has just overwritten
 * Cache-Control itself, so nothing another party wrote survives to be read. It is therefore not the
 * equivalent of the delivery-time private check in Magento's shipped Varnish configuration, which runs much
 * later and against a header nobody has since replaced.
 *
 * The comparison ignores case, and an absent header is not an objection.
 */
class ResponseIsCacheableRule implements EligibilityRuleInterface
{
    /**
     * Visibility directive that means the response belongs to one visitor only.
     */
    private const PRIVATE_DIRECTIVE = 'private';

    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        if ($snapshot->markedNotCacheable) {
            return false;
        }

        if ($snapshot->currentCacheControl === null) {
            return true;
        }

        return stripos($snapshot->currentCacheControl, self::PRIVATE_DIRECTIVE) === false;
    }
}
