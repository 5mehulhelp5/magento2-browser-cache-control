<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Http;

use InvalidArgumentException;

/**
 * An immutable set of Cache-Control directives, and the single place that knows how to render them.
 *
 * The visibility flag is not cosmetic. A response that a shared cache in front of Magento is expected to
 * store must be public, or that cache will refuse it. A response written after Magento's built-in cache has
 * already taken its copy must be private instead: the browser is then the only cache that should keep
 * another one, and publishing it as public would license every proxy in between to hand one visitor's page
 * to the next visitor, with nothing in the response to key it by customer context and no way to purge it.
 *
 * There is deliberately no no-store directive. RFC 9111 makes no-store unconditional -- a cache told
 * no-store keeps nothing at all -- so pairing it with max-age would quietly cancel the very lifetime this
 * object exists to express. It is not a back/forward-cache switch under another name; it is the same
 * directive, and adding it back would switch the feature off.
 */
final class CacheDirectives
{
    /**
     * @param bool $public Whether shared caches may store the response, as opposed to the browser only
     * @param int $maxAge Seconds the browser may reuse the response without contacting the origin
     * @param int|null $sharedMaxAge Seconds a shared cache may reuse it, or null to say nothing about it
     * @param bool $mustRevalidate Whether a stale copy must be re-checked instead of reused
     * @throws InvalidArgumentException When the requested combination of directives contradicts itself
     */
    public function __construct(
        public readonly bool $public,
        public readonly int $maxAge,
        public readonly ?int $sharedMaxAge = null,
        public readonly bool $mustRevalidate = false
    ) {
        if ($maxAge < 1) {
            throw new InvalidArgumentException(
                'A browser lifetime of at least one second is required; a lifetime of zero means no header '
                . 'should be written at all and must be handled before building directives.'
            );
        }

        if ($sharedMaxAge !== null && !$public) {
            throw new InvalidArgumentException(
                'A shared lifetime cannot be combined with private visibility; s-maxage addresses shared '
                . 'caches while private tells those same caches not to store the response at all.'
            );
        }

        if ($sharedMaxAge !== null && $sharedMaxAge < $maxAge) {
            throw new InvalidArgumentException(
                'A shared cache must not be told to hold a page for less time than the browser; the shared '
                . 'lifetime has to be at least as long as the browser lifetime.'
            );
        }
    }

    /**
     * Render the directives as a Cache-Control header value.
     *
     * What matters about this header is which directives it carries, not the order they are written in.
     * Magento's built-in full page cache decides whether a response is storable, and for how long, by
     * running a regular expression over it that requires the literal word public to appear ahead of the
     * shared lifetime it then reads — but the response object does not preserve the order emitted here.
     * Laminas sorts a Cache-Control header's directives alphabetically before it reaches the wire, and
     * public happens to sort ahead of s-maxage, which is what actually satisfies that expression; Magento's
     * own header generation relies on the same accident. Emitting visibility first and any shared lifetime
     * last is therefore defensive tidiness, not the mechanism.
     *
     * Dropping either token is a different matter and does break things: without a shared lifetime Magento
     * stops storing the page, and without the word public there is nothing for that expression to match at
     * all — which is exactly what makes a private rendering structurally incapable of being re-stored.
     *
     * @return string Directives in a fixed order, comma separated
     */
    public function render(): string
    {
        $directives = [$this->public ? 'public' : 'private'];

        if ($this->mustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        $directives[] = 'max-age=' . $this->maxAge;

        if ($this->sharedMaxAge !== null) {
            $directives[] = 's-maxage=' . $this->sharedMaxAge;
        }

        return implode(', ', $directives);
    }
}
