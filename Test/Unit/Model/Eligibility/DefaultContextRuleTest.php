<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Model\Eligibility\DefaultContextRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule that keeps a browser lifetime off every page variant but the default one.
 */
class DefaultContextRuleTest extends TestCase
{
    /**
     * The default page, the one every visitor without a context of their own receives, may be kept by a browser.
     *
     * @return void
     */
    public function testAcceptsTheDefaultVariant(): void
    {
        self::assertTrue((new DefaultContextRule())->isEligible($this->createSnapshot(null)));
    }

    /**
     * A page rendered for a context of its own, such as a signed-in customer's, would be replayed by the browser to
     * whoever opens the URL next on that machine, so it keeps the stock headers.
     *
     * @return void
     */
    public function testRejectsAVariantRenderedForAContextOfItsOwn(): void
    {
        self::assertFalse((new DefaultContextRule())->isEligible($this->createSnapshot('a1b2c3')));
    }

    /**
     * Build a snapshot of an otherwise eligible page with the given variant.
     *
     * @param string|null $contextVary Vary string of the variant, or null for the default page
     * @return ResponseSnapshot
     */
    private function createSnapshot(?string $contextVary): ResponseSnapshot
    {
        return new ResponseSnapshot(
            'GET',
            200,
            '/women/tops.html',
            'catalog_category_view',
            'public, max-age=86400, s-maxage=86400',
            false,
            86400,
            1,
            $contextVary
        );
    }
}
