# Magento 2 & Adobe Commerce Browser Cache Control

Gives the visitor's **browser** a short `Cache-Control` lifetime on full-page-cacheable storefront responses,
from inside Magento — without shortening how long the **shared** cache keeps them.

## Description

Magento never lets a browser cache a page, and it does so for a different reason in each caching application.
Normally the fix is two lines of VCL:

```vcl
sub vcl_deliver {
    if (resp.http.Cache-Control !~ "private" && req.url !~ "^/(media|static)/" && obj.ttl > 0s) {
        set resp.http.Cache-Control = "public, must-revalidate, max-age=120";
    }
}
```

This module is for the installations where that edit is not available: a managed Varnish whose VCL you do not
control, or a stack with no Varnish at all, running Magento's built-in full page cache.

It never invents cacheability. A response qualifies only if **Magento itself** declared it full-page
cacheable, or served it out of the full page cache. Everything else — checkout, customer account, anything
marked uncacheable — keeps the headers Magento gave it, untouched.

### What Magento does today, and why that shapes everything here

Four facts out of the 2.4.x source. They are the whole design rationale.

1. **`Response\Http::setPublicHeaders($ttl)`** emits `Pragma: cache`,
   `Cache-Control: public, max-age=<ttl>, s-maxage=<ttl>`, `Expires: now+ttl`. It is called for every
   cacheable page, and also by the ESI block endpoint (with the *block's* lifetime, which is `0` when no
   block matched) and by the swatch media endpoint. The last two are not page views.

2. **The built-in full page cache deliberately forbids browser caching.**
   `Framework\App\PageCache\Kernel::process()` matches `/public.*s-maxage=(\d+)/`, then calls
   `$response->setNoCacheHeaders()` **before** serialising the response into the cache. So the stored entry
   carries `no-store, no-cache, must-revalidate, max-age=0`, and `Kernel::load()` replays those headers on
   every hit. A browser is told "never store this" on both a miss and a hit. That is what this module fixes.

3. **The built-in cache reads its own storage lifetime out of the `s-maxage` it finds in that header.**
   Anything that drops `public` or `s-maxage` *before* `process()` runs silently switches whole-page caching
   off. This is why the module leaves the declaration moment strictly alone under the built-in cache.

4. **Magento's shipped VCL rewrites `Cache-Control` on the way out — and `private` is the one value that
   survives it.** `module-page-cache/etc/varnish{5,6,7}.vcl`, identical in all three:

   ```vcl
   # Not letting browser to cache non-static files.
   if (resp.http.Cache-Control !~ "private" && req.url !~ "^/(pub/)?(media|static)/") {
       set resp.http.Pragma = "no-cache";
       set resp.http.Expires = "-1";
       set resp.http.Cache-Control = "no-store, no-cache, must-revalidate, max-age=0";
   }
   ```

   That is the very block the VCL snippet above replaces. Its guard is also a door: a response containing
   `private` passes through untouched.

### What it emits

| Caching application | Emitted header | Reaches the browser? |
|---|---|---|
| **Built-in** (`1`) | `private, must-revalidate, max-age=<n>` | **Always** — bare, and through an unmodified Magento VCL |
| **Varnish** (`2`) | `public, must-revalidate, max-age=<n>, s-maxage=<declared>` | Only behind a CDN or a customised VCL |

`Pragma` becomes `cache` and `Expires` is removed in both cases. All three headers move together, because
Magento's no-cache set writes all three and any survivor would contradict the other two.

> **With Varnish as the caching application and a stock VCL, no origin-side solution exists.** Anything
> `public` is overwritten in `vcl_deliver`; anything containing `private` is marked `beresp.uncacheable` by
> `vcl_backend_response` and Varnish stops caching pages altogether. The VCL snippet works only because
> `vcl_deliver` runs *after* Varnish has already stored the object — a moment the origin does not have. The
> Varnish strategy ships for CDN-fronted installations; if you can edit the VCL, edit the VCL.
>
> The built-in caching application covers both cases this module was written for: no Varnish, and a Varnish
> you cannot edit.

## Features

- Per store view: on/off, lifetime in seconds, `must-revalidate`, URL exclusions.
- **Ships disabled.** Installing it changes nothing until an administrator switches it on.
- The browser lifetime is **capped at the lifetime Magento announced** for the shared cache, so it can never
  outlive the stored copy.
- Under the built-in cache the response is marked `private`, so no downstream proxy may keep a copy Magento
  has no way to purge.
- Magento's own stale-vary guard stays the last word — a page rendered for a context the visitor's browser
  does not know is never handed a lifetime.
- Five independent eligibility rules, extensible from another module through `di.xml` without editing a line
  of this one.

## Configuration

**Stores → Configuration → Hryvinskyi → Browser Cache Control**, ACL resource
`Hryvinskyi_BrowserCacheControl::config`. All fields are shown at default, website and store-view scope and
are read at store scope.

