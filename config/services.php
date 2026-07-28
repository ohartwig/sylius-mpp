<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use Koh\SyliusMpp\Agent\MppEndpointProvider;
use Koh\SyliusMpp\Controller\OpenApiController;
use Koh\SyliusMpp\OpenApi\OpenApiBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(OpenApiBuilder::class)
        ->args([
            service(AgentEndpointRegistry::class),
            service(PayableOperationRegistry::class),
            param('koh_mpp.categories'),
        ])
        ->public();

    $services->set(OpenApiController::class)
        ->args([
            service(OpenApiBuilder::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(MppEndpointProvider::class)->tag('koh_agent.api_catalog_entry');
};
