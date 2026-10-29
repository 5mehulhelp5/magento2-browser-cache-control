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
 * Keeps the stock headers for any status code that is not itself worth caching.
 *
 * The accepted set is a constructor argument whose defaults are declared in configuration, and it matches
 * the set Magento's own full page cache is willing to store -- a successful page and a not-found page.
 * Granting a browser lifetime to a status Magento would not store would let a browser hold on to something
 * the origin never considered a cacheable page.
 */
class ResponseStatusRule implements EligibilityRuleInterface
{
    /**
     * Accepted status codes, cast once on construction.
     *
     * Configuration hands numeric arguments over as strings, so normalising here is what lets the
     * comparison stay strict instead of silently falling back to loose equality.
     *
     * @var int[]
     */
    private readonly array $acceptedStatusCodes;

    /**
     * @param array<array-key, int|string> $acceptedStatusCodes Status codes that may carry a browser lifetime
     */
    public function __construct(array $acceptedStatusCodes = [])
    {
        $this->acceptedStatusCodes = array_values(array_map('intval', $acceptedStatusCodes));
    }

    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        return in_array($snapshot->statusCode, $this->acceptedStatusCodes, true);
    }
}
