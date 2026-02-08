{{--
    User Manager

    Admin-only interface for managing application users with CRUD operations, search, and filtering.
--}}
<div class="rounded-xl border border-default bg-surface p-6 flex flex-col gap-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">User Management</flux:heading>
            <flux:subheading>Manage user accounts, permissions, and access.</flux:subheading>
        </div>
        <flux:button wire:click="createUser" type="button" variant="primary" icon="plus">
            Create User
        </flux:button>
    </div>

    <!-- Filters -->
    <div class="rounded-xl border border-default bg-surface p-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search Users"
                    placeholder="Search by name or email..."
                    icon="magnifying-glass" />
            </div>
            <div>
                <flux:field>
                    <flux:label>Role</flux:label>
                    <select wire:model.live="selectedRole" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Roles</option>
                        <option value="admin">Admins</option>
                        <option value="user">Users</option>
                    </select>
                </flux:field>
            </div>
            <div>
                <flux:field>
                    <flux:label>Status</flux:label>
                    <select wire:model.live="selectedStatus" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </flux:field>
            </div>
        </div>
    </div>

    <!-- User List -->
    @if($users->count() > 0)
        <div class="rounded-xl border border-default bg-surface p-6">
            @foreach($users as $user)
                <div class="flex items-center justify-between p-4 {{ !$loop->last ? 'border-b border-default' : '' }}">
                    <div class="flex items-center space-x-4 flex-1 min-w-0">
                        <!-- Avatar -->
                        <div class="flex-shrink-0">
                            <flux:avatar
                                size="lg"
                                :initials="$user->initials()" />
                        </div>

                        <!-- User Details -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                                <flux:heading size="sm" class="truncate">{{ $user->name }}</flux:heading>

                                <!-- Admin Badge -->
                                @if($user->is_admin)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-warning-300)] text-[var(--palette-warning-950)]">
                                        <flux:icon.shield-check class="w-3 h-3 mr-1" />
                                        Admin
                                    </span>
                                @endif

                                <!-- Current User Badge -->
                                @if($user->id === auth()->id())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-300)] text-[var(--palette-notify-950)]">
                                        You
                                    </span>
                                @endif

                                <!-- Account Status Badge -->
                                @if($user->account_status === 'active')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-success-300)] text-[var(--palette-success-950)]">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-error-300)] text-[var(--palette-error-950)]">
                                        Suspended
                                    </span>
                                @endif
                            </div>

                            <flux:subheading class="mt-1">{{ $user->email }}</flux:subheading>

                            <div class="mt-2 flex items-center space-x-4 flex-wrap">
                                <flux:text size="xs">Joined {{ $user->created_at->diffForHumans() }}</flux:text>
                                @if($user->email_verified_at)
                                    <flux:text size="xs">Email verified</flux:text>
                                @else
                                    <flux:text size="xs" class="text-[var(--palette-warning-600)]">Email not verified</flux:text>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center space-x-2 ml-4">
                        <!-- Edit -->
                        <flux:button
                            wire:click="editUser({{ $user->id }})"
                            variant="ghost"
                            size="sm"
                            icon="pencil">
                            Edit
                        </flux:button>

                        <!-- Toggle Admin (not for self) -->
                        @if($user->id !== auth()->id())
                            <flux:button
                                wire:click="toggleAdminStatus({{ $user->id }})"
                                variant="ghost"
                                size="sm"
                                icon="shield-check">
                                {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
                            </flux:button>
                        @endif

                        <!-- Suspend/Activate (not for self) -->
                        @if($user->id !== auth()->id())
                            @if($user->account_status === 'active')
                                <flux:button
                                    wire:click="suspendUser({{ $user->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="no-symbol">
                                    Suspend
                                </flux:button>
                            @else
                                <flux:button
                                    wire:click="activateUser({{ $user->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="check-circle">
                                    Activate
                                </flux:button>
                            @endif
                        @endif

                        <!-- Impersonate (only for non-admin, active users) -->
                        @if($user->id !== auth()->id() && !$user->is_admin && $user->account_status === 'active' && !auth()->user()->isImpersonated())
                            <flux:button
                                href="{{ route('impersonate', $user->id) }}"
                                variant="ghost"
                                size="sm"
                                icon="user">
                                Impersonate
                            </flux:button>
                        @endif

                        <!-- Delete (not for self) -->
                        @if($user->id !== auth()->id())
                            <flux:button
                                wire:click="deleteUser({{ $user->id }})"
                                onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-error hover:text-error">
                                Delete
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        @if($users->hasPages())
            <div class="mt-6">
                {{ $users->links() }}
            </div>
        @endif
    @else
        <!-- Empty State -->
        <div class="rounded-xl border border-default bg-surface text-center py-12">
            <div class="flex flex-col items-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center">
                    <flux:icon.users class="h-12 w-12 text-tertiary" />
                </div>
                <flux:heading size="lg" class="mt-4">No users found</flux:heading>
                <flux:subheading class="mt-2">
                    {{ $search || $selectedRole !== 'all' || $selectedStatus !== 'all'
                        ? 'Try adjusting your filters to find users.'
                        : 'Get started by creating your first user.' }}
                </flux:subheading>
                @if(!$search && $selectedRole === 'all' && $selectedStatus === 'all')
                    <div class="mt-6">
                        <flux:button wire:click="createUser" type="button" variant="primary" icon="plus">
                            Create User
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- User Editor Modal -->
    @if($showCreateModal)
        @if($editingUser)
            <livewire:user-editor :user="$editingUser" wire:key="user-editor-edit-{{ $editingUser->id }}" />
        @else
            <livewire:user-editor wire:key="user-editor-create" />
        @endif
    @endif
</div>

@script
<script>
    // Listen for editor events
    $wire.on('user-saved', () => {
        // Refresh the users list
        $wire.$refresh();
    });

    // Show success/error messages
    $wire.on('success', (message) => {
        console.log('Success:', message);
    });

    $wire.on('error', (message) => {
        console.error('Error:', message);
    });
</script>
@endscript
