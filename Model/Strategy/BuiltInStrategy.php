<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Strategy;

use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyInterface;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;

/**
 * What may be written when Magento's own built-in cache is the application caching whole pages.
 *
 * The built-in cache lives inside the request. It reads the shared lifetime out of the header Magento has
 * just written, stores the page, and then replaces that header with one telling the browser to keep nothing
 * -- which is why a built-in installation answers every visitor with no-store on both a miss and a hit, and
 * why this is the mode where a browser lifetime is worth the most.
 *
 * The declaration moment is therefore left strictly alone: it is the header the built-in cache reads to
 * decide whether the page is storable and for how long, so any rewrite there changes what Magento stores.
 * The rewrite happens after the copy has been taken, where it changes only what the visitor is told.
 *
 * By then the shared cache already has its page, so no shared lifetime is published -- there is nothing left
 * to address. That is also why the response is described as private rather than public. Simply dropping the
 * shared lifetime would not be enough: a plain public lifetime is a standing invitation to any proxy between
 * Magento and the visitor to keep the page and hand it to somebody else for as long as the browser lifetime
 * lasts, and Magento publishes nothing that would make such a proxy key the page by customer context. That
 * proxy is not hypothetical -- an installation can perfectly well run a Varnish in front while having
 * Magento's built-in cache selected, and Magento only ever purges Varnish when Varnish is the selected
 * caching application, so those copies could not even be invalidated. Private states exactly what is meant:
 * the page has been stored once already, and the visitor's own browser is the only cache that may keep
 * another.
 *
 * There is a second, structural benefit. The built-in cache recognises a storable response by looking for the
 * literal word public ahead of a shared lifetime, so a private response cannot be stored again however the
 * rewrite is reached.
 */
class BuiltInStrategy implements PageCacheStrategyInterface
{
    /**
     * @inheritDoc
     */
    public function ownsWritePoint(WritePoint $point): bool
    {
        return $point === WritePoint::BuiltInDelivery;
    }

    /**
     * @inheritDoc
     */
    public function sharedTtlToPublish(?int $declaredSharedTtl): ?int
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function isPublic(): bool
    {
        return false;
    }
}
