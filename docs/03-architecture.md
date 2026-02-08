# Architecture

This guide provides a deep dive into PromptlyAgent's architecture, explaining how different components work together to provide intelligent AI-powered capabilities.

**📚 Prerequisites**: For an introduction to core concepts (What are Agents? What is RAG? Workflows), see the [Introduction](00-introduction.md) first.

---

## System Architecture

PromptlyAgent follows a layered architecture pattern with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────────────┐
│                          Presentation Layer                          │
│  ┌──────────────────────────┬──────────────────────────────────┐   │
│  │    Web Interface          │         REST API                 │   │
│  │  (Livewire/Volt/Flux)    │  (Laravel Controllers/Sanctum)   │   │
│  └──────────────────────────┴──────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────────┤
│                          Application Layer                           │
│  ┌─────────────┬──────────────┬──────────────┬──────────────────┐  │
│  │   Agents    │  Knowledge   │  Workflows   │   Integrations   │  │
│  │  Services   │   Services   │ Orchestrator │     System       │  │
│  └─────────────┴──────────────┴──────────────┴──────────────────┘  │
├─────────────────────────────────────────────────────────────────────┤
│                          Integration Layer                           │
│  ┌─────────────┬──────────────┬──────────────┬──────────────────┐  │
│  │  Prism-PHP  │  Tool System │    Actions   │     Triggers     │  │
│  │   (AI SDK)  │   (Registry) │   (Pipeline) │   (Webhooks)     │  │
│  └─────────────┴──────────────┴──────────────┴──────────────────┘  │
├─────────────────────────────────────────────────────────────────────┤
│                         Infrastructure Layer                         │
│  ┌─────────────┬──────────────┬──────────────┬──────────────────┐  │
│  │   MySQL     │ Meilisearch  │    Redis     │     Reverb       │  │
│  │  (Primary)  │   (Search)   │ (Cache/Jobs) │  (WebSockets)    │  │
│  └─────────────┴──────────────┴──────────────┴──────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

## Agent System

The agent system is the core of PromptlyAgent, enabling AI-powered task execution with specialized tools and capabilities.

### Agent Model

Agents are Eloquent models with the following key attributes:

```php
Agent {
    id: int
    name: string
    description: string
    agent_type: enum('chat', 'research', 'synthesis', 'custom')
    ai_provider: string ('openai', 'anthropic', 'bedrock')
    ai_model: string
    system_prompt: text
    max_steps: int
    status: enum('active', 'inactive')
    user_id: int (nullable)

    // Relationships
    tools: AgentTool[] (many-to-many)
    executions: AgentExecution[]
    knowledgeDocuments: KnowledgeDocument[] (many-to-many)
}
```

**Agent Types:**
- `chat` - Conversational agents for general dialogue
- `research` - Specialized for information gathering
- `synthesis` - Consolidates and summarizes information
- `custom` - User-defined specialized agents

### Agent Executor

The `AgentExecutor` service (`app/Services/Agents/AgentExecutor.php`) manages the complete agent lifecycle:

**Execution Flow:**
```
1. Initialize Context
   ├─ Load agent configuration
   ├─ Assemble system prompt
   └─ Prepare user message

2. Register Tools
   ├─ Query enabled tools for agent
   ├─ Instantiate Prism tool classes
   └─ Register with Prism client

3. Execute Agent
   ├─ Call AI provider via Prism-PHP
   ├─ Handle tool invocations
   ├─ Track step count (max_steps)
   └─ Stream progress updates

4. Process Result
   ├─ Extract final answer
   ├─ Parse knowledge sources
   ├─ Update execution record
   └─ Broadcast completion
```

**Key Methods:**
- `execute(Agent $agent, string $input)` - Main execution entry point
- `assembleSystemPrompt(Agent $agent)` - Builds system instructions
- `executeWithStreaming()` - Real-time SSE streaming variant
- `extractKnowledgeSources()` - Parses cited documents

### Tool System

Tools extend agent capabilities with specific functions:

**Tool Interface (Prism-PHP):**
```php
abstract class Tool {
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function parameters(): array;
    abstract public function handle(ToolCall $toolCall): ToolResult;
}
```

**Built-in Tools:**
- `WebSearchTool` - Search the web via SerpAPI/Perplexity
- `ReadKnowledgeTool` - Query agent's knowledge base
- `CalculatorTool` - Perform calculations
- `DateTimeTool` - Get current date/time
- `FileReadTool` - Read uploaded files
- `ImageAnalysisTool` - Analyze images

**Tool Registry** (`app/Services/Agents/ToolRegistry.php`):
- Manages tool availability per agent
- Handles tool instantiation and dependency injection
- Validates tool parameters
- Provides tool metadata for UI/API

### Agent Execution Tracking

Every agent execution is tracked in the database:

