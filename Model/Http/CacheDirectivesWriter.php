<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model\Http;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Puts a decided set of directives onto a response, and is the only class in this module that changes one.
 *
 * Everything above this class decides; this class does. Keeping the two apart is what lets the decision be
 * read and tested as a question about values, and it means there is exactly one place to look when asking what
 * this module can possibly do to a response.
 */
final class CacheDirectivesWriter
{
    /**
     * Header carrying the directives themselves.
     */
    private const CACHE_CONTROL_HEADER = 'Cache-Control';

    /**
     * Legacy caching header that older intermediaries still act on.
     */
    private const PRAGMA_HEADER = 'Pragma';

    /**
     * Value that makes the legacy header agree with a response that may be cached.
     */
    private const PRAGMA_CACHE = 'cache';

    /**
     * Header carrying an absolute moment of expiry.
     */
    private const EXPIRES_HEADER = 'Expires';

    /**
     * Publish the directives on a response, replacing whatever it was saying about caching.
     *
     * Three headers move as one, because Magento's no-cache set writes all three and any survivor of it would
     * argue with the other two: a Pragma of no-cache tells an old intermediary the opposite of what the new
     * directives say, and an expiry stamped a year in the past tells a cache the page is already stale. Both
     * are therefore dealt with here rather than left for someone to notice.
     *
     * The expiry is cleared rather than recalculated, and that is a decision rather than an omission. An
     * absolute moment computed while the page is being rendered is already wrong by the time a shared cache
     * in front hands that same stored page to a visitor some hours later, whereas a relative lifetime is
     * measured from delivery and stays true. Every cache that matters prefers the directives over the
     * absolute expiry anyway, so the only thing a recalculated expiry could add is a second, wronger answer.
     *
     * Everything is written with replacement, so publishing twice in one request leaves exactly what
     * publishing once would have: the directives cannot accumulate.
     *
     * @param HttpResponse $response Response to publish on
     * @param CacheDirectives $directives Directives that were decided on
     * @return void
     */
    public function write(HttpResponse $response, CacheDirectives $directives): void
    {
        $response->setHeader(self::CACHE_CONTROL_HEADER, $directives->render(), true);
        $response->setHeader(self::PRAGMA_HEADER, self::PRAGMA_CACHE, true);
        $response->clearHeader(self::EXPIRES_HEADER);
    }
}
