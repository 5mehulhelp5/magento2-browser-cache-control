<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Model\Eligibility\ExcludedActionRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the excluded action rule.
 */
class ExcludedActionRuleTest extends TestCase
{
    private ExcludedActionRule $rule;

    /**
     * Build the subject with the action names the module ships as its defaults.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule = new ExcludedActionRule([
            'page_cache_esi' => 'magento_pagecache_block_esi',
            'swatches_media' => 'swatches_ajax_media',
        ]);
    }

    /**
     * A page view is allowed through.
     *
     * @param string $fullActionName
     * @return void
     * @dataProvider allowedActionDataProvider
     */
    public function testAcceptsPageViews(string $fullActionName): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshotForAction($fullActionName)));
    }

    /**
     * Action names that are ordinary storefront page views.
     *
     * @return array<string, array{0: string}>
     */
    public static function allowedActionDataProvider(): array
    {
        return [
            'category' => ['catalog_category_view'],
            'product' => ['catalog_product_view'],
            'home' => ['cms_index_index'],
        ];
    }

    /**
     * The ESI endpoint is excluded under the name the router actually produces, which is built from the
     * route id and not from the front name that appears in the URL.
     *
     * @return void
     */
    public function testRejectsEsiEndpointUnderItsRouteIdBasedName(): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshotForAction('magento_pagecache_block_esi')));
    }

    /**
     * The front name is not the action name, so a snapshot carrying it is not the ESI endpoint and is not
     * what the exclusion is keyed on.
     *
     * @return void
     */
    public function testFrontNameBasedSpellingIsNotWhatIsExcluded(): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshotForAction('page_cache_block_esi')));
    }

    /**
     * The swatch media endpoint is excluded.
     *
     * @return void
     */
    public function testRejectsSwatchMediaEndpoint(): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshotForAction('swatches_ajax_media')));
    }

    /**
     * The empty-ish action name a built-in cache hit carries is not an exclusion by itself.
     *
     * @return void
     */
    public function testAcceptsTheEmptyActionNameOfACacheHit(): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshotForAction('__')));
    }

    /**
     * With nothing configured every action qualifies.
     *
     * @return void
     */
    public function testAcceptsEverythingWhenNothingIsExcluded(): void
    {
        $rule = new ExcludedActionRule();

        self::assertTrue($rule->isEligible($this->snapshotForAction('magento_pagecache_block_esi')));
    }

    /**
     * Build a snapshot that differs from its siblings only in the full action name.
     *
     * @param string $fullActionName Action name to place on the snapshot
     * @return ResponseSnapshot
     */
    private function snapshotForAction(string $fullActionName): ResponseSnapshot
    {
        return new ResponseSnapshot('GET', 200, '/women/tops.html', $fullActionName, null, false, 86400, 1);
    }
}
