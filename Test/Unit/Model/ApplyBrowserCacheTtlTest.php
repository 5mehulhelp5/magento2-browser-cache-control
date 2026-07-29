<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BrowserCacheControl\Test\Unit\Model;

use Hryvinskyi\BrowserCacheControl\Api\ConfigInterface;
use Hryvinskyi\BrowserCacheControl\Api\EligibilityRuleInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyInterface;
use Hryvinskyi\BrowserCacheControl\Api\PageCacheStrategyResolverInterface;
use Hryvinskyi\BrowserCacheControl\Model\ApplyBrowserCacheTtl;
use Hryvinskyi\BrowserCacheControl\Model\ResponseSnapshot;
use Hryvinskyi\BrowserCacheControl\Model\Strategy\BuiltInStrategy;
use Hryvinskyi\BrowserCacheControl\Model\Strategy\VarnishStrategy;
use Hryvinskyi\BrowserCacheControl\Model\WritePoint;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the decision about whether a response may be handed a browser lifetime.
 */
class ApplyBrowserCacheTtlTest extends TestCase
{
    /**
     * Store the snapshots in this test belong to. Every configuration reading must be made against it.
     */
    private const STORE_ID = 3;

    /**
     * @var ConfigInterface&MockObject
     */
    private $configMock;

    /**
     * @var PageCacheStrategyResolverInterface&MockObject
     */
    private $strategyResolverMock;

    /**
     * Build the collaborators shared by every case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigInterface::class);
        $this->strategyResolverMock = $this->createMock(PageCacheStrategyResolverInterface::class);
    }

    /**
     * The full matrix of things that decide what a response is told, and what it ends up being told.
     *
     * @param WritePoint $point Moment in the request that has been reached
     * @param int|null $cachingApplication Caching application in force, or null when none resolves
     * @param bool $enabled Whether an administrator switched the module on
     * @param int $browserTtl Configured browser lifetime in seconds
     * @param bool $mustRevalidate Whether the revalidation directive is configured
     * @param int|null $declaredSharedTtl Lifetime Magento announced, or null when none is known
     * @param bool[] $ruleOutcomes Verdict of each eligibility rule, in order
     * @param string|null $expected Header value to publish, or null to leave the response untouched
     * @return void
     * @dataProvider decisionDataProvider
     */
    public function testDecidesWhatToPublish(
        WritePoint $point,
        ?int $cachingApplication,
        bool $enabled,
        int $browserTtl,
        bool $mustRevalidate,
        ?int $declaredSharedTtl,
        array $ruleOutcomes,
        ?string $expected
    ): void {
        $this->configMock->method('isEnabled')->with(self::STORE_ID)->willReturn($enabled);
        $this->configMock->method('getBrowserTtl')->with(self::STORE_ID)->willReturn($browserTtl);
        $this->configMock->method('isMustRevalidate')->with(self::STORE_ID)->willReturn($mustRevalidate);
        $this->strategyResolverMock->method('resolve')->willReturn(self::strategyFor($cachingApplication));

        $useCase = new ApplyBrowserCacheTtl(
            $this->configMock,
            $this->strategyResolverMock,
            $this->createRules($ruleOutcomes)
        );

        $directives = $useCase->resolve($this->snapshotWithDeclaredSharedTtl($declaredSharedTtl), $point);

        if ($expected === null) {
            self::assertNull($directives);

            return;
        }

        self::assertNotNull($directives);
        self::assertSame($expected, $directives->render());
    }

