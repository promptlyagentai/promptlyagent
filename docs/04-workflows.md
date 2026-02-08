# Creating Custom Workflow Commands

This guide explains how to create custom workflow commands in PromptlyAgent that orchestrate multiple agents programmatically without requiring AI-driven planning.

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Workflow Execution Strategies](#workflow-execution-strategies)
- [Building Workflow Plans](#building-workflow-plans)
- [Working with Actions](#working-with-actions)
- [Input Trigger Integration](#input-trigger-integration)
- [Best Practices](#best-practices)
- [Complete Example](#complete-example)

## Overview

**Workflow commands** allow you to create deterministic, multi-agent workflows where you define the exact sequence of agent executions, data transformations, and integrations. Unlike AI-powered planning (via the Research Planner agent), workflow commands give you complete control over:

- Which agents execute and in what order
- How data flows between agents
- When transformations occur (input/output/final actions)
- Integration points (webhooks, Slack, email, etc.)

**Use Cases:**
- Daily news digests with multiple research topics
- Multi-stage content pipelines (research → synthesis → QA → delivery)
- Scheduled reports with predictable structure
- Webhook-triggered workflows with deterministic behavior

**Reference Implementation:** `app/Console/Commands/Research/DailyDigestCommand.php`

## Quick Start

### 1. Create the Command

```bash
./vendor/bin/sail artisan make:command Research/CustomWorkflowCommand
```

### 2. Basic Structure

```php
<?php

namespace App\Console\Commands\Research;

use App\Console\Commands\Concerns\RegistersAsInputTrigger;
use App\Models\Agent;
use App\Services\Agents\{WorkflowOrchestrator, WorkflowPlan, WorkflowStage, WorkflowNode, ActionConfig};
use Illuminate\Console\Command;

class CustomWorkflowCommand extends Command
{
    use RegistersAsInputTrigger; // Enable webhook triggering

    protected $signature = 'workflow:custom
                            {input : Your input parameter}
                            {--user-id=1 : User ID for execution context}';

    protected $description = 'Custom workflow description';

    public function handle(WorkflowOrchestrator $orchestrator): int
    {
        // 1. Validate inputs
        // 2. Get required agents
        // 3. Create workflow plan
        // 4. Execute workflow
        // 5. Return tracking information
    }
}
```

## Workflow Execution Strategies

PromptlyAgent supports four execution strategies:

### 1. Simple (Single Agent)

One agent executes independently. No synthesis needed.

```php
WorkflowPlanSchema::createSimplePlan(
    query: $userQuery,
    agentId: $agent->id,
    agentName: $agent->name,
    rationale: 'Single agent execution'
);
```

**Use When:**
- Query requires only one specialized agent
- No data consolidation needed
- Fastest execution time

### 2. Sequential (Chain)

Agents execute one after another. Each agent receives the previous agent's output.

```php
new WorkflowPlan(
    originalQuery: $query,
    strategyType: 'sequential',
    stages: [
        new WorkflowStage(type: 'sequential', nodes: [
            new WorkflowNode(agentId: $researcher->id, ...),
            new WorkflowNode(agentId: $analyst->id, ...),  // Gets researcher's output
            new WorkflowNode(agentId: $writer->id, ...),   // Gets analyst's output
        ])
    ],
    synthesizerAgentId: null // No synthesis needed (chained results)
);
```

**Use When:**
- Each step builds on previous results
- Linear dependency chain
- Progressive refinement workflows

### 3. Parallel (Fan-Out)

Multiple agents execute simultaneously. Synthesizer combines results.

```php
new WorkflowPlan(
    originalQuery: $query,
    strategyType: 'parallel',
    stages: [
        new WorkflowStage(type: 'parallel', nodes: [
            new WorkflowNode(agentId: $researcher1->id, ...),
            new WorkflowNode(agentId: $researcher2->id, ...),
            new WorkflowNode(agentId: $researcher3->id, ...),
        ])
    ],
    synthesizerAgentId: $synthesizer->id // Combines all results
);
```

**Use When:**
- Independent tasks can run concurrently
- Need to maximize speed
- Results need consolidation

### 4. Mixed (Multi-Stage)

Combination of parallel and sequential stages. Most flexible and powerful.

```php
new WorkflowPlan(
    originalQuery: $query,
    strategyType: 'mixed',
    stages: [
        // Stage 1: Parallel research
        new WorkflowStage(type: 'parallel', nodes: [
            new WorkflowNode(agentId: $researcher1->id, ...),
            new WorkflowNode(agentId: $researcher2->id, ...),
        ]),
        // Stage 2: Sequential processing
        new WorkflowStage(type: 'sequential', nodes: [
            new WorkflowNode(agentId: $synthesizer->id, ...),
            new WorkflowNode(agentId: $qaValidator->id, ...),
        ]),
    ],
    synthesizerAgentId: $finalSynthesizer->id
);
```

**Use When:**
- Complex workflows with multiple phases
- Need both speed (parallel) and coordination (sequential)
- Multi-step pipelines (research → consolidate → validate → deliver)

**Example:** DailyDigestCommand uses mixed strategy:
1. **Parallel:** Research all topics simultaneously
2. **Sequential:** Synthesize findings → QA validation
3. **Final Action:** Convert to Slack markdown

## Building Workflow Plans

### Core Components

#### 1. WorkflowPlan

The top-level plan describing the entire workflow.

```php
new WorkflowPlan(
    originalQuery: string,           // User's original query
    strategyType: string,            // 'simple' | 'sequential' | 'parallel' | 'mixed'
    stages: array,                   // Array of WorkflowStage objects
    synthesizerAgentId: ?int,        // Agent to combine results (null if not needed)
    requiresQA: bool,                // Enable optional QA validation (default: false)
    estimatedDurationSeconds: int,   // Estimated completion time
    finalActions: array              // Workflow-level actions (optional)
);
```

#### 2. WorkflowStage

A phase in the workflow containing one or more nodes.

```php
new WorkflowStage(
    type: 'parallel' | 'sequential', // Execution type
    nodes: array                     // Array of WorkflowNode objects
);
```

#### 3. WorkflowNode

An individual agent execution with optional actions.

```php
new WorkflowNode(
    agentId: int,              // Agent to execute
    agentName: string,         // Agent name for validation
    input: string,             // Prompt/task for this agent
    rationale: string,         // Why this agent was selected
    inputActions: array,       // Transform input before execution (optional)
    outputActions: array       // Transform output after execution (optional)
);
```

#### 4. ActionConfig

Configuration for input/output/final actions.

```php
new ActionConfig(
    method: string,  // Action method name (must be registered in ActionRegistry)
    params: array,   // Parameters for the action
    priority: int    // Execution order (lower = earlier, default: 10)
);
```

### Example: Building a Complete Plan

```php
protected function createWorkflowPlan(array $topics, array $agents): WorkflowPlan
{
    $stages = [];

    // Stage 1: Parallel Research
    $researchNodes = [];
    foreach ($topics as $topic) {
        $researchNodes[] = new WorkflowNode(
            agentId: $agents['research']->id,
            agentName: $agents['research']->name,
            input: "Research latest news on {$topic}. Provide summaries, key developments, and sources.",
            rationale: "Research specialist for topic: {$topic}",
            outputActions: [
                new ActionConfig(
                    method: 'formatAsJson',
                    params: [],
                    priority: 10
                ),
            ]
        );
    }

    $stages[] = new WorkflowStage(
        type: 'parallel',
        nodes: $researchNodes
    );

    // Stage 2: Sequential Synthesis
    $synthesisNode = new WorkflowNode(
        agentId: $agents['synthesizer']->id,
        agentName: $agents['synthesizer']->name,
        input: "Create comprehensive digest from research findings.",
        rationale: 'Consolidates and synthesizes research',
        inputActions: [
            new ActionConfig(
                method: 'consolidateResearch',
                params: [
                    'operation' => 'deduplicate_and_merge',
                    'similarity_threshold' => 80,
                ],
                priority: 10
            ),
        ]
    );

    $stages[] = new WorkflowStage(
        type: 'sequential',
        nodes: [$synthesisNode]
    );

    // Final Actions (workflow-level)
    $finalActions = [
        new ActionConfig(
            method: 'slackMarkdown',
            params: [],
            priority: 10
        ),
    ];

    return new WorkflowPlan(
        originalQuery: "Daily digest for: ".implode(', ', $topics),
        strategyType: 'mixed',
        stages: $stages,
        synthesizerAgentId: $agents['synthesizer']->id,
        requiresQA: false,
        estimatedDurationSeconds: 180,
        finalActions: $finalActions
    );
}
```

## Working with Actions

Actions transform data at three critical points:

### 1. Input Actions (Pre-Processing)

Execute **before** agent sees data. Used for:
- Data consolidation from previous agents
- Format conversion
- Data cleaning/normalization

```php
inputActions: [
    new ActionConfig(
        method: 'consolidateResearch',
        params: ['operation' => 'deduplicate_and_merge'],
        priority: 10
    ),
]
```

### 2. Output Actions (Post-Processing)

Execute **after** agent completes. Used for:
- Structuring results (JSON, XML)
- Extracting specific data
- Validation

```php
outputActions: [
    new ActionConfig(
        method: 'formatAsJson',
        params: [],
        priority: 10
    ),
]
```

### 3. Final Actions (Workflow-Level)

Execute **once** after entire workflow completes. Used for:
- Final formatting (Slack, email)
- Delivery (webhooks, notifications)
- Archiving/logging

```php
finalActions: [
    new ActionConfig(
        method: 'slackMarkdown',
        params: ['stripHtml' => true],
        priority: 10
    ),
    new ActionConfig(
        method: 'sendWebhook',
        params: ['url' => 'https://example.com/webhook'],
        priority: 20 // Runs after slackMarkdown
    ),
]
```

### Action Pipeline

Actions form a pipeline where data flows through transformations:

```
Input Data
   ↓
[Input Action 1] → [Input Action 2]
   ↓
[Agent Execution]
   ↓
[Output Action 1] → [Output Action 2]
   ↓
Output Data
```

For workflow-level:
```
All Agent Results
   ↓
[Synthesizer]
   ↓
[Final Action 1] → [Final Action 2] → [Final Action 3]
   ↓
Final Output (stored in ChatInteraction)
```

**See [Actions Development Guide](./actions.md) for creating custom actions.**

## Input Trigger Integration

Enable webhook/API triggering by:

### 1. Add Trait to Command

```php
use App\Console\Commands\Concerns\RegistersAsInputTrigger;

class CustomWorkflowCommand extends Command
{
    use RegistersAsInputTrigger;

    // ... rest of command
}
```

### 2. Define Trigger Definition

```php
public function getTriggerDefinition(): array
{
    return [
        'name' => 'Custom Workflow',
        'description' => 'Executes custom workflow with specified parameters',
        'parameters' => [
            'topics' => [
                'type' => 'array',
                'required' => true,
                'description' => 'Topics to process (1-4 topics)',
                'min' => 1,
                'max' => 4,
            ],
            'output-format' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Output format',
                'default' => 'markdown',
                'options' => ['markdown', 'json', 'html'],
            ],
        ],
    ];
}
```

### 3. Triggering via Webhook

Once registered, the command can be triggered via:

**API Endpoint:**
```
POST /api/input-triggers/{triggerId}/execute
```

**Payload:**
```json
{
  "topics": ["AI regulation", "Climate tech"],
  "output-format": "markdown"
}
```

**Payload Mapping:**
- Use `payload_template` in InputTrigger to map webhook JSON to command arguments
- PayloadTemplateProcessor handles dynamic extraction using dot notation

## Best Practices

### 1. Use Constants for Magic Numbers

```php
private const MAX_WORKFLOW_STEPS = 50;
private const DEFAULT_ACTION_PRIORITY = 10;
private const ESTIMATED_WORKFLOW_DURATION = 180;

// Then use:
'max_steps' => self::MAX_WORKFLOW_STEPS,
```

### 2. Validate Inputs Early

```php
public function handle(WorkflowOrchestrator $orchestrator): int
{
    $topics = $this->argument('topics');

    if (empty($topics)) {
        $this->error('At least one topic is required');
        return self::FAILURE;
    }

    if (count($topics) > 4) {
        $this->error('Maximum of 4 topics allowed');
        return self::FAILURE;
    }

    // ... proceed with workflow
}
```

### 3. Check Agent Availability

```php
protected function getRequiredAgents(): array
{
    $requiredNames = [
        'research' => 'Research Assistant',
        'synthesizer' => 'Research Synthesizer',
    ];

    $agents = Agent::whereIn('name', array_values($requiredNames))
        ->get()
        ->keyBy('name');

    foreach ($requiredNames as $key => $name) {
        if (!$agents->has($name)) {
            throw new \Exception("{$name} agent not found. Please run database seeders.");
        }
    }

    return $agents->toArray();
}
```

### 4. Create Observability

```php
// Link ChatInteraction for UI tracking
$interaction = ChatInteraction::create([
    'chat_session_id' => $session->id,
    'user_id' => $user->id,
    'question' => $originalQuery,
    'answer' => '', // Will be populated by workflow
    'metadata' => [
        'source' => 'command_line',
        'command' => 'workflow:custom',
    ],
]);

// Create parent execution
$parentExecution = AgentExecution::create([
    'agent_id' => $agents['synthesizer']->id,
    'user_id' => $user->id,
    'chat_session_id' => $session->id,
    'input' => $originalQuery,
    'max_steps' => 50,
    'status' => 'pending',
    'metadata' => [
        'workflow_type' => 'custom',
        'interaction_id' => $interaction->id,
    ],
]);

// Link interaction to execution
$interaction->update(['agent_execution_id' => $parentExecution->id]);
```

### 5. Provide User Feedback

```php
// Show configuration
$this->info('Creating workflow for '.count($topics).' topic(s):');
foreach ($topics as $topic) {
    $this->line("  - {$topic}");
}

$this->newLine();
$this->line('Workflow configuration:');
$this->line('  ├─ Stage 1: Parallel Research ('.count($topics).' agents)');
$this->line('  ├─ Stage 2: Synthesis');
$this->line('  └─ Final Action: Formatting');
$this->newLine();

// After dispatch
$this->info('Workflow dispatched!');
$this->line("  Batch ID: {$batchId}");
$this->line("  Execution ID: {$parentExecution->id}");
$this->newLine();
$this->line("Monitor in chat: /sessions/{$session->id}");
$this->line("View execution: /agent-executions/{$parentExecution->id}");
```

### 6. Handle Errors Gracefully

```php
try {
    $workflowPlan = $this->createWorkflowPlan($topics, $agents);
} catch (\Exception $e) {
    $this->error('Failed to create workflow plan: '.$e->getMessage());
    Log::error('CustomWorkflowCommand: Failed to create workflow plan', [
        'error' => $e->getMessage(),
        'topics' => $topics,
    ]);

    $interaction->update(['answer' => '❌ Failed to create workflow plan: '.$e->getMessage()]);

    return self::FAILURE;
}
```

### 7. Log Important Events

```php
Log::info('CustomWorkflowCommand: Workflow dispatched successfully', [
    'batch_id' => $batchId,
    'parent_execution_id' => $parentExecution->id,
    'interaction_id' => $interaction->id,
    'topics' => $topics,
]);
```

## Complete Example

See `app/Console/Commands/Research/DailyDigestCommand.php` for a complete, production-ready implementation demonstrating:

- ✅ Input validation and error handling
- ✅ Agent availability checking (single optimized query)
- ✅ Session strategy pattern (new/continue/reuse)
- ✅ Mixed workflow strategy (parallel + sequential stages)
- ✅ Input actions (consolidateResearch)
- ✅ Output actions (formatAsJson)
- ✅ Final actions (slackMarkdown)
- ✅ ChatInteraction and AgentExecution creation
- ✅ Comprehensive logging and observability
- ✅ User feedback and progress reporting
- ✅ Input trigger integration (webhook-triggerable)
- ✅ Constants for configuration values
- ✅ Detailed inline documentation

**Run the example:**
```bash
./vendor/bin/sail artisan research:daily-digest "AI regulation" "Climate tech" "Space news"
```

## Related Documentation

- **[Actions Development Guide](./actions.md)** - Creating custom workflow actions
- **[Theming & Color System](./06-theming.md)** - UI theming and semantic colors
- **[Package Development Guide](./07-package-development.md)** - Building Laravel packages for integrations

## Architecture Notes

### Workflow Execution Flow

```
Command
   ↓
WorkflowPlan created
   ↓
WorkflowOrchestrator.execute()
   ↓
Jobs dispatched to Horizon
   ↓
ExecuteAgentJob (per node)
   ├─ Apply input actions
   ├─ AgentExecutor.execute()
   ├─ Apply output actions
   └─ Store result in WorkflowResultStore (Redis)
   ↓
SynthesizeWorkflowJob
   ├─ Collect results from Redis
   ├─ Execute synthesizer agent
   ├─ Execute final actions
   └─ Update ChatInteraction + Broadcast completion
```

### Key Services

- **WorkflowOrchestrator** - Dispatches jobs and creates batch coordination
- **AgentExecutor** - Executes individual agents with Prism-PHP
- **WorkflowResultStore** - Redis-based result coordination between jobs
- **ActionRegistry** - Whitelist-based action execution system
- **ToolRegistry** - Per-agent tool registration for Prism
- **StatusReporter** - Real-time progress broadcasting

### Database Models

- **AgentExecution** - Tracks individual agent executions (parent/child relationships)
- **ChatInteraction** - Stores user queries and final answers
- **ChatSession** - Groups related interactions
- **StatusStream** - Real-time progress events

---

**Next Steps:**
1. Read the [Actions Development Guide](./actions.md)
2. Study `DailyDigestCommand.php` implementation
3. Create your first custom workflow command
4. Test with webhooks via Input Triggers
