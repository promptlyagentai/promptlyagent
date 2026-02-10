# Development

This guide covers the development workflow, architecture, and best practices for contributing to PromptlyAgent.

## Development Environment

### Docker Architecture

PromptlyAgent uses a **multi-container Docker architecture** with load-balanced services:

**Main Application Container:**
- Runs **Supervisor** managing 3 processes:
  - PHP-FPM 8.4 (application server)
  - Nginx (web server)
  - Laravel Scheduler (`schedule:work`)

**Database & Cache:**
- MySQL 8.0 (primary database)
- Redis Alpine (cache & queues with custom config)

**Search & Indexing:**
- Meilisearch v1.15 (semantic search)

**Background Processing:**
- Horizon container (queue workers, auto-restart)
- Reverb container (WebSocket server)

**Load-Balanced Services:**
- MarkItDown (2 instances + Nginx LB) - Document conversion
- SearXNG (2 instances + Nginx LB) - Web search

**Development Tools:**
- Mailpit (email testing: SMTP 1025, UI 8025)

### Prerequisites

- Docker Desktop running
- Basic understanding of Laravel, Livewire, and TALL stack

### Starting Development Mode

Run all services in development mode with hot reloading:

```bash
./vendor/bin/sail composer dev
```

This concurrently runs:
- **Laravel dev server** (http://localhost:8000)
- **Queue worker** with auto-restart on code changes
- **Pail log viewer** for real-time logs
- **Vite dev server** for hot module replacement

### Individual Services

Start services separately if needed:

```bash
# Application server
./vendor/bin/sail artisan serve

# Queue worker (Horizon)
./vendor/bin/sail artisan horizon

# Real-time logs
./vendor/bin/sail artisan pail

# Frontend dev server
./vendor/bin/sail npm run dev
```

## Project Structure

PromptlyAgent follows Laravel 12 conventions with TALL stack architecture:

```
├── app/
│   ├── Http/Controllers/Api/   # REST API endpoints
│   ├── Livewire/               # Livewire components (Volt)
│   ├── Models/                 # Eloquent models
│   ├── Services/
│   │   ├── Agents/             # Agent execution & tools
│   │   │   ├── Tools/          # Prism tools for agents
│   │   │   └── Schemas/        # Agent configurations
│   │   └── Knowledge/          # RAG & embeddings
│   └── Jobs/                   # Queued background jobs
├── database/
│   ├── migrations/             # Database schema (consolidated)
│   └── seeders/                # Database seeders
├── resources/
│   ├── views/livewire/         # Livewire Volt views
│   ├── css/                    # Tailwind 4 styles
│   └── js/                     # Frontend JavaScript
├── routes/
│   ├── web.php                 # Web routes
│   ├── api.php                 # API routes (Sanctum auth)
│   └── console.php             # Artisan commands
├── tests/
│   ├── Feature/                # Feature tests
│   └── Unit/                   # Unit tests
├── config/                     # Configuration files
└── docs/                       # Additional documentation
```

## Technology Stack

### Backend & Infrastructure
- **Laravel 12** - PHP framework
- **PHP 8.4** - Latest PHP version
- **Nginx** - Web server
- **PHP-FPM** - FastCGI process manager
- **Supervisor** - Process control system
- **MySQL 8.0** - Primary database
- **Redis Alpine** - Caching & queues (custom config)
- **Horizon** - Queue monitoring (dedicated container)
- **Sanctum** - API authentication
- **Scout + Meilisearch v1.15** - Semantic search
- **Reverb** - WebSocket server (dedicated container)
- **Mailpit** - Development email testing

### Specialized Services
- **MarkItDown** - Document-to-markdown service (load balanced)
- **SearXNG** - Meta-search engine (load balanced)

### Frontend
- **Livewire 3** - Server-driven UI framework
- **Volt** - Single-file Livewire components
- **Flux UI (Free)** - Component library
- **Flux UI** - Component library (free edition)
- **Tailwind CSS 4** - Utility-first styling
- **Alpine.js** - Client-side reactivity

### AI Integration
- **Prism-PHP** - Multi-provider AI SDK
- **OpenAI** - GPT models
- **Anthropic** - Claude models
- **AWS Bedrock** - Multiple models

## Development Workflow

### 1. Create a Feature Branch

```bash
git checkout -b feature/your-feature-name
```

### 2. Make Changes

Follow Laravel best practices:
- Use Eloquent relationships over raw queries
- Extract complex logic into Services
- Use Form Requests for validation
- Write descriptive commit messages

### 3. Code Formatting

Format code with Laravel Pint before committing:

```bash
./vendor/bin/pint
```

Pint is configured to match Laravel conventions and runs automatically on pre-commit hooks.

### 4. Run Tests

```bash
# Run all tests
./vendor/bin/sail artisan test

# Run specific test file
./vendor/bin/sail artisan test tests/Feature/AgentTest.php

# Run with filter
./vendor/bin/sail artisan test --filter=testAgentExecution
```

### 5. Database Migrations

Create migrations for schema changes:

```bash
./vendor/bin/sail artisan make:migration create_things_table
```

**Important**: Always review and test migrations:

```bash
# Run migrations
./vendor/bin/sail artisan migrate

# Rollback last batch
./vendor/bin/sail artisan migrate:rollback

# Fresh migration (⚠️ destroys data)
./vendor/bin/sail artisan migrate:fresh
./vendor/bin/sail artisan db:seed
```

### 6. Update Documentation

If you add new API endpoints, update documentation:

```bash
./vendor/bin/sail composer docs
```

This regenerates Scribe API documentation.

## Common Development Tasks

### Creating New API Endpoints

1. **Add route** in `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'throttle:300,1'])
    ->get('/v1/things', [ThingController::class, 'index'])
    ->name('api.things.index');
```

2. **Create controller**:

```bash
./vendor/bin/sail artisan make:controller Api/ThingController
```

3. **Add Scribe annotations**:

```php
/**
 * @group Things
 *
 * Manage things via API.
 *
 * @authenticated
 */
class ThingController extends Controller
{
    /**
     * List all things
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "things": [...]
     * }
     */
    public function index(Request $request): JsonResponse
    {
        // Implementation
    }
}
```

4. **Regenerate docs**:

```bash
./vendor/bin/sail composer docs
```

### Creating Livewire Components

Use Volt for new components:

```bash
./vendor/bin/sail artisan make:volt Things.ShowThing --pest
```

This creates:
- Component: `resources/views/livewire/things/show-thing.blade.php`
- Test: `tests/Feature/Livewire/Things/ShowThingTest.php`

### Creating Agent Tools

1. **Create tool class** in `app/Services/Agents/Tools/`:

```php
namespace App\Services\Agents\Tools;

use EchoLabs\Prism\Tool;
use EchoLabs\Prism\ValueObjects\{ToolCall, ToolResult};

class CustomTool extends Tool
{
    public function name(): string
    {
        return 'custom_tool';
    }

    public function description(): string
    {
        return 'Clear description for AI to understand when to use this tool';
    }

    public function parameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'What to search for',
                'required' => true,
            ],
        ];
    }

    public function handle(ToolCall $toolCall): ToolResult
    {
        try {
            $result = $this->executeLogic($toolCall->arguments());
            return ToolResult::text($result);
        } catch (\Exception $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
```

2. **Register tool** in `app/Services/Agents/ToolRegistry.php`

3. **Seed tool** in database seeders

### Working with Knowledge Documents

```bash
# Reindex all documents
./vendor/bin/sail artisan knowledge:reindex

# Cleanup stale documents
./vendor/bin/sail artisan knowledge:cleanup-index

# Refresh external URLs
./vendor/bin/sail artisan knowledge:refresh-external
```

## Debugging

### Logs

View real-time logs with Pail:

```bash
./vendor/bin/sail artisan pail
```

Or use Laravel Debugbar (enabled in development):
- View queries, routes, views in browser toolbar
- Inspect request/response data

### Tinker

Interactive PHP REPL:

```bash
./vendor/bin/sail artisan tinker

# Example: Query agents
>>> Agent::with('tools')->find(1)

# Example: Execute service
>>> app(AgentExecutor::class)->execute($agent, $input)
```

### Database Queries

Use Laravel Boost MCP tools for quick database access:

```bash
# Via MCP
mcp__laravel-boost__database-query "SELECT * FROM agents WHERE status = 'active'"

# Get schema
mcp__laravel-boost__database-schema "agent_executions"
```

### Queue Monitoring

Access Horizon dashboard:
- URL: http://localhost/horizon
- View failed jobs, throughput, metrics
- Retry failed jobs
- Monitor queue health

## Testing

### Writing Tests

PromptlyAgent uses Pest 3 for testing:

```php
it('creates an agent', function () {
    $user = User::factory()->create();

    $agent = Agent::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Agent',
    ]);

    expect($agent->name)->toBe('Test Agent');
    expect($agent->user_id)->toBe($user->id);
});

it('executes agent with tools', function () {
    $agent = Agent::factory()->create();
    $execution = AgentExecution::factory()->create([
        'agent_id' => $agent->id,
    ]);

    expect($execution->status)->toBe('pending');
});
```

### Running Tests

```bash
# All tests
./vendor/bin/sail artisan test

# With coverage
./vendor/bin/sail artisan test --coverage

# Specific test
./vendor/bin/sail artisan test --filter=AgentTest

# Parallel execution
./vendor/bin/sail artisan test --parallel
```

## Code Style Guidelines

### PHP Conventions

- Follow Laravel conventions
- Use type declarations for all methods
- Prefer Eloquent over Query Builder
- Use constructor property promotion (PHP 8.4)
- No empty constructors
- Use enums for fixed values (TitleCase keys)

### Livewire/Volt Conventions

- Use `wire:model.live` for real-time updates
- Add `wire:key` in loops
- Keep components focused and small
- Use `wire:loading` for async actions

### Tailwind Conventions

- Use semantic color variables (not hard-coded colors)
- Prefer utility classes over custom CSS
- Use `gap` utilities instead of margin spacing
- Follow project's dark mode conventions

### Documentation

- Add PHPDoc blocks for complex methods
- Use Scribe annotations for API endpoints
- Keep README and docs up to date
- Add inline comments only for complex logic

## Git Workflow

### Commit Messages

Use conventional commits format:

```
feat: add new agent tool for web scraping
fix: resolve race condition in queue processing
docs: update API documentation for triggers
refactor: simplify knowledge search logic
test: add coverage for agent execution
chore: update dependencies
```

### Pre-commit Hooks

The project uses git hooks to:
- Run Pint for code formatting
- Validate commit message format
- Check for debugging statements

### Pull Requests

1. Create feature branch from `main`
2. Make changes with descriptive commits
3. Run tests and ensure they pass
4. Format code with Pint
5. Update documentation if needed
6. Create PR with clear description
7. Address review feedback

## Performance Optimization

### Eager Loading

Always eager load relationships to avoid N+1:

```php
// Good
$agents = Agent::with(['tools', 'executions'])->get();

// Bad
$agents = Agent::all();
foreach ($agents as $agent) {
    $agent->tools; // N+1 query
}
```

### Caching

Use Redis for caching:

```php
Cache::remember('agents.active', 3600, function () {
    return Agent::active()->get();
});
```

### Queue Heavy Operations

Queue expensive operations:

```php
ProcessKnowledgeDocument::dispatch($document);
```

### Database Indexes

Ensure frequently queried columns are indexed:

```php
$table->index(['user_id', 'status']);
```

## Additional Resources

- [Laravel 12 Documentation](https://laravel.com/docs/12.x)
- [Livewire 3 Documentation](https://livewire.laravel.com)
- [Flux UI Documentation](https://fluxui.dev)
- [Prism-PHP Documentation](https://github.com/echolabsdev/prism)
- [Tailwind CSS 4](https://tailwindcss.com)

## Next Steps

Now that you understand the development workflow, explore these advanced topics:

**📚 Deep Dive:**
- **[Architecture](03-architecture.md)** - System design, agent execution, knowledge pipeline
- **[Workflows](04-workflows.md)** - Build custom multi-agent orchestrations
- **[Actions](05-actions.md)** - Create workflow actions for data transformation

**🔌 Extend:**
- **[Package Development](07-package-development.md)** - Build integration packages
- **[Theming](06-theming.md)** - Customize UI colors and themes

## Getting Help

- Check Laravel Boost MCP for framework docs
- Review existing code for patterns
- Ask in team chat or GitHub discussions
- Create GitHub issues for bugs
