<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * User editor modal for creating and editing users.
 *
 * Features:
 * - Create new users with password
 * - Edit existing users (no password change by admin per user preference)
 * - Admin status toggle
 * - Account status management (active/suspended)
 *
 * @property User|null $user User being edited
 * @property bool $isEditing Whether editing existing user or creating new
 * @property bool $showModal Whether modal is visible
 * @property string $name User name
 * @property string $email User email
 * @property string|null $password New password (required on create only)
 * @property string|null $password_confirmation Password confirmation
 * @property bool $is_admin Admin status
 * @property string $account_status Account status (active|suspended)
 */
class UserEditor extends Component
{
    public $user;

    public $isEditing = false;

    public $showModal = false;

    // User properties
    public $name = '';

    public $email = '';

    public $password = '';

    public $password_confirmation = '';

    public $is_admin = false;

    public $account_status = 'active';

    protected $listeners = [
        'openUserEditor' => 'openEditor',
        'closeUserEditor' => 'closeEditor',
    ];

    public function mount($user = null)
    {
        if ($user) {
            $this->user = $user;
            if (! Gate::allows('update', $this->user)) {
                $this->dispatch('error', 'You do not have permission to edit this user.');

                return;
            }
            $this->isEditing = true;
            $this->loadUserData();
            $this->showModal = true;
        } else {
            if (! Gate::allows('create', User::class)) {
                $this->dispatch('error', 'You do not have permission to create users.');

                return;
            }
            $this->isEditing = false;
            $this->resetForm();
            $this->showModal = true;
        }
    }

    public function openEditor($userId = null)
    {
        if ($userId) {
            $this->user = User::find($userId);
            if ($this->user) {
                if (! Gate::allows('update', $this->user)) {
                    $this->dispatch('error', 'You do not have permission to edit this user.');

                    return;
                }
                $this->isEditing = true;
                $this->loadUserData();
            }
        } else {
            if (! Gate::allows('create', User::class)) {
                $this->dispatch('error', 'You do not have permission to create users.');

                return;
            }
            $this->isEditing = false;
            $this->resetForm();
        }

        $this->showModal = true;
    }

    public function closeEditor()
    {
        $this->showModal = false;
        $this->resetForm();
        $this->resetValidation();
    }

    protected function loadUserData()
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->is_admin = $this->user->is_admin ?? false;
        $this->account_status = $this->user->account_status ?? 'active';
        // Never load password
        $this->password = '';
        $this->password_confirmation = '';
    }

    protected function resetForm()
    {
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->is_admin = false;
        $this->account_status = 'active';
        $this->user = null;
    }

    protected function rules()
    {
        $rules = [
            'name' => 'required|string|min:2|max:255',
            'is_admin' => 'boolean',
            'account_status' => 'required|in:active,suspended',
        ];

        if ($this->isEditing) {
            // Email must be unique except for current user
            $rules['email'] = 'required|email|max:255|unique:users,email,'.$this->user->id;
            // Password is optional when editing (admins don't change user passwords)
            // Users change their own passwords via profile settings
        } else {
            // Email must be unique
            $rules['email'] = 'required|email|max:255|unique:users,email';
            // Password is required when creating
            $rules['password'] = 'required|string|min:8|confirmed';
        }

        return $rules;
    }

    public function save()
    {
        try {
            // Run validation
            $validatedData = $this->validate();

            if ($this->isEditing) {
                // Update existing user
                if (! Gate::allows('update', $this->user)) {
                    $this->dispatch('error', 'You do not have permission to update this user.');

                    return;
                }

                $userData = [
                    'name' => $this->name,
                    'email' => $this->email,
                    'is_admin' => $this->is_admin,
                    'account_status' => $this->account_status,
                ];

                // Admins do not change user passwords per user preference
                // Users change their own passwords via profile settings

                $this->user->update($userData);

                $this->dispatch('success', "User '{$this->user->name}' has been updated.");
            } else {
                // Create new user
                if (! Gate::allows('create', User::class)) {
                    $this->dispatch('error', 'You do not have permission to create users.');

                    return;
                }

                $userData = [
                    'name' => $this->name,
                    'email' => $this->email,
                    'password' => Hash::make($this->password),
                    'is_admin' => $this->is_admin,
                    'account_status' => $this->account_status,
                ];

                $user = User::create($userData);

                $this->dispatch('success', "User '{$user->name}' has been created.");
            }

            $this->dispatch('user-saved');
            $this->closeEditor();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation errors are automatically handled by Livewire
            throw $e;
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to save user: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.user-editor');
    }
}