| Path (under `hryvinskyi_browser_cache_control/general/`) | Field | Default |
|---|---|---|
| `enabled` | Enabled | `0` |
| `browser_ttl` | Browser lifetime, in seconds | `120` |
| `must_revalidate` | Require revalidation | `1` |
| `excluded_url_patterns` | Excluded URL patterns | `^/(media\|static)/` |

Notes that are easy to get wrong:

- **`browser_ttl` of `0` or blank turns the feature off.** There is no silent fallback to the default — the
  `config.xml` default is the only default, so clearing the field is a way to disable, not a way to reset.
- **`excluded_url_patterns` takes one PCRE *body* per line, without delimiters.** They are compiled with `~`
  as the delimiter and the `i` flag, and matched against the request path (leading slash, no query string).
  A line that does not compile is dropped on read and treated as non-matching — one typo costs one exclusion,
  never a PHP warning on every page render.
- Magento's own **shared lifetime and caching application are global** (`system/full_page_cache/ttl` and
  `…/caching_application` are read at default scope), while this module's settings are per store view. The
  cap reconciles the two.
- The ESI and swatch-media exclusions are **correctness invariants, not preferences** — they live in
  `etc/di.xml` and are deliberately not offered in the admin.

### Verifying it

```bash
bin/magento cache:flush config     # di.xml, frontend/di.xml, system.xml and acl.xml all live in this cache
curl -sI https://<host>/<a-cacheable-page> | grep -i 'cache-control\|pragma\|expires'
```

Expect `max-age=120, must-revalidate, private` under the built-in cache — alphabetically ordered, no
`s-maxage`, no `public`, no `Expires`. Run it twice: the first request is a cache miss, the second a hit, and
both must carry it. A checkout or customer page must carry none of it.

A **signed-in** visitor gets nothing on a built-in cache *hit*, and that is correct rather than a bug: a hit
short-circuits before routing, so `Framework\App\Http\Context` is empty and its vary string is `null` while
the visitor's `X-Magento-Vary` cookie is a hash. Magento's own `HttpPlugin::beforeSendResponse` sees the
mismatch and strips the caching headers. Guests match `null` to `null` and keep them.

## How it works

Three plugins, two logical write points, and **none of them at response-send time**.

| Seam | Write point | Live when |
|---|---|---|
| `after Response\Http::setPublicHeaders($ttl)` | `SharedCacheDeclaration` | Varnish is the caching application |
| `after PageCache\Kernel::process()` — a miss | `BuiltInDelivery` | built-in is the caching application |
| `after PageCache\Kernel::load()` — a hit | `BuiltInDelivery` | built-in is the caching application |

**Why not `beforeSendResponse`**, the obvious seam:
`PageCache\Model\App\Response\HttpPlugin::beforeSendResponse` strips the caching headers when the visitor's
`X-Magento-Vary` cookie disagrees with the current context. That guard has to keep winning. Writing *earlier*
leaves it the last word for free; writing at send time would mean negotiating plugin `sortOrder` against core
or duplicating core's vary logic. The ordering guarantee comes from **which methods are plugged**, which is
why `etc/frontend/di.xml` declares no `sortOrder` at all.

**Why the declaration moment for Varnish** — nothing has stored the response yet, so what is written there is
what the shared cache keeps and obeys. It is the only moment at which a lifetime meant for a shared cache can
be announced.

**Why not that moment under the built-in cache** — see fact 3 above. The built-in path writes only after the
copy has been taken, where it changes what the visitor is told and nothing else.

**Why `Kernel::load()` matters most** — see fact 2. Without it, the repeat visitor, who has already proved
they come back, gains nothing at all.

### Layering

```
Plugin\DeclarePublicResponse   ─┐
Plugin\BuiltInCacheStored      ─┼─► ResponseSnapshotFactory ─► ApplyBrowserCacheTtl ─► CacheDirectives (VO)
Plugin\BuiltInCacheServed      ─┘        (the boundary)          (the decision)              │
                                                                       │                     ▼
                                    ConfigInterface ◄─────────────────┤          CacheDirectivesWriter
                                    EligibilityRuleInterface[] ◄──────┤          (the only class that
                                    PageCacheStrategyResolverInterface ┤           mutates a response)
                                    PublicResponseStateInterface ◄─────┘
```

The decision and everything below it work on plain values and are unit-tested without Magento present.
`ApplyBrowserCacheTtl` contains **no** test for the caching application or the write point anywhere — those
are asked once, of the strategy. Keeping it that way is the property to protect in review.

### The five eligibility rules

All must agree. Order only decides how quickly a rejection is reached.

| Rule | Rejects when |
|---|---|
| `RequestMethodRule` | the method is not `GET` or `HEAD` |
| `ResponseStatusRule` | the status is not `200` or `404` — the pair `Kernel::process()` stores |
| `ResponseIsCacheableRule` | the response is a `NotCacheableInterface`, or carries the `NotCacheable` metadata flag |
| `ExcludedActionRule` | the full action name is `magento_pagecache_block_esi` or `swatches_ajax_media` |
| `ExcludedUrlRule` | the request path matches a configured exclusion |

