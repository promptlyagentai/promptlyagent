# CLAUDE.md - AI Assistant Development Guidelines

## 🎯 Project Overview

**PromptlyAgent** - Laravel TALL stack AI-powered research and knowledge management platform with multi-agent orchestration, RAG pipelines, and real-time streaming.

**Stack**: Laravel 12, PHP 8.4, Livewire 3, Volt, Flux UI (Free), Tailwind 4, Prism-PHP, Meilisearch, Horizon, Reverb, Pest 3

---

## ⚡ Quick Start

### Command Prefix
**ALL artisan commands MUST use Sail:**
```bash
./vendor/bin/sail artisan [command]
```

### Most Common Commands
```bash
# Development
./vendor/bin/sail artisan tinker                    # Interactive shell
./vendor/bin/sail artisan pail                      # Log viewer
./vendor/bin/sail npm run dev                       # Frontend dev server

# Testing (when needed)
./vendor/bin/sail artisan test --filter=AgentTest
./vendor/bin/sail artisan test tests/Feature/Agents/

# Code formatting (REQUIRED before commits)
./vendor/bin/pint

# Database
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed

# Queues (Horizon)
./vendor/bin/sail artisan horizon:status
./vendor/bin/sail artisan queue:failed
```

---

## 🛠️ Laravel Boost MCP Server (PRIMARY TOOL)

**Use Laravel Boost FIRST for all Laravel tasks:**

```bash
# Documentation (ALWAYS CHECK DOCS BEFORE CODING)
mcp__laravel-boost__search-docs ["topic", "keywords"]

# Database Operations
mcp__laravel-boost__database-query "SELECT * FROM agents WHERE status = 'active'"
mcp__laravel-boost__database-schema "agent_executions"

# Code Execution
mcp__laravel-boost__tinker "Agent::with('tools')->find(1)"
```

**When to Use:**
- ✅ Before making any code changes (search docs for patterns)
- ✅ Debugging (query database, execute code)
- ✅ Understanding relationships and schemas
- ✅ Finding version-specific examples

---

## 🐳 Docker Infrastructure

**Multi-Container Architecture** with load-balanced services:

**Main Application Container (`laravel.test`):**
- **Supervisor** manages 3 processes:
  - PHP-FPM 8.4 (application server)
  - Nginx (web server)
  - Laravel Scheduler (`schedule:work`)

**Data Layer:**
- **MySQL 8.0** - Primary database
- **Redis Alpine** - Cache & queues (custom config)
- **Meilisearch v1.15** - Semantic search

**Background Processing:**
- **Horizon** (dedicated container) - Queue workers with auto-restart
- **Reverb** (dedicated container) - WebSocket server

**Load-Balanced Services:**
- **MarkItDown** - 2 instances + Nginx LB (document conversion)
- **SearXNG** - 2 instances + Nginx LB (meta-search engine)

**Development Tools:**
- **Mailpit** - Email testing (SMTP: 1025, UI: 8025)

**Access URLs:**
- Web: http://localhost
- Horizon: http://localhost/horizon
- Meilisearch: http://localhost:7700
- Mailpit: http://localhost:8025
- MarkItDown: http://localhost:8000
- SearXNG: http://localhost:4000

---

## 📂 Application Architecture

### Directory Structure
```
app/
├── Livewire/                # User-facing components (Volt + Flux UI)
├── Models/                  # Eloquent models
├── Services/
│   ├── Agents/              # Agent execution, workflows, tools
│   │   ├── Tools/           # Prism tools for agents
│   │   └── Schemas/         # Agent configuration
│   └── Knowledge/           # RAG, embeddings, document processing
└── Jobs/                    # Queued background jobs

database/
├── migrations/              # 67 consolidated migrations (2025-07-01)
└── migrations_archived_20260101/  # Historical reference

resources/
├── views/livewire/          # Livewire component views
└── css/                     # Tailwind 4, theme system
```

### Core Domain Models

**Agent System**
```
Agent → User, AgentTool (many), AgentExecution (many), KnowledgeDocument (M2M)
AgentExecution → Agent, User, ChatSession, AgentExecution (parent), StatusStream (many)
```

