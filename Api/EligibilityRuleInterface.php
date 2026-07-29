<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;

/**
 * One reason a response must keep Magento's stock cache headers.
 *
 * Each implementation carries exactly one reason and knows nothing about the others; the caller combines
 * them by requiring all of them to pass. A new reason therefore arrives as a new class added to the pool in
 * configuration, without any existing class being edited and without any conditional chain growing.
 */
interface EligibilityRuleInterface
{
    /**
     * Decide whether this rule allows the response to receive a browser lifetime.
     *
     * @param ResponseSnapshot $snapshot Facts about the response under consideration
     * @return bool True when this rule sees no objection, false when the stock headers must be kept
     */
    public function isEligible(ResponseSnapshot $snapshot): bool;
}
