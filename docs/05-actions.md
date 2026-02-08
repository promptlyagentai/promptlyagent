# Workflow Actions Development Guide

This guide explains how to create custom workflow actions in PromptlyAgent that transform data and perform side effects during workflow execution.

## Table of Contents

- [Overview](#overview)
- [Action Types](#action-types)
- [Creating Actions](#creating-actions)
- [Action Interface](#action-interface)
- [Registering Actions](#registering-actions)
- [Action Pipeline](#action-pipeline)
- [Built-in Actions](#built-in-actions)
- [Best Practices](#best-practices)
- [Testing Actions](#testing-actions)
- [Complete Examples](#complete-examples)

## Overview

**Workflow actions** are reusable, composable transformations that execute at specific points during workflow execution:

- **Initial Actions** - Execute once at workflow start (before any agents run)
- **Input Actions** - Transform data before each agent sees it
- **Output Actions** - Transform data after each agent completes
- **Final Actions** - Transform/deliver data after entire workflow completes

Actions enable:
- Data transformation (JSON conversion, markdown formatting)
- Data consolidation (deduplication, merging)
- Side effects (webhooks, logging, notifications)
- Integration (Slack, email, external APIs)

**Key Characteristics:**
- Whitelist-based security (prevents arbitrary code execution)
- Sequential execution in priority order
- Graceful error handling (non-critical actions don't fail workflow)
- Observable (tracked in metadata and status streams)

## Action Types

### 1. Initial Actions (Workflow-Level)

Execute **once** at workflow start, before any agents run.

**Use Cases:**
- Log workflow start
- Fetch external configuration
- Send "processing started" notifications
- Initialize shared resources
- Record analytics/metrics

**Execution Point:** `WorkflowOrchestrator.execute()` line 90-92, immediately after parent execution created

**Example:**
```php
new WorkflowPlan(
    originalQuery: $query,
    strategyType: 'mixed',
    stages: $stages,
    synthesizerAgentId: $synthesizer->id,
    initialActions: [
        new ActionConfig(
            method: 'logOutput',
            params: ['level' => 'info', 'message' => 'Workflow started'],
            priority: 10
        ),
        new ActionConfig(
            method: 'sendWebhook',
            params: ['url' => 'https://example.com/webhook', 'event' => 'workflow.started'],
            priority: 20
        ),
    ],
    finalActions: [...]
);
```

**Context Provided:**
```php
[
    'execution' => AgentExecution,  // Parent execution
    'workflow_type' => 'initial',
    'original_query' => string,     // User's original query
]
```

**Input Data:** Original user query (unchanged)

### 2. Input Actions (Node-Level)

Transform data **before** agent sees it. Execute for each agent node.

**Use Cases:**
- Data consolidation from previous agents
- Format conversion (markdown → JSON)
- Data cleaning/normalization
- Context enrichment

**Execution Point:** `ExecuteAgentJob.applyInputActions()` before `AgentExecutor.execute()`

**Example:**
```php
new WorkflowNode(
    agentId: $synthesizer->id,
    agentName: $synthesizer->name,
    input: "Create comprehensive digest",
    rationale: 'Consolidates research',
    inputActions: [
        new ActionConfig(
            method: 'consolidateResearch',
            params: ['operation' => 'deduplicate_and_merge'],
            priority: 10
        ),
    ]
);
```

### 3. Output Actions (Node-Level)

Transform data **after** agent completes. Execute for each agent node.

**Use Cases:**
- Structuring results (JSON, XML)
- Extracting specific data
- Validation
- Per-agent logging

**Execution Point:** `ExecuteAgentJob.applyOutputActions()` after `AgentExecutor.execute()`

**Example:**
```php
new WorkflowNode(
    agentId: $researcher->id,
    agentName: $researcher->name,
    input: "Research latest news on {$topic}",
    rationale: "Research specialist",
    outputActions: [
        new ActionConfig(
            method: 'formatAsJson',
            params: [],
            priority: 10
        ),
    ]
);
```

### 4. Final Actions (Workflow-Level)

Execute **once** after entire workflow completes (after synthesis).

**Use Cases:**
- Final formatting (Slack, email)
- Delivery (webhooks, notifications)
- Archiving/logging
- Cleanup
- Analytics

**Execution Point:** `SynthesizeWorkflowJob.executeFinalActions()` after synthesis completes

**Example:**
```php
new WorkflowPlan(
    originalQuery: $query,
    strategyType: 'mixed',
    stages: $stages,
    synthesizerAgentId: $synthesizer->id,
    initialActions: [...],
    finalActions: [
        new ActionConfig(
            method: 'slackMarkdown',
            params: [],
            priority: 10
        ),
        new ActionConfig(
            method: 'sendWebhook',
            params: ['url' => 'https://example.com/webhook'],
            priority: 20
        ),
    ]
);
```

### Transformation Actions

Transform data without side effects (pure functions).

**Examples:**
- `formatAsJson` - Convert markdown to structured JSON
- `slackMarkdown` - Convert standard markdown to Slack mrkdwn
- `normalizeText` - Clean and normalize text data
- `truncateText` - Limit text length

**Characteristics:**
- Return transformed data
- Deterministic (same input → same output)
- No external API calls
- Fast execution

### Side Effect Actions

Perform operations with external effects.

**Examples:**
- `sendWebhook` - POST data to external URL
- `logOutput` - Write to application logs
- `sendEmail` - Send email notifications
- `notifySlack` - Post to Slack channels

**Characteristics:**
- May return original data unchanged
- Network I/O operations
- Should not throw on failure (log and continue)
- Idempotent when possible

### Hybrid Actions

Both transform data AND have side effects.

**Examples:**
- `consolidateResearch` - Deduplicates data + logs statistics
- `validateOutput` - Validates data + records metrics

**Characteristics:**
- Transform data based on side effects
- May throw on critical validation failures
- Log operations for observability

## Creating Actions

### Step 1: Create Action Class

```bash
# Create in app/Services/Agents/Actions/
touch app/Services/Agents/Actions/CustomAction.php
```

### Step 2: Implement ActionInterface

```php
<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

class CustomAction implements ActionInterface
{
    /**
     * Execute the action
     *
     * @param  string  $data  Input data to process
     * @param  array  $context  Execution context (agent, execution, etc.)
     * @param  array  $params  Action parameters from ActionConfig
     * @return string Transformed output data
     */
    public function execute(string $data, array $context, array $params): string
    {
        Log::info('CustomAction: Starting execution', [
            'input_length' => strlen($data),
            'params' => $params,
        ]);

        try {
            // Your transformation logic here
            $transformed = $this->transform($data, $params);

            Log::info('CustomAction: Completed successfully', [
                'input_length' => strlen($data),
                'output_length' => strlen($transformed),
            ]);

            return $transformed;

        } catch (\Exception $e) {
            Log::error('CustomAction: Execution failed', [
                'error' => $e->getMessage(),
                'input_length' => strlen($data),
            ]);

            // For non-critical actions: return original data
            return $data;

            // For critical actions: throw exception
            // throw $e;
        }
    }

    /**
     * Validate action parameters
     *
     * @param  array  $params  Parameters to validate
     * @return bool True if parameters are valid
     */
    public function validate(array $params): bool
    {
        // Validate required parameters exist and have correct types
        if (isset($params['required_param']) && !is_string($params['required_param'])) {
            return false;
        }

        return true;
    }

    /**
     * Whether action should be queued
     *
     * @return bool True if action should run asynchronously
     */
    public function shouldQueue(): bool
    {
        // Return true for:
        // - Long-running operations
        // - External API calls
        // - Heavy computations
        //
        // Return false for:
        // - Quick transformations
        // - In-memory operations
        return false;
    }

    /**
     * Human-readable description
     *
     * @return string Action description
     */
    public function getDescription(): string
    {
        return 'Short description of what this action does';
    }

    /**
     * Parameter schema for documentation/UI
     *
     * @return array Parameter definitions
     */
    public function getParameterSchema(): array
    {
        return [
            'required_param' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Description of this parameter',
                'default' => null,
            ],
            'optional_param' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional parameter',
                'default' => 100,
            ],
        ];
    }

    /**
     * Your custom transformation logic
     */
    private function transform(string $data, array $params): string
    {
        // Implement your logic here
        return $data;
    }
}
```

### Step 3: Register in ActionRegistry

```php
// app/Services/Agents/Actions/ActionRegistry.php

private static array $actions = [
    // ... existing actions ...

    'customAction' => \App\Services\Agents\Actions\CustomAction::class,
];
```

### Step 4: Use in Workflow

```php
// Initial action (workflow-level)
new WorkflowPlan(
    initialActions: [
        new ActionConfig(
            method: 'customAction',
            params: ['required_param' => 'value'],
            priority: 10
        ),
    ],
    // ... rest of plan
);

// Input/output action (node-level)
new WorkflowNode(
    agentId: $agent->id,
    agentName: $agent->name,
    input: "Your task description",
    rationale: "Why this agent",
    outputActions: [
        new ActionConfig(
            method: 'customAction',
            params: ['required_param' => 'value'],
            priority: 10
        ),
    ]
);
```

## Action Interface

All actions must implement `ActionInterface`:

```php
interface ActionInterface
{
    /**
     * Execute the action
     *
     * @param  string  $data  Input data (from previous action or agent output)
     * @param  array  $context  Execution context
     * @param  array  $params  Action configuration parameters
     * @return string Transformed data (or original if no transformation)
     */
    public function execute(string $data, array $context, array $params): string;

    /**
     * Validate action parameters
     *
     * @param  array  $params  Parameters to validate
     * @return bool True if valid
     */
    public function validate(array $params): bool;

    /**
     * Whether action should be queued
     *
     * @return bool True to run asynchronously
     */
    public function shouldQueue(): bool;

    /**
     * Human-readable description
     *
     * @return string Action description for documentation/UI
     */
    public function getDescription(): string;

    /**
     * Parameter schema
     *
     * @return array<string, array{type: string, required: bool, description: string, default: mixed}>
     */
    public function getParameterSchema(): array;
}
```

### Context Array

The `$context` array provides execution details:

**Initial Actions Context:**
```php
[
    'execution' => AgentExecution,  // Parent execution
    'workflow_type' => 'initial',
    'original_query' => string,     // User's original query
]
```

**Input/Output Actions Context:**
```php
[
    'agent' => Agent,              // Agent model
    'execution' => AgentExecution, // Current execution
    'input' => string,             // Original agent input
    'output' => string,            // Agent output (output actions only)
]
```

**Final Actions Context:**
```php
[
    'execution' => AgentExecution,        // Synthesis execution
    'parent_execution' => AgentExecution, // Parent execution
    'workflow_type' => 'final',
    'original_query' => string,           // User's original query
    'batch_id' => string,                 // Batch identifier
    'total_jobs' => int,                  // Total jobs in workflow
]
```

**Usage:**
```php
public function execute(string $data, array $context, array $params): string
{
    $executionId = $context['execution']->id;
    $workflowType = $context['workflow_type'] ?? 'unknown';

    Log::info("Processing for {$workflowType} action", [
        'execution_id' => $executionId,
    ]);

    // Your logic...
}
```

## Registering Actions

Actions must be registered in `ActionRegistry` to be executable:

```php
// app/Services/Agents/Actions/ActionRegistry.php

private static array $actions = [
    // Data transformation
    'normalizeText' => \App\Services\Agents\Actions\NormalizeTextAction::class,
    'truncateText' => \App\Services\Agents\Actions\TruncateTextAction::class,
    'formatAsJson' => \App\Services\Agents\Actions\FormatAsJsonAction::class,
    'slackMarkdown' => \App\Services\Agents\Actions\SlackMarkdownAction::class,

    // Side effects
    'logOutput' => \App\Services\Agents\Actions\LogOutputAction::class,
    'sendWebhook' => \App\Services\Agents\Actions\SendWebhookAction::class,

    // Hybrid
    'consolidateResearch' => \App\Services\Agents\Actions\ConsolidateResearchAction::class,
];
```

**Security:** Only registered actions can execute. This prevents:
- Arbitrary code execution
- Injection attacks
- Unauthorized operations

### Dynamic Registration (for packages)

```php
// In a service provider
ActionRegistry::register('packageAction', PackageAction::class);
```

## Action Pipeline

Actions form sequential pipelines where data flows through transformations:

### Initial Action Pipeline (Workflow-Level)

```
Workflow Starts
   ↓
Original Query
   ↓
[Initial Action 1: priority 10]
   ↓
[Initial Action 2: priority 20]
   ↓
Stage 1 Begins (agents start executing)
```

**Example:**
```php
initialActions: [
    new ActionConfig(
        method: 'logOutput',  // Log workflow start
        params: ['level' => 'info'],
        priority: 10
    ),
    new ActionConfig(
        method: 'sendWebhook',  // Notify external system
        params: ['url' => '...', 'event' => 'workflow.started'],
        priority: 20
    ),
]
```

### Input Action Pipeline (Node-Level)

```
Previous Agent Results (from WorkflowResultStore)
   ↓
[Input Action 1: priority 10]
   ↓
[Input Action 2: priority 20]
   ↓
Agent Execution (sees transformed input)
```

**Example:**
```php
inputActions: [
    new ActionConfig(
        method: 'consolidateResearch',  // Deduplicates results
        params: ['operation' => 'deduplicate_and_merge'],
        priority: 10
    ),
    new ActionConfig(
        method: 'normalizeText',  // Cleans consolidated data
        params: [],
        priority: 20
    ),
]
```

### Output Action Pipeline (Node-Level)

```
Agent Output (raw)
   ↓
[Output Action 1: priority 10]
   ↓
[Output Action 2: priority 20]
   ↓
Result stored in WorkflowResultStore
```

**Example:**
```php
outputActions: [
    new ActionConfig(
        method: 'formatAsJson',  // Structure as JSON
        params: [],
        priority: 10
    ),
    new ActionConfig(
        method: 'logOutput',  // Log structured data
        params: ['level' => 'info'],
        priority: 20
    ),
]
```

### Final Action Pipeline (Workflow-Level)

```
Synthesizer Output (combined results)
   ↓
[Final Action 1: priority 10]
   ↓
[Final Action 2: priority 20]
   ↓
[Final Action 3: priority 30]
   ↓
Result stored in ChatInteraction.answer + Broadcasted
```

**Example:**
```php
finalActions: [
    new ActionConfig(
        method: 'slackMarkdown',  // Format for Slack
        params: [],
        priority: 10
    ),
    new ActionConfig(
        method: 'sendWebhook',  // Deliver to external system
        params: ['url' => 'https://example.com/webhook'],
        priority: 20
    ),
    new ActionConfig(
        method: 'logOutput',  // Archive final output
        params: ['level' => 'info'],
        priority: 30
    ),
]
```

## Built-in Actions

### NormalizeTextAction

Cleans and normalizes text data.

**Usage:**
```php
new ActionConfig(
    method: 'normalizeText',
    params: [],
    priority: 10
)
```

**Parameters:** None

### TruncateTextAction

Limits text length while preserving readability.

**Usage:**
```php
new ActionConfig(
    method: 'truncateText',
    params: [
        'max_length' => 1000,
        'suffix' => '...',
    ],
    priority: 10
)
```

**Parameters:**
- `max_length` (int, required): Maximum character count
- `suffix` (string, optional): Truncation indicator (default: '...')

### FormatAsJsonAction

Converts markdown research output to structured JSON.

**Usage:**
```php
new ActionConfig(
    method: 'formatAsJson',
    params: [],
    priority: 10
)
```

**Output Structure:**
```json
{
    "topics": ["Topic 1", "Topic 2"],
    "news": ["News item 1", "News item 2"],
    "relevance": ["Reason 1", "Reason 2"],
    "sources": [
        {"title": "Source Title", "url": "https://..."},
        {"title": "Another Source", "url": "https://..."}
    ],
    "raw_content": "Original markdown content"
}
```

**Parameters:** None

### ConsolidateResearchAction

Deduplicates and merges results from multiple research agents.

**Usage:**
```php
new ActionConfig(
    method: 'consolidateResearch',
    params: [
        'operation' => 'deduplicate_and_merge',
        'similarity_threshold' => 80,
    ],
    priority: 10
)
```

**Parameters:**
- `operation` (string, required): 'deduplicate_and_merge'
- `similarity_threshold` (int, optional): Fuzzy matching threshold (0-100, default: 80)

**Logic:**
1. Extracts JSON from each research agent's output
2. Parses and collects all news items, topics, sources
3. Deduplicates news items by title similarity and URL matching
4. Merges overlapping topics
5. Collects unique sources
6. Creates consolidated markdown for synthesizer

### SlackMarkdownAction

Converts standard markdown to Slack mrkdwn format.

**Usage:**
```php
new ActionConfig(
    method: 'slackMarkdown',
    params: [],
    priority: 10
)
```

**Transformations:**
- Headers `## Text` → `*Text*`
- Bold `**text**` → `*text*`
- Italic `*text*` → `_text_`
- Links `[text](url)` → `<url|text>`
- Code blocks - Remove language identifiers
- Horizontal rules `---` → `─────────────────────`
- Strikethrough `~~text~~` → `~text~`
- Blockquotes `> text` → `💬 _text_`

**Parameters:** None

### SendWebhookAction

Posts data to external webhook URL via HTTP POST.

**Usage:**
```php
new ActionConfig(
    method: 'sendWebhook',
    params: [
        'url' => 'https://example.com/webhook',
        'format' => 'json',
        'include_metadata' => true,
    ],
    priority: 20
)
```

**Parameters:**
- `url` (string, required): Webhook endpoint URL
- `format` (string, optional): 'json' (default) | 'xml' | 'form'
- `include_metadata` (bool, optional): Include execution metadata (default: true)

**Payload Structure:**
```json
{
    "digest": {
        "title": "Workflow Result - January 4, 2026",
        "content": "...",
        "generated_at": "2026-01-04T10:30:00Z"
    },
    "metadata": {
        "execution_id": 123,
        "agent_name": "Research Synthesizer",
        "duration_seconds": 45
    },
    "sources": [
        {"title": "Source", "url": "https://..."}
    ]
}
```

**Error Handling:** Logs errors but does NOT throw (webhook failures shouldn't stop workflow)

### LogOutputAction

Writes output to application logs.

**Usage:**
```php
new ActionConfig(
    method: 'logOutput',
    params: [
        'level' => 'info',
        'truncate' => 500,
    ],
    priority: 30
)
```

**Parameters:**
- `level` (string, optional): 'debug' | 'info' (default) | 'warning' | 'error'
- `truncate` (int, optional): Max length to log (default: null = full)

## Best Practices

### 1. Keep Actions Focused

Each action should do ONE thing well:

```php
// ❌ BAD: Action does too much
class ProcessAndDeliverAction // Transforms + sends webhook + logs
{
    public function execute(string $data, array $context, array $params): string
    {
        $transformed = $this->transform($data);
        $this->sendWebhook($transformed, $params['url']);
        $this->log($transformed);
        return $transformed;
    }
}

// ✅ GOOD: Separate concerns
class SlackMarkdownAction // Only transforms
class SendWebhookAction   // Only delivers
class LogOutputAction     // Only logs

// Chain them:
finalActions: [
    new ActionConfig(method: 'slackMarkdown', ...),
    new ActionConfig(method: 'sendWebhook', ...),
    new ActionConfig(method: 'logOutput', ...),
]
```

### 2. Validate Parameters

```php
public function validate(array $params): bool
{
    // Check required parameters
    if (!isset($params['url'])) {
        return false;
    }

    // Validate types
    if (!is_string($params['url'])) {
        return false;
    }

    // Validate values
    if (!filter_var($params['url'], FILTER_VALIDATE_URL)) {
        return false;
    }

    // Optional parameters with defaults
    if (isset($params['timeout']) && (!is_int($params['timeout']) || $params['timeout'] < 1)) {
        return false;
    }

    return true;
}
```

### 3. Handle Errors Gracefully

```php
public function execute(string $data, array $context, array $params): string
{
    try {
        // Your logic
        return $this->transform($data, $params);

    } catch (\Exception $e) {
        Log::error('ActionName: Failed to execute', [
            'error' => $e->getMessage(),
            'params' => $params,
        ]);

        // For NON-CRITICAL actions:
        // Return original data so workflow continues
        return $data;

        // For CRITICAL actions:
        // Re-throw to stop workflow
        // throw $e;
    }
}
```

### 4. Log Important Operations

```php
public function execute(string $data, array $context, array $params): string
{
    Log::info('CustomAction: Starting execution', [
        'input_length' => strlen($data),
        'execution_id' => $context['execution']->id ?? null,
        'params' => $params,
    ]);

    $startTime = microtime(true);
    $result = $this->transform($data, $params);
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    Log::info('CustomAction: Completed successfully', [
        'input_length' => strlen($data),
        'output_length' => strlen($result),
        'duration_ms' => $duration,
        'data_modified' => $data !== $result,
    ]);

    return $result;
}
```

### 5. Document Parameter Schema

```php
public function getParameterSchema(): array
{
    return [
        'url' => [
            'type' => 'string',
            'required' => true,
            'description' => 'Webhook endpoint URL (must be valid HTTPS)',
            'example' => 'https://example.com/webhook',
            'default' => null,
        ],
        'timeout' => [
            'type' => 'integer',
            'required' => false,
            'description' => 'Request timeout in seconds',
            'example' => 30,
            'default' => 10,
        ],
        'retry' => [
            'type' => 'boolean',
            'required' => false,
            'description' => 'Whether to retry failed requests',
            'example' => true,
            'default' => false,
        ],
    ];
}
```

### 6. Return Non-Empty Strings

```php
public function execute(string $data, array $context, array $params): string
{
    $transformed = $this->transform($data);

    // Prevent empty results from breaking pipeline
    if (empty($transformed)) {
        Log::warning('CustomAction: Transformation resulted in empty string, returning original data');
        return $data;
    }

    return $transformed;
}
```

### 7. Use Priority for Ordering

```php
// Lower priority = runs first

// Initial actions
initialActions: [
    new ActionConfig(
        method: 'logOutput',      // Log start first
        priority: 5
    ),
    new ActionConfig(
        method: 'sendWebhook',    // Then notify
        priority: 10
    ),
]

// Final actions
finalActions: [
    new ActionConfig(
        method: 'normalizeText',  // Clean first
        priority: 5
    ),
    new ActionConfig(
        method: 'slackMarkdown',  // Then format
        priority: 10
    ),
    new ActionConfig(
        method: 'sendWebhook',    // Then deliver
        priority: 20
    ),
    new ActionConfig(
        method: 'logOutput',      // Finally archive
        priority: 30
    ),
]
```

### 8. Consider Idempotency

For side effect actions, make them idempotent when possible:

```php
public function execute(string $data, array $context, array $params): string
{
    $executionId = $context['execution']->id;
    $idempotencyKey = "webhook_{$executionId}";

    // Check if already sent
    if (Cache::has($idempotencyKey)) {
        Log::info('SendWebhookAction: Already sent (idempotent skip)', [
            'execution_id' => $executionId,
        ]);
        return $data;
    }

    // Send webhook
    $this->sendWebhook($params['url'], $data);

    // Mark as sent (24 hour TTL)
    Cache::put($idempotencyKey, true, now()->addDay());

    return $data;
}
```

## Testing Actions

### Unit Testing

```php
<?php

use App\Services\Agents\Actions\CustomAction;

it('transforms data correctly', function () {
    $action = new CustomAction();

    $input = "Original data";
    $context = ['execution' => AgentExecution::factory()->create()];
    $params = ['param' => 'value'];

    $result = $action->execute($input, $context, $params);

    expect($result)->not->toBeEmpty()
        ->and($result)->not->toBe($input); // Should transform
});

it('validates parameters correctly', function () {
    $action = new CustomAction();

    expect($action->validate(['param' => 'value']))->toBeTrue();
    expect($action->validate([]))->toBeFalse(); // Missing required param
    expect($action->validate(['param' => 123]))->toBeFalse(); // Wrong type
});

it('returns original data on error', function () {
    $action = new CustomAction();

    $input = "Original data";
    $context = ['execution' => AgentExecution::factory()->create()];
    $params = ['invalid_param' => 'causes error'];

    $result = $action->execute($input, $context, $params);

    expect($result)->toBe($input); // Should return unchanged
});
```

### Integration Testing

```php
<?php

use App\Models\Agent;
use App\Services\Agents\{WorkflowNode, ActionConfig};

it('executes output actions in workflow', function () {
    $agent = Agent::factory()->create(['name' => 'Test Agent']);

    $node = new WorkflowNode(
        agentId: $agent->id,
        agentName: $agent->name,
        input: "Test input",
        rationale: "Testing",
        outputActions: [
            new ActionConfig(
                method: 'customAction',
                params: ['param' => 'value'],
                priority: 10
            ),
        ]
    );

    // Execute workflow
    // ... workflow execution code ...

    // Assert action executed
    $execution = AgentExecution::latest()->first();
    expect($execution->metadata)->toHaveKey('output_actions_executed')
        ->and($execution->metadata['output_actions_executed'][0]['action'])->toBe('customAction')
        ->and($execution->metadata['output_actions_executed'][0]['status'])->toBe('success');
});
```

## Complete Examples

### Example 1: Initial Action (Workflow Start Notification)

```php
<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\{Http, Log};

class NotifyWorkflowStartAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        $url = $params['url'] ?? null;

        if (!$url) {
            Log::warning('NotifyWorkflowStartAction: No URL provided');
            return $data;
        }

        try {
            $executionId = $context['execution']->id;
            $query = $context['original_query'];

            Http::timeout(5)->post($url, [
                'event' => 'workflow.started',
                'execution_id' => $executionId,
                'query' => $query,
                'started_at' => now()->toIso8601String(),
            ]);

            Log::info('NotifyWorkflowStartAction: Notification sent', [
                'url' => $url,
                'execution_id' => $executionId,
            ]);

        } catch (\Exception $e) {
            Log::error('NotifyWorkflowStartAction: Failed to send notification', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - notifications shouldn't stop workflow
        }

        return $data; // Return unchanged (side effect only)
    }

    public function validate(array $params): bool
    {
        if (!isset($params['url']) || !filter_var($params['url'], FILTER_VALIDATE_URL)) {
            return false;
        }
        return true;
    }

    public function shouldQueue(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Send HTTP notification when workflow starts';
    }

    public function getParameterSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Notification webhook URL',
            ],
        ];
    }
}
```

### Example 2: Text Transformation Action

```php
<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

class ExtractUrlsAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        Log::info('ExtractUrlsAction: Extracting URLs from text', [
            'input_length' => strlen($data),
        ]);

        // Extract all URLs using regex
        preg_match_all(
            '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/',
            $data,
            $matches
        );

        $urls = array_unique($matches[0]);
        $format = $params['format'] ?? 'json';

        if ($format === 'json') {
            $output = json_encode(['urls' => $urls], JSON_PRETTY_PRINT);
        } else {
            $output = "Extracted URLs:\n\n" . implode("\n", $urls);
        }

        Log::info('ExtractUrlsAction: Extraction complete', [
            'url_count' => count($urls),
            'format' => $format,
        ]);

        return $output;
    }

    public function validate(array $params): bool
    {
        if (isset($params['format']) && !in_array($params['format'], ['json', 'text'])) {
            return false;
        }

        return true;
    }

    public function shouldQueue(): bool
    {
        return false; // Fast in-memory operation
    }

    public function getDescription(): string
    {
        return 'Extract all URLs from text and format as JSON or plain text list';
    }

    public function getParameterSchema(): array
    {
        return [
            'format' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Output format',
                'default' => 'json',
                'options' => ['json', 'text'],
            ],
        ];
    }
}
```

## Related Documentation

- **[Workflow Commands Guide](./04-workflows.md)** - Creating custom workflow commands
- **DailyDigestCommand** - `app/Console/Commands/Research/DailyDigestCommand.php`
- **WorkflowOrchestrator** - `app/Services/Agents/WorkflowOrchestrator.php`
- **ActionRegistry** - `app/Services/Agents/Actions/ActionRegistry.php`
- **ActionInterface** - `app/Services/Agents/Actions/ActionInterface.php`

## Action Execution Internals

### Initial Action Execution (Workflow-Level)

Handled by `WorkflowOrchestrator.executeInitialActions()`:

```php
// Lines 555-598 in WorkflowOrchestrator.php
protected function executeInitialActions(WorkflowPlan $plan, AgentExecution $parentExecution): void
{
    // 1. Sort actions by priority
    // 2. Execute each action sequentially
    // 3. Pass original query as input data
    // 4. Log execution
    // 5. Continue even if action fails
}
```

**Execution Point:** Line 90-92 in `WorkflowOrchestrator.execute()`, immediately after parent execution created, before Stage 1 begins

### Input/Output Action Execution (Node-Level)

Handled by `ExecuteAgentJob`:

```php
// Before agent execution (lines 547-702)
protected function applyInputActions(): void
{
    // 1. Sort actions by priority
    // 2. Emit status stream
    // 3. Execute each action sequentially
    // 4. Track results in execution metadata
    // 5. Update execution input with transformed data
}

// After agent execution (lines 704-861)
protected function applyOutputActions(string $result): string
{
    // 1. Sort actions by priority
    // 2. Emit status stream
    // 3. Execute each action sequentially
    // 4. Track results in execution metadata
    // 5. Return transformed result
}
```

### Final Action Execution (Workflow-Level)

Handled by `SynthesizeWorkflowJob`:

```php
// After synthesis completes (lines 559-723)
protected function executeFinalActions(string $finalAnswer, ...): void
{
    // 1. Sort actions by priority
    // 2. Emit status streams
    // 3. Execute each action sequentially
    // 4. Track results in parent execution metadata
    // 5. Update ChatInteraction if data transformed
}
```

### Result Tracking

All action executions are tracked in metadata:

```json
{
  "input_actions_executed": [
    {
      "action": "consolidateResearch",
      "status": "success",
      "duration_ms": 125.5,
      "input_length": 5000,
      "output_length": 3500,
      "params": {"operation": "deduplicate_and_merge"},
      "executed_at": "2026-01-04T10:30:15Z"
    }
  ],
  "output_actions_executed": [...],
  "final_actions_executed": [...]
}
```

**Note:** Initial actions are not currently tracked in metadata (they execute before parent execution is fully initialized), but their side effects (logs, webhooks, etc.) are observable.

---

**Next Steps:**
1. Study built-in actions in `app/Services/Agents/Actions/`
2. Create your first custom action
3. Register in ActionRegistry
4. Test in workflow command
5. Review action execution in metadata and logs