```php
AgentExecution {
    id: int
    agent_id: int
    user_id: int
    chat_session_id: int (nullable)
    parent_execution_id: int (nullable) // For workflows

    input: text
    output: text (nullable)

    status: enum('pending', 'processing', 'completed', 'failed')
    error: text (nullable)

    max_steps: int
    steps_taken: int

    metadata: json // Tools used, sources cited, workflow info

    started_at: timestamp
    completed_at: timestamp
}
```

**Execution Hierarchy:**
- Workflows create a **parent execution** for coordination
- Each agent in the workflow creates a **child execution**
- Child executions reference parent via `parent_execution_id`

## Knowledge System (RAG)

The knowledge system enables semantic search and retrieval-augmented generation.

### Knowledge Document Model

```php
KnowledgeDocument {
    id: int
    title: string
    content: text
    content_type: enum('file', 'text', 'external')
    privacy_level: enum('private', 'public')

    // File-based documents
    asset_id: int (nullable)
    file_path: string (nullable)
    file_size: int (nullable)
    mime_type: string (nullable)

    // External documents
    url: string (nullable)
    domain: string (nullable)
    last_fetched_at: timestamp

    // Processing
    processing_status: enum('pending', 'processing', 'completed', 'failed')
    embedding_status: enum('pending', 'generating', 'completed', 'failed')
    chunk_count: int

    // Ownership
    created_by: int

    // Relationships
    tags: KnowledgeTag[]
    agents: Agent[] (many-to-many)
    chunks: KnowledgeChunk[]
}
```

### RAG Pipeline

**Document Processing Flow:**
```
1. Upload/Create Document
   ├─ Validate file (magic bytes, executables, path traversal)
   ├─ Store in S3/local storage
   └─ Create KnowledgeDocument record

2. Text Extraction
   ├─ PDF: smalot/pdfparser
   ├─ Word: PhpOffice/PhpWord
   ├─ Text/Code: Direct read
   └─ External: Readability.php + HTML parsing

3. Chunking
   ├─ Split into semantically meaningful segments
   ├─ Overlap for context preservation
   ├─ Store as KnowledgeChunk records
   └─ Track chunk metadata (position, word count)

4. Embedding Generation
   ├─ Generate vectors via OpenAI Embeddings API
   ├─ Batch process for efficiency
   ├─ Store embeddings in chunks
   └─ Update embedding_status

5. Indexing
   ├─ Index in Meilisearch for keyword search
   ├─ Configure filterable attributes
   ├─ Set up ranking rules
   └─ Enable typo tolerance
```

**Search Strategies:**

**Keyword Search:**
```php
// Meilisearch direct query
$results = Meilisearch::search('query terms', [
    'filter' => 'privacy_level = public OR created_by = '.$userId,
    'limit' => 10,
]);
```

**Semantic Search:**
```php
// Generate query embedding
$queryEmbedding = OpenAI::embeddings('query text');

// Find similar chunks via cosine similarity
$chunks = KnowledgeChunk::whereHas('document', function($q) use ($userId) {
    $q->where('privacy_level', 'public')
      ->orWhere('created_by', $userId);
})->orderByCosineSimilarity($queryEmbedding)
  ->limit(10)
  ->get();
```

**Hybrid Search:**
```php
// Combine keyword + semantic
$keywordResults = Meilisearch::search(...);
$semanticResults = KnowledgeChunk::orderByCosineSimilarity(...);

// Merge and rank results
$merged = $this->mergeResults($keywordResults, $semanticResults);
```

### Knowledge Context Injection

When agents have assigned knowledge documents, the RAG system:

1. **Query Expansion** - Enhance user query with context
2. **Relevant Retrieval** - Fetch top-k relevant chunks
3. **Context Assembly** - Format chunks with metadata
4. **Prompt Injection** - Add to system prompt or user message
5. **Source Attribution** - Track which documents were used

## Workflow System

The workflow system orchestrates multi-agent executions for complex tasks.

### Workflow Components

**WorkflowPlan:**
- Overall workflow definition
- Original user query
- Execution strategy (simple, sequential, parallel, mixed)
- Synthesizer agent for result consolidation
- Final actions for formatting/delivery

**WorkflowStage:**
- Phase within workflow
- Stage type (parallel or sequential)
- Collection of workflow nodes

**WorkflowNode:**
- Individual agent execution
- Agent ID and configuration
- Input prompt for this node
- Input/output actions for data transformation

### Workflow Orchestrator

The `WorkflowOrchestrator` (`app/Services/Agents/WorkflowOrchestrator.php`) coordinates workflow execution:

