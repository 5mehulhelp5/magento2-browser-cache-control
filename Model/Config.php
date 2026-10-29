<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Model;

use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the browser cache control settings from Magento's scope configuration at store scope.
 */
class Config implements ConfigInterface
{
    /**
     * Excluded URL patterns already parsed and validated, keyed by the store they were resolved for.
     *
     * The getter runs on every cacheable page render, so the textarea is split and probed once per store
     * rather than once per call.
     *
     * @var array<int|string, list<string>>
     */
    private array $excludedUrlPatterns = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getBrowserTtl(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BROWSER_TTL, ScopeInterface::SCOPE_STORE, $storeId);

        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int)$value);
    }

    /**
     * @inheritDoc
     */
    public function isMustRevalidate(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MUST_REVALIDATE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getExcludedUrlPatterns(?int $storeId = null): array
    {
        $cacheKey = $storeId === null ? 'current' : (string)$storeId;

        if (!isset($this->excludedUrlPatterns[$cacheKey])) {
            $this->excludedUrlPatterns[$cacheKey] = $this->readExcludedUrlPatterns($storeId);
        }

        return $this->excludedUrlPatterns[$cacheKey];
    }

    /**
     * Split the configured textarea into a re-indexed list of usable patterns.
     *
     * Blank lines are dropped, surrounding whitespace is stripped, and the configured order is preserved.
     *
     * @param int|null $storeId Store to resolve against, or null for the current scope
     * @return list<string> Patterns that compile, in the order they were configured
     */
    private function readExcludedUrlPatterns(?int $storeId): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_URL_PATTERNS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $patterns = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $line) {
            $pattern = trim($line);

            if ($pattern === '' || !$this->isCompilable($pattern)) {
                continue;
            }

            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * Probe whether a pattern compiles under the delimiter the exclusion is matched with.
     *
     * A malformed pattern typed into the admin must not raise a warning on every storefront page, so the
     * probe silences the compilation error and reports the line as unusable instead.
     *
     * @param string $pattern Pattern as configured, without delimiters
     * @return bool True when the pattern can be used for matching
     */
    private function isCompilable(string $pattern): bool
    {
        return @preg_match(self::PATTERN_DELIMITER . $pattern . self::PATTERN_DELIMITER, '') !== false;
    }
}
