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

        foreach ($this->payable->all($context) as $operation) {
            $method = strtolower($operation->method);
            $offer = $operation->toPaymentInfo();

            // Two bundles pricing the same operation differently is a shop
            // misconfiguration, and the one resolution that must not happen is
            // by wiring order. The registry deliberately keeps both offers, so
            // the x402 side sees two prices while this document would publish
            // whichever provider was registered last — the shop then advertises
            // a price it does not charge, which is the single thing the shared
            // PayableOperation shape exists to prevent.
            //
            // An identical redeclaration is harmless and stays silent: it says
            // the same thing twice.
            $declared = $paths[$operation->path][$method]['x-payment-info'] ?? null;
            if (null !== $declared && $declared !== $offer) {
                throw new \LogicException(\sprintf(
                    'Conflicting prices declared for %s %s. Exactly one provider may price an operation; got %s and %s.',
                    strtoupper($method),
                    $operation->path,
                    json_encode($declared, \JSON_THROW_ON_ERROR),
                    json_encode($offer, \JSON_THROW_ON_ERROR),
                ));
            }

            $paths[$operation->path][$method] = array_merge(
                $paths[$operation->path][$method] ?? [
                    'summary' => $operation->summary ?? $operation->description ?? $operation->path,
                    'responses' => ['200' => ['description' => 'Success']],
                ],
                [
                    'x-payment-info' => $offer,
                ],
            );

            // A payable operation answers 402 when unpaid; saying so in the
            // document lets a client plan for it instead of treating it as an
            // error.
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

        $serviceInfo = $this->serviceInfo($context);
        if ([] !== $serviceInfo) {
            $document['x-service-info'] = $serviceInfo;
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
