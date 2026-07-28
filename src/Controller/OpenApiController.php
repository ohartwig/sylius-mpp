<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMpp\OpenApi\OpenApiBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiController
{
    public function __construct(
        private readonly OpenApiBuilder $builder,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();

        return $this->responder->respond($this->builder->build($context), request: $request);
    }
}
