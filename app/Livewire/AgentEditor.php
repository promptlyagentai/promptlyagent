<?php

namespace App\Livewire;

use App\Models\Agent;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Agents\AgentKnowledgeService;
use App\Services\Agents\AgentService;
use App\Services\Agents\ToolRegistry;
use App\Services\AI\ModelSelector;
use Aws\Bedrock\BedrockClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Prism\Prism\Enums\Provider;

/**
 * Agent configuration editor with model selection, tool management, and knowledge assignments.
 *
 * Supports agent types:
 * - individual: Single agent with tools
 * - workflow: Multi-agent orchestration (deprecated, use Research Planner)
 * - direct: Direct chat agent
 * - promptly: Dynamic agent selection
 *
 * Features:
 * - Dynamic model fetching from provider APIs
 * - Tool configuration with priority and execution strategy
 * - Knowledge document/tag assignments
 * - AWS Bedrock model discovery
 *
 * @property string $agent_type Agent type (individual|workflow|direct|promptly)
 * @property array<int, array{id: int, name: string, execution_order: int, enabled: bool}> $workflow_agents
 * @property array<string, array{enabled: bool, execution_order: int, priority_level: string, ...}> $toolConfigs
 */
class AgentEditor extends Component
{
    public $agent;

    public $isEditing = false;

    // Agent properties
    public $name = '';

    public $description = '';

    public $system_prompt = '';

    public $ai_provider = 'openai';

    public $ai_model;

    public $max_steps = 10;

    public $is_public = false;

    public $show_in_chat = true;

    public $available_for_research = false;

    public $enforce_response_language = true;

    public $status = 'active';

    // Cache for models
    protected $modelCache = [];

    protected $modelCacheExpiration = 3600; // 1 hour in seconds

    // Multi-agent workflow properties
    public $agent_type = 'individual'; // 'individual' or 'workflow'

    public $workflow_agents = [];

    public $available_agents = [];

    // Tool configuration
    public $availableTools = [];

    public $selectedTools = [];

    public $toolConfigs = [];

    // Knowledge assignment configuration
    public $knowledgeAssignmentType = 'none'; // 'none', 'documents', 'tags', 'all'

    public $selectedDocuments = [];

    public $selectedTags = [];

    public $availableDocuments = [];

    public $availableTags = [];

    public $knowledgeSearch = '';

    public $knowledgePriority = 2; // Default to medium priority

    public $showModal = false;

    protected $listeners = [
        'openAgentEditor' => 'openEditor',
        'closeAgentEditor' => 'closeEditor',
    ];

    /**
     * Update system prompt when agent type changes
     *
     * @deprecated Workflow agent type is deprecated. Use Research Planner for AI-generated workflows.
     */
    public function updatedAgentType($value)
    {
        // Warn about deprecated workflow type (only when creating, not editing)
        if ($value === 'workflow' && ! $this->isEditing) {
            $this->dispatch('warning', 'Manual workflow creation is deprecated. Consider using Research Planner for AI-generated multi-agent workflows instead.');
        }

        // Only update system prompt if it's still the default value or empty
        $isDefaultIndividual = str_contains($this->system_prompt, 'You are a helpful AI assistant');
        $isDefaultWorkflow = str_contains($this->system_prompt, 'You are a workflow orchestrator');
        $isEmpty = empty(trim($this->system_prompt));

        if ($isEmpty || $isDefaultIndividual || $isDefaultWorkflow) {
            if ($value === 'workflow') {
                $this->system_prompt = "You are a workflow orchestrator that coordinates multiple specialized agents and synthesizes their results into comprehensive, high-quality outputs.\n\n## Available Agents\n\n{available_agents}\n\n## Your Role\n\n**Coordination**: Execute the above agents in sequence, providing each with appropriate context from previous results. Leverage each agent's unique capabilities and specializations.\n\n**Synthesis**: Combine agent outputs into a coherent, professional final result that fully addresses the original request. Consider how each agent's contribution fits into the overall solution.\n\n**Quality Control**: Ensure the final output meets high standards for accuracy, completeness, and usefulness by leveraging the collective expertise of all agents.\n\n## Output Requirements\n\n- Create well-structured, markdown-formatted responses\n- Include comprehensive citations and sources when applicable\n- Maintain professional tone and clarity\n- Synthesize insights rather than simply concatenating results\n- Address the original request completely and thoroughly\n- Highlight how different agent specializations contributed to the final result\n\nProvide intelligent orchestration that leverages each agent's unique strengths to deliver superior results.";
            } else {
                $this->system_prompt = "You are a helpful AI assistant. Use the following tools when appropriate:\n\n{available_tools}\n\nAlways think step-by-step to provide the best response.";
            }
        }
    }

    /**
     * When AI provider changes, update the model to a cost-effective one
     */
    public function updatedAiProvider()
    {
        // Fetch models for the new provider if not already cached
        $this->getAvailableModelsForProvider($this->ai_provider);

        // Set to most cost-effective model
        $this->ai_model = $this->getCostEffectiveModel($this->ai_provider);
    }

    protected function rules()
    {
        $availableProviders = array_keys($this->getAvailableProvidersProperty());

        $rules = [
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:500',
            'ai_provider' => 'required|in:'.implode(',', $availableProviders),
            'ai_model' => 'required|string',
            'max_steps' => 'required|integer|min:1|max:50',
            'is_public' => 'boolean',
            'show_in_chat' => 'boolean',
            'available_for_research' => 'boolean',
            'status' => 'required|in:active,inactive',
            'agent_type' => 'required|in:individual,workflow,direct,promptly',
        ];

        // System prompt is required for both individual and workflow agents
        $rules['system_prompt'] = 'required|string|min:10';

        // Apply workflow_agents validation only for workflow-type agents
        // Promptly agents dynamically select agents at runtime, so they don't need pre-configured workflow_agents
        if ($this->agent_type === 'workflow') {
            $rules['workflow_agents'] = 'required|array|min:2';
            $rules['workflow_agents.*.id'] = 'exists:agents,id';
        } else {
            // For non-workflow agents (individual, direct, promptly), ensure workflow_agents is not validated
            // and clear any leftover workflow data
            $this->workflow_agents = [];
        }

        return $rules;
    }

