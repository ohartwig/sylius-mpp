<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\OpenApi;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;

/**
 * Builds the OpenAPI document served at /openapi.json.
 *
 * Payment metadata follows draft-payment-discovery-01 (tempoxyz/mpp-specs):
 * `x-payment-info` in the multi-offer form on every operation with an MPP
 * offer, and `x-service-info` only when there is at least one.
 *
 * Assembled from the registries rather than generated from API Platform: this
 * document is about which endpoints an agent can call and what they cost, which
 * is a much smaller and differently-shaped question than "describe every
 * resource in the application". A shop can also serve it with API Platform
 * absent entirely.
 */
final class OpenApiBuilder
{
    public function __construct(
        private readonly AgentEndpointRegistry $endpoints,
        private readonly PayableOperationRegistry $payable,
        /** @var list<string> */
        private readonly array $categories = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function build(AgentContext $context): array
    {
        $paths = [];

        foreach ($this->endpoints->all($context) as $endpoint) {
            $paths[$endpoint->path] = [
                'get' => [
                    'summary' => $endpoint->title,
                    'description' => $endpoint->description ?? $endpoint->title,
                    'responses' => [
                        '200' => ['description' => 'Success'],
                    ],
                ],
            ];
        }

        /** @var array<string, array<string, list<array<string, mixed>>>> $offers path => method => offers */
        $offers = [];

        foreach ($this->payable->all($context) as $operation) {
            // Only MPP offers belong in x-payment-info. An x402 price is a
            // different protocol (announced by its own 402 PAYMENT-REQUIRED
            // header), and `x402` is not an MPP method identifier; declaring
            // it here would point an MPP client at a challenge that never
            // comes (draft-httpauth-payment-01, draft-payment-discovery-01).
            if (PayableOperation::PAY_METHOD_X402 === $operation->payMethod) {
                continue;
            }

            // Anything else that claims to be an MPP offer has to be one. A
            // malformed amount or method published here is a price no client
            // can pay, so it fails as loudly as a conflict does.
            if (!$operation->isMppOffer()) {
                throw new \LogicException(\sprintf(
                    'Not a valid MPP payment offer for %s %s (draft-payment-discovery-01): %s.',
                    strtoupper($operation->method),
                    $operation->path,
                    json_encode($operation->toPaymentInfo(), \JSON_THROW_ON_ERROR),
                ));
            }

            $method = strtolower($operation->method);
            $offer = $operation->toPaymentInfo();

            // Offers with a different intent, payment method or currency are
            // alternatives and all published (draft-payment-discovery-01,
            // "Same Intent, Different Currency", "Multiple Methods Under One
            // Intent"). Two providers pricing the same intent, method and
            // currency differently, though, is a shop
            // misconfiguration, and the one resolution that must not happen is
            // by wiring order: the shop would advertise a price it does not
            // charge, which is the single thing the shared PayableOperation
            // shape exists to prevent.
            //
            // An identical redeclaration is harmless and stays silent: it says
            // the same thing twice.
            foreach ($offers[$operation->path][$method] ?? [] as $declared) {
                if ($declared['intent'] !== $offer['intent']
                    || $declared['method'] !== $offer['method']
                    || ($declared['currency'] ?? null) !== ($offer['currency'] ?? null)) {
                    continue;
                }
                if ($declared !== $offer) {
                    throw new \LogicException(\sprintf(
                        'Conflicting prices declared for %s %s. Exactly one provider may price an operation per intent, payment method and currency; got %s and %s.',
                        strtoupper($method),
                        $operation->path,
                        json_encode($declared, \JSON_THROW_ON_ERROR),
                        json_encode($offer, \JSON_THROW_ON_ERROR),
                    ));
                }
                continue 2;
            }

            $offers[$operation->path][$method][] = $offer;

            $paths[$operation->path][$method] ??= [
                'summary' => $operation->summary ?? $operation->description ?? $operation->path,
                'responses' => ['200' => ['description' => 'Success']],
            ];

            // The multi-offer form, which new documents SHOULD use.
            $paths[$operation->path][$method]['x-payment-info'] = ['offers' => $offers[$operation->path][$method]];

            // A payable operation answers 402 when unpaid, and the draft
            // requires saying so; it lets a client plan for it instead of
            // treating it as an error.
            $paths[$operation->path][$method]['responses']['402'] = ['description' => 'Payment Required'];
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => ($context->channel->getName() ?? 'Shop').' — agent API',
                'version' => '1.0.0',
            ],
            'servers' => [['url' => $context->baseUrl]],
        ];

        // x-service-info describes a service that accepts payment via the
        // "Payment" scheme. Without a single MPP offer the shop is no such
        // service, and announcing it as one would advertise a capability with
        // nothing behind it.
        if ([] !== $offers) {
            $document['x-service-info'] = $this->serviceInfo($context);
        }

        $document['paths'] = $paths;

        return $document;
    }

    /** @return array<string, mixed> */
    private function serviceInfo(AgentContext $context): array
    {
        $info = [];

        if ([] !== $this->categories) {
            $info['categories'] = $this->categories;
        }

        $info['docs'] = [
            'homepage' => $context->baseUrl,
            'llms' => $context->url('/llms.txt'),
        ];

        return $info;
    }

    public function hasPayableOperations(AgentContext $context): bool
    {
        return [] !== $this->payable->all($context);
    }

    /** @return list<PayableOperation> */
    public function payableOperations(AgentContext $context): array
    {
        return $this->payable->all($context);
    }
}
