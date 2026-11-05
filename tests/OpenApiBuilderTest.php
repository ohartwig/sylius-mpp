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

final class OpenApiBuilderTest extends TestCase
{
    public function testDocumentIsOpenApi31(): void
    {
        $doc = $this->build();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame('https://shop.example.com', $doc['servers'][0]['url']);
        self::assertStringContainsString('Example Shop', $doc['info']['title']);
    }

    public function testFreeEndpointsAppearWithoutAPrice(): void
    {
        $doc = $this->build(endpoints: [new AgentEndpoint('/llms.txt', 'Overview', 'text/plain')]);

        self::assertArrayHasKey('/llms.txt', $doc['paths']);
        self::assertArrayNotHasKey('x-payment-info', $doc['paths']['/llms.txt']['get']);
    }

    public function testPayableOperationCarriesItsPaymentInfo(): void
    {
        $info = $this->build(payable: [$this->operation()])['paths']['/api/agent/feed']['get']['x-payment-info'];

        // The multi-offer form, which new documents SHOULD use
        // (draft-payment-discovery-01).
        self::assertSame(
            ['offers' => [[
                'intent' => 'charge',
                'method' => 'tempo',
                'amount' => '10000',
                'currency' => '0xAsset',
                'description' => 'Product feed',
            ]]],
            $info,
        );
    }

    /**
     * x402 is a protocol of its own, not an MPP method. An x402 price in
     * x-payment-info would send an MPP client after a challenge that never
     * comes, so the operation is left out, and with no MPP offer at all the
     * document announces no payment service either.
     */
    public function testAnX402PriceIsNotDeclaredAsAnMppOffer(): void
    {
        $doc = $this->build(
            payable: [new PayableOperation('/api/agent/feed', payMethod: 'x402', amount: '10000', currency: '0xAsset')],
            categories: ['data'],
        );

        self::assertArrayNotHasKey('/api/agent/feed', $doc['paths']);
        self::assertArrayNotHasKey('x-service-info', $doc);
        self::assertStringNotContainsString('x-payment-info', json_encode($doc, \JSON_THROW_ON_ERROR));
    }

    /** An x402 price on an announced endpoint leaves the endpoint free of payment metadata. */
    public function testAnX402PriceLeavesAnAnnouncedEndpointUntouched(): void
    {
        $doc = $this->build(
            endpoints: [new AgentEndpoint('/api/agent/feed', 'Product feed')],
            payable: [new PayableOperation('/api/agent/feed', amount: '10000')],
        );

        self::assertArrayNotHasKey('x-payment-info', $doc['paths']['/api/agent/feed']['get']);
        self::assertArrayNotHasKey('402', $doc['paths']['/api/agent/feed']['get']['responses']);
    }

    /** Different methods or intents are alternatives, published side by side. */
    public function testAlternativeOffersArePublishedTogether(): void
    {
        $offers = $this->build(payable: [
            new PayableOperation('/api/agent/feed', payMethod: 'tempo', amount: '500', currency: '0x20c000000000000000000000b9537d11c60e8b50'),
            new PayableOperation('/api/agent/feed', payMethod: 'stripe', amount: '5', currency: 'usd'),
            new PayableOperation('/api/agent/feed', intent: 'session', payMethod: 'tempo', amount: null),
        ])['paths']['/api/agent/feed']['get']['x-payment-info']['offers'];

        self::assertSame(
            [
                ['intent' => 'charge', 'method' => 'tempo', 'amount' => '500', 'currency' => '0x20c000000000000000000000b9537d11c60e8b50'],
                ['intent' => 'charge', 'method' => 'stripe', 'amount' => '5', 'currency' => 'usd'],
                ['intent' => 'session', 'method' => 'tempo', 'amount' => null],
            ],
            $offers,
        );
    }

    /** A malformed offer is a price no client can pay; it is refused, not published. */
    public function testAMalformedMppOfferIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Not a valid MPP payment offer for GET /api/agent/feed');