    protected $messages = [
        'name.required' => 'Agent name is required.',
        'name.min' => 'Agent name must be at least 3 characters.',
        'system_prompt.required' => 'System prompt is required.',
        'system_prompt.min' => 'System prompt must be at least 10 characters.',
        'ai_provider.required' => 'Please select an AI provider.',
        'ai_model.required' => 'Please specify an AI model.',
        'max_steps.min' => 'Max steps must be at least 1.',
        'max_steps.max' => 'Max steps cannot exceed 50.',
        'agent_type.required' => 'Please select an agent type.',
        'workflow_agents.required_if' => 'Workflow agents are required for workflow type.',
        'workflow_agents.min' => 'At least 2 agents are required for a workflow.',
    ];

    public function mount(?Agent $agent = null)
    {
        try {
            $this->loadAvailableTools(); // Load tools first
            $this->loadAvailableAgents(); // Load available agents for workflows
            $this->loadAvailableKnowledge(); // Load available knowledge

            // Use explicit null check instead of truthy check
            if (! is_null($agent) && $agent->exists) {
                $this->agent = $agent->load(['tools', 'knowledgeAssignments']);
                $this->isEditing = true;
                $this->loadAgentData();
            } else {
                // Explicitly ensure we're in create mode
                $this->agent = null;
                $this->isEditing = false;

                // Set default AI provider to first available
                $availableProviders = $this->getAvailableProvidersProperty();
                if (! empty($availableProviders)) {
                    $this->ai_provider = array_key_first($availableProviders);
                    // Set default model to most cost-effective for this provider
                    $this->ai_model = $this->getCostEffectiveModel($this->ai_provider);
                } else {
                    // Fallback if no providers are configured
                    $this->ai_provider = 'openai';
                    $this->ai_model = app(ModelSelector::class)->getLowCostModel()['model'];
                }
            }

            $this->showModal = true; // Auto-open when component is mounted

        } catch (\Exception $e) {
            Log::error('AgentEditor mount failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'agent_id' => $agent?->id,
            ]);

            // Set fallback values to prevent component failure
            $this->availableTools = [];
            $this->available_agents = [];
            $this->availableDocuments = [];
            $this->availableTags = [];

            // Set basic defaults
            $this->ai_provider = 'openai';
            $this->ai_model = 'gpt-3.5-turbo';
            $this->isEditing = false; // Ensure this is false in error state
            $this->showModal = true;

            // Dispatch error to user
            $this->dispatch('error', 'Failed to initialize agent editor: '.$e->getMessage());
        }
    }

    public function openEditor($agentId = null)
    {
        $this->loadAvailableTools(); // Refresh tools when opening
        $this->loadAvailableAgents(); // Refresh agents when opening
        $this->loadAvailableKnowledge(); // Refresh knowledge when opening

        if ($agentId) {
            // Reset form first to ensure clean state
            $this->resetForm();
            $this->agent = Agent::with(['tools', 'knowledgeAssignments'])->findOrFail($agentId);
            $this->isEditing = true;
            $this->loadAgentData();
        } else {
            $this->agent = null;
            $this->isEditing = false;
            $this->resetForm();
        }

        $this->showModal = true;
    }

    public function closeEditor()
    {
        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('closeAgentEditor');
    }

    protected function loadAgentData()
    {
        if (! $this->agent) {
            return;
        }

        $this->name = $this->agent->name;
        $this->description = $this->agent->description;
        $this->system_prompt = $this->agent->system_prompt;
        $this->ai_provider = $this->agent->ai_provider;
        $this->ai_model = $this->agent->ai_model;
        $this->max_steps = $this->agent->max_steps;
        $this->is_public = $this->agent->is_public;
        $this->show_in_chat = $this->agent->show_in_chat ?? true;
        $this->available_for_research = $this->agent->available_for_research ?? false;
        $this->enforce_response_language = $this->agent->enforce_response_language ?? true;
        $this->status = $this->agent->status;
        $this->agent_type = $this->agent->agent_type ?? 'individual';

        // Load workflow configuration for workflow-type agents only
        // Promptly agents don't use pre-configured workflows
        if ($this->agent_type === 'workflow' && $this->agent->workflow_config) {
            $this->workflow_agents = $this->agent->workflow_config['agents'] ?? [];
        } else {
            $this->workflow_agents = []; // Clear workflow agents for non-workflow agents
        }

        // Load tool configuration for individual-type agents (individual and direct)
        if (in_array($this->agent_type, ['individual', 'direct'])) {
            $this->selectedTools = $this->agent->tools->pluck('tool_name')->toArray();

            $this->toolConfigs = [];
            foreach ($this->agent->tools as $tool) {
                $this->toolConfigs[$tool->tool_name] = [
                    'enabled' => $tool->enabled,
                    'execution_order' => $tool->execution_order,
                    'priority_level' => $tool->priority_level ?? 'standard',
                    'execution_strategy' => $tool->execution_strategy ?? 'always',
                    'min_results_threshold' => $tool->min_results_threshold,
                    'max_execution_time' => $tool->max_execution_time ?? 30000,
                    'config' => $tool->tool_config ?? [],
                ];
            }
        }

        // Load knowledge assignments
        $this->loadKnowledgeAssignments();
    }

    protected function resetForm()
    {
        $this->name = '';
        $this->description = '';

        // Set default system prompt based on agent type
        if ($this->agent_type === 'workflow') {
            $this->system_prompt = "You are a workflow orchestrator that coordinates multiple specialized agents and synthesizes their results into comprehensive, high-quality outputs.\n\n## Available Agents\n\n{available_agents}\n\n## Your Role\n\n**Coordination**: Execute the above agents in sequence, providing each with appropriate context from previous results. Leverage each agent's unique capabilities and specializations.\n\n**Synthesis**: Combine agent outputs into a coherent, professional final result that fully addresses the original request. Consider how each agent's contribution fits into the overall solution.\n\n**Quality Control**: Ensure the final output meets high standards for accuracy, completeness, and usefulness by leveraging the collective expertise of all agents.\n\n## Output Requirements\n\n- Create well-structured, markdown-formatted responses\n- Include comprehensive citations and sources when applicable\n- Maintain professional tone and clarity\n- Synthesize insights rather than simply concatenating results\n- Address the original request completely and thoroughly\n- Highlight how different agent specializations contributed to the final result\n\nProvide intelligent orchestration that leverages each agent's unique strengths to deliver superior results.";
        } else {
            $this->system_prompt = "You are a helpful AI assistant. Use the following tools when appropriate:\n\n{available_tools}\n\nAlways think step-by-step to provide the best response.";
        }

        // Set default AI provider to first available
        $availableProviders = $this->getAvailableProvidersProperty();
        if (! empty($availableProviders)) {
            $this->ai_provider = array_key_first($availableProviders);
            // Set default model to most cost-effective for this provider
            $this->ai_model = $this->getCostEffectiveModel($this->ai_provider);
        } else {
            $this->ai_provider = 'openai';
            $this->ai_model = app(ModelSelector::class)->getLowCostModel()['model'];
        }

        $this->max_steps = 10;
        $this->is_public = false;
        $this->show_in_chat = true;
        $this->available_for_research = false;
        $this->enforce_response_language = true;
        $this->status = 'active';
        $this->agent_type = 'individual';
        $this->workflow_agents = [];
        $this->selectedTools = [];
        $this->toolConfigs = [];

        // Reset knowledge fields
        $this->knowledgeAssignmentType = 'none';
        $this->selectedDocuments = [];
        $this->selectedTags = [];
        $this->knowledgeSearch = '';
        $this->knowledgePriority = 2;

        $this->agent = null;
        $this->resetValidation();
    }

    protected function loadAvailableTools()
    {
        try {
            $toolRegistry = app(ToolRegistry::class);
            $tools = $toolRegistry->getAvailableTools(Auth::id());

            $this->availableTools = collect($tools)->map(function ($tool, $toolKey) {
                return [
                    'name' => $toolKey, // Use the array key as the tool name for commands
                    'display_name' => $tool['name'] ?? $toolKey, // Use the display name from config
                    'source' => $tool['source'] ?? 'local',
                    'description' => $tool['description'] ?? 'No description available',
                    'category' => $tool['category'] ?? 'general',
                ];
            })->sortBy('display_name')->values()->toArray();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to load available tools: '.$e->getMessage());
            $this->availableTools = [];
        }
    }

    protected function loadAvailableAgents()
    {
        try {
            // Load all active individual-type agents (excluding workflow agents and the current agent if editing)
            // Only workflow agents should not be nested in other workflows
            // Promptly agents can be included since they dynamically select agents at runtime
            $query = Agent::active()
                ->where(function ($q) {
                    $q->where('agent_type', '!=', 'workflow')
                        ->orWhereNull('agent_type'); // Include agents without type (defaults to individual)
                })
                ->select('id', 'name', 'description', 'agent_type')
                ->orderBy('name');

            // If editing, exclude the current agent
            if ($this->isEditing && $this->agent) {
                $query->where('id', '!=', $this->agent->id);
            }

            $this->available_agents = $query->get()->toArray();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to load available agents: '.$e->getMessage());
            $this->available_agents = [];
        }
    }

    protected function loadAvailableKnowledge()
    {
        try {
            // Load available knowledge documents
            if (! empty($this->knowledgeSearch)) {
                // Use hybrid search for better relevance
                $knowledgeService = app(\App\Services\Knowledge\KnowledgeToolService::class);

                $searchResult = $knowledgeService->search([
                    'query' => $this->knowledgeSearch,
                    'search_type' => 'hybrid',
                    'limit' => 50,
                    'include_content' => false,
                    'relevance_threshold' => config('knowledge.search.agent_relevance_threshold', 0.6), // Show more options for assignment
                ]);

                $this->availableDocuments = collect($searchResult['documents'])->map(function ($doc) {
                    return [
                        'id' => $doc['id'],
                        'title' => $doc['title'],
                        'type' => $doc['type'] ?? 'document',
                        'updated_at' => isset($doc['created_at']) ?
                            \Carbon\Carbon::parse($doc['created_at'])->format('M j, Y') :
                            'Unknown',
                        'score' => $doc['score'] ?? null,
                    ];
                })->toArray();
            } else {
                // Load recent documents when no search query
                $documentsQuery = KnowledgeDocument::completed()
                    ->select('id', 'title', 'source_type', 'updated_at')
                    ->orderBy('updated_at', 'desc');

                $this->availableDocuments = $documentsQuery->limit(50)->get()->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'title' => $doc->title,
                        'type' => $doc->source_type ?? 'document',
                        'updated_at' => $doc->updated_at->format('M j, Y'),
                        'score' => null,
                    ];
                })->toArray();
            }

            // Load available tags
            $this->availableTags = KnowledgeTag::select('id', 'name', 'color', 'description')
                ->orderBy('name')
                ->get()
                ->map(function ($tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color,
                        'description' => $tag->description,
                    ];
                })
                ->toArray();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to load knowledge: '.$e->getMessage());
            $this->availableDocuments = [];
            $this->availableTags = [];
        }
    }

    protected function loadKnowledgeAssignments()
    {
        if (! $this->agent) {
            $this->knowledgeAssignmentType = 'none';
            $this->selectedDocuments = [];
            $this->selectedTags = [];
            $this->knowledgePriority = 2;

            return;
        }

        try {
            $assignments = $this->agent->knowledgeAssignments;

            if ($assignments->isEmpty()) {
                $this->knowledgeAssignmentType = 'none';
                $this->selectedDocuments = [];
                $this->selectedTags = [];
                $this->knowledgePriority = 2;

                return;
            }

            // Check for all knowledge assignment
            $allAssignment = $assignments->where('assignment_type', 'all')->first();
            if ($allAssignment) {
                $this->knowledgeAssignmentType = 'all';
                $this->knowledgePriority = $allAssignment->priority;
                $this->selectedDocuments = [];
                $this->selectedTags = [];

                return;
            }

            // Check for document assignments
            $documentAssignments = $assignments->where('assignment_type', 'document');
            if ($documentAssignments->isNotEmpty()) {
                $this->knowledgeAssignmentType = 'documents';
                $this->selectedDocuments = $documentAssignments->pluck('knowledge_document_id')->toArray();
                $this->knowledgePriority = $documentAssignments->first()->priority;
                $this->selectedTags = [];

                return;
            }

            // Check for tag assignments
            $tagAssignments = $assignments->where('assignment_type', 'tag');
            if ($tagAssignments->isNotEmpty()) {
                $this->knowledgeAssignmentType = 'tags';
                $this->selectedTags = $tagAssignments->pluck('knowledge_tag_id')->toArray();
                $this->knowledgePriority = $tagAssignments->first()->priority;
                $this->selectedDocuments = [];

                return;
            }

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to load knowledge assignments: '.$e->getMessage());
            $this->knowledgeAssignmentType = 'none';
            $this->selectedDocuments = [];
            $this->selectedTags = [];
        }
    }

    public function addWorkflowAgent($agentId)
    {
        $agent = collect($this->available_agents)->firstWhere('id', $agentId);
        if ($agent && ! in_array($agentId, array_column($this->workflow_agents, 'id'))) {
            $this->workflow_agents[] = [
                'id' => $agentId,
                'name' => $agent['name'],
                'description' => $agent['description'],
                'execution_order' => count($this->workflow_agents) + 1,
                'enabled' => true,
            ];
        }
    }

    public function removeWorkflowAgent($agentId)
    {
        $this->workflow_agents = array_values(array_filter($this->workflow_agents, fn ($agent) => $agent['id'] !== $agentId));

        // Reorder remaining agents
        foreach ($this->workflow_agents as $index => &$agent) {
            $agent['execution_order'] = $index + 1;
        }
    }

    public function moveWorkflowAgentUp($agentId)
    {
        $currentIndex = array_search($agentId, array_column($this->workflow_agents, 'id'));
        if ($currentIndex > 0) {
            $temp = $this->workflow_agents[$currentIndex];
            $this->workflow_agents[$currentIndex] = $this->workflow_agents[$currentIndex - 1];
            $this->workflow_agents[$currentIndex - 1] = $temp;

            $this->updateWorkflowAgentOrder();
        }
    }

    public function moveWorkflowAgentDown($agentId)
    {
        $currentIndex = array_search($agentId, array_column($this->workflow_agents, 'id'));
        if ($currentIndex < count($this->workflow_agents) - 1) {
            $temp = $this->workflow_agents[$currentIndex];
            $this->workflow_agents[$currentIndex] = $this->workflow_agents[$currentIndex + 1];
            $this->workflow_agents[$currentIndex + 1] = $temp;

            $this->updateWorkflowAgentOrder();
        }
    }

    public function toggleWorkflowAgent($agentId)
    {
        foreach ($this->workflow_agents as &$agent) {
            if ($agent['id'] === $agentId) {
                $agent['enabled'] = ! $agent['enabled'];
                break;
            }
        }
    }

    protected function updateWorkflowAgentOrder()
    {
        foreach ($this->workflow_agents as $index => &$agent) {
            $agent['execution_order'] = $index + 1;
        }
    }

    public function addTool($toolName)
    {
        if (! in_array($toolName, $this->selectedTools)) {
            $this->selectedTools[] = $toolName;

            // Initialize tool configuration with priority settings
            $this->toolConfigs[$toolName] = [
                'enabled' => true,
                'execution_order' => count($this->selectedTools),
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => null,
                'max_execution_time' => 30000,
                'config' => [],
            ];
        }
    }

    public function removeTool($toolName)
    {
        $this->selectedTools = array_values(array_filter($this->selectedTools, fn ($tool) => $tool !== $toolName));
        unset($this->toolConfigs[$toolName]);

        // Reorder remaining tools
        $order = 1;
        foreach ($this->selectedTools as $tool) {
            $this->toolConfigs[$tool]['execution_order'] = $order++;
        }
    }

    public function toggleTool($toolName)
    {
        if (isset($this->toolConfigs[$toolName])) {
            $this->toolConfigs[$toolName]['enabled'] = ! $this->toolConfigs[$toolName]['enabled'];
        }
    }

    public function moveToolUp($toolName)
    {
        $currentIndex = array_search($toolName, $this->selectedTools);
        if ($currentIndex > 0) {
            $temp = $this->selectedTools[$currentIndex];
            $this->selectedTools[$currentIndex] = $this->selectedTools[$currentIndex - 1];
            $this->selectedTools[$currentIndex - 1] = $temp;

            $this->updateToolOrder();
        }
    }

    public function moveToolDown($toolName)
    {
        $currentIndex = array_search($toolName, $this->selectedTools);
        if ($currentIndex < count($this->selectedTools) - 1) {
            $temp = $this->selectedTools[$currentIndex];
            $this->selectedTools[$currentIndex] = $this->selectedTools[$currentIndex + 1];
            $this->selectedTools[$currentIndex + 1] = $temp;

            $this->updateToolOrder();
        }
    }

    protected function updateToolOrder()
    {
        foreach ($this->selectedTools as $index => $toolName) {
            $this->toolConfigs[$toolName]['execution_order'] = $index + 1;
        }
    }

    /**
     * Update tool priority level
     */
    public function updateToolPriority($toolName, $priorityLevel)
    {
        if (isset($this->toolConfigs[$toolName])) {
            $this->toolConfigs[$toolName]['priority_level'] = $priorityLevel;

            // Auto-adjust execution strategy based on priority
            if ($priorityLevel === 'preferred') {
                $this->toolConfigs[$toolName]['execution_strategy'] = 'always';
            } elseif ($priorityLevel === 'fallback') {
                $this->toolConfigs[$toolName]['execution_strategy'] = 'if_preferred_fails';
            }
        }
    }

    /**
     * Update tool execution strategy
     */
    public function updateToolExecutionStrategy($toolName, $strategy)
    {
        if (isset($this->toolConfigs[$toolName])) {
            $this->toolConfigs[$toolName]['execution_strategy'] = $strategy;
        }
    }

    /**
     * Update tool minimum results threshold
     */
    public function updateToolMinResults($toolName, $threshold)
    {
        if (isset($this->toolConfigs[$toolName])) {
            $this->toolConfigs[$toolName]['min_results_threshold'] = $threshold ? (int) $threshold : null;
        }
    }

    /**
     * Update tool maximum execution time
     */
    public function updateToolMaxExecutionTime($toolName, $time)
    {
        if (isset($this->toolConfigs[$toolName])) {
            $this->toolConfigs[$toolName]['max_execution_time'] = $time ? (int) $time : 30000;
        }
    }

    /**
     * Knowledge assignment methods
     */
    public function updatedKnowledgeSearch()
    {
        $this->loadAvailableKnowledge();
    }

    public function updatedKnowledgeAssignmentType($value)
    {
        if ($value !== 'none') {
            $this->loadAvailableKnowledge();
        }

        // Reset selections when changing type
        if ($value !== 'documents') {
            $this->selectedDocuments = [];
        }
        if ($value !== 'tags') {
            $this->selectedTags = [];
        }
    }

    public function addDocument($documentId)
    {
        if (! in_array($documentId, $this->selectedDocuments)) {
            $this->selectedDocuments[] = $documentId;
        }
    }

    public function removeDocument($documentId)
    {
        $this->selectedDocuments = array_values(array_filter($this->selectedDocuments, fn ($id) => $id != $documentId));
    }

    public function addTag($tagId)
    {
        if (! in_array($tagId, $this->selectedTags)) {
            $this->selectedTags[] = $tagId;
        }
    }

    public function removeTag($tagId)
    {
        $this->selectedTags = array_values(array_filter($this->selectedTags, fn ($id) => $id != $tagId));
    }

    protected function saveKnowledgeAssignments(Agent $agent)
    {
        try {
            $agentKnowledgeService = app(AgentKnowledgeService::class);

            // Remove all existing assignments
            $agentKnowledgeService->removeAgentKnowledgeAssignments($agent);

            // Add new assignments based on selection
            switch ($this->knowledgeAssignmentType) {
                case 'all':
                    $agentKnowledgeService->assignAllKnowledgeToAgent($agent, [
                        'priority' => $this->knowledgePriority,
                    ]);
                    break;

                case 'documents':
                    if (! empty($this->selectedDocuments)) {
                        $agentKnowledgeService->assignDocumentsToAgent($agent, $this->selectedDocuments, [
                            'priority' => $this->knowledgePriority,
                        ]);
                    }
                    break;

                case 'tags':
                    if (! empty($this->selectedTags)) {
                        $agentKnowledgeService->assignTagsToAgent($agent, $this->selectedTags, [
                            'priority' => $this->knowledgePriority,
                        ]);
                    }
                    break;

                case 'none':
                default:
                    // No assignments needed - already removed above
                    break;
            }

            Log::info('Knowledge assignments saved for agent', [
                'agent_id' => $agent->id,
                'assignment_type' => $this->knowledgeAssignmentType,
                'documents_count' => count($this->selectedDocuments),
                'tags_count' => count($this->selectedTags),
                'priority' => $this->knowledgePriority,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to save knowledge assignments', [
                'agent_id' => $agent->id,
                'assignment_type' => $this->knowledgeAssignmentType,
                'documents_count' => count($this->selectedDocuments),
                'tags_count' => count($this->selectedTags),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('error', 'Failed to save knowledge assignments: '.$e->getMessage());
        }
    }

    public function save()
    {
        try {
            // Run validation
            $validatedData = $this->validate();
            Log::info('Agent validation passed', ['agent_name' => $this->name]);

            $agentService = new AgentService(app(ToolRegistry::class));

            $agentData = [
                'name' => $this->name,
                'description' => $this->description,
                'agent_type' => $this->agent_type,
                'ai_provider' => $this->ai_provider,
                'ai_model' => $this->ai_model,
                'max_steps' => $this->max_steps,
                'is_public' => $this->is_public,
                'show_in_chat' => $this->show_in_chat,
                'available_for_research' => $this->available_for_research,
                'enforce_response_language' => $this->enforce_response_language,
                'status' => $this->status,
            ];

            // Handle different agent types
            // Workflow agents use workflow_config with pre-configured agents
            // Promptly agents dynamically select agents at runtime (no workflow_config needed)
            // Individual-type agents (individual, direct) use tool configs
            if ($this->agent_type === 'workflow') {
                $agentData['system_prompt'] = $this->system_prompt; // Use user-provided system prompt
                $agentData['workflow_config'] = [
                    'agents' => $this->workflow_agents,
                    'orchestration_mode' => 'sequential', // Default mode
                ];
                $toolConfigs = []; // Workflow agents don't use individual tools
            } else {
                // Individual, direct, and promptly agents use standard system prompt
                $agentData['system_prompt'] = $this->system_prompt;
                $agentData['workflow_config'] = null;
                $toolConfigs = $this->toolConfigs;
            }

            Log::info('Saving agent', [
                'isEditing' => $this->isEditing,
                'agent_name' => $this->name,
                'ai_provider' => $this->ai_provider,
                'ai_model' => $this->ai_model,
                'tool_count' => count($this->selectedTools),
            ]);

            if ($this->isEditing) {
                // Update existing agent
                $agent = $agentService->updateAgent($this->agent, $agentData, $toolConfigs);
                Log::info('Agent updated', ['agent_id' => $agent->id, 'agent_name' => $agent->name]);

                // Refresh the agent data from database to ensure we have the latest
                $this->agent = Agent::with(['tools'])->find($agent->id);

                $this->dispatch('success', "Agent '{$agent->name}' updated successfully.");
            } else {
                // Create new agent
                $agent = $agentService->createAgent($agentData, $toolConfigs, Auth::user());
                Log::info('Agent created', ['agent_id' => $agent->id, 'agent_name' => $agent->name]);

                // Set agent for possible further edits
                $this->agent = $agent;
                $this->isEditing = true;

                $this->dispatch('success', "Agent '{$agent->name}' created successfully.");
            }

            // Save knowledge assignments
            $this->saveKnowledgeAssignments($agent);

            // We're keeping the editor open now after save for better UX
            // Just dispatch that we saved the agent
            $this->dispatch('agent-saved', ['agent_id' => $agent->id]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation errors are automatically shown by Livewire
            Log::warning('Agent validation failed', ['errors' => $e->errors()]);
            $this->dispatch('error', 'Please check the form for errors.');
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to save agent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->dispatch('error', 'Failed to save agent: '.$e->getMessage());
        }
    }

    protected function generateWorkflowSystemPrompt()
    {
        $agentNames = array_column($this->workflow_agents, 'name');
        $agentCount = count($this->workflow_agents);

        return "You are a workflow orchestrator that coordinates {$agentCount} specialized AI agents: ".implode(', ', $agentNames).'. '.
               'You execute these agents in sequence, passing context and results between them to provide comprehensive responses. '.
               "Each agent has specialized capabilities that contribute to the overall workflow.\n\n".
               "You also have access to the following tools that you can use directly:\n\n{available_tools}\n\n".
               'Use these tools when appropriate to enhance your response quality.';
    }

    /**
     * Get available AI providers that are configured in the system
     */
    protected function getAvailableProvidersProperty(): array
    {
        $providers = [];

        // Check OpenAI
        if (! empty(config('prism.providers.openai.api_key'))) {
            $providers['openai'] = 'OpenAI';
        }

        // Check Anthropic
        if (! empty(config('prism.providers.anthropic.api_key'))) {
            $providers['anthropic'] = 'Anthropic';
        }

        // Check AWS Bedrock
        if (! empty(config('prism.providers.bedrock.api_key')) || config('prism.providers.bedrock.use_default_credential_provider')) {
            $providers['bedrock'] = 'AWS Bedrock';
        }

        // Check Google
        if (! empty(config('prism.providers.google.api_key'))) {
            $providers['google'] = 'Google';
        }

        // Check Mistral
        if (! empty(config('prism.providers.mistral.api_key'))) {
            $providers['mistral'] = 'Mistral';
        }

        return $providers;
    }

    /**
     * Get the most cost-effective model for a provider
     */
    protected function getCostEffectiveModel(?string $provider): string
    {
        // Use centralized model configuration for cost-effective defaults
        if (empty($provider)) {
            return app(ModelSelector::class)->getLowCostModel()['model'];
        }

        $models = $this->getAvailableModelsForProvider($provider);

        if (empty($models)) {
            // Use centralized model configuration as fallback
            $modelSelector = app(ModelSelector::class);
            $lowCostConfig = $modelSelector->getLowCostModel();

            return match ($provider) {
                'openai' => $lowCostConfig['provider'] === 'openai' ? $lowCostConfig['model'] : 'gpt-4o-mini',
                'anthropic' => $lowCostConfig['provider'] === 'anthropic' ? $lowCostConfig['model'] : 'claude-3-haiku-20240307',
                'bedrock' => $lowCostConfig['provider'] === 'bedrock' ? $lowCostConfig['model'] : 'anthropic.claude-3-haiku-20240307-v1:0',
                'google' => 'gemini-pro',
                'mistral' => 'mistral-small-latest',
                default => $lowCostConfig['model']
            };
        }

        // Check for known cost-effective models in order of preference
        $costEffectiveModels = [
            'openai' => ['gpt-3.5-turbo', 'gpt-4o-mini', 'gpt-4o'],
            'anthropic' => ['claude-3-haiku-20240307', 'claude-3-5-sonnet-20241022', 'claude-3-opus-20240229'],
            'bedrock' => ['anthropic.claude-3-haiku-20240307-v1:0', 'amazon.nova-micro-v1:0', 'anthropic.claude-3-5-sonnet-20241022-v2:0'],
            'google' => ['gemini-pro', 'gemini-pro-vision'],
            'mistral' => ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest'],
        ];

        $preferredModels = $costEffectiveModels[$provider] ?? [];

        // Return the first available preferred model
        foreach ($preferredModels as $modelId) {
            if (isset($models[$modelId])) {
                return $modelId;
            }
        }

        // If no preferred models are available, return the first model from the list
        return array_key_first($models) ?? app(ModelSelector::class)->getLowCostModel()['model'];
    }

    /**
     * Get available models for the current provider
     */
    public function getAvailableModelsProperty()
    {
        return $this->getAvailableModelsForProvider($this->ai_provider);
    }

    /**
     * Get available models for a specific provider
     * Attempts to fetch from API first, falls back to hardcoded defaults
     */
    protected function getAvailableModelsForProvider(?string $provider): array
    {
        // Handle null provider
        if (empty($provider)) {
            $fallbackModel = app(ModelSelector::class)->getLowCostModel()['model'];

            return [$fallbackModel => ucwords(str_replace('-', ' ', $fallbackModel))];
        }

        // Check if we have cached models for this provider
        $cacheKey = "agent_editor_models_{$provider}";
        $cachedModels = Cache::get($cacheKey);

        if ($cachedModels) {
            return $cachedModels;
        }

        // Try to fetch from API
        $apiModels = $this->fetchModelsFromProviderApi($provider);

        if (! empty($apiModels)) {
            // Cache the results
            Cache::put($cacheKey, $apiModels, now()->addSeconds($this->modelCacheExpiration));

            return $apiModels;
        }

        // Fallback to hardcoded defaults if API fetch fails
        $defaultModels = $this->getDefaultModelsForProvider($provider);

        // Cache the defaults too, but for a shorter time
        Cache::put($cacheKey, $defaultModels, now()->addMinutes(30));

        return $defaultModels;
    }

    /**
     * Fetch models from the provider's API
     */
    protected function fetchModelsFromProviderApi(string $provider): array
    {
        try {
            switch ($provider) {
                case 'openai':
                    return $this->fetchOpenAiModels();
                case 'anthropic':
                    return $this->fetchAnthropicModels();
                case 'bedrock':
                    return $this->fetchBedrockModels();
                case 'google':
                    return $this->fetchGoogleModels();
                case 'mistral':
                    return $this->fetchMistralModels();
                default:
                    return [];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch models from {$provider} API: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Fetch models from OpenAI API
     */
    protected function fetchOpenAiModels(): array
    {
        $apiKey = config('prism.providers.openai.api_key');
        if (empty($apiKey)) {
            return [];
        }

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])->get('https://api.openai.com/v1/models');

        if (! $response->successful()) {
            return [];
        }

        $models = $response->json()['data'] ?? [];

        // Filter for GPT models only and format them
        $formattedModels = [];
        foreach ($models as $model) {
            $id = $model['id'];
            // Only include GPT models that are meant for chat
            if (strpos($id, 'gpt') === 0 && ! strpos($id, 'instruct')) {
                // Create a nicer display name
                $displayName = strtoupper(str_replace('-', ' ', $id));
                $formattedModels[$id] = $displayName;
            }
        }

        // Sort by newest first (assuming higher version numbers are newer)
        arsort($formattedModels);

        return $formattedModels;
    }

    /**
     * Fetch models from Anthropic API
     */
    protected function fetchAnthropicModels(): array
    {
        // Anthropic doesn't provide a models listing API endpoint
        return $this->getDefaultModelsForProvider('anthropic');
    }

    /**
     * Fetch models from AWS Bedrock
     */
    protected function fetchBedrockModels(): array
    {
        try {
            // Get AWS credentials from config
            $region = config('prism.providers.bedrock.region', 'us-east-1');
            $useDefaultProvider = config('prism.providers.bedrock.use_default_credential_provider', false);

            // Build credentials array
            $credentials = [];
            if (! $useDefaultProvider) {
                $apiKey = config('prism.providers.bedrock.api_key');
                $apiSecret = config('prism.providers.bedrock.api_secret');
                $sessionToken = config('prism.providers.bedrock.session_token');

                if (empty($apiKey) || empty($apiSecret)) {
                    Log::debug('AWS Bedrock credentials not configured, using defaults');

                    return $this->getDefaultModelsForProvider('bedrock');
                }

                $credentials = [
                    'key' => $apiKey,
                    'secret' => $apiSecret,
                ];

                if (! empty($sessionToken)) {
                    $credentials['token'] = $sessionToken;
                }
            }

            // Create Bedrock client
            $clientConfig = [
                'region' => $region,
                'version' => 'latest',
            ];

            if (! empty($credentials)) {
                $clientConfig['credentials'] = $credentials;
            }

            $client = new BedrockClient($clientConfig);

            // List foundation models
            $result = $client->listFoundationModels([
                'byOutputModality' => 'TEXT', // Only text generation models
            ]);

            $models = [];
            $seenModelIds = []; // Track for debugging duplicates

            foreach ($result['modelSummaries'] as $modelSummary) {
                $modelId = $modelSummary['modelId'];

                // Filter to only include models suitable for text generation
                // (Claude, Nova, Titan Text, etc.)
                if (
                    str_starts_with($modelId, 'anthropic.claude') ||
                    str_starts_with($modelId, 'amazon.nova') ||
                    str_starts_with($modelId, 'amazon.titan-text')
                ) {
                    // Log if we've seen this model ID before (shouldn't happen)
                    if (isset($seenModelIds[$modelId])) {
                        Log::warning('Duplicate Bedrock model ID detected', [
                            'model_id' => $modelId,
                        ]);
                    }
                    $seenModelIds[$modelId] = true;

                    // Format model ID into readable display name with full version info
                    $displayName = $this->formatBedrockModelName($modelId);
                    $models[$modelId] = $displayName;

                    Log::debug('Bedrock model formatted', [
                        'model_id' => $modelId,
                        'display_name' => $displayName,
                    ]);
                }
            }

            // Sort models by name
            asort($models);

            Log::info('Successfully fetched Bedrock models', [
                'region' => $region,
                'model_count' => count($models),
            ]);

            return ! empty($models) ? $models : $this->getDefaultModelsForProvider('bedrock');

        } catch (AwsException $e) {
            Log::warning('Failed to fetch Bedrock models from AWS API', [
                'error' => $e->getMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);

            return $this->getDefaultModelsForProvider('bedrock');
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Bedrock models', [
                'error' => $e->getMessage(),
            ]);

            return $this->getDefaultModelsForProvider('bedrock');
        }
    }

    /**
     * Format Bedrock model ID into readable display name with full model ID
     */
    protected function formatBedrockModelName(string $modelId): string
    {
        // Remove provider prefix for parsing
        $name = preg_replace('/^(anthropic\.|amazon\.|cohere\.|meta\.|mistral\.)/', '', $modelId);

        // Extract base model name (everything before version/date identifiers)
        $baseName = '';

        if (str_contains($name, 'claude')) {
            // Extract Claude variant (haiku, sonnet, opus, instant, etc.)
            if (preg_match('/claude-(\d+(?:-\d+)?)-(\w+)/', $name, $matches) ||
                preg_match('/claude-(\w+)/', $name, $matches)) {
                $baseName = 'Claude';
                if (preg_match('/claude-(\d+)-(\d+)-(\w+)/', $name, $versionMatches)) {
                    $baseName .= " {$versionMatches[1]}.{$versionMatches[2]} ".ucfirst($versionMatches[3]);
                } elseif (preg_match('/claude-(\d+)-(\w+)/', $name, $versionMatches)) {
                    $baseName .= " {$versionMatches[1]} ".ucfirst($versionMatches[2]);
                } elseif (preg_match('/claude-v(\d+)/', $name, $versionMatches)) {
                    $baseName .= " v{$versionMatches[1]}";
                } elseif (preg_match('/claude-(\w+)/', $name, $versionMatches)) {
                    $baseName .= ' '.ucfirst($versionMatches[1]);
                }
            }
        } elseif (str_contains($name, 'nova')) {
            if (preg_match('/nova-(\w+)/', $name, $matches)) {
                $baseName = 'Nova '.ucfirst($matches[1]);
            }
        } elseif (str_contains($name, 'titan')) {
            if (preg_match('/titan-text-(\w+)/', $name, $matches)) {
                $baseName = 'Titan Text '.ucfirst($matches[1]);
            }
        }

        // If we couldn't parse it, use a generic format
        if (empty($baseName)) {
            $baseName = ucfirst(str_replace(['-', '_'], ' ', $name));
        }

        // Return base name with full model ID in parentheses for uniqueness
        return "{$baseName} ({$modelId})";
    }

    /**
     * Fetch models from Google API
     */
    protected function fetchGoogleModels(): array
    {
        // Google API doesn't provide a models listing endpoint
        return $this->getDefaultModelsForProvider('google');
    }

    /**
     * Fetch models from Mistral API
     */
    protected function fetchMistralModels(): array
    {
        $apiKey = config('prism.providers.mistral.api_key');
        if (empty($apiKey)) {
            return [];
        }

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])->get('https://api.mistral.ai/v1/models');

        if (! $response->successful()) {
            return [];
        }

        $models = $response->json()['data'] ?? [];

        // Format models
        $formattedModels = [];
        foreach ($models as $model) {
            $id = $model['id'];
            // Create a nicer display name
            $displayName = ucfirst(str_replace('-', ' ', $id));
            $formattedModels[$id] = $displayName;
        }

        return $formattedModels;
    }

    /**
     * Get default hardcoded models for a provider
     */
    protected function getDefaultModelsForProvider(string $provider): array
    {
        return match ($provider) {
            'openai' => [
                'gpt-4o' => 'GPT-4o',
                'gpt-4o-mini' => 'GPT-4o Mini',
                'gpt-4-turbo' => 'GPT-4 Turbo',
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            ],
            'anthropic' => [
                'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
                'claude-3-haiku-20240307' => 'Claude 3 Haiku',
                'claude-3-opus-20240229' => 'Claude 3 Opus',
            ],
            'bedrock' => [
                'anthropic.claude-3-5-sonnet-20241022-v2:0' => 'Claude 3.5 Sonnet v2 (Bedrock)',
                'anthropic.claude-3-5-sonnet-20240620-v1:0' => 'Claude 3.5 Sonnet (Bedrock)',
                'anthropic.claude-3-opus-20240229-v1:0' => 'Claude 3 Opus (Bedrock)',
                'anthropic.claude-3-sonnet-20240229-v1:0' => 'Claude 3 Sonnet (Bedrock)',
                'anthropic.claude-3-haiku-20240307-v1:0' => 'Claude 3 Haiku (Bedrock)',
                'amazon.nova-pro-v1:0' => 'AWS Nova Pro',
                'amazon.nova-lite-v1:0' => 'AWS Nova Lite',
                'amazon.nova-micro-v1:0' => 'AWS Nova Micro',
            ],
            'google' => [
                'gemini-pro' => 'Gemini Pro',
                'gemini-pro-vision' => 'Gemini Pro Vision',
            ],
            'mistral' => [
                'mistral-large-latest' => 'Mistral Large',
                'mistral-medium-latest' => 'Mistral Medium',
                'mistral-small-latest' => 'Mistral Small',
            ],
            default => []
        };
    }

    public function render()
    {
        return view('livewire.agent-editor', [
            'availableModels' => $this->availableModels,
            'availableProviders' => $this->getAvailableProvidersProperty(),
        ]);
    }
}
