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
        self::assertStringContainsString('Tabak Budde', $doc['info']['title']);
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

        self::assertSame('charge', $info['intent']);
        self::assertSame('x402', $info['method']);
        self::assertSame('10000', $info['amount']);
    }

    /** Amounts are ASCII digits in the smallest unit — a float here loses cents. */
    public function testAmountIsAStringOfDigits(): void
    {
        $amount = $this->build(payable: [$this->operation()])['paths']['/api/agent/feed']['get']['x-payment-info']['amount'];

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
        $info = $this->build(categories: ['data'])['x-service-info'];

        self::assertSame(['data'], $info['categories']);
        self::assertSame('https://shop.example.com/llms.txt', $info['docs']['llms']);
    }

    /** A shop charging for nothing still publishes a valid document. */
    public function testDocumentIsValidWithoutAnyPayableOperation(): void
    {
        $doc = $this->build();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertIsArray($doc['paths']);
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
            amount: '10000',
            currency: '0xAsset',
            description: 'Product feed',
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