**Knowledge System**
```
KnowledgeDocument → User, Asset, Integration, Agent (M2M), KnowledgeTag (M2M)
Privacy: private (user-specific) | public (shared)
Types: file | text | external (URLs with auto-refresh)
```

**Chat System**
```
ChatSession → User, ChatInteraction (many), AgentExecution (many)
ChatInteraction → ChatSession, AgentExecution, KnowledgeDocument (M2M, sources)
```

**Artifacts & Integrations**
```
Artifact → User, ArtifactVersion (many), ArtifactTag (M2M), Integration (M2M)
Integration → User, IntegrationToken
```

**Input/Output System**
```
InputTrigger → User, Agent, OutputAction (M2M)
OutputAction → User, Agent (M2M), OutputActionLog (many)
```

### Key Services

**Agent Execution Flow:**
1. User chat → `ChatSession` + `ChatInteraction` created
2. `AgentExecution` queued via Horizon
3. Prism-PHP: context assembly → tool registration → streaming response → source extraction
4. Results: `StatusStream` (progress) → `ChatInteraction` (final output)

**Critical Files:**
- `app/Services/Agents/AgentExecutor.php` - Main execution orchestrator
- `app/Services/Agents/ToolRegistry.php` - Tool management per agent
- `app/Services/Agents/WorkflowOrchestrator.php` - Multi-agent workflows
- `app/Http/Controllers/StreamingController.php` - Real-time streaming

### Prism Tool Pattern
```php
namespace App\Services\Agents\Tools;

use EchoLabs\Prism\Tool;
use EchoLabs\Prism\ValueObjects\{ToolCall, ToolResult};

class CustomTool extends Tool
{
    public function name(): string { return 'custom_tool'; }

    public function description(): string {
        return 'Clear description for AI to understand when to use';
    }

    public function parameters(): array {
        return [
            'query' => ['type' => 'string', 'description' => '...', 'required' => true],
        ];
    }

    public function handle(ToolCall $toolCall): ToolResult {
        try {
            return ToolResult::text($this->executeLogic($toolCall->arguments()));
        } catch (\Exception $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
```

---

## 🧠 Knowledge System (RAG)

**Architecture:** Parse files → Extract text → Generate embeddings → Meilisearch (hybrid search)

**Commands:**
```bash
./vendor/bin/sail artisan knowledge:reindex           # Full rebuild (expensive!)
./vendor/bin/sail artisan knowledge:cleanup-index     # Remove stale docs
./vendor/bin/sail artisan knowledge:refresh-external  # Refresh URLs
```

**Index:** `knowledge_documents` with semantic + keyword search

---

## 🔧 Development Workflow

### Before Making Changes
1. **Search docs:** `mcp__laravel-boost__search-docs ["topic"]`
2. **Read sibling files** for patterns
3. Make changes following existing conventions
4. **Format code:** `./vendor/bin/pint` (REQUIRED)
5. Commit (hooks validate)

### Creating New Resources
```bash
# Model with migration, factory, seeder
./vendor/bin/sail artisan make:model Thing -mfs

# Livewire Volt component (with Flux UI)
./vendor/bin/sail artisan make:volt Things.ShowThing --pest

# Test (if needed)
./vendor/bin/sail artisan make:test Feature/ThingTest --pest
```

---

## 🐛 Debugging Quick Reference

### Agent Issues
```bash
# Check executions
mcp__laravel-boost__database-query "SELECT id, status, error FROM agent_executions ORDER BY created_at DESC LIMIT 10"

# Failed jobs
./vendor/bin/sail artisan queue:failed

# Debug in Tinker
./vendor/bin/sail artisan tinker
> AgentExecution::with('agent')->latest()->first()
```

### Knowledge Issues
```bash
# Index status
mcp__laravel-boost__database-query "SELECT COUNT(*) as total, processing_status FROM knowledge_documents GROUP BY processing_status"

# Search docs
mcp__laravel-boost__search-docs ["Meilisearch", "RAG"]
```

### Frontend Issues
```bash
# Rebuild assets
./vendor/bin/sail npm run build

# Check logs
./vendor/bin/sail artisan pail

# Test component (if needed)
./vendor/bin/sail artisan test --filter=ComponentTest
```

