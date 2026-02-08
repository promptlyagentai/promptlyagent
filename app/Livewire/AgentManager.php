<?php

namespace App\Livewire;

use App\Models\Agent;
use App\Models\User;
use App\Services\Agents\AgentService;
use App\Services\Agents\ToolRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Agent management interface for CRUD operations.
 *
 * Features:
 * - Agent listing with filters (status, type, ownership)
 * - Agent duplication
 * - Agent activation/deactivation
 * - Agent deletion
 * - Integrated with AgentEditor for create/edit
 *
 * @property string $search Search query for agent names
 * @property bool $showOnlyMyAgents Filter to show only user's agents
 * @property string $selectedStatus Filter by agent status (active|inactive|all)
 * @property string $selectedAgentType Filter by agent type (individual|workflow|all)
 */
class AgentManager extends Component
{
    use WithPagination;

    public $search = '';

    public $showOnlyMyAgents = true;

    public $selectedStatus = 'all';

    public $selectedAgentType = 'all';

    public $showCreateModal = false;

    public $editingAgent = null;

    protected $listeners = [
        'agent-saved' => '$refresh',
        'closeAgentEditor' => 'closeModal',
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'showOnlyMyAgents' => ['except' => true],
        'selectedStatus' => ['except' => 'all'],
        'selectedAgentType' => ['except' => 'all'],
    ];

    public function mount()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingShowOnlyMyAgents()
    {
        $this->resetPage();
    }

    public function updatingSelectedStatus()
    {
        $this->resetPage();
    }

    public function updatingSelectedAgentType()
    {
        $this->resetPage();
    }

    public function createAgent()
    {
        $this->reset(['editingAgent']);
        $this->showCreateModal = true;
    }

    public function editAgent(Agent $agent)
    {
        // Check permissions
        if (! $this->canEditAgent($agent)) {
            $this->dispatch('error', 'You do not have permission to edit this agent.');

            return;
        }

        $this->editingAgent = $agent;
        $this->showCreateModal = true;
        $this->dispatch('openAgentEditor', $agent->id);
    }

    public function duplicateAgent(Agent $agent)
    {
        try {
            $agentService = new AgentService(app(ToolRegistry::class));
            $newAgent = $agentService->duplicateAgent($agent, Auth::user());

            $this->dispatch('success', "Agent '{$newAgent->name}' created as a duplicate.");
            $this->resetPage();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to duplicate agent: '.$e->getMessage());
        }
    }

    public function toggleAgentStatus(Agent $agent)
    {
        // Check permissions
        if (! $this->canEditAgent($agent)) {
            $this->dispatch('error', 'You do not have permission to modify this agent.');

            return;
        }

        try {
            $newStatus = $agent->status === 'active' ? 'inactive' : 'active';
            $agent->update(['status' => $newStatus]);

            $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';
            $this->dispatch('success', "Agent '{$agent->name}' has been {$statusText}.");

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update agent status: '.$e->getMessage());
        }
    }

    public function deleteAgent(Agent $agent)
    {
        // Check permissions
        if (! $this->canDeleteAgent($agent)) {
            $this->dispatch('error', 'You do not have permission to delete this agent.');

            return;
        }

        try {
            $agentName = $agent->name;
            $agent->delete();

            $this->dispatch('success', "Agent '{$agentName}' has been deleted.");
            $this->resetPage();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete agent: '.$e->getMessage());
        }
    }

    public function closeModal()
    {
        $this->showCreateModal = false;
        $this->editingAgent = null;
    }

    protected function canEditAgent(Agent $agent): bool
    {
        $user = Auth::user();

        // Admin users can edit any agent
        if ($user->is_admin) {
            return true;
        }

        // Owner can always edit
        if ($agent->created_by === $user->id) {
            return true;
        }

        return false;
    }

    protected function canDeleteAgent(Agent $agent): bool
    {
        return $this->canEditAgent($agent);
    }

    protected function getAgentsQuery()
    {
        $query = Agent::with(['creator', 'tools', 'executions'])
            ->withCount(['tools', 'executions']);

        // Search filter
        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('description', 'like', '%'.$this->search.'%');
            });
        }

        // Owner filter
        if ($this->showOnlyMyAgents) {
            $query->where('created_by', Auth::id());
        } else {
            // Show public agents, user's own agents, and all agents for admins
            $query->where(function ($q) {
                $q->where('is_public', true)
                    ->orWhere('created_by', Auth::id());

                // Admin users can see all agents
                if (Auth::user()->is_admin) {
                    $q->orWhereNotNull('id'); // Include all agents
                }
            });
        }

        // Status filter
        if ($this->selectedStatus !== 'all') {
            $query->where('status', $this->selectedStatus);
        }

        // Agent type filter
        if ($this->selectedAgentType !== 'all') {
            $query->where('agent_type', $this->selectedAgentType);
        }

        return $query->orderBy('updated_at', 'desc');
    }

    public function render()
    {
        $agents = $this->getAgentsQuery()->paginate(10);

        return view('livewire.agent-manager', [
            'agents' => $agents,
        ])->layout('components.layouts.app', [
            'title' => 'Agent Manager',
        ]);
    }
}
