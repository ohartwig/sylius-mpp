<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use Koh\SyliusMpp\OpenApi\OpenApiBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * The builder assembles one document from two registries that are filled by
 * bundles which know nothing about each other, and the interesting behaviour is
 * entirely in how the two halves land on the same path. A free endpoint and a
 * price can describe the same operation, different HTTP methods on one path
 * belong to the same path item, and either half may arrive first — none of
 * which the existing tests exercised, because each of them uses one half only.
 */
final class OpenApiPathMergeTest extends TestCase
{
    /**
     * The case that matters most: a path announced as a free endpoint and later
     * priced. The description written by the announcing bundle must survive, or
     * pricing an endpoint silently strips its documentation.
     */
    public function testPricingAnAnnouncedEndpointKeepsItsDescription(): void
    {
        $doc = $this->build(
            endpoints: [new AgentEndpoint('/api/agent/feed', 'Product feed', 'application/json', description: 'Every product, once a day.')],
            payable: [new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR')],
        );

        $get = $doc['paths']['/api/agent/feed']['get'];

        self::assertSame('Product feed', $get['summary']);
        self::assertSame('Every product, once a day.', $get['description']);
        self::assertSame('2500', $get['x-payment-info']['amount']);
        self::assertArrayHasKey('200', $get['responses']);
        self::assertArrayHasKey('402', $get['responses']);
    }

    /**
     * A price on POST must not overwrite the free GET of the same path. Both are
     * operations of one OpenAPI path item, and collapsing them would remove a
     * documented endpoint from the document.
     */
    public function testAPricedPostDoesNotDisplaceTheFreeGetOnTheSamePath(): void
    {
        $doc = $this->build(
            endpoints: [new AgentEndpoint('/api/agent/feed', 'Product feed')],
            payable: [new PayableOperation('/api/agent/feed', 'POST', amount: '5000', currency: 'EUR')],
        );

        $pathItem = $doc['paths']['/api/agent/feed'];

        self::assertSame(['get', 'post'], array_keys($pathItem));
        self::assertArrayNotHasKey('x-payment-info', $pathItem['get']);
        self::assertSame('5000', $pathItem['post']['x-payment-info']['amount']);
    }

    /** The HTTP verb is lowercased for the document; OpenAPI keys are lowercase. */
    public function testTheHttpVerbIsLowercasedIntoTheDocument(): void
    {
        $doc = $this->build(payable: [new PayableOperation('/api/agent/bulk', 'POST', amount: '5000')]);

        self::assertArrayHasKey('post', $doc['paths']['/api/agent/bulk']);
        self::assertArrayNotHasKey('POST', $doc['paths']['/api/agent/bulk']);
    }

    /** A priced path nobody announced still has to appear, or the price describes nothing. */
    public function testAPricedPathThatWasNeverAnnouncedIsStillPublished(): void
    {
        $doc = $this->build(payable: [
            new PayableOperation('/api/agent/quote', summary: 'Live quote', amount: null),
        ]);

        self::assertSame('Live quote', $doc['paths']['/api/agent/quote']['get']['summary']);
        self::assertNull($doc['paths']['/api/agent/quote']['get']['x-payment-info']['amount']);
    }

    /** Without a summary the description stands in, and without either the path does. */
    public function testTheSummaryFallsBackToTheDescriptionAndThenToThePath(): void
    {
        $withDescription = $this->build(payable: [
            new PayableOperation('/api/agent/quote', description: 'Ask for a price'),
        ]);
        $withNeither = $this->build(payable: [new PayableOperation('/api/agent/quote')]);

        self::assertSame('Ask for a price', $withDescription['paths']['/api/agent/quote']['get']['summary']);
        self::assertSame('/api/agent/quote', $withNeither['paths']['/api/agent/quote']['get']['summary']);
    }

    /** Every priced operation declares the 402 it will answer with when unpaid. */
    public function testEveryPricedOperationDeclaresIts402(): void
    {
        $doc = $this->build(payable: [
            new PayableOperation('/api/agent/feed', 'GET', amount: '2500'),
            new PayableOperation('/api/agent/bulk', 'POST', amount: '5000'),
        ]);

        self::assertArrayHasKey('402', $doc['paths']['/api/agent/feed']['get']['responses']);
        self::assertArrayHasKey('402', $doc['paths']['/api/agent/bulk']['post']['responses']);
    }

    /** A free endpoint must never gain a 402 — it is not payable and clients would plan for one. */
    public function testAFreeEndpointDeclaresNo402(): void
    {
        $doc = $this->build(endpoints: [new AgentEndpoint('/llms.txt', 'Overview', 'text/plain')]);

        self::assertArrayNotHasKey('402', $doc['paths']['/llms.txt']['get']['responses']);
        self::assertArrayNotHasKey('x-payment-info', $doc['paths']['/llms.txt']['get']);
    }

    /** An endpoint without its own description reuses the title rather than emitting null. */
    public function testAnEndpointWithoutADescriptionReusesItsTitle(): void
    {
        $doc = $this->build(endpoints: [new AgentEndpoint('/llms.txt', 'Overview')]);

        self::assertSame('Overview', $doc['paths']['/llms.txt']['get']['description']);
    }

    /**
     * The registry keeps both offers on purpose, so the x402 enforcement table
     * would see two prices while this document could only publish one. Picking
     * the later provider would make the shop advertise a price it does not
     * charge — the exact failure the shared PayableOperation shape exists to
     * prevent — so the conflict has to surface instead.
     */
    public function testTwoDifferentPricesForOneOperationAreRefused(): void
    {
        try {
            $this->build(payable: [
                new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
                new PayableOperation('/api/agent/feed', amount: '9900', currency: 'EUR'),
            ]);
            self::fail('Two different prices for one operation must not be silently resolved.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('/api/agent/feed', $exception->getMessage());
            self::assertStringContainsString('2500', $exception->getMessage());
            self::assertStringContainsString('9900', $exception->getMessage());
        }
    }

    /** The same price twice says the same thing twice; that is not a conflict. */
    public function testAnIdenticalRedeclarationIsAccepted(): void
    {
        $doc = $this->build(payable: [
            new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
            new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
        ]);

        self::assertSame('2500', $doc['paths']['/api/agent/feed']['get']['x-payment-info']['amount']);
    }

    /** Only a genuine conflict counts: the same path priced on two verbs is two operations. */
    public function testTheSamePathPricedOnTwoVerbsIsNotAConflict(): void
    {
        $doc = $this->build(payable: [
            new PayableOperation('/api/agent/feed', 'GET', amount: '2500'),
            new PayableOperation('/api/agent/feed', 'POST', amount: '9900'),
        ]);

        self::assertSame('2500', $doc['paths']['/api/agent/feed']['get']['x-payment-info']['amount']);
        self::assertSame('9900', $doc['paths']['/api/agent/feed']['post']['x-payment-info']['amount']);
    }

    /**
     * The offers are compared as declared text, not as numbers. An amount is
     * ASCII digits in the smallest unit, so "2500" and "2500.0" are two
     * different declarations and only one of them is well formed — comparing
     * them numerically would let a malformed declaration pass as agreement and
     * publish whichever provider happened to be registered first.
     */
    public function testAmountsAreComparedAsDeclaredTextAndNotAsNumbers(): void
    {
        try {
            $this->build(payable: [
                new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
                new PayableOperation('/api/agent/feed', amount: '2500.0', currency: 'EUR'),
            ]);
            self::fail('Two differently declared amounts must not be treated as one price.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('2500.0', $exception->getMessage());
        }
    }

    /** A differing currency on an equal amount is still a different price. */
    public function testADifferingCurrencyOnAnEqualAmountIsAConflict(): void
    {
        try {
            $this->build(payable: [
                new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
                new PayableOperation('/api/agent/feed', amount: '2500', currency: 'USD'),
            ]);
            self::fail('An equal amount in two currencies is not one price.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('USD', $exception->getMessage());
        }
    }

    /** hasPayableOperations() is what a caller uses to decide whether to advertise x402 at all. */
    public function testHasPayableOperationsReflectsTheRegistry(): void
    {
        self::assertFalse($this->builder()->hasPayableOperations($this->context()));
        self::assertTrue(
            $this->builder(payable: [new PayableOperation('/api/agent/feed', amount: '2500')])
                ->hasPayableOperations($this->context()),
        );
    }

    /** The x402 price table is built from this list; it must be the operations, not the document. */
    public function testPayableOperationsExposesTheOperationsThemselves(): void
    {
        $operation = new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR');

        $operations = $this->builder(payable: [$operation])->payableOperations($this->context());

        self::assertSame([$operation], $operations);
    }

    /** The channel name reaches the document title; a nameless channel degrades to "Shop". */
    public function testANamelessChannelStillProducesATitle(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn(null);

        $doc = $this->builder()->build(new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']));

        self::assertSame('Shop — agent API', $doc['info']['title']);
    }

    /** Configured categories reach x-service-info; without them only the docs block remains. */
    public function testCategoriesAreOptionalButTheDocsBlockIsNot(): void
    {
        $without = $this->build();
        $with = $this->build(categories: ['data', 'search']);

        self::assertArrayNotHasKey('categories', $without['x-service-info']);
        self::assertSame(['data', 'search'], $with['x-service-info']['categories']);
        self::assertSame('https://shop.example.com', $without['x-service-info']['docs']['homepage']);
    }

    /**
     * @param list<AgentEndpoint>    $endpoints
     * @param list<PayableOperation> $payable
     * @param list<string>           $categories
     *
     * @return array<string, mixed>
     */
    private function build(array $endpoints = [], array $payable = [], array $categories = []): array
    {
        return $this->builder($endpoints, $payable, $categories)->build($this->context());
    }

    /**
     * @param list<AgentEndpoint>    $endpoints
     * @param list<PayableOperation> $payable
     * @param list<string>           $categories
     */
    private function builder(array $endpoints = [], array $payable = [], array $categories = []): OpenApiBuilder
    {
        $endpointProvider = new class($endpoints) implements AgentEndpointProviderInterface {
            /** @param list<AgentEndpoint> $endpoints */
            public function __construct(private array $endpoints)
            {
            }

            public function getAgentEndpoints(AgentContext $context): iterable
            {
                yield from $this->endpoints;
            }
        };

        $payableProvider = new class($payable) implements PayableOperationProviderInterface {
            /** @param list<PayableOperation> $operations */
            public function __construct(private array $operations)
            {
            }

            public function getPayableOperations(AgentContext $context): iterable
            {
                yield from $this->operations;
            }
        };

        return new OpenApiBuilder(
            new AgentEndpointRegistry([$endpointProvider]),
            new PayableOperationRegistry([$payableProvider]),
            $categories,
        );
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