---

## 📚 Documentation References

### Project Docs (`docs/`)
- **`06-theming.md`** - Semantic colors, dark mode, theme system
- **`07-package-development.md`** - Self-registering Laravel packages for integrations

### Vendor Patches (`patches/`)
- **`prism-relay-named-arguments.patch`** - Fixes MCP tool named argument handling in prism-php/relay
- Applied via `cweagans/composer-patches` during `composer install`

### Other Resources
- `database/MIGRATION_HISTORY.md` - Migration timeline
- `packages/README.md` - Package development overview

### Custom Agents (`.claude/agents/`)
**Note:** Laravel Boost skills handle most code review and development patterns. Custom agents provide specialized functionality not covered by Boost.

**Available Custom Agents:**
- **`doc-logger.md`** - PHPDoc/JSDoc documentation and contextual logging specialist
  - Use for: Adding strategic documentation to service classes, improving logging patterns
  - Provides: PHPDoc/JSDoc suggestions, contextual logging (Error, Warning, Info, Debug)
  - Hybrid mode with user approval workflow

- **`api-docs.md`** - Scribe API documentation specialist
  - Use for: Documenting API endpoints, improving Scribe annotations
  - Provides: @group, @bodyParam, @responseField guidance, OpenAPI/Postman integration
  - Knows PromptlyAgent API patterns (Agent, Knowledge, Chat APIs)

**Removed Agents (2025-02-05):**
- ~~`css-code-review.md`~~ - Replaced by `tailwindcss-development` Boost skill
- ~~`js-livewire-alpine-code-review.md`~~ - Replaced by `volt-development` Boost skill
- ~~`laravel-code-review.md`~~ - Replaced by core Laravel Boost guidelines

---

## 🎨 Frontend Stack

**Components:**
- **Livewire 3 + Volt** - Server-driven UI with single-file components
- **Flux UI (Free)** - Primary component library (no Pro components)
- **Tailwind 4** - Utility-first CSS
- **Alpine.js** - Client-side interactivity (bundled with Livewire)
- **Dark Mode** - Full support via theme system

**Flux Components Available:**
avatar, badge, brand, breadcrumbs, button, callout, checkbox, dropdown, field, heading, icon, input, modal, navbar, profile, radio, select, separator, switch, text, textarea, tooltip

**Real-time:** Laravel Echo/Reverb for streaming responses

---

## ⚡ Queue System (Horizon)

**Dashboard:** `http://localhost/horizon`

**Jobs:**
- Agent executions (long-running AI operations)
- Knowledge processing (embeddings, document parsing)
- External refresh (scheduled URL fetching)

**Commands:**
```bash
./vendor/bin/sail artisan horizon:status
./vendor/bin/sail artisan horizon:pause/continue
./vendor/bin/sail artisan queue:retry {id}
```

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.17
- filament/filament (FILAMENT) - v3
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- laravel/scout (SCOUT) - v10
- laravel/socialite (SOCIALITE) - v5
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v3
- livewire/volt (VOLT) - v1
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- laravel-echo (ECHO) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `fluxui-development` — Develops UIs with Flux UI Free components. Activates when creating buttons, forms, modals, inputs, dropdowns, checkboxes, or UI components; replacing HTML form elements with Flux; working with flux: components; or when the user mentions Flux, component library, UI components, form fields, or asks about available Flux components.
- `volt-development` — Develops single-file Livewire components with Volt. Activates when creating Volt components, converting Livewire to Volt, working with @volt directive, functional or class-based Volt APIs; or when the user mentions Volt, single-file components, functional Livewire, or inline component logic in Blade files.
- `pest-testing` — Tests applications using the Pest 3 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, architecture testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `vendor/bin/sail artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== fluxui-free/core rules ===

# Flux UI Free

- Flux UI is the official Livewire component library. This project uses the free edition, which includes all free components and variants but not Pro components.
- Use `<flux:*>` components when available; they are the recommended way to build Livewire interfaces.
- IMPORTANT: Activate `fluxui-development` when working with Flux UI components.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
