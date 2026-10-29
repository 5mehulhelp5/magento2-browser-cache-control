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
 * Keeps the stock headers on every variant of a page except the default one.
 *
 * Magento's page cache stores one copy of a page per context: a signed-in customer's group, a chosen currency, any
 * other value a module adds to the HTTP context. A shared cache tells those copies apart by the visitor's vary
 * cookie. A browser does not: its cache keys a page on the URL alone, so a copy it was allowed to keep is replayed
 * whatever the visitor's context has become since. Once a customer signs out, a browser holding their copy of a
 * category page would show their prices and their Add to basket button to whoever opens that URL next on the same
 * machine, until the lifetime runs out.
 *
 * Only the default variant, the one every visitor without a context of their own receives, is therefore given a
 * lifetime. Every other variant keeps Magento's stock headers and is fetched afresh on each visit.
 */
class DefaultContextRule implements EligibilityRuleInterface
{
    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        return $snapshot->contextVary === null;
    }
}
