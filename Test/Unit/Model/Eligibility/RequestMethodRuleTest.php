<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model\Eligibility;

use Hryvinskyi\BrowserCacheControl\Model\Eligibility\RequestMethodRule;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the request method rule.
 */
class RequestMethodRuleTest extends TestCase
{
    private RequestMethodRule $rule;

    /**
     * Build the subject with the methods the module ships as its defaults.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule = new RequestMethodRule(['get' => 'GET', 'head' => 'HEAD']);
    }

    /**
     * A retrieval request is allowed through.
     *
     * @param string $method
     * @return void
     * @dataProvider acceptedMethodDataProvider
     */
    public function testAcceptsRetrievalMethods(string $method): void
    {
        self::assertTrue($this->rule->isEligible($this->snapshotForMethod($method)));
    }

    /**
     * Methods that may carry a browser lifetime, in the casings a client might send them in.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedMethodDataProvider(): array
    {
        return [
            'get' => ['GET'],
            'head' => ['HEAD'],
            'lower case get' => ['get'],
            'mixed case head' => ['Head'],
        ];
    }

    /**
     * Anything that changes state on the server is refused.
     *
     * @param string $method
     * @return void
     * @dataProvider rejectedMethodDataProvider
     */
    public function testRejectsOtherMethods(string $method): void
    {
        self::assertFalse($this->rule->isEligible($this->snapshotForMethod($method)));
    }

    /**
     * Methods whose responses must never be replayed from a browser cache.
     *
     * @return array<string, array{0: string}>
     */
    public static function rejectedMethodDataProvider(): array
    {
        return [
            'post' => ['POST'],
            'put' => ['PUT'],
            'delete' => ['DELETE'],
            'empty' => [''],
        ];
    }

    /**
     * A configured method survives being written in lower case.
     *
     * @return void
     */
    public function testConfiguredMethodsAreNormalised(): void
    {
        $rule = new RequestMethodRule(['get' => 'get']);

        self::assertTrue($rule->isEligible($this->snapshotForMethod('GET')));
    }

    /**
     * Without any configured method nothing qualifies.
     *
     * @return void
     */
    public function testRejectsEverythingWhenNoMethodIsConfigured(): void
    {
        self::assertFalse((new RequestMethodRule())->isEligible($this->snapshotForMethod('GET')));
    }

    /**
     * Build a snapshot that differs from its siblings only in the request method.
     *
     * @param string $method HTTP method to place on the snapshot
     * @return ResponseSnapshot
     */
    private function snapshotForMethod(string $method): ResponseSnapshot
    {
        return new ResponseSnapshot($method, 200, '/women/tops.html', 'catalog_category_view', null, false, 86400, 1, null);
    }
}