**Execution Process:**
```
1. Create Parent Execution
   ├─ Generate batch ID for job coordination
   ├─ Create AgentExecution record (pending)
   └─ Link to ChatInteraction

2. Execute Initial Actions (if configured)
   ├─ Sort by priority
   ├─ Execute sequentially
   └─ Log results in metadata

3. Dispatch Stages
   ├─ Iterate through WorkflowStages
   ├─ Parallel stages → dispatch all jobs simultaneously
   ├─ Sequential stages → chain jobs with dependencies
   └─ Track job IDs in batch

4. Job Execution (ExecuteAgentJob)
   ├─ Apply input actions (transform data)
   ├─ Execute agent via AgentExecutor
   ├─ Apply output actions (format results)
   ├─ Store result in WorkflowResultStore (Redis)
   └─ Emit status updates

5. Synthesis (SynthesizeWorkflowJob)
   ├─ Wait for all jobs to complete
   ├─ Collect results from Redis
   ├─ Execute synthesizer agent
   ├─ Apply final actions
   ├─ Update ChatInteraction
   └─ Broadcast completion
```

**Batch Coordination:**
- Laravel's Bus facade manages job batches
- Jobs share a common batch ID
- Synthesis waits for batch completion
- Failed jobs trigger failure callbacks

### Action Pipeline

Actions transform data at critical points in the workflow:

**Action Types:**
1. **Initial Actions** - Execute once at workflow start
2. **Input Actions** - Transform data before each agent
3. **Output Actions** - Transform data after each agent
4. **Final Actions** - Transform final synthesized result

**Action Execution:**
```php
// Sort by priority
$sortedActions = collect($actions)->sortBy('priority');

// Execute sequentially
foreach ($sortedActions as $actionConfig) {
    $action = ActionRegistry::get($actionConfig->method);

    if (!$action->validate($actionConfig->params)) {
        // Log validation error, skip action
        continue;
    }

    $data = $action->execute($data, $context, $actionConfig->params);

    // Track in metadata
    $metadata['actions_executed'][] = [
        'action' => $actionConfig->method,
        'status' => 'success',
        'duration_ms' => $duration,
    ];
}
```

## Integration System

The integration system enables PromptlyAgent to connect with external services.

### Input Triggers

Input triggers provide webhook-based automation:

```php
InputTrigger {
    id: int
    name: string
    provider_id: string // e.g., 'slack', 'webhook', 'schedule'
    user_id: int
    agent_id: int (nullable)

    is_active: bool

    // Configuration
    config: json // Provider-specific settings
    payload_template: json // Map webhook data to command args
    ip_whitelist: json // Allowed IP addresses

    // Security
    secret_token: string // For request validation

    // Execution tracking
    last_triggered_at: timestamp
    total_triggers: int
}
```

**Trigger Execution Flow:**
```
1. Webhook Request Arrives
   ├─ Validate IP against whitelist
   ├─ Verify secret token
   └─ Rate limit check

2. Payload Processing
   ├─ Extract data using payload_template
   ├─ Map to command arguments
   └─ Validate required fields

3. Command Execution
   ├─ Resolve command class
   ├─ Pass arguments
   └─ Execute in background (queued)

4. Response
   ├─ Return execution ID
   ├─ Provide status URL
   └─ Log trigger event
```

### Output Actions

Output actions deliver results to external systems:

```php
OutputAction {
    id: int
    name: string
    type: enum('webhook', 'slack', 'email', 'custom')
    user_id: int

    // Configuration
    config: json // Type-specific settings (URLs, tokens, etc.)

    // Agent linking
    agents: Agent[] (many-to-many)

    // Execution tracking
    logs: OutputActionLog[]
}
```

**Common Output Actions:**
- **Webhook** - POST results to external URL
- **Slack** - Send formatted messages to channels
- **Email** - Send digest emails
- **Custom** - Package-defined actions

### Package System

PromptlyAgent supports self-registering Laravel packages for integrations:

**Package Structure:**
```
packages/
└── notion-integration/
    ├── src/
    │   ├── NotionIntegrationServiceProvider.php
    │   ├── NotionOutputAction.php
    │   ├── NotionInputTrigger.php
    │   └── NotionClient.php
    ├── config/
    │   └── notion-integration.php
    └── composer.json
```

**Service Provider Auto-Registration:**
```php
class NotionIntegrationServiceProvider extends ServiceProvider {
    public function register() {
        // Register output actions
        OutputActionRegistry::register('notion', NotionOutputAction::class);

        // Register input trigger providers
        InputTriggerRegistry::register('notion', NotionInputTrigger::class);
    }

    public function boot() {
        // Publish config, migrations, views
        $this->publishes([...]);
    }
}
```

## Real-Time System

PromptlyAgent provides real-time updates using Laravel Reverb (WebSockets).

### Status Streaming

