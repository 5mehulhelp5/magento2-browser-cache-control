<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Stub;

use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectives;
use Hryvinskyi\BrowserCacheControl\Model\Http\CacheDirectivesFactory;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshotFactory;

/**
 * Stands in for the factories Magento generates, building the real objects from the named arguments they receive,
 * as the object manager does.
 */
trait GeneratedFactoriesTrait
{
    /**
     * Return a snapshot factory that builds real snapshots.
     *
     * @return ResponseSnapshotFactory
     */
    private function snapshotFactoryStub(): ResponseSnapshotFactory
    {
        $factory = $this->createMock(ResponseSnapshotFactory::class);
        $factory->method('create')->willReturnCallback(self::buildSnapshot(...));

        return $factory;
    }

    /**
     * Return a directives factory that builds real directives.
     *
     * @return CacheDirectivesFactory
     */
    private function directivesFactoryStub(): CacheDirectivesFactory
    {
        $factory = $this->createMock(CacheDirectivesFactory::class);
        $factory->method('create')->willReturnCallback(self::buildDirectives(...));

        return $factory;
    }

    /**
     * Build a snapshot from the named arguments the generated factory receives.
     *
     * @param array{httpMethod: string, statusCode: int, requestPath: string, fullActionName: string,
     *     currentCacheControl: string|null, markedNotCacheable: bool, declaredSharedTtl: int|null,
     *     storeId: int|null, contextVary: string|null} $data Constructor arguments by name
     * @return ResponseSnapshot
     */
    private static function buildSnapshot(array $data): ResponseSnapshot
    {
        return new ResponseSnapshot(...$data);
    }

    /**
     * Build directives from the named arguments the generated factory receives.
     *
     * @param array{public: bool, maxAge: int, sharedMaxAge: int|null, mustRevalidate: bool} $data Constructor
     *     arguments by name
     * @return CacheDirectives
     */
    private static function buildDirectives(array $data): CacheDirectives
    {
        return new CacheDirectives(...$data);
    }
}
