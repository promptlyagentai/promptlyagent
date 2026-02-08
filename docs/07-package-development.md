# Package Development Guide

## Overview

PromptlyAgent uses a modular package system that enables clean separation of integration code from the core application. Packages are self-registering Laravel packages that require zero changes to the main application code - they automatically discover and register themselves through Laravel's service provider auto-discovery mechanism.

## Key Benefits

- **Zero Core Changes**: Add new integrations without modifying the main application
- **Modular Architecture**: Each integration is self-contained and independently maintainable
- **Auto-Discovery**: Packages automatically register services, views, routes, and components
- **Registry Pattern**: Dynamic registration of tools, providers, and converters
- **Standard Structure**: Consistent conventions across all packages

## Package Architecture

### Directory Structure

Packages are located in `/packages/` directory. Each package follows this standard structure:

```
packages/
└── your-integration/
    ├── composer.json                    # Package definition
    ├── README.md                        # Package documentation
    ├── src/
    │   ├── YourIntegrationServiceProvider.php  # Main service provider
    │   ├── Services/                    # Business logic services
    │   │   ├── YourService.php
    │   │   └── YourKnowledgeSource.php
    │   ├── Providers/                   # Integration providers
    │   │   └── YourOAuthProvider.php
    │   ├── Tools/                       # AI agent tools
    │   │   ├── YourSearchTool.php
    │   │   └── YourCreateTool.php
    │   ├── Livewire/                    # Livewire components
    │   │   └── YourBrowser.php
    │   ├── Listeners/                   # Event listeners
    │   │   └── YourEventListener.php
    │   └── Http/
    │       └── Controllers/             # HTTP controllers
    │           └── YourWebhookController.php
    ├── resources/
    │   ├── views/                       # Blade templates
    │   │   └── livewire/
    │   └── config/                      # Package configuration
    ├── routes/
    │   ├── web.php                      # Web routes
    │   └── webhooks.php                 # Webhook routes
    ├── database/
    │   └── migrations/                  # Database migrations
    └── tests/                           # Package tests
        └── Feature/
```

## Creating a New Package

### Step 1: Initialize Package Structure

Create the package directory structure:

```bash
mkdir -p packages/my-integration/{src,resources/views,routes,database/migrations,tests}
```

### Step 2: Create composer.json

Define your package in `packages/my-integration/composer.json`:

```json
{
    "name": "promptlyagentai/my-integration",
    "description": "My Integration for PromptlyAgent",
    "type": "library",
    "license": "MIT",
    "keywords": [
        "my-integration",
        "integration",
        "laravel",
        "livewire",
        "ai"
    ],
    "authors": [
        {
            "name": "Your Name",
            "email": "your.email@example.com"
        }
    ],
    "require": {
        "php": "^8.2",
        "laravel/framework": "^11.0|^12.0",
        "livewire/livewire": "^3.0",
        "illuminate/support": "^11.0|^12.0"
    },
    "autoload": {
        "psr-4": {
            "PromptlyAgentAI\\MyIntegration\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "PromptlyAgentAI\\MyIntegration\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "PromptlyAgentAI\\MyIntegration\\MyIntegrationServiceProvider"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Critical Elements:**

- `extra.laravel.providers`: Declares your service provider for auto-discovery
- `autoload.psr-4`: PSR-4 namespace mapping
- Package name follows convention: `promptlyagentai/{integration-name}`

### Step 3: Create Service Provider

Create `src/MyIntegrationServiceProvider.php`:

```php
<?php

namespace PromptlyAgentAI\MyIntegration;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PromptlyAgentAI\MyIntegration\Providers\MyOAuthProvider;
use PromptlyAgentAI\MyIntegration\Services\MyService;
use PromptlyAgentAI\MyIntegration\Tools\MySearchTool;

class MyIntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register all services as singletons for performance
        $this->app->singleton(MyService::class);
        $this->app->singleton(MyOAuthProvider::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // 1. Register package views with namespace
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'my-integration');

        // 2. Register migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // 3. Register routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // 4. Register Livewire components (if applicable)
        Livewire::component('my-browser', MyBrowser::class);

        // 5. Register with ProviderRegistry (OAuth/Integration provider)
        if ($this->app->bound(\App\Services\Integrations\ProviderRegistry::class)) {
            $registry = $this->app->make(\App\Services\Integrations\ProviderRegistry::class);
            $registry->register($this->app->make(MyOAuthProvider::class));
        }

        // 6. Register tools with ToolRegistry
        if ($this->app->bound(\App\Services\Agents\ToolRegistry::class)) {
            $toolRegistry = $this->app->make(\App\Services\Agents\ToolRegistry::class);

            $toolRegistry->registerTool('my_search', [
                'class' => MySearchTool::class,
                'name' => 'My Integration Search',
                'description' => 'Search within My Integration',
                'category' => 'my-integration',
                'requires_integration' => true,
                'integration_provider' => 'my-integration',
            ]);
        }
    }
}
```

### Step 4: Register Package in Main Application

Add the package to the main `composer.json` repositories and require sections:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/my-integration"
        }
    ],
    "require": {
        "promptlyagentai/my-integration": "@dev"
    }
}
```

### Step 5: Install Package

Run Composer to install and register the package:

```bash
composer update promptlyagentai/my-integration
```

Laravel will automatically:
- Discover the service provider
- Register all services, views, routes, and components
- Make the package available to the application

## Self-Registration Mechanisms

### 1. Service Provider Auto-Discovery

Laravel automatically discovers service providers declared in `composer.json`:

```json
"extra": {
    "laravel": {
        "providers": [
            "PromptlyAgentAI\\MyIntegration\\MyIntegrationServiceProvider"
        ]
    }
}
```

The main application's `composer.json` triggers package discovery:

```json
"scripts": {
    "post-autoload-dump": [
        "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
        "@@php artisan package:discover --ansi"
    ]
}
```

### 2. PSR-4 Autoloading

Composer automatically loads classes based on PSR-4 namespace mapping:

```json
"autoload": {
    "psr-4": {
        "PromptlyAgentAI\\MyIntegration\\": "src/"
    }
}
```

### 3. Registry Pattern Integration

Packages self-register with application registries using conditional binding checks:

```php
// Only register if the registry exists (graceful degradation)
if ($this->app->bound(\App\Services\Agents\ToolRegistry::class)) {
    $toolRegistry = $this->app->make(\App\Services\Agents\ToolRegistry::class);
    $toolRegistry->registerTool('my_tool', [...]);
}
```

This approach ensures:
- Packages work independently
- No circular dependencies
- Graceful handling of missing core services

## Integration Points

### Available Registries

Packages can register with these core application registries:

#### 1. ToolRegistry (`App\Services\Agents\ToolRegistry`)

Register AI agent tools that can be used during research and workflows:

```php
$toolRegistry->registerTool('tool_identifier', [
    'class' => MyTool::class,              // Tool class (must implement ToolInterface)
    'name' => 'Tool Display Name',         // Human-readable name
    'description' => 'What this tool does', // Tool description for AI
    'category' => 'category-name',         // Tool category grouping
    'requires_integration' => true,        // Whether it needs integration setup
    'integration_provider' => 'provider',  // Which provider it needs
]);
```

#### 2. ProviderRegistry (`App\Services\Integrations\ProviderRegistry`)

Register OAuth/integration providers:

```php
$registry->register($this->app->make(MyOAuthProvider::class));
```

Provider classes should implement integration-specific logic:
- OAuth flow handling
- API authentication
- Token management
- Connection verification

#### 3. ContentConverterRegistry (`App\Services\Tools\ContentConverterRegistry`)

Register content converters that transform external content to application format:

```php
$converterRegistry->register($this->app->make(MyContentConverter::class));
```

#### 4. OutputActionRegistry (`App\Services\OutputAction\OutputActionRegistry`)

Register output actions that handle research results:

```php
$outputActionRegistry->register($this->app->make(MyOutputActionProvider::class));
```

#### 5. InputTriggerRegistry (`App\Services\InputTrigger\InputTriggerRegistry`)

Register input triggers that can initiate workflows:

```php
$inputTriggerRegistry->register($this->app->make(MyInputTrigger::class));
```

### Event Listeners

Packages can listen to application events:

