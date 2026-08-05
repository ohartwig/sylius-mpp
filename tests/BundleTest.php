<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use Koh\SyliusMpp\Agent\MppEndpointProvider;
use Koh\SyliusMpp\Controller\OpenApiController;
use Koh\SyliusMpp\KohSyliusMppBundle;
use Koh\SyliusMpp\OpenApi\OpenApiBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * The wiring is where this bundle can actually break: a service id that moved
 * in the contracts package, a configuration key that never reaches the builder,
 * a route that was never imported. None of it is visible to a unit test of the
 * builder, and all of it turns /openapi.json into a 404 or a 500 in a shop that
 * looks correctly configured.
 */
final class BundleTest extends TestCase
{
    public function testTheContainerCompilesAndTheDocumentServicesAreWired(): void
    {
        $container = $this->compile();

        self::assertTrue($container->has(OpenApiBuilder::class));
        self::assertTrue($container->has(OpenApiController::class));
    }

    /**
     * The provider is announced through the contracts tag rather than by any
     * direct dependency. Losing the tag leaves the document served and nothing
     * pointing at it, which is indistinguishable from working.
     */
    public function testTheCatalogProviderIsTaggedForTheContractsRegistry(): void
    {
        // Inspected with the removing passes disabled: the provider is reached
        // only through its tag, so an optimised container drops it here where a
        // real application keeps it via the contracts registry's tagged_iterator.
        $container = $this->compile(keepUnused: true);

        self::assertTrue(
            $container->getDefinition(MppEndpointProvider::class)->hasTag('koh_agent.api_catalog_entry'),
        );
    }

    /** The controller has to be resolvable as a service, or the route cannot reach it. */
    public function testTheControllerIsRegisteredAsAControllerService(): void
    {
        $definition = $this->compile()->getDefinition(OpenApiController::class);

        self::assertTrue($definition->hasTag('controller.service_arguments'));
        self::assertTrue($definition->isPublic());
    }

    public function testCategoriesDefaultToNone(): void
    {
        self::assertSame([], $this->compile()->getParameter('koh_mpp.categories'));
    }

    /**
     * Configured categories must reach the built document, not merely the
     * parameter bag. The builder is resolved from the compiled container and
     * asked for a document, so a parameter that is set but never wired into the
     * constructor argument fails here.
     */
    public function testConfiguredCategoriesReachTheBuiltDocument(): void
    {
        $container = $this->compile(config: ['categories' => ['data', 'search']]);

        $container->set(AgentEndpointRegistry::class, new AgentEndpointRegistry([]));
        $container->set(PayableOperationRegistry::class, new PayableOperationRegistry([]));

        $builder = $container->get(OpenApiBuilder::class);
        self::assertInstanceOf(OpenApiBuilder::class, $builder);

        $document = $builder->build($this->context());

        self::assertSame(['data', 'search'], $document['x-service-info']['categories']);
    }

    /**
     * MPP discovery looks specifically at /openapi.json; API Platform serves its
     * own document elsewhere. A moved path is a silently undiscoverable shop.
     */
    public function testTheDocumentIsRoutedAtOpenapiJson(): void
    {
        $routes = $this->routes();
        $route = $routes->get('koh_mpp_openapi');

        self::assertNotNull($route);
        self::assertSame('/openapi.json', $route->getPath());
        self::assertSame(OpenApiController::class, $route->getDefault('_controller'));
    }

    /** HEAD has to be allowed: crawlers use it to check freshness without pulling the body. */
    public function testTheRouteAnswersGetAndHead(): void
    {
        $route = $this->routes()->get('koh_mpp_openapi');

        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
    }

    /**
     * AbstractBundle resolves config/ relative to getPath(), and this package
     * keeps config/ at the package root rather than under src/. Without the
     * override, loadExtension()'s '../config/services.php' resolves outside the
     * package and the failure surfaces as an unrelated missing service.
     */
    public function testTheBundlePathIsThePackageRootWhereConfigActuallyLives(): void
    {
        $path = (new KohSyliusMppBundle())->getPath();

        self::assertFileExists($path.'/config/services.php');
        self::assertFileExists($path.'/config/routes.php');
        self::assertSame(\dirname(__DIR__), $path);
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }

    /** @param array<string, mixed> $config */
    private function compile(array $config = [], bool $keepUnused = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', \dirname(__DIR__));

        $bundle = new KohSyliusMppBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('koh_mpp', $config);

        // The contracts services this bundle consumes — stubbed, so the wiring
        // can be compiled without booting the whole Sylius stack.
        foreach ([
            AgentEndpointRegistry::class,
            PayableOperationRegistry::class,
            AgentContextFactoryInterface::class,
            AgentJsonResponder::class,
        ] as $id) {
            $container->setDefinition($id, (new Definition($id))->setSynthetic(true)->setPublic(true));
        }

        if ($keepUnused) {
            $container->getCompilerPassConfig()->setRemovingPasses([]);
        }

        $container->compile();

        return $container;
    }

    private function routes(): RouteCollection
    {
        $loader = new PhpFileLoader(new FileLocator(\dirname(__DIR__).'/config'));

        return $loader->load('routes.php');
    }
}
