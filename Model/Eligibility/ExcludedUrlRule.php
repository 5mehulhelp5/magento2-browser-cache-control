<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Api\EligibilityRuleInterface;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;

/**
 * Keeps the stock headers for request paths an administrator has excluded.
 *
 * The configured patterns are regular expression bodies without delimiters, and they are matched with the
 * one delimiter the configuration published and validated them against. Using any other delimiter here
 * would accept patterns at save time that cannot be applied at match time, which is exactly how an
 * exclusion silently stops excluding anything; the tilde in particular is chosen because a hash would be
 * broken by any pattern containing a URL fragment.
 *
 * Matching is case insensitive, and a pattern that fails to compile is treated as not matching. That makes
 * the rule fail open on purpose: a typo in one admin line must cost that one exclusion, not the storefront.
 */
class ExcludedUrlRule implements EligibilityRuleInterface
{
    /**
     * Flag applied to every configured pattern so that path casing does not defeat an exclusion.
     */
    private const CASE_INSENSITIVE_FLAG = 'i';

    /**
     * @param ConfigInterface $config Source of the administrator-configured exclusion patterns
     */
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        foreach ($this->config->getExcludedUrlPatterns($snapshot->storeId) as $pattern) {
            $expression = ConfigInterface::PATTERN_DELIMITER
                . $pattern
                . ConfigInterface::PATTERN_DELIMITER
                . self::CASE_INSENSITIVE_FLAG;

            if (@preg_match($expression, $snapshot->requestPath) === 1) {
                return false;
            }
        }

        return true;
    }
}