```php
use Illuminate\Support\Facades\Event;

Event::listen(
    \App\Events\ResearchComplete::class,
    MyEventListener::class
);
```

Common events:
- `App\Events\ResearchComplete` - Research workflow finished
- `App\Events\StatusStreamCreated` - Status update created
- `App\Events\HolisticWorkflowCompleted` - Full workflow completed

## Package Components

### Services

Service classes contain business logic:

```php
namespace PromptlyAgentAI\MyIntegration\Services;

class MyService
{
    public function __construct(
        private MyApiClient $client
    ) {}

    public function search(string $query): array
    {
        return $this->client->search($query);
    }
}
```

Register as singleton in service provider:

```php
$this->app->singleton(MyService::class);
```

### Tools

Tools are AI-accessible functions. Create in `src/Tools/`:

```php
namespace PromptlyAgentAI\MyIntegration\Tools;

use App\Services\Agents\Tools\ToolInterface;

class MySearchTool implements ToolInterface
{
    public function __construct(
        private MyService $service
    ) {}

    public function getDefinition(): array
    {
        return [
            'name' => 'my_search',
            'description' => 'Search My Integration',
            'parameters' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query',
                    'required' => true,
                ],
            ],
        ];
    }

    public function execute(array $parameters): array
    {
        return $this->service->search($parameters['query']);
    }
}
```

### Providers

Integration providers handle OAuth and API connections:

```php
namespace PromptlyAgentAI\MyIntegration\Providers;

use App\Services\Integrations\IntegrationProviderInterface;

class MyOAuthProvider implements IntegrationProviderInterface
{
    public function getName(): string
    {
        return 'my-integration';
    }

    public function getDisplayName(): string
    {
        return 'My Integration';
    }

    public function getOAuthUrl(User $user): string
    {
        // Return OAuth authorization URL
    }

    public function handleCallback(Request $request, User $user): void
    {
        // Handle OAuth callback and store tokens
    }
}
```

### Livewire Components

Create interactive UI components:

```php
namespace PromptlyAgentAI\MyIntegration\Livewire;

use Livewire\Component;

class MyBrowser extends Component
{
    public $items = [];

    public function mount()
    {
        $this->loadItems();
    }

    public function render()
    {
        return view('my-integration::livewire.browser');
    }
}
```

Register in service provider:

```php
Livewire::component('my-browser', MyBrowser::class);
```

### Views

Create Blade templates in `resources/views/`:

```blade
<!-- resources/views/livewire/browser.blade.php -->
<div>
    <h2>My Integration Browser</h2>
    @@foreach($items as $item)
        <div>@{{ $item->name }}</div>
    @@endforeach
</div>
```

Use in application with namespace:

```blade
@@include('my-integration::livewire.browser')
```

### Routes

Define routes in `routes/web.php`:

```php
use Illuminate\Support\Facades\Route;
use PromptlyAgentAI\MyIntegration\Http\Controllers\MyWebhookController;

Route::post('/webhooks/my-integration', [MyWebhookController::class, 'handle'])
    ->name('my-integration.webhook');
```

Routes are automatically loaded and prefixed by Laravel.

### Migrations

Create migrations in `database/migrations/`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('my_integration_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('my_integration_data');
    }
};
```

Migrations run automatically when the package is installed.

## Best Practices

### 1. Namespace Conventions

- Package namespace: `PromptlyAgentAI\{IntegrationName}`
- Follow PSR-4 naming conventions
- Use descriptive class names: `{Integration}{Purpose}{Type}`
  - Example: `NotionSearchTool`, `SlackApiService`

### 2. Singleton Registration

Register services as singletons for performance:

```php
$this->app->singleton(MyService::class);
```

### 3. Conditional Registry Binding

Always check if registries exist before registration:

```php
if ($this->app->bound(\App\Services\Agents\ToolRegistry::class)) {
    // Register tool
}
```

### 4. Dependency Injection

Use constructor injection for dependencies:

```php
public function __construct(
    private MyService $service,
    private LoggerInterface $logger
) {}
```

### 5. Configuration

Publish configuration files if needed:

```php
$this->publishes([
    __DIR__.'/../resources/config/my-integration.php' => config_path('my-integration.php'),
], 'config');
```

### 6. Testing

Include tests in `tests/` directory:

```php
namespace PromptlyAgentAI\MyIntegration\Tests;

