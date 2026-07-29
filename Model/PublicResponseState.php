<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

use Hryvinskyi\BrowserCacheControl\Api\PublicResponseStateInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Remembers, for the length of one request, that Magento declared the response publicly cacheable.
 *
 * This is the one mutable object in the module, and deliberately so. Everything else here is a value that
 * describes a response; this describes something that *happened earlier in the same request* and has since
 * been erased from the place it was originally recorded, because Magento replaces Cache-Control with its own
 * no-cache set before the moments at which this module writes. Two moments in the request need that fact and
 * only one of them witnesses it, so it is carried rather than recomputed.
 *
 * Nothing here is written anywhere or shared between requests. A single instance is handed to every collaborator
 * in a request, which under one request per process is the whole of its lifetime.
 *
 * Clearing the state between requests is not left to that assumption, though. A test runner, or any application
 * server that keeps the process alive across requests, reuses this object -- and a declaration surviving into
 * the next request would tell it a page was publicly cacheable when nothing had said so. Magento offers a hook
 * for exactly this, and the framework's own request context and HTTP response both use it for the same reason.
 */
final class PublicResponseState implements PublicResponseStateInterface, ResetAfterRequestInterface
{
    /**
     * Whether Magento has declared the response publicly cacheable during this request.
     */
    private bool $declared = false;

    /**
     * Lifetime that came with the declaration, when one did.
     */
    private ?int $sharedTtl = null;

    /**
     * @inheritDoc
     */
    public function declarePublic(?int $sharedTtl): void
    {
        $this->declared = true;
        $this->sharedTtl = $sharedTtl;
    }

    /**
     * @inheritDoc
     */
    public function isDeclared(): bool
    {
        return $this->declared;
    }

    /**
     * @inheritDoc
     */
    public function getSharedTtl(): ?int
    {
        return $this->sharedTtl;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->declared = false;
        $this->sharedTtl = null;
    }
}