    /**
     * Every combination that decides the outcome, named for the situation it describes.
     *
     * @return array<string, array{
     *     0: WritePoint,
     *     1: int|null,
     *     2: bool,
     *     3: int,
     *     4: bool,
     *     5: int|null,
     *     6: bool[],
     *     7: string|null
     * }>
     */
    public static function decisionDataProvider(): array
    {
        $allRulesAgree = [true, true, true, true, true];

        return [
            'varnish publishes both lifetimes and stays public' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                120,
                true,
                86400,
                $allRulesAgree,
                'public, must-revalidate, max-age=120, s-maxage=86400',
            ],
            'built in publishes only the browser lifetime and is private' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                120,
                true,
                86400,
                $allRulesAgree,
                'private, must-revalidate, max-age=120',
            ],
            'revalidation is omitted when it is not configured' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                120,
                false,
                86400,
                $allRulesAgree,
                'public, max-age=120, s-maxage=86400',
            ],
            'varnish publishes no shared lifetime when none was announced' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                120,
                true,
                null,
                $allRulesAgree,
                'public, must-revalidate, max-age=120',
            ],
            'varnish is left alone at the moment that belongs to the built in cache' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::VARNISH,
                true,
                120,
                true,
                86400,
                $allRulesAgree,
                null,
            ],
            'the built in cache is left alone at the moment the page is declared cacheable' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::BUILT_IN,
                true,
                120,
                true,
                86400,
                $allRulesAgree,
                null,
            ],
            'a caching application with no strategy leaves the response alone' => [
                WritePoint::SharedCacheDeclaration,
                null,
                true,
                120,
                true,
                86400,
                $allRulesAgree,
                null,
            ],
            'one rule objecting is enough to keep the stock headers' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                120,
                true,
                86400,
                [true, true, false, true, true],
                null,
            ],
            'the last rule objecting is enough to keep the stock headers' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                120,
                true,
                86400,
                [true, true, true, true, false],
                null,
            ],
            'a browser lifetime longer than the announced one is cut down to it under varnish' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                300,
                true,
                120,
                $allRulesAgree,
                'public, must-revalidate, max-age=120, s-maxage=120',
            ],
            'a browser lifetime longer than the announced one is cut down to it under the built in cache' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                300,
                true,
                120,
                $allRulesAgree,
                'private, must-revalidate, max-age=120',
            ],
            'an announced lifetime of nothing leaves an embedded fragment alone' => [
                WritePoint::SharedCacheDeclaration,
                PageCacheConfig::VARNISH,
                true,
                120,
                true,
                0,
                $allRulesAgree,
                null,
            ],
            'an announced lifetime of nothing leaves a fragment alone under the built in cache too' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                120,
                true,
                0,
                $allRulesAgree,
                null,
            ],
            'a configured lifetime of nothing publishes nothing' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                0,
                true,
                86400,
                $allRulesAgree,
                null,
            ],
            'the module being switched off publishes nothing' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                false,
                120,
                true,
                86400,
                $allRulesAgree,
                null,
            ],
            'a shorter announced lifetime is still honoured down to one second' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                120,
                false,
                1,
                $allRulesAgree,
                'private, max-age=1',
            ],
            'no rules at all leaves the decision to the configuration and the strategy' => [
                WritePoint::BuiltInDelivery,
                PageCacheConfig::BUILT_IN,
                true,
                60,
                true,
                86400,
                [],
                'private, must-revalidate, max-age=60',
            ],
        ];
    }

    /**
     * An announced lifetime of zero seconds is a real announcement and not a missing one, so it cuts the
     * browser lifetime to nothing where an unknown lifetime would have left it alone. The two are the same
     * situation to anyone reading them loosely, and treating them alike would hand a browser lifetime to
     * every embedded fragment no block claimed.
     *
     * @return void
     */
    public function testAnAnnouncedNothingIsNotTheSameAsNothingAnnounced(): void
    {
        $this->configMock->method('isEnabled')->willReturn(true);
        $this->configMock->method('getBrowserTtl')->willReturn(120);
        $this->configMock->method('isMustRevalidate')->willReturn(true);
        $this->strategyResolverMock->method('resolve')->willReturn(new VarnishStrategy());

        $useCase = new ApplyBrowserCacheTtl($this->configMock, $this->strategyResolverMock, []);

        self::assertNull(
            $useCase->resolve(
                $this->snapshotWithDeclaredSharedTtl(0),
                WritePoint::SharedCacheDeclaration
            )
        );
        self::assertNotNull(
            $useCase->resolve(
                $this->snapshotWithDeclaredSharedTtl(null),
                WritePoint::SharedCacheDeclaration
            )
        );
    }

    /**
     * Once a rule objects the remaining ones are not asked, so an objection costs nothing and the rule that
     * has to read configuration can be placed last.
     *
     * @return void
     */
    public function testStopsAskingRulesAfterTheFirstObjection(): void
    {
        $this->configMock->method('isEnabled')->willReturn(true);
        $this->configMock->method('getBrowserTtl')->willReturn(120);
        $this->strategyResolverMock->method('resolve')->willReturn(new BuiltInStrategy());

        $objecting = $this->createMock(EligibilityRuleInterface::class);
        $objecting->expects(self::once())->method('isEligible')->willReturn(false);

        $neverAsked = $this->createMock(EligibilityRuleInterface::class);
        $neverAsked->expects(self::never())->method('isEligible');

        $useCase = new ApplyBrowserCacheTtl(
            $this->configMock,
            $this->strategyResolverMock,
            [$objecting, $neverAsked]
        );

        self::assertNull(
            $useCase->resolve($this->snapshotWithDeclaredSharedTtl(86400), WritePoint::BuiltInDelivery)
        );
    }

    /**
     * Nothing is asked of a strategy, or of any rule, once the module is found to be switched off.
     *
     * @return void
     */
    public function testAsksNothingElseOnceTheModuleIsFoundSwitchedOff(): void
    {
        $this->configMock->method('isEnabled')->willReturn(false);
        $this->configMock->expects(self::never())->method('getBrowserTtl');
        $this->strategyResolverMock->expects(self::never())->method('resolve');

        $rule = $this->createMock(EligibilityRuleInterface::class);
        $rule->expects(self::never())->method('isEligible');

        $useCase = new ApplyBrowserCacheTtl($this->configMock, $this->strategyResolverMock, [$rule]);

        self::assertNull(
            $useCase->resolve($this->snapshotWithDeclaredSharedTtl(86400), WritePoint::BuiltInDelivery)
        );
    }

    /**
     * Every rule is handed the snapshot under consideration, not some reconstruction of it.
     *
     * @return void
     */
    public function testHandsTheSnapshotItselfToEveryRule(): void
    {
        $snapshot = $this->snapshotWithDeclaredSharedTtl(86400);

        $this->configMock->method('isEnabled')->willReturn(true);
        $this->configMock->method('getBrowserTtl')->willReturn(120);
        $this->strategyResolverMock->method('resolve')->willReturn(new BuiltInStrategy());

        $first = $this->createMock(EligibilityRuleInterface::class);
        $first->expects(self::once())->method('isEligible')->with(self::identicalTo($snapshot))
            ->willReturn(true);

        $second = $this->createMock(EligibilityRuleInterface::class);
        $second->expects(self::once())->method('isEligible')->with(self::identicalTo($snapshot))
            ->willReturn(true);

        $useCase = new ApplyBrowserCacheTtl(
            $this->configMock,
            $this->strategyResolverMock,
            [$first, $second]
        );

        self::assertNotNull($useCase->resolve($snapshot, WritePoint::BuiltInDelivery));
    }

    /**
     * A strategy asking for a combination the directives refuse -- here a shared lifetime on a response no
     * shared cache may store -- publishes nothing rather than half of what was asked for.
     *
     * @return void
     */
    public function testPublishesNothingWhenAStrategyContradictsItself(): void
    {
        $this->configMock->method('isEnabled')->willReturn(true);
        $this->configMock->method('getBrowserTtl')->willReturn(120);

        $contradictory = $this->createMock(PageCacheStrategyInterface::class);
        $contradictory->method('ownsWritePoint')->willReturn(true);
        $contradictory->method('isPublic')->willReturn(false);
        $contradictory->method('sharedTtlToPublish')->willReturn(86400);

        $this->strategyResolverMock->method('resolve')->willReturn($contradictory);

        $useCase = new ApplyBrowserCacheTtl($this->configMock, $this->strategyResolverMock, []);

        self::assertNull(
            $useCase->resolve($this->snapshotWithDeclaredSharedTtl(86400), WritePoint::BuiltInDelivery)
        );
    }

    /**
     * A strategy asking a shared cache to hold a page for less time than the browser publishes nothing.
     *
     * @return void
     */
    public function testPublishesNothingWhenASharedLifetimeIsShorterThanTheBrowserLifetime(): void
    {
        $this->configMock->method('isEnabled')->willReturn(true);
        $this->configMock->method('getBrowserTtl')->willReturn(120);

        $contradictory = $this->createMock(PageCacheStrategyInterface::class);
        $contradictory->method('ownsWritePoint')->willReturn(true);
        $contradictory->method('isPublic')->willReturn(true);
        $contradictory->method('sharedTtlToPublish')->willReturn(30);

        $this->strategyResolverMock->method('resolve')->willReturn($contradictory);

        $useCase = new ApplyBrowserCacheTtl($this->configMock, $this->strategyResolverMock, []);

        self::assertNull(
            $useCase->resolve($this->snapshotWithDeclaredSharedTtl(null), WritePoint::SharedCacheDeclaration)
        );
    }

    /**
     * The strategy the caching application in force would produce.
     *
     * @param int|null $cachingApplication Caching application in force, or null when none resolves
     * @return PageCacheStrategyInterface|null Strategy the resolver hands over
     */
    private static function strategyFor(?int $cachingApplication): ?PageCacheStrategyInterface
    {
        return match ($cachingApplication) {
            PageCacheConfig::BUILT_IN => new BuiltInStrategy(),
            PageCacheConfig::VARNISH => new VarnishStrategy(),
            default => null,
        };
    }

    /**
     * Build one rule per requested verdict.
     *
     * @param bool[] $ruleOutcomes Verdict each rule must return, in order
     * @return EligibilityRuleInterface[]
     */
    private function createRules(array $ruleOutcomes): array
    {
        $rules = [];

        foreach ($ruleOutcomes as $outcome) {
            $rule = $this->createMock(EligibilityRuleInterface::class);
            $rule->method('isEligible')->willReturn($outcome);
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Build a snapshot that differs from its siblings only in the lifetime Magento announced.
     *
     * @param int|null $declaredSharedTtl Announced lifetime, or null when none is known
     * @return ResponseSnapshot
     */
    private function snapshotWithDeclaredSharedTtl(?int $declaredSharedTtl): ResponseSnapshot
    {
        return new ResponseSnapshot(
            'GET',
            200,
            '/women/tops.html',
            'catalog_category_view',
            'public, max-age=86400, s-maxage=86400',
            false,
            $declaredSharedTtl,
            self::STORE_ID
        );
    }
}
