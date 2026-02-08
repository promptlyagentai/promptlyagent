{{--
    User Editor Modal Component

    Purpose: Create and edit user accounts with permissions and status

    Features:
    - User creation with password
    - User editing (no password change by admin)
    - Admin status toggle
    - Account status management (active/suspended)

    Livewire Properties:
    - @property bool $showModal Controls modal visibility
    - @property bool $isEditing Whether editing existing user
    - @property string $name User's full name
    - @property string $email User's email address
    - @property string $password Password (required on create only)
    - @property bool $is_admin Admin status
    - @property string $account_status Account status (active/suspended)
--}}
<div>
    @if($showModal)
        <flux:modal wire:model.live="showModal" class="w-[600px]">
            <form wire:submit.prevent="save">
                {{-- Modal Header --}}
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <flux:heading>
                            {{ $isEditing ? 'Edit User' : 'Create New User' }}
                        </flux:heading>
                        <flux:subheading>
                            {{ $isEditing ? 'Update user information and permissions' : 'Add a new user to the system' }}
                        </flux:subheading>
                    </div>
                </div>

                <!-- User Information -->
                <div class="space-y-6">
                    <!-- Name -->
                    <flux:input
                        wire:model.blur="name"
                        label="Full Name"
                        placeholder="John Doe"
                        required
                        error="{{ $errors->first('name') }}" />

                    <!-- Email -->
                    <flux:input
                        wire:model.blur="email"
                        label="Email Address"
                        type="email"
                        placeholder="john@example.com"
                        required
                        error="{{ $errors->first('email') }}" />

                    @if(!$isEditing)
                        <!-- Password (only when creating) -->
                        <flux:input
                            wire:model.blur="password"
                            label="Password"
                            type="password"
                            placeholder="Minimum 8 characters"
                            required
                            error="{{ $errors->first('password') }}" />

                        <!-- Password Confirmation -->
                        <flux:input
                            wire:model.blur="password_confirmation"
                            label="Confirm Password"
                            type="password"
                            placeholder="Confirm password"
                            required
                            error="{{ $errors->first('password_confirmation') }}" />
                    @else
                        <!-- Info message for editing -->
                        <div class="rounded-lg border border-default bg-surface p-4">
                            <flux:text size="sm" class="text-secondary">
                                Password changes are not available through admin panel. Users can change their own passwords via profile settings.
                            </flux:text>
                        </div>
                    @endif

                    <!-- Admin Status -->
                    <flux:field>
                        <flux:checkbox wire:model="is_admin" label="Administrator" />
                        <flux:description>
                            Administrators have full access to all system features including user management.
                        </flux:description>
                    </flux:field>

                    <!-- Account Status -->
                    <flux:field>
                        <flux:label>Account Status</flux:label>
                        <select wire:model="account_status" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                        <flux:description>
                            Suspended users cannot log in to the system.
                        </flux:description>
                        @error('account_status')
                            <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>
                </div>

                <!-- Footer -->
                <div class="flex justify-end space-x-3 pt-6 border-t border-default mt-6">
                    <flux:button type="button" wire:click="closeEditor" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>
                            {{ $isEditing ? 'Update User' : 'Create User' }}
                        </span>
                        <span wire:loading wire:target="save">
                            Saving...
                        </span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>

@script
<script>
    // Listen for open/close events
    $wire.on('openUserEditor', (userId) => {
        // Modal will open automatically via showModal property
    });

    $wire.on('closeUserEditor', () => {
        // Modal will close automatically via showModal property
    });
</script>
@endscript
