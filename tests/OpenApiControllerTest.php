<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use Koh\SyliusMpp\Controller\OpenApiController;
use Koh\SyliusMpp\OpenApi\OpenApiBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The controller is three lines, and all three of them matter, because this is
 * the response an agent reads to learn what the shop charges. It has to build
 * the document for the channel the request actually arrived on, and it has to
 * go out cacheable and cross-origin readable — a price list that a browser
 * agent cannot fetch, or that a shared cache serves for the wrong channel, is
 * the same as no price list.
 */
final class OpenApiControllerTest extends TestCase
{
    public function testTheDocumentIsServedAsJson(): void
    {
        $response = $this->respond();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));

        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi']);
    }

    /** The price reaches the wire, not just the builder's return value. */
    public function testADeclaredPriceIsPresentInTheServedBody(): void
    {
        $response = $this->respond(payable: [
            new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
        ]);

        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $offer = $decoded['paths']['/api/agent/feed']['get']['x-payment-info'];

        self::assertSame('2500', $offer['amount']);
        self::assertSame('EUR', $offer['currency']);
    }

    /**
     * The context is resolved per request rather than injected, because the
     * document is channel-dependent: a shop with two channels must not serve
     * channel A's servers[] under channel B's hostname.
     */
    public function testTheDocumentIsBuiltForTheResolvedChannel(): void
    {
        $response = $this->respond(baseUrl: 'https://at.example.com');

        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('https://at.example.com', $decoded['servers'][0]['url']);
    }

    /** Discovery documents are read cross-origin by in-browser agents. */
    public function testTheResponseIsCrossOriginReadableAndPubliclyCacheable(): void
    {
        $response = $this->respond();

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertNotNull($response->getEtag());
    }

    /**
     * A returning crawler sends If-None-Match, and the request has to reach the
     * responder for the 304 to be possible at all. Dropping the argument leaves
     * a working endpoint that silently rebuilds and resends the whole document
     * on every poll.
     */
    public function testAMatchingEtagYieldsA304(): void
    {
        $etag = $this->respond()->getEtag();

        self::assertNotNull($etag);

        $conditional = Request::create('/openapi.json');
        $conditional->headers->set('If-None-Match', $etag);

        self::assertSame(304, $this->respond(request: $conditional)->getStatusCode());
    }

    /** A shop that charges for nothing still serves a valid, non-empty document. */
    public function testAShopWithNoPricesStillServesADocument(): void
    {
        $decoded = json_decode((string) $this->respond()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['paths']);
        self::assertArrayHasKey('x-service-info', $decoded);
    }

    /** @param list<PayableOperation> $payable */
    private function respond(
        array $payable = [],
        string $baseUrl = 'https://shop.example.com',
        ?Request $request = null,
    ): \Symfony\Component\HttpFoundation\Response {
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

        $endpointProvider = new class implements AgentEndpointProviderInterface {
            public function getAgentEndpoints(AgentContext $context): iterable
            {
                return [];
            }
        };

        $builder = new OpenApiBuilder(
            new AgentEndpointRegistry([$endpointProvider]),
            new PayableOperationRegistry([$payableProvider]),
        );

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($this->context($baseUrl));

        $controller = new OpenApiController($builder, $factory, new AgentJsonResponder());

        return $controller($request ?? Request::create('/openapi.json'));
    }

    private function context(string $baseUrl): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, $baseUrl, 'de_DE', ['de_DE']);
    }
}
