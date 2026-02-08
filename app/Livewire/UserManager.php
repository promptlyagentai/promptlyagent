<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * User management interface for CRUD operations (admin-only).
 *
 * Features:
 * - User listing with filters (role, status)
 * - User creation and editing
 * - Admin status toggling
 * - Account suspension/activation
 * - User deletion
 * - Integrated with UserEditor for create/edit
 *
 * @property string $search Search query for user names/emails
 * @property string $selectedRole Filter by role (all|admin|user)
 * @property string $selectedStatus Filter by account status (all|active|suspended)
 * @property bool $showCreateModal Whether to show the user editor modal
 * @property User|null $editingUser User being edited
 */
class UserManager extends Component
{
    use WithPagination;

    public $search = '';

    public $selectedRole = 'all';

    public $selectedStatus = 'all';

    public $showCreateModal = false;

    public $editingUser = null;

    protected $listeners = [
        'user-saved' => '$refresh',
        'closeUserEditor' => 'closeModal',
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'selectedRole' => ['except' => 'all'],
        'selectedStatus' => ['except' => 'all'],
    ];

    public function mount()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        // Admin-only access
        if (! Gate::allows('viewAny', User::class)) {
            abort(403, 'Unauthorized access to user management.');
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingSelectedRole()
    {
        $this->resetPage();
    }

    public function updatingSelectedStatus()
    {
        $this->resetPage();
    }

    public function createUser()
    {
        if (! Gate::allows('create', User::class)) {
            $this->dispatch('error', 'You do not have permission to create users.');

            return;
        }

        $this->reset(['editingUser']);
        $this->showCreateModal = true;
    }

    public function editUser(User $user)
    {
        if (! Gate::allows('update', $user)) {
            $this->dispatch('error', 'You do not have permission to edit this user.');

            return;
        }

        $this->editingUser = $user;
        $this->showCreateModal = true;
    }

    public function toggleAdminStatus(User $user)
    {
        if (! Gate::allows('update', $user)) {
            $this->dispatch('error', 'You do not have permission to modify this user.');

            return;
        }

        // Prevent removing own admin status
        if ($user->id === Auth::id()) {
            $this->dispatch('error', 'You cannot remove your own admin status.');

            return;
        }

        try {
            $newStatus = ! $user->is_admin;
            $user->update(['is_admin' => $newStatus]);

            $statusText = $newStatus ? 'granted admin access' : 'removed admin access';
            $this->dispatch('success', "User '{$user->name}' has been {$statusText}.");
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update admin status: '.$e->getMessage());
        }
    }

    public function suspendUser(User $user)
    {
        if (! Gate::allows('update', $user)) {
            $this->dispatch('error', 'You do not have permission to suspend this user.');

            return;
        }

        // Prevent suspending self
        if ($user->id === Auth::id()) {
            $this->dispatch('error', 'You cannot suspend your own account.');

            return;
        }

        try {
            $user->update(['account_status' => 'suspended']);
            $this->dispatch('success', "User '{$user->name}' has been suspended.");
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to suspend user: '.$e->getMessage());
        }
    }

    public function activateUser(User $user)
    {
        if (! Gate::allows('update', $user)) {
            $this->dispatch('error', 'You do not have permission to activate this user.');

            return;
        }

        try {
            $user->update(['account_status' => 'active']);
            $this->dispatch('success', "User '{$user->name}' has been activated.");
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to activate user: '.$e->getMessage());
        }
    }

    public function deleteUser(User $user)
    {
        if (! Gate::allows('delete', $user)) {
            $this->dispatch('error', 'You do not have permission to delete this user.');

            return;
        }

        try {
            $userName = $user->name;
            $user->delete();

            $this->dispatch('success', "User '{$userName}' has been deleted.");
            $this->resetPage();
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete user: '.$e->getMessage());
        }
    }

    public function closeModal()
    {
        $this->showCreateModal = false;
        $this->editingUser = null;
    }

    protected function getUsersQuery()
    {
        $query = User::query();

        // Search filter
        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        // Role filter
        if ($this->selectedRole === 'admin') {
            $query->where('is_admin', true);
        } elseif ($this->selectedRole === 'user') {
            $query->where('is_admin', false);
        }

        // Status filter
        if ($this->selectedStatus !== 'all') {
            $query->where('account_status', $this->selectedStatus);
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function render()
    {
        $users = $this->getUsersQuery()->paginate(10);

        return view('livewire.user-manager', [
            'users' => $users,
        ])->layout('components.layouts.app', [
            'title' => 'User Management',
        ]);
    }
}