use Orchestra\Testbench\TestCase;

class MyServiceTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \PromptlyAgentAI\MyIntegration\MyIntegrationServiceProvider::class,
        ];
    }

    public function test_search_returns_results()
    {
        // Test implementation
    }
}
```

### 7. Documentation

Include a README.md in each package:

- Installation instructions
- Configuration steps
- Usage examples
- API documentation
- Troubleshooting

### 8. Error Handling

Implement graceful error handling:

```php
try {
    return $this->service->search($query);
} catch (ApiException $e) {
    Log::error('Search failed', ['error' => $e->getMessage()]);
    throw new ToolException('Search unavailable', previous: $e);
}
```

### 9. Logging

Use structured logging:

```php
Log::info('Tool executed', [
    'tool' => 'my_search',
    'query' => $query,
    'results' => count($results),
]);
```

## Real-World Example: Notion Integration

Here's a complete example from the Notion integration package:

### Service Provider

```php
class NotionIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotionService::class);
        $this->app->singleton(NotionBlockConverter::class);
        $this->app->singleton(NotionContentAnalyzer::class);
        $this->app->singleton(NotionKnowledgeSource::class);
        $this->app->singleton(NotionIntegrationProvider::class);
    }

    public function boot(): void
    {
        // Views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'notion-integration');

        // Livewire components
        Livewire::component('notion-browser', NotionBrowser::class);
        Livewire::component('notion-page-manager', NotionPageManager::class);

        // Register with ProviderRegistry
        if ($this->app->bound(\App\Services\Integrations\ProviderRegistry::class)) {
            $registry = $this->app->make(\App\Services\Integrations\ProviderRegistry::class);
            $registry->register($this->app->make(NotionIntegrationProvider::class));
        }

        // Register content converter
        if ($this->app->bound(\App\Services\Tools\ContentConverterRegistry::class)) {
            $converterRegistry = $this->app->make(\App\Services\Tools\ContentConverterRegistry::class);
            $converterRegistry->register($this->app->make(NotionBlockConverter::class));
        }

        // Register tools
        if ($this->app->bound(\App\Services\Agents\ToolRegistry::class)) {
            $toolRegistry = $this->app->make(\App\Services\Agents\ToolRegistry::class);

            $toolRegistry->registerTool('notion_search', [
                'class' => NotionSearchTool::class,
                'name' => 'Notion Search',
                'description' => 'Search Notion pages and databases',
                'category' => 'notion',
                'requires_integration' => true,
                'integration_provider' => 'notion',
            ]);

            // Additional tools...
        }
    }
}
```

## Troubleshooting

### Package Not Discovered

If your package isn't being discovered:

1. Check `composer.json` has correct `extra.laravel.providers`
2. Run `composer dump-autoload`
3. Run `php artisan package:discover --ansi`
4. Clear cache: `php artisan config:clear`

### Namespace Issues

If you get "Class not found" errors:

1. Verify PSR-4 autoload configuration in `composer.json`
2. Ensure file paths match namespace structure
3. Run `composer dump-autoload`

### Registry Not Available

If conditional registry binding fails:

1. Check that you're using `if ($this->app->bound(...))` checks
2. Verify the registry class exists in the main application
3. Ensure you're using the correct fully qualified class name

### Views Not Found

If Blade views can't be found:

1. Verify `loadViewsFrom()` path is correct
2. Check view namespace matches usage
3. Ensure view files exist in `resources/views/`

## Conclusion

The PromptlyAgent package system provides a powerful, modular architecture for building integrations. By following these conventions and patterns, you can create self-registering packages that seamlessly integrate with the core application without requiring any changes to the main codebase.

Key takeaways:

- **Zero coupling**: Packages don't modify core application code
- **Auto-discovery**: Laravel handles service provider registration
- **Registry pattern**: Dynamic registration of tools and providers
- **Standard structure**: Consistent conventions across packages
- **Graceful degradation**: Conditional binding handles missing dependencies

For additional help, refer to existing packages in `/packages/` directory or consult the Laravel package development documentation.
