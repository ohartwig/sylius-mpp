<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Registers the OpenAPI document as the origin's service description.
 *
 * Not an AgentEndpoint: the document lists the endpoints, so announcing it
 * among them would make it describe itself.
 */
final class MppEndpointProvider implements ApiCatalogEntryProviderInterface
{
    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        yield new ApiCatalogEntry(
            anchor: $context->baseUrl,
            serviceDesc: [new LinkTarget($context->url('/openapi.json'), 'application/json')],
            title: 'Agent API description',
        );
    }
}
