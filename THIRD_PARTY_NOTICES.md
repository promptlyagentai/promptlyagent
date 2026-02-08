# Third-Party Notices

PromptlyAgent includes third-party software components. This document lists these components and their respective licenses.

> **Note**: Current version information for all dependencies can be found in `composer.lock` and `package-lock.json` files.

## License Summary

The majority of PromptlyAgent's dependencies use permissive open-source licenses that are compatible with both our MIT (non-commercial) and commercial licensing model:

- **MIT License**: Most dependencies
- **Apache-2.0**: AWS SDK, various utilities
- **BSD-3-Clause**: Firebase JWT, League CommonMark, and others
- **ISC**: Sodium compatibility library

### Important Notes

1. **Livewire Flux**: We use Livewire Flux Free Edition, which is available for use in our project. The "proprietary" license designation refers to the Pro/paid features which we do not use.

2. **GPL/LGPL Components**: Some dependencies (listed below) use GPL or LGPL licenses. These components are used in accordance with their license terms:
   - `nette/schema` (BSD-3-Clause, GPL-2.0-only, GPL-3.0-only)
   - `nette/utils` (BSD-3-Clause, GPL-2.0-only, GPL-3.0-only)
   - `phpoffice/phpword` (LGPL-3.0-only)

3. **Containerized Services**: PromptlyAgent uses several containerized services that run as separate processes. These services are not linked libraries or embedded code, and therefore their copyleft licenses (GPL, AGPL) do not extend to the main PromptlyAgent application. Each service is used in accordance with its respective license terms.

## Containerized Services

The following external services run in Docker containers as part of the PromptlyAgent infrastructure:

