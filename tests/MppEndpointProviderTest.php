<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMpp\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusMpp\Agent\MppEndpointProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * This provider is how the rest of the family learns the OpenAPI document
 * exists. It is the only link between /openapi.json and the api-catalog, so if
 * it stops emitting, the document is still served and nothing points at it —
 * which looks exactly like everything working.
 */
final class MppEndpointProviderTest extends TestCase
{
    public function testTheOpenApiDocumentIsAnnouncedAsTheServiceDescription(): void
    {
        $entry = $this->entry('https://shop.example.com');

        self::assertSame('https://shop.example.com', $entry->anchor);
        self::assertSame('Agent API description', $entry->title);
        self::assertCount(1, $entry->serviceDesc);
        self::assertSame('https://shop.example.com/openapi.json', $entry->serviceDesc[0]->href);
        self::assertSame('application/json', $entry->serviceDesc[0]->type);
    }

    /** Multi-channel: the announcement has to follow the channel the context resolved to. */
    public function testTheAnnouncementFollowsTheChannelBaseUrl(): void
    {
        self::assertSame(
            'https://at.example.com/openapi.json',
            $this->entry('https://at.example.com')->serviceDesc[0]->href,
        );
    }

    /**
     * Deliberately not an AgentEndpoint. The document lists the endpoints, so
     * announcing it among them would make it describe itself — and it would
     * then be probed by the readiness command as though it were one of them.
     */
    public function testItIsACatalogEntryAndCarriesNoHumanFacingDocumentation(): void
    {
        $entry = $this->entry('https://shop.example.com');

        self::assertSame([], $entry->serviceDoc);
        self::assertSame([], $entry->describedBy);
        self::assertSame('active', $entry->status);
    }

    /** The linkset projection is what actually ships; the shape has to survive it. */
    public function testItSerialisesIntoTheLinksetShape(): void
    {
        self::assertSame(
            [
                'anchor' => 'https://shop.example.com',
                'service-desc' => [[
                    'href' => 'https://shop.example.com/openapi.json',
                    'type' => 'application/json',
                ]],
                'title' => 'Agent API description',
            ],
            $this->entry('https://shop.example.com')->toArray(),
        );
    }

    public function testExactlyOneEntryIsContributed(): void
    {
        $entries = iterator_to_array(
            (new MppEndpointProvider())->getApiCatalogEntries($this->context('https://shop.example.com')),
        );

        self::assertCount(1, $entries);
    }

    private function entry(string $baseUrl): ApiCatalogEntry
    {
        $entries = iterator_to_array(
            (new MppEndpointProvider())->getApiCatalogEntries($this->context($baseUrl)),
        );

        return $entries[0];
    }

    private function context(string $baseUrl): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, $baseUrl, 'de_DE', ['de_DE']);
    }
}
