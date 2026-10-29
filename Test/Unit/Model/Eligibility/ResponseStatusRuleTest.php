<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Model\Eligibility\ResponseStatusRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the response status rule.
 */
class ResponseStatusRuleTest extends TestCase
{
    private ResponseStatusRule $rule;

    /**
     * Build the subject with the status codes the module ships as its defaults.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule = new ResponseStatusRule(['ok' => 200, 'not_found' => 404]);
    }

    /**
     * The statuses Magento itself stores are allowed through.
     *
     * @param int $statusCode
     * @return void
     * @dataProvider acceptedStatusDataProvider
     */
    public function testAcceptsCacheableStatuses(int $statusCode): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshotForStatus($statusCode)));
    }

    /**
     * Statuses that may carry a browser lifetime.
     *
     * @return array<string, array{0: int}>
     */
    public static function acceptedStatusDataProvider(): array
    {
        return [
            'ok' => [200],
            'not found' => [404],
        ];
    }

    /**
     * Anything else keeps the stock headers.
     *
     * @param int $statusCode
     * @return void
     * @dataProvider rejectedStatusDataProvider
     */
    public function testRejectsOtherStatuses(int $statusCode): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshotForStatus($statusCode)));
    }

    /**
     * Statuses whose responses must not gain a browser lifetime.
     *
     * @return array<string, array{0: int}>
     */
    public static function rejectedStatusDataProvider(): array
    {
        return [
            'created' => [201],
            'moved permanently' => [301],
            'found' => [302],
            'forbidden' => [403],
            'server error' => [500],
        ];
    }

    /**
     * Configuration hands numeric arguments over as strings, and the rule still matches strictly.
     *
     * @return void
     */
    public function testAcceptsStatusCodesConfiguredAsNumericStrings(): void
    {
        $rule = new ResponseStatusRule(['ok' => '200', 'not_found' => '404']);

        self::assertTrue($rule->isEligible($this->snapshotForStatus(200)));
        self::assertTrue($rule->isEligible($this->snapshotForStatus(404)));
        self::assertFalse($rule->isEligible($this->snapshotForStatus(500)));
    }

    /**
     * Without any configured status nothing qualifies.
     *
     * @return void
     */
    public function testRejectsEverythingWhenNoStatusIsConfigured(): void
    {
        self::assertFalse((new ResponseStatusRule())->isEligible($this->snapshotForStatus(200)));
    }

    /**
     * Build a snapshot that differs from its siblings only in the status code.
     *
     * @param int $statusCode Status code to place on the snapshot
     * @return ResponseSnapshot
     */
    private function snapshotForStatus(int $statusCode): ResponseSnapshot
    {
        return new ResponseSnapshot(
            'GET',
            $statusCode,
            '/women/tops.html',
            'catalog_category_view',
            null,
            false,
            86400,
            1,
            null
        );
    }
}