| Service | Version | License | Repository | Purpose |
|---------|---------|---------|------------|---------|
| **MarkItDown** | Latest | MIT | [microsoft/markitdown](https://github.com/microsoft/markitdown) | Document-to-markdown conversion service |
| **Pandoc** | Latest | GPL-2.0-or-later | [jgm/pandoc](https://github.com/jgm/pandoc) | Universal document converter for exports/PDF generation |
| **MermaidJS** | Latest | MIT | [mermaid-js/mermaid](https://github.com/mermaid-js/mermaid) | JavaScript-based diagram and chart generation |
| **SearXNG** | Latest | AGPL-3.0 | [searxng/searxng](https://github.com/searxng/searxng) | Privacy-respecting metasearch engine |

### License Compatibility Note

These containerized services operate as independent processes accessed via HTTP APIs. They are not statically or dynamically linked with PromptlyAgent code. Under standard interpretations of GPL and AGPL licenses, this architectural separation means:

- **Pandoc (GPL-2.0+)**: Used as a standalone service for document conversion. The GPL requirements apply to Pandoc itself but not to PromptlyAgent.
- **SearXNG (AGPL-3.0)**: Used as a standalone search service. The AGPL network copyleft provisions apply to SearXNG but not to PromptlyAgent.
- **MarkItDown & MermaidJS (MIT)**: Permissive licenses with no copyleft requirements.

This architecture is consistent with common practices for using GPL/AGPL services in commercial applications (e.g., MySQL, PostgreSQL, Redis used via network protocols).

## Production Dependencies

The following is a comprehensive list of all production (non-development) dependencies and their licenses:

### Core Framework & Laravel Ecosystem

| Package | License |
|---------|---------|
| laravel/framework | MIT |
| laravel/horizon | MIT |
| laravel/reverb | MIT |
| laravel/sanctum | MIT |
| laravel/scout | MIT |
| laravel/socialite | MIT |
| laravel/tinker | MIT |
| laravel/prompts | MIT |
| laravel/serializable-closure | MIT |

### Livewire Stack

| Package | License |
|---------|---------|
| livewire/livewire | MIT |
| livewire/volt | MIT |
| livewire/flux | Proprietary (Free Edition) |

### AI & ML Libraries (Prism-PHP)

| Package | License |
|---------|---------|
| prism-php/prism | MIT |
| prism-php/bedrock | MIT |
| prism-php/relay | MIT |

### Search & Data

| Package | License |
|---------|---------|
| meilisearch/meilisearch-php | MIT |
| doctrine/dbal | MIT |
| doctrine/inflector | MIT |
| doctrine/lexer | MIT |

### UI Components

| Package | License |
|---------|---------|
| filament/filament | MIT |
| filament/actions | MIT |
| filament/forms | MIT |
| filament/tables | MIT |
| filament/widgets | MIT |
| filament/notifications | MIT |
| filament/infolists | MIT |
| filament/support | MIT |
| blade-ui-kit/blade-icons | MIT |
| blade-ui-kit/blade-heroicons | MIT |

### AWS Services

| Package | License |
|---------|---------|
| aws/aws-sdk-php | Apache-2.0 |
| aws/aws-crt-php | Apache-2.0 |

### HTTP & Networking

| Package | License |
|---------|---------|
| guzzlehttp/guzzle | MIT |
| guzzlehttp/promises | MIT |
| guzzlehttp/psr7 | MIT |
| guzzlehttp/uri-template | MIT |
| fruitcake/php-cors | MIT |

### Document Processing

| Package | License |
|---------|---------|
| phpoffice/phpword | LGPL-3.0-only |
| phpoffice/math | MIT |
| fivefilters/readability.php | Apache-2.0 |
| league/html-to-markdown | MIT |
| masterminds/html5 | MIT |
| erusev/parsedown | MIT |
| openspout/openspout | MIT |
| smalot/pdfparser | MIT |

### Data Processing

| Package | License |
|---------|---------|
| league/csv | MIT |
| league/commonmark | BSD-3-Clause |
| league/config | BSD-3-Clause |

### Security & Cryptography

| Package | License |
|---------|---------|
| firebase/php-jwt | BSD-3-Clause |
| phpseclib/phpseclib | MIT |
| paragonie/constant_time_encoding | MIT |
| paragonie/random_compat | MIT |
| paragonie/sodium_compat | ISC |

### Storage & File Systems

| Package | License |
|---------|---------|
| league/flysystem | MIT |
| league/flysystem-aws-s3-v3 | MIT |
| league/flysystem-local | MIT |
| league/mime-type-detection | MIT |

### Utilities

| Package | License |
|---------|---------|
| nesbot/carbon | MIT |
| brick/math | MIT |
| monolog/monolog | MIT |
| nunomaduro/termwind | MIT |
| nikic/php-parser | BSD-3-Clause |
| nette/schema | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nette/utils | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| symfony/expression-language | MIT |

### PromptlyAgent Packages

| Package | License |
|---------|---------|
| promptlyagentai/notion-integration | MIT |
| promptlyagentai/slack-integration | MIT |
| promptlyagentai/http-webhook-integration | MIT |
| promptlyagentai/schedule-trigger | MIT |
| promptlyagentai/perplexity-integration | MIT |
| promptlyagentai/email-output-action | MIT |

### Redis & Queue

| Package | License |
|---------|---------|
| predis/predis | MIT |
| clue/redis-protocol | MIT |
| clue/redis-react | MIT |

### OAuth & Authentication

| Package | License |
|---------|---------|
| league/oauth1-client | MIT |

### API Documentation

| Package | License |
|---------|---------|
| knuckleswtf/scribe | MIT |
| mpociot/reflection-docblock | MIT |

### Laravel MCP Server

| Package | License |
|---------|---------|
| opgginc/laravel-mcp-server | MIT |

### Communication Services

| Package | License |
|---------|---------|
| pusher/pusher-php-server | MIT |
| resend/resend-php | MIT |

### Impersonation & Development

| Package | License |
|---------|---------|
| lab404/laravel-impersonate | MIT |

### Other Dependencies

| Package | License |
|---------|---------|
| fakerphp/faker | MIT |
| dragonmantank/cron-expression | MIT |
| egulias/email-validator | MIT |
| mtdowling/jmespath.php | MIT |
| phpoption/phpoption | Apache-2.0 |
| graham-campbell/result-type | MIT |
| php-http/discovery | MIT |
| league/uri | MIT |
| league/uri-interfaces | MIT |

## Frontend Dependencies (NPM)

The following JavaScript/TypeScript packages are used in the frontend:

### Text Editing & Rich Content

| Package | License |
|---------|---------|
| @tiptap/core | MIT |
| @tiptap/pm | MIT |
| @tiptap/starter-kit | MIT |

### Build Tools & Development

| Package | License |
|---------|---------|
| vite | MIT |
| autoprefixer | MIT |
| laravel-vite-plugin | MIT |
| concurrently | MIT |
| nodemon | MIT |

### UI & Styling

| Package | License |
|---------|---------|
| tailwindcss | MIT |
| @tailwindcss/vite | MIT |
| @tailwindcss/forms | MIT |
| @tailwindcss/typography | MIT |

### Real-time & Communication

| Package | License |
|---------|---------|
| laravel-echo | MIT |
| pusher-js | MIT |
| axios | MIT |

### Markdown & Syntax Highlighting

| Package | License |
|---------|---------|
| marked | MIT |
| marked-highlight | MIT |
| highlight.js | BSD-3-Clause |

### PWA & Service Workers

| Package | License |
|---------|---------|
| vite-plugin-pwa | MIT |
| @vite-pwa/assets-generator | MIT |
| workbox-window | Apache-2.0 |

### Storage & Testing

| Package | License |
|---------|---------|
| idb | ISC |
| @hyvor/laravel-playwright | MIT |
| @playwright/test | Apache-2.0 |

### Automation & Browser Control

| Package | License |
|---------|---------|
| puppeteer | Apache-2.0 |
| chrome-remote-interface | MIT |

## License Texts

For the complete text of each license mentioned above, please refer to:

- **MIT License**: https://opensource.org/licenses/MIT
- **Apache-2.0**: https://www.apache.org/licenses/LICENSE-2.0
- **BSD-3-Clause**: https://opensource.org/licenses/BSD-3-Clause
- **GPL-2.0**: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
- **GPL-3.0**: https://www.gnu.org/licenses/gpl-3.0.html
- **AGPL-3.0**: https://www.gnu.org/licenses/agpl-3.0.html
- **LGPL-3.0**: https://www.gnu.org/licenses/lgpl-3.0.html
- **ISC**: https://opensource.org/licenses/ISC

## Verification

To verify the current dependencies and their licenses, run:

**PHP Dependencies:**
```bash
./vendor/bin/sail composer licenses --no-dev
```

**NPM Dependencies:**
```bash
./vendor/bin/sail npm list --depth=0
```

## Questions

If you have questions about third-party licenses or compatibility with PromptlyAgent's dual licensing model, please contact: legal@promptlyagent.ai