**Status Reporter** (`app/Services/Agents/StatusReporter.php`):
```php
StatusReporter::emit($execution, [
    'type' => 'agent_step',
    'step' => 3,
    'max_steps' => 10,
    'message' => 'Processing tool result...',
    'metadata' => [
        'tool' => 'web_search',
        'result_count' => 5,
    ],
]);
```

**Frontend Subscription (Laravel Echo):**
```javascript
Echo.private(`agent-execution.${executionId}`)
    .listen('StatusUpdate', (event) => {
        console.log('Progress:', event.message);
        updateUI(event);
    });
```

**StatusStream Model:**
```php
StatusStream {
    id: int
    agent_execution_id: int
    type: enum('progress', 'tool_call', 'error', 'completion')
    message: string
    metadata: json
    created_at: timestamp
}
```

### Chat Streaming (SSE)

For direct chat API, Server-Sent Events provide streaming:

```php
// Backend
return response()->stream(function() use ($agent, $input) {
    $stream = $this->agentExecutor->executeWithStreaming($agent, $input);

    foreach ($stream as $chunk) {
        echo "data: ".json_encode($chunk)."\n\n";
        ob_flush();
        flush();
    }
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
    'X-Accel-Buffering' => 'no',
]);
```

## Queue System (Horizon)

Background job processing is managed by Laravel Horizon.

### Job Types

**ExecuteAgentJob:**
- Executes individual agent in workflow
- Applies input/output actions
- Stores results in Redis
- Emits status updates

**SynthesizeWorkflowJob:**
- Waits for batch completion
- Collects all results
- Executes synthesizer
- Broadcasts final result

**ProcessKnowledgeDocumentJob:**
- Extracts text from files
- Generates embeddings
- Updates Meilisearch index

**RefreshExternalDocumentJob:**
- Fetches updated content from URLs
- Re-processes and re-indexes
- Scheduled via cron

### Queue Configuration

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 600, // 10 minutes
        'block_for' => null,
    ],
],
```

**Queue Priority:**
- `high` - User-initiated chat/research
- `default` - Workflow jobs, synthesis
- `low` - Knowledge processing, external refresh

## Security

### Authentication

**Laravel Sanctum** provides API token authentication:
- Tokens scoped with abilities (`agent:view`, `chat:create`, etc.)
- Token validation on every API request
- Revocable tokens via UI

### Authorization

**Policies** enforce access control:
```php
AgentPolicy:
- view() - Can user view this agent?
- execute() - Can user execute this agent?
- update() - Can user modify this agent?
```

### Input Validation

**Form Requests** validate all input:
```php
StoreKnowledgeRequest:
- File validation (mime types, size, magic bytes)
- Path traversal prevention
- Executable detection
- Virus scanning (optional)
```

### Rate Limiting

Tiered rate limiting per route group:
- Expensive operations: 10/min
- Moderate operations: 60/min
- Read operations: 300/min

## Performance Optimization

### Caching Strategy

**Redis caching** for frequently accessed data:
- Agent configurations (1 hour TTL)
- Knowledge document metadata (5 minutes)
- User preferences (session lifetime)

### Eager Loading

Prevent N+1 queries with relationship loading:
```php
Agent::with(['tools', 'knowledgeDocuments.tags'])
    ->where('status', 'active')
    ->get();
```

### Database Indexing

Critical indexes for performance:
```sql
INDEX idx_agent_executions_status ON agent_executions(status, created_at);
INDEX idx_knowledge_documents_user ON knowledge_documents(created_by, privacy_level);
INDEX idx_chat_sessions_user ON chat_sessions(user_id, updated_at);
```

### Queue Optimization

- Batch job dispatching reduces overhead
- Job chunking for large datasets
- Failed job retry with exponential backoff

## Monitoring & Observability

### Logging

Structured logging with context:
```php
Log::info('AgentExecutor: Execution started', [
    'agent_id' => $agent->id,
    'user_id' => $user->id,
    'execution_id' => $execution->id,
]);
```

### Metrics

Key metrics tracked:
- Agent execution duration
- Tool invocation frequency
- Knowledge search latency
- Queue processing throughput

### Error Tracking

Comprehensive error handling:
- Exception logging with stack traces
- Failed job tracking in Horizon
- User-facing error messages
- Admin notifications for critical failures

---

## Next Steps

Now that you understand the architecture, apply this knowledge:

**🛠️ Build with It:**
- **[Development Guide](02-development.md)** - Day-to-day development patterns
- **[Workflows](04-workflows.md)** - Create multi-agent orchestrations
- **[Package Development](07-package-development.md)** - Build custom integrations

**🔍 Prerequisites:**
- **[Introduction](00-introduction.md)** - Core concepts (if you haven't read it yet)

---

This architecture enables PromptlyAgent to scale from single-user research to enterprise-grade AI orchestration. The modular design allows for easy extension and customization to meet specific use cases.