        $this->build(payable: [new PayableOperation('/api/agent/feed', payMethod: 'tempo', amount: '1.5')]);
    }

    /**
     * Every x-payment-info and x-service-info the builder emits validates
     * against the JSON Schemas in the appendices of draft-payment-discovery-01.
     */
    public function testTheExtensionsMatchTheDraftSchemas(): void
    {
        $doc = $this->build(
            payable: [
                new PayableOperation('/api/agent/feed', payMethod: 'tempo', amount: '0', currency: '0xAsset', description: 'Feed'),
                new PayableOperation('/api/agent/feed', payMethod: 'stripe', amount: null),
                new PayableOperation('/api/agent/bulk', 'POST', intent: 'session', payMethod: 'tempo', amount: '12'),
            ],
            categories: ['data'],
        );

        foreach ($doc['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                if (!isset($operation['x-payment-info'])) {
                    continue;
                }
                $info = $operation['x-payment-info'];
                self::assertSame(['offers'], array_keys($info));
                self::assertNotEmpty($info['offers']);
                foreach ($info['offers'] as $offer) {
                    self::assertSame([], array_diff(array_keys($offer), ['intent', 'method', 'amount', 'currency', 'description']));
                    self::assertContains($offer['intent'], ['charge', 'session']);
                    self::assertIsString($offer['method']);
                    self::assertArrayHasKey('amount', $offer);
                    if (null !== $offer['amount']) {
                        self::assertMatchesRegularExpression('/^(0|[1-9][0-9]*)$/', $offer['amount']);
                    }
                }
                self::assertSame(['description' => 'Payment Required'], $operation['responses']['402']);
            }
        }

        $serviceInfo = $doc['x-service-info'];
        self::assertSame([], array_diff(array_keys($serviceInfo), ['categories', 'docs']));
        self::assertSame([], array_diff(array_keys($serviceInfo['docs']), ['apiReference', 'homepage', 'llms']));
        foreach ($serviceInfo['docs'] as $uri) {
            self::assertNotFalse(filter_var($uri, \FILTER_VALIDATE_URL));
        }
    }

    /** Amounts are ASCII digits in the smallest unit — a float here loses cents. */
    public function testAmountIsAStringOfDigits(): void
    {
        $amount = $this->build(payable: [$this->operation()])['paths']['/api/agent/feed']['get']['x-payment-info']['offers'][0]['amount'];

        self::assertIsString($amount);
        self::assertMatchesRegularExpression('/^\d+$/', $amount);
    }

    /** Declaring the 402 lets a client plan for it rather than treat it as an error. */
    public function testPayableOperationDocumentsIts402(): void
    {
        $responses = $this->build(payable: [$this->operation()])['paths']['/api/agent/feed']['get']['responses'];

        self::assertArrayHasKey('402', $responses);
        self::assertArrayHasKey('200', $responses);
    }

    public function testServiceInfoPointsAtTheHumanReadableEntryPoints(): void
    {
        $info = $this->build(payable: [$this->operation()], categories: ['data'])['x-service-info'];

        self::assertSame(['data'], $info['categories']);
        self::assertSame('https://shop.example.com/llms.txt', $info['docs']['llms']);
    }

    /** A shop charging for nothing still publishes a valid document, without payment metadata. */
    public function testDocumentIsValidWithoutAnyPayableOperation(): void
    {
        $doc = $this->build(categories: ['data']);

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertIsArray($doc['paths']);
        self::assertArrayNotHasKey('x-service-info', $doc);
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

        return (new OpenApiBuilder(
            new AgentEndpointRegistry([$endpointProvider]),
            new PayableOperationRegistry([$payableProvider]),
            $categories,
        ))->build($this->context());
    }

    private function operation(): PayableOperation
    {
        return new PayableOperation(
            path: '/api/agent/feed',
            payMethod: 'tempo',
            amount: '10000',
            currency: '0xAsset',
            description: 'Product feed',
        );
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Example Shop');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
