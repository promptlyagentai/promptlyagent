<?php

namespace App\Livewire;

use App\Livewire\Traits\HasSessionManagement;
use App\Livewire\Traits\HasToolManagement;
use App\Models\Agent;
use App\Services\Agents\AgentService;
use App\Services\Agents\ToolRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Base class for chat interfaces with session and tool management.
 *
 * Provides common functionality for chat components:
 * - Session lifecycle (create, open, delete)
 * - Agent selection and management
 * - Tool configuration loading
 * - User preferences persistence
 *
 * Child classes must implement:
 * - sendMessage(): Handle message submission
 * - render(): Render the component view
 *
 * @property string $message Current message input
 * @property int|null $selectedAgentId Selected agent for execution
 * @property array<int, array{id: int, name: string, description: string, agent_type: string, ...}> $availableAgents
 */
abstract class BaseChatInterface extends Component
{
    use HasSessionManagement, HasToolManagement;

    public $message = '';

    public $isLoading = false;

    // Agent properties
    public $selectedAgentId = null;

    public $availableAgents = [];

    public $agentExecutionId = null;

    protected $rules = [
        'message' => 'required|string|max:2000',
    ];

    public function mount()
    {
        $this->loadSessions();
        $this->loadAvailableTools();
        $this->loadUserToolPreferences(); // Load user preferences after tools are loaded
        $this->loadAvailableAgents();

        if ($this->sessions->isEmpty()) {
            $this->createSession();
        } else {
            $lastActiveSessionId = $this->getLastActiveSessionId();
            if ($lastActiveSessionId && $this->sessions->contains('id', $lastActiveSessionId)) {
                $this->currentSessionId = $lastActiveSessionId;
            } else {
                $this->currentSessionId = $this->sessions->first()->id;
            }
            $this->loadInteractions();
        }
    }

    protected function getLastActiveSessionId(): ?int
    {
        return session('last_active_session_id');
    }

    public function setLastActiveSession($sessionId)
    {
        session(['last_active_session_id' => $sessionId]);
    }

    protected function ensureSessionContext(): void
    {
        if ($this->currentSessionId) {
            \App\Services\ToolStatusReporter::setGlobalSessionContext($this->currentSessionId);
        }
    }

    protected function getEnabledToolNamesForDisplay(): array
    {
        $enabledToolNames = [];
        foreach ($this->availableTools as $tool) {
            if ($tool['source'] === 'local' && in_array($tool['name'], $this->enabledTools)) {
                $enabledToolNames[] = $tool['name'];
            } elseif ($tool['source'] !== 'local' && in_array($tool['source'], $this->enabledServers)) {
                $enabledToolNames[] = $tool['name'];
            }
        }

        return $enabledToolNames;
    }

    protected function loadAvailableAgents(): void
    {
        if (! Auth::check()) {
            $this->availableAgents = [];

            return;
        }

        $agentService = new AgentService(app(ToolRegistry::class));
        $agents = $agentService->getAvailableAgentsForUser(Auth::user());

        $this->availableAgents = $agents->map(function ($agent) {
            $agentType = $agent->agent_type ?? 'individual';

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'agent_type' => $agentType,
                'tool_count' => $agentType === 'individual' ? $agent->enabledTools->count() : 0,
                'workflow_agent_count' => $agentType === 'workflow' ? count($agent->workflow_config['agents'] ?? []) : 0,
            ];
        })->toArray();
    }

    /**
     * Select an agent for the next message
     *
     * Validates that the user has permission to use the selected agent
     * before allowing selection to prevent unauthorized access to private agents.
     */
    public function selectAgent($agentId = null): void
    {
        // Allow deselecting agent
        if ($agentId === null) {
            $this->selectedAgentId = null;

            return;
        }

        // Verify agent exists and user has permission to use it
        if (! $this->canSelectAgent($agentId)) {
            $this->addError('agent', 'You do not have permission to use this agent.');

            return;
        }

        $this->selectedAgentId = $agentId;
    }

    /**
     * Check if user can select a specific agent
     *
     * Validates against the list of available agents which already
     * filters by user ownership and public/private status.
     */
    protected function canSelectAgent(int $agentId): bool
    {
        return collect($this->availableAgents)->contains('id', $agentId);
    }

    /**
     * Get the selected agent instance
     */
    protected function getSelectedAgent(): ?Agent
    {
        if (! $this->selectedAgentId) {
            return null;
        }

        return Agent::with(['enabledTools'])->find($this->selectedAgentId);
    }

    /**
     * Check if an agent is selected
     */
    public function isAgentMode(): bool
    {
        return $this->selectedAgentId !== null;
    }

    /**
     * Get the display name for the current mode
     */
    public function getCurrentModeName(): string
    {
        if ($this->isAgentMode()) {
            $agent = collect($this->availableAgents)->firstWhere('id', $this->selectedAgentId);

            return $agent ? $agent['name'] : 'Agent';
        }

        return 'Chat';
    }

    /**
     * Abstract method that child classes must implement
     */
    abstract public function sendMessage();

    /**
     * Abstract method for rendering the component
     */
    abstract public function render();
}