## Developer notes

### Adding a caching application

One class implementing `Api\PageCacheStrategyInterface` — `ownsWritePoint()`, `sharedTtlToPublish()`,
`isPublic()` — plus one entry in the strategy pool, keyed by the value stored in
`system/full_page_cache/caching_application`:

```xml
<type name="Hryvinskyi\BrowserCacheControl\Model\Strategy\PageCacheStrategyResolver">
    <arguments>
        <argument name="strategies" xsi:type="array">
            <item name="3" xsi:type="object">Vendor\Module\Model\Strategy\MyCdnStrategy</item>
        </argument>
    </arguments>
</type>
```

No existing file changes. An unrecognised caching application leaves responses alone and logs one warning per
request — it never throws, because this runs on every storefront page render.

### Adding an exclusion

One class implementing `Api\EligibilityRuleInterface` — `isEligible(ResponseSnapshot): bool` — added to the
`rules` argument of `Model\ApplyBrowserCacheTtl`. Rules never know about each other, so a new reason to skip
is a new class rather than a new branch.

### Traps worth knowing before you touch this

- **`xsi:type="number"` DI arguments arrive as numeric strings, not integers.**
  `Data\Argument\Interpreter\Number::evaluate()` returns the raw value uncast, so a strict `in_array` against
  `int` never matches. `ResponseStatusRule` casts for this reason.
- **`Laminas\Http\Header\CacheControl::getFieldValue()` sorts its directives alphabetically.** Whatever
  `CacheDirectives::render()` produces, the wire value is `max-age=120, must-revalidate, public,
  s-maxage=86400`. Magento's `/public.*s-maxage=(\d+)/` still matches only because `public` happens to sort
  ahead of `s-maxage`; core's own header depends on the same accident. **Never assert this header against a
  literal ordered string.**
- **The ESI full action name is `magento_pagecache_block_esi`,** not `page_cache_block_esi`.
  `getFullActionName()` uses the route *id*; `page_cache` is only the front name.
- **An announced shared lifetime of `0` is a real announcement, distinct from "unknown".** The ESI endpoint
  announces exactly `0` when no block matched. Reading it as "nothing announced" would fall through to the
  configured lifetime and hand every unclaimed fragment a lifetime of its own. Every check is `!== null`,
  never truthiness.
- **`PageCache\Model\Config::isEnabled()` is the Cache Management toggle**, not the caching application, and
  **`getTtl()` is annotated `@return int` while returning the raw uncast scope value.** Both are normalised
  once, in `Model\PageCacheEnvironment` — the only class in this module allowed to name that Magento class.
- **On a built-in cache hit the response is not the one `AbstractController::getResponse()` returns.**
  `Kernel::buildResponse()` mints a fresh response through `HttpFactory`, and `Framework\App\Http::launch()`
  keeps it in its own private field. Assert on the object `Kernel::load()` hands back.

### Why there is no `no-store` option

The VCL snippet carries one, commented out, labelled "disable back/forward cache". It is not implemented here
and will not be. `no-store` is unconditional in RFC 9111 §5.2.2.5 — a cache told `no-store` keeps no part of
the response — so pairing it with `max-age` does not mean "cache briefly but skip the back/forward cache", it
means "cache nothing", and the lifetime beside it is dead text. Suppressing the back/forward cache is a
*consequence* of storing nothing, not a capability that can be had while still caching. At the origin it
would also be destructive: Magento's `vcl_backend_response` turns a `no-store` backend response into
hit-for-pass, so Varnish would stop caching the page entirely.

### Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Hryvinskyi/BrowserCacheControl/Test/Unit
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist app/code/Hryvinskyi/BrowserCacheControl/Test/Integration
vendor/bin/phpstan analyse app/code/Hryvinskyi/BrowserCacheControl --level=6 \
    --autoload-file=dev/tests/integration/framework/autoload.php
vendor/bin/php-cs-fixer fix --dry-run --diff app/code/Hryvinskyi/BrowserCacheControl
```

188 unit tests cover the domain, the rules, both strategies, the decision matrix and all three plugins; 8
integration tests cover the emitted headers under each caching application. The `--autoload-file` on the
phpstan line is required for any Magento integration test: `Magento\TestFramework\` is not in composer
autoload, and the integration framework self-registers at runtime.

## Dependencies

- PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5
- `magento/framework`, `magento/module-backend`, `magento/module-config`, `magento/module-page-cache`,
  `magento/module-store`

`etc/module.xml` sequences `Magento_PageCache` and `Magento_Store`, so this module's plugins load in the
right relationship to Magento's own page cache plugins. Everything is registered in the `frontend` area only
— the admin panel, the web APIs and GraphQL are deliberately untouched.

## Author

**Volodymyr Hryvinskyi**
- Email: volodymyr@hryvinskyi.com

## License

Proprietary
