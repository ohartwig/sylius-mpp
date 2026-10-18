<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-mpp

MPP payment discovery for Sylius: an OpenAPI 3.1 document at `/openapi.json`
that lists the shop's agent endpoints and marks the payable ones with
`x-payment-info`.

- **One document for agents** — every endpoint the other `koh` bundles register
  (ACP feed, MCP, llms.txt, …) appears as a path, without listing them by hand.
- **Prices from the same source as enforcement** — payable operations come from
  `PayableOperationProviderInterface`, which `koh/sylius-x402` also reads to
  charge. A route cannot advertise a price it does not charge.
- **Conflicts fail loudly** — two providers pricing the same operation
  differently raise an error instead of publishing whichever was wired last.
- **Independent of API Platform** — API Platform keeps its own document at
  `/docs.jsonopenapi`; this one answers at `/openapi.json`, where MPP discovery
  looks.

Requires PHP 8.3 or later and Sylius 2.2 or 2.3.

## Install

```bash
composer require koh/sylius-mpp
```

Register the bundle and the shared contracts bundle it builds on:

```php
// config/bundles.php
return [
    // …
    Koh\SyliusAgentContracts\KohSyliusAgentContractsBundle::class => ['all' => true],
    Koh\SyliusMpp\KohSyliusMppBundle::class => ['all' => true],
];
```

Import the route:

```yaml
# config/routes/koh_agent.yaml
koh_mpp:
    resource: '@KohSyliusMppBundle/config/routes.php'
```

Clear the cache and check the document:

```bash
bin/console cache:clear
curl -s https://shop.example.com/openapi.json
```

If `public/openapi.json` exists, delete it: the web server answers from disk
before Symfony is asked.

## Configure

One optional setting, the service categories announced in `x-service-info`:

```yaml
# config/packages/koh_mpp.yaml
koh_mpp:
    categories: ['data', 'search']
```

## Declaring a payable operation

Install `koh/sylius-x402` and configure its price table — this bundle picks the
prices up from there. Or implement the contract yourself and tag the service
`koh_agent.payable_operation`:

```php
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;

final class FeedPrice implements PayableOperationProviderInterface
{
    public function getPayableOperations(AgentContext $context): iterable
    {
        yield new PayableOperation(
            path: '/api/agent/feed',
            method: 'GET',
            intent: 'charge',
            payMethod: 'x402',
            amount: '10000',          // smallest unit of the currency
            currency: '0x…',          // ISO 4217 for fiat, contract address for a token
            description: 'Product feed',
        );
    }
}
```

## Output

`GET /openapi.json` with the ACP feed installed and priced:

```json
{
  "openapi": "3.1.0",
  "info": { "title": "Fashion Web Store — agent API", "version": "1.0.0" },
  "servers": [{ "url": "https://shop.example.com" }],
  "x-service-info": {
    "categories": ["data", "search"],
    "docs": {
      "homepage": "https://shop.example.com",
      "llms": "https://shop.example.com/llms.txt"
    }
  },
  "paths": {
    "/.well-known/acp.json": {
      "get": {
        "summary": "ACP discovery",
        "description": "Agentic Commerce Protocol: catalogue access for agents.",
        "responses": { "200": { "description": "Success" } }
      }
    },
    "/api/agent/feed": {
      "get": {
        "summary": "Product feed",
        "description": "Paginated, SKU-level product feed.",
        "responses": {
          "200": { "description": "Success" },
          "402": { "description": "Payment Required" }
        },
        "x-payment-info": {
          "intent": "charge",
          "method": "x402",
          "amount": "10000",
          "currency": "0x…",
          "description": "Product feed"
        }
      }
    }
  }
}
```

Every payable operation also gets a `402` response, so a client can plan for
payment instead of treating it as an error. The document is registered in
`/.well-known/api-catalog` as the origin's service description.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry, the route import
and `config/packages/koh_mpp.yaml`. No database table, no cache pool.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

The family threat model, covering this package, is at
<https://github.com/ohartwig/sylius-agent-ready/blob/main/docs/threat-model.md>.

## Licence

MIT — see [LICENSE](LICENSE).
