# Contributing to PromptlyAgent

Thank you for your interest in contributing to PromptlyAgent! This guide will help you get started with development and ensure a smooth contribution process.

## 🎯 Getting Started

### Prerequisites

Before contributing, ensure you have:
- **Docker Desktop** (Mac/Windows) or **Docker Engine + Docker Compose** (Linux)
- **Git** for version control
- At least one **AI provider API key** (OpenAI, Anthropic, Google, or AWS Bedrock)
- Basic knowledge of Laravel, PHP, and the TALL stack

### Development Setup

**Quick Start:**

```bash
# 1. Fork and clone
git clone https://github.com/YOUR_USERNAME/promptlyagent.git
cd promptlyagent

# 2. Configure environment
cp .env.example .env
# Edit .env and set:
# - Your AI provider API key (OpenAI, Anthropic, Google, or AWS)
# - WWWUSER=$(id -u) and WWWGROUP=$(id -g) to match your host user for proper file permissions

# 3. Install Composer dependencies (first-time setup)
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html:z" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-scripts

# 4. Start Docker containers
./vendor/bin/sail up -d
# Note: The initial build can take 10-15 minutes depending on hardware
# Subsequent starts are much faster using cached images

# 5. Complete Composer setup (runs post-install scripts)
./vendor/bin/sail composer install

# 6. Install npm dependencies
./vendor/bin/sail npm install

# 7. Initialize application
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate

# 8. Create admin user
./vendor/bin/sail artisan make:admin

# 9. Seed database (creates default agents)
./vendor/bin/sail artisan db:seed

# 10. Build frontend
./vendor/bin/sail npm run build

# 11. Access the application
# Open http://localhost in your browser
```

