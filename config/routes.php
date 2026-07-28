<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusMpp\Controller\OpenApiController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // API Platform serves its own document at /docs.jsonopenapi; MPP discovery
    // looks specifically at /openapi.json.
    $routes->add('koh_mpp_openapi', '/openapi.json')
        ->controller(OpenApiController::class)
        ->methods(['GET', 'HEAD']);
};
