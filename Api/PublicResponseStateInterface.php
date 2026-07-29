<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Api;

/**
 * Carries, within one request, the fact that Magento declared the response publicly cacheable.
 *
 * The fact starts its life encoded in the response headers, and it does not survive there: Magento overwrites
 * Cache-Control with its own no-cache set before the moments at which this module writes, so by then the
 * headers can no longer tell a page the full page cache stored apart from a page it refused. Rather than
 * guess, the declaration is remembered when it is made and read back later.
 *
 * The announced shared lifetime and the fact that an announcement happened are two separate questions, and a
 * caller must not answer the first to learn the second. A page can be declared publicly cacheable with a
 * lifetime of zero seconds -- that is exactly what Magento publishes for an embedded fragment no block
 * claimed -- and reading that zero as "nothing was announced" is how such a fragment would be handed a
 * browser lifetime it must never have.
 */
interface PublicResponseStateInterface
{
    /**
     * Record that Magento declared the response publicly cacheable.
     *
     * @param int|null $sharedTtl Seconds Magento announced for a shared cache, or null when the announcement
     *        carried no lifetime -- as on a page replayed from storage, where the remaining lifetime of the
     *        stored copy is not knowable
     * @return void
     */
    public function declarePublic(?int $sharedTtl): void;

    /**
     * Whether a declaration has been made during this request.
     *
     * @return bool True once Magento has declared the response publicly cacheable
     */
    public function isDeclared(): bool;

    /**
     * Lifetime that came with the declaration.
     *
     * Zero is a lifetime that was announced, and null means no lifetime was. Both read as absent to anyone
     * asking this question alone, which is why the question of whether a declaration happened has its own
     * method.
     *
     * @return int|null Announced seconds, or null when the declaration carried no lifetime or none was made
     */
    public function getSharedTtl(): ?int;
}