📚 **Troubleshooting?** See the [Getting Started Guide](https://github.com/promptlyagentai/promptlyagent/blob/main/docs/01-getting-started.md) for detailed setup, advanced configuration, and common issues.

---

## 📋 Development Workflow

### Branch Naming

Use descriptive branch names following this convention:
- `feature/description` - New features
- `fix/description` - Bug fixes
- `refactor/description` - Code refactoring
- `docs/description` - Documentation updates
- `test/description` - Test additions or updates

Example: `feature/add-slack-notifications` or `fix/agent-execution-timeout`

### Commit Messages

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation only
- `style:` - Code style (formatting, no logic change)
- `refactor:` - Code restructuring (no behavior change)
- `test:` - Add or update tests
- `chore:` - Build process, dependencies, tools

**Examples:**
```bash
feat(agents): add streaming response support
fix(knowledge): resolve embedding generation timeout
docs(api): update authentication documentation
refactor(tools): simplify tool registration process
test(agents): add agent execution workflow tests
chore(deps): update Laravel to version 12.1
```

**Scope** is optional but recommended (agents, knowledge, auth, tools, etc.)

### Pull Request Process

1. **Create a feature branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes**
   - Follow existing code style and patterns
   - Add tests for new functionality
   - Update documentation if needed

3. **Run code quality checks**
   ```bash
   # Format code with Pint (REQUIRED)
   ./vendor/bin/sail pint

   # Run tests
   ./vendor/bin/sail artisan test

   # Check for common issues
   ./vendor/bin/sail composer analyse  # If PHPStan is configured
   ```

4. **Commit your changes**
   ```bash
   git add .
   git commit -m "feat(scope): add amazing feature"
   ```

5. **Push to your fork**
   ```bash
   git push origin feature/your-feature-name
   ```

6. **Open a Pull Request**
   - Provide a clear title and description
   - Reference any related issues
   - Include screenshots for UI changes
   - Wait for review and address feedback

### PR Checklist

Before submitting your PR, ensure:
- [ ] Code follows Laravel and project conventions
- [ ] All tests pass (`./vendor/bin/sail artisan test`)
- [ ] Code is formatted with Pint (`./vendor/bin/sail pint`)
- [ ] New features include tests
- [ ] Documentation is updated (if applicable)
- [ ] Commit messages follow conventional commits format
- [ ] No sensitive data (API keys, credentials) in commits

---

## 🧪 Testing

### Running Tests

PromptlyAgent uses **Pest 3** for testing. All changes should include appropriate tests.

```bash
# Run all tests
./vendor/bin/sail artisan test

# Run specific test file
./vendor/bin/sail artisan test tests/Feature/Agents/AgentExecutionTest.php

# Filter by test name
./vendor/bin/sail artisan test --filter=agent_can_execute

# Run tests in parallel (faster)
./vendor/bin/sail artisan test --parallel

# Generate coverage report
./vendor/bin/sail artisan test --coverage
```

### Writing Tests

**Feature tests** are preferred over unit tests. Test the behavior, not implementation details.

**Example test structure:**
```php
<?php

use App\Models\Agent;
use App\Models\User;

it('executes agent with knowledge sources', function () {
    // Arrange
    $user = User::factory()->create();
    $agent = Agent::factory()
        ->for($user)
        ->hasKnowledgeDocuments(3)
        ->create();

    // Act
    $response = $this->actingAs($user)
        ->post("/api/agents/{$agent->id}/execute", [
            'query' => 'Test query'
        ]);

    // Assert
    $response->assertSuccessful();
    expect($agent->executions)->toHaveCount(1);
});
```

**Testing Guidelines:**
- Use factories for creating models
- Use descriptive test names (what behavior is being tested)
- Follow Arrange-Act-Assert pattern
- Test edge cases and error conditions
- Keep tests isolated and independent

---

## 📝 Code Style

### Laravel Conventions

Follow Laravel best practices:
- Use Eloquent relationships over raw queries
- Leverage form request validation
- Use service classes for complex business logic
- Follow RESTful routing conventions
- Use resource controllers appropriately

### PHP Code Style

Code formatting is **enforced** using Laravel Pint:

```bash
# Format your code (REQUIRED before committing)
./vendor/bin/sail pint

# Check formatting without changes
./vendor/bin/sail pint --test
```

**Key conventions:**
- Use PHP 8.4 features (constructor property promotion, match expressions, etc.)
- Type hint all parameters and return types
- Use meaningful variable and method names
- Add PHPDoc blocks for complex methods
- Always use curly braces for control structures

**Example:**
```php
<?php

namespace App\Services\Agents;

class AgentExecutor
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private ContextBuilder $contextBuilder
    ) {}

    public function execute(Agent $agent, string $query): AgentExecution
    {
        $context = $this->contextBuilder->build($agent);
        $tools = $this->toolRegistry->getForAgent($agent);

        return $this->processExecution($agent, $query, $context, $tools);
    }
}
```

### Frontend Code Style

**Livewire + Volt:**
- Use single-file Volt components for simple UI
- Follow existing functional or class-based patterns
- Use Flux UI components when available
- Keep component logic minimal (delegate to services)

**Tailwind CSS:**
- Use Tailwind utility classes
- Follow the semantic color system (see `docs/color-system.md`)
- Use dark mode variants where appropriate
- Avoid custom CSS unless absolutely necessary

**Alpine.js:**
- Keep JavaScript minimal and inline with Livewire
- Use Alpine for simple interactivity only
- Delegate complex logic to Livewire

---

## 🏗️ Architecture Guidelines

### Directory Structure

Follow the established structure:
```
app/
├── Filament/              # Admin panel resources
├── Http/
│   ├── Controllers/       # HTTP controllers (prefer Livewire)
│   └── Middleware/        # Custom middleware
├── Livewire/              # User-facing Livewire components
├── Models/                # Eloquent models
├── Services/
│   ├── Agents/            # Agent execution, workflows, tools
│   └── Knowledge/         # RAG, embeddings, document processing
└── Jobs/                  # Queued background jobs
```

### Service Layer Pattern

Complex business logic belongs in service classes:

```php
<?php

namespace App\Services\Knowledge;

class DocumentProcessor
{
    public function process(KnowledgeDocument $document): void
    {
        $this->extractText($document);
        $this->generateEmbeddings($document);
        $this->indexInMeilisearch($document);
    }

    private function extractText(KnowledgeDocument $document): string
    {
        // Implementation
    }
}
```

### Creating Agent Tools

Tools extend agent capabilities. Place in `app/Services/Agents/Tools/`:

```php
<?php

namespace App\Services\Agents\Tools;

use EchoLabs\Prism\Tool;
use EchoLabs\Prism\ValueObjects\{ToolCall, ToolResult};

class CustomTool extends Tool
{
    public function name(): string
    {
        return 'tool_name';
    }

    public function description(): string
    {
        return 'Clear description for AI to understand when to use this tool';
    }

    public function parameters(): array
    {
        return [
            'param_name' => [
                'type' => 'string',
                'description' => 'Parameter description',
                'required' => true,
            ],
        ];
    }

    public function handle(ToolCall $toolCall): ToolResult
    {
        try {
            $result = $this->executeToolLogic($toolCall->arguments());
            return ToolResult::text($result);
        } catch (\Exception $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
```

### Package Development

For integrations, use the self-registering package system:

1. Create in `packages/your-integration/`
2. Follow the structure of existing packages (notion, slack)
3. See `docs/package-development-guide.md` for complete tutorial

---

## 🔧 Common Development Tasks

### Adding a New Model

```bash
# Create model with migration, factory, and seeder
./vendor/bin/sail artisan make:model Thing -mfs
```

### Creating Livewire Components

```bash
# Volt component with test
./vendor/bin/sail artisan make:volt Things.ShowThing --pest

# Traditional Livewire component
./vendor/bin/sail artisan make:livewire ShowThing
```

### Database Migrations

```bash
# Create migration
./vendor/bin/sail artisan make:migration create_things_table

# Run migrations
./vendor/bin/sail artisan migrate

# Rollback last migration
./vendor/bin/sail artisan migrate:rollback

# Fresh start with seed data
./vendor/bin/sail artisan migrate:fresh --seed
```

### Queue Management

```bash
# Start Horizon
./vendor/bin/sail artisan horizon

# Check queue status
./vendor/bin/sail artisan horizon:status

# View failed jobs
./vendor/bin/sail artisan queue:failed

# Retry failed job
./vendor/bin/sail artisan queue:retry {id}
```

### Debugging

```bash
# Interactive shell (Tinker)
./vendor/bin/sail artisan tinker

# Real-time log viewer
./vendor/bin/sail artisan pail

# Database queries
./vendor/bin/sail artisan db
```

---

## 🐛 Reporting Issues

### Before Submitting an Issue

1. **Search existing issues** to avoid duplicates
2. **Check documentation** in `docs/` directory
3. **Try with a fresh environment** to rule out local issues

### Creating a Good Issue Report

Include:
- **Clear title** describing the problem
- **PromptlyAgent version** (commit hash or release tag)
- **PHP version** (`php -v`)
- **Steps to reproduce** the issue
- **Expected behavior** vs **actual behavior**
- **Error messages** (full stack trace if available)
- **Environment details** (OS, Docker version, etc.)

**Example:**
```markdown
## Description
Agent execution fails with timeout error when using large knowledge sources

## Steps to Reproduce
1. Create agent with 50+ knowledge documents
2. Execute query requiring knowledge lookup
3. Observe timeout after 60 seconds

## Expected Behavior
Agent should complete execution within reasonable time

## Actual Behavior
Execution times out with error: "Maximum execution time exceeded"

## Environment
- PromptlyAgent: commit abc123
- PHP: 8.4.2
- Laravel: 12.0.1
- OS: macOS 14.2
```

---

## 📚 Resources

### Documentation

- **[README.md](README.md)** - Project overview and quick start
- **[CLAUDE.md](CLAUDE.md)** - AI assistant development guidelines
- **[docs/color-system.md](docs/color-system.md)** - Theme customization
- **[docs/package-development-guide.md](docs/package-development-guide.md)** - Create integrations
- **[database/MIGRATION_HISTORY.md](database/MIGRATION_HISTORY.md)** - Database schema

### Learning Resources

- [Laravel Documentation](https://laravel.com/docs/12.x)
- [Livewire Documentation](https://livewire.laravel.com/docs)
- [Prism-PHP Documentation](https://prism.echolabs.dev)
- [FilamentPHP Documentation](https://filamentphp.com/docs)
- [Pest Documentation](https://pestphp.com/docs)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)

### Community

- **Email**: security@promptlyagent.ai (security issues)
- **Email**: legal@promptlyagent.ai (licensing questions)

---

## ✅ Checklist for Contributors

Before submitting your contribution:

- [ ] I have read and understood this CONTRIBUTING guide
- [ ] My code follows the project's code style (Pint formatted)
- [ ] I have added tests that prove my fix/feature works
- [ ] All tests pass locally
- [ ] I have updated documentation where necessary
- [ ] My commits follow the conventional commits format
- [ ] I have not included any sensitive data (API keys, passwords)
- [ ] My PR description clearly explains what and why

---

## 🙏 Thank You!

Your contributions help make PromptlyAgent better for everyone. We appreciate your time and effort in improving this project!

If you have questions about contributing, feel free to open a discussion issue or reach out through our communication channels.

**Happy coding!** 🚀
