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
 * Keeps the stock headers for anything that is not a safe, retrievable request.
 *
 * Only a method whose response is worth replaying from a browser cache qualifies; a form post or a delete
 * must reach the origin every time. The accepted set is a constructor argument with its defaults declared
 * in configuration, so an installation with an unusual method can widen it without this class changing.
 */
class RequestMethodRule implements EligibilityRuleInterface
{
    /**
     * Accepted methods, upper-cased once so that every comparison afterwards is a plain equality test.
     *
     * @var string[]
     */
    private readonly array $acceptedMethods;

    /**
     * @param string[] $acceptedMethods HTTP methods whose responses may carry a browser lifetime
     */
    public function __construct(array $acceptedMethods = [])
    {
        $this->acceptedMethods = array_values(array_map('strtoupper', $acceptedMethods));
    }

    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        return in_array(strtoupper($snapshot->httpMethod), $this->acceptedMethods, true);
    }
}
