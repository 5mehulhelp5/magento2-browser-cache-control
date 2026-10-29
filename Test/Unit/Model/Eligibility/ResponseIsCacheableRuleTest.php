<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Model\Eligibility\ResponseIsCacheableRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the response-is-cacheable rule.
 */
class ResponseIsCacheableRuleTest extends TestCase
{
    private ResponseIsCacheableRule $rule;

    /**
     * Build the subject; it has no collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule = new ResponseIsCacheableRule();
    }

    /**
     * A response nobody objected to is allowed through.
     *
     * @return void
     */
    public function testAcceptsResponseNobodyMarked(): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshot(false, 'public, max-age=0, s-maxage=86400')));
    }

    /**
     * A missing Cache-Control header is not itself an objection.
     *
     * @return void
     */
    public function testAcceptsResponseWithoutCacheControlHeader(): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshot(false, null)));
    }

    /**
     * Magento's own verdict is honoured, whatever the header currently says.
     *
     * @return void
     */
    public function testRejectsResponseMagentoMarkedNotCacheable(): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshot(true, 'public, max-age=0, s-maxage=86400')));
    }

    /**
     * Magento's verdict is honoured even when there is no header at all to read.
     *
     * @return void
     */
    public function testRejectsResponseMagentoMarkedNotCacheableWithoutHeader(): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshot(true, null)));
    }

    /**
     * A header that already claims the response belongs to one visitor is refused on its own.
     *
     * @param string $header
     * @return void
     * @dataProvider privateHeaderDataProvider
     */
    public function testRejectsResponseAlreadyMarkedPrivateInTheHeader(string $header): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshot(false, $header)));
    }

    /**
     * Header values that carry the private directive, in the casings a plugin might write it in.
     *
     * @return array<string, array{0: string}>
     */
    public static function privateHeaderDataProvider(): array
    {
        return [
            'lower case' => ['private, max-age=0'],
            'upper case' => ['PRIVATE, MAX-AGE=0'],
            'mixed case' => ['Private, must-revalidate'],
            'not first directive' => ['max-age=0, private'],
        ];
    }

    /**
     * A public header is not mistaken for a private one.
     *
     * @return void
     */
    public function testAcceptsPublicHeader(): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshot(false, 'public, must-revalidate, max-age=120')));
    }

    /**
     * Build a snapshot that differs from its siblings only in the two facts this rule reads.
     *
     * @param bool $markedNotCacheable Whether Magento declared the response uncacheable
     * @param string|null $cacheControl Cache-Control value as it currently stands, or null when absent
     * @return ResponseSnapshot
     */
    private function snapshot(bool $markedNotCacheable, ?string $cacheControl): ResponseSnapshot
    {
        return new ResponseSnapshot(
            'GET',
            200,
            '/women/tops.html',
            'catalog_category_view',
            $cacheControl,
            $markedNotCacheable,
            86400,
            1,
            null
        );
    }
}
