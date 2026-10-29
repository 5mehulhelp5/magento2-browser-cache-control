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
 * Keeps the stock headers for controller actions that publish a public lifetime of their own.
 *
 * A handful of Magento controllers announce a public cache lifetime without being page views at all: the
 * ESI block endpoint answers a sub-request from a shared cache and publishes the individual block's
 * lifetime, which is frequently zero, and the swatch media endpoint answers an ajax call. Neither is
 * something a browser should be told to hold for the site-wide browser lifetime, and neither is a page a
 * visitor navigated to.
 *
 * The excluded names are a constructor argument whose defaults are declared in configuration rather than in
 * the admin, because they are correctness invariants and not preferences: switching them off would produce
 * wrong headers, so no administrator is offered the choice.
 *
 * On the name of the ESI endpoint. It is magento_pagecache_block_esi and not page_cache_block_esi, however
 * much the URL suggests otherwise. A full action name is the route id, controller and action joined by
 * underscores, and the router sets the route id by looking the front name up in the registered routes --
 * page_cache is only the front name under which the endpoint is reachable, while the route id declared by
 * Magento_PageCache for the storefront is magento_pagecache. Anyone tempted to "correct" this string should
 * read that route declaration first.
 */
class ExcludedActionRule implements EligibilityRuleInterface
{
    /**
     * Full action names that must never receive a browser lifetime.
     *
     * @var string[]
     */
    private readonly array $excludedActions;

    /**
     * @param string[] $excludedActions Full action names to leave alone
     */
    public function __construct(array $excludedActions = [])
    {
        $this->excludedActions = array_values($excludedActions);
    }

    /**
     * @inheritDoc
     */
    public function isEligible(ResponseSnapshot $snapshot): bool
    {
        return !in_array($snapshot->fullActionName, $this->excludedActions, true);
    }
}
