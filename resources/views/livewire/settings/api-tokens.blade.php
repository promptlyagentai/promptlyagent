{{--
    API Tokens Settings Page

    Purpose: Create and manage API tokens for programmatic access to PromptlyAgent

    Features:
    - Token creation with custom abilities/scopes
    - Scope selection by category (Chat, Triggers, Knowledge, Agents)
    - One-time plain text token display (security)
    - Token revocation
    - Usage tracking (created, last used dates)
    - API documentation and examples

    Livewire Component Properties:
    - @property string $tokenName Descriptive name for token
    - @property array $selectedAbilities Array of scope strings (e.g., ['trigger:invoke', 'chat:create'])
    - @property string|null $newTokenPlainText Plain text token (shown once after creation)

    Token Scopes (from ScopeRegistry):
    - Chat: chat:create, chat:view, chat:interact
    - Triggers: trigger:invoke, trigger:view
    - Knowledge: knowledge:view, knowledge:search
    - Agents: agent:view, agent:execute

    Livewire Component Methods:
    - createToken(): Validate and create new token with selected abilities
    - revokeToken(id): Delete specified token
    - closeNewTokenModal(): Clear new token display
    - with(): Provide tokens and scopes to view

    Security:
    - Plain text token shown ONLY on creation
    - Tokens stored as hashed values (Laravel Sanctum)
    - Cannot retrieve plain text after creation
    - Revocation is permanent

    Usage Example:
    curl -X POST {{ url('/api/v1/triggers/{uuid}') }} \
      -H "Authorization: Bearer YOUR_API_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"input": "Your query"}'

    Events:
    - token-created: Dispatched when token created
    - token-revoked: Dispatched when token revoked

    Related:
    - App\Services\ApiToken\ScopeRegistry: Scope definitions and descriptions
    - Laravel Sanctum: Token authentication system
--}}
<?php

use App\Services\ApiToken\ScopeRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public string $tokenName = '';
    public array $selectedAbilities = [];
    public ?string $newTokenPlainText = null;

    /**
     * Create a new API token
     */
    public function createToken(): void
    {
        $scopeRegistry = app(ScopeRegistry::class);
        $validScopes = implode(',', $scopeRegistry->getAllKeys());

        $validated = $this->validate([
            'tokenName' => ['required', 'string', 'max:255'],
            'selectedAbilities' => ['required', 'array', 'min:1'],
            'selectedAbilities.*' => ['string', 'in:' . $validScopes],
        ], [
            'tokenName.required' => 'Please provide a name for this token',
            'selectedAbilities.required' => 'Please select at least one ability',
            'selectedAbilities.min' => 'Please select at least one ability',
        ]);

        $token = Auth::user()->createToken(
            $validated['tokenName'],
            $validated['selectedAbilities']
        );

        $this->newTokenPlainText = $token->plainTextToken;

        // Reset form
        $this->tokenName = '';
        $this->selectedAbilities = [];

        $this->dispatch('token-created');
    }

    /**
     * Revoke an API token
     */
    public function revokeToken(int $tokenId): void
    {
        $token = Auth::user()->tokens()->where('id', $tokenId)->first();

        if ($token) {
            $token->delete();
            $this->dispatch('token-revoked');
        }
    }

    /**
     * Close the new token modal
     */
    public function closeNewTokenModal(): void
    {
        $this->newTokenPlainText = null;
    }

    /**
     * Get all user's tokens and available scopes
     */
    public function with(): array
    {
        $scopeRegistry = app(ScopeRegistry::class);

        return [
            'tokens' => Auth::user()->tokens()->orderBy('created_at', 'desc')->get(),
            'scopesByCategory' => $scopeRegistry->getByCategory(),
            'allScopes' => $scopeRegistry->getAll(),
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('API Tokens')" :subheading="__('Manage API tokens for programmatic access to your agents')">
        <!-- Create New Token Form -->
        <div class="my-6 w-full space-y-6">
            <flux:heading size="lg">Create New Token</flux:heading>
            <flux:subheading>Generate a new API token with specific abilities for invoking input triggers</flux:subheading>

            <form wire:submit="createToken" class="space-y-4">
                <flux:input
                    wire:model="tokenName"
                    label="Token Name"
                    type="text"
                    required
                    placeholder="My API Token"
                    description="A descriptive name to help you identify this token" />

                <div>
                    <flux:fieldset>
                        <flux:legend>Token Abilities</flux:legend>
                        <flux:description>Select the permissions this token will have</flux:description>

                        <div class="mt-3 mb-3 flex gap-2">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                wire:click="$set('selectedAbilities', {{ json_encode(array_keys($allScopes)) }})">
                                Check All
                            </flux:button>
                            <flux:button
                                type="button"
                                size="sm"
                                variant="outline"
                                wire:click="$set('selectedAbilities', [])">
                                Uncheck All
                            </flux:button>
                        </div>

                        <div class="mt-3 space-y-6">
                            @foreach($scopesByCategory as $category => $scopes)
                                <div>
                                    <div class="mb-2 text-sm font-medium text-primary ">
                                        {{ $category }}
                                    </div>
                                    <div class="space-y-2">
                                        @foreach($scopes as $ability => $description)
                                            <flux:checkbox
                                                wire:model="selectedAbilities"
                                                value="{{ $ability }}"
                                                label="{{ ucfirst(str_replace(':', ' - ', $ability)) }}"
                                                description="{{ $description }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:fieldset>

                    @error('selectedAbilities')
                        <flux:text variant="danger" class="mt-2">{{ $message }}</flux:text>
                    @enderror
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">Generate Token</flux:button>

                    <x-action-message class="me-3" on="token-created">
                        Token created!
                    </x-action-message>
                </div>
            </form>
        </div>

        <!-- New Token Display Modal -->
        @if($newTokenPlainText)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" wire:click="closeNewTokenModal">
                <div class="bg-surface  rounded-lg p-6 max-w-2xl w-full mx-4" wire:click.stop>
                    <flux:heading size="lg">Token Created Successfully</flux:heading>
                    <flux:subheading class="mt-2">
                        Please copy your new API token. For security reasons, it won't be shown again.
                    </flux:subheading>

                    <div class="mt-4 p-4 bg-surface  rounded-lg font-mono text-sm break-all">
                        {{ $newTokenPlainText }}
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <flux:button
                            variant="primary"
                            onclick="navigator.clipboard.writeText('{{ $newTokenPlainText }}'); this.textContent = 'Copied!'; setTimeout(() => this.textContent = 'Copy to Clipboard', 2000)">
                            Copy to Clipboard
                        </flux:button>
                        <flux:button wire:click="closeNewTokenModal">Close</flux:button>
                    </div>

                    <flux:text class="mt-4 text-[var(--palette-warning-700)]">
                        ⚠️ Make sure to copy your token now. You won't be able to see it again!
                    </flux:text>
                </div>
            </div>
        @endif

        <!-- Existing Tokens List -->
        <div class="mt-10">
            <flux:heading size="lg">Active Tokens</flux:heading>
            <flux:subheading>Manage your existing API tokens</flux:subheading>

            @if($tokens->count() > 0)
                <div class="mt-4 space-y-3">
                    @foreach($tokens as $token)
                        <div class="flex items-center justify-between p-4 border border-default  rounded-lg bg-surface ">
                            <div class="flex-1">
                                <div class="font-medium text-primary ">
                                    {{ $token->name }}
                                </div>
                                <div class="text-sm text-tertiary  mt-1">
                                    <span>Created {{ $token->created_at->diffForHumans() }}</span>
                                    @if($token->last_used_at)
                                        <span class="ml-3">Last used {{ $token->last_used_at->diffForHumans() }}</span>
                                    @else
                                        <span class="ml-3">Never used</span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($token->abilities as $ability)
                                        <flux:badge size="sm" color="blue">{{ $ability }}</flux:badge>
                                    @endforeach
                                </div>
                            </div>

                            <flux:button
                                variant="danger"
                                size="sm"
                                wire:click="revokeToken({{ $token->id }})"
                                wire:confirm="Are you sure you want to revoke this token? This action cannot be undone.">
                                Revoke
                            </flux:button>
                        </div>
                    @endforeach
                </div>

                <x-action-message class="mt-4" on="token-revoked">
                    Token revoked successfully.
                </x-action-message>
            @else
                <div class="mt-4 p-6 border border-default  rounded-lg bg-surface /50 text-center">
                    <flux:text class="text-tertiary ">
                        No API tokens yet. Create your first token above to get started.
                    </flux:text>
                </div>
            @endif
        </div>

        <!-- Documentation Section -->
        <div class="mt-10 p-6 border border-[var(--palette-notify-200)] rounded-lg bg-[var(--palette-notify-100)]">
            <flux:heading size="lg">Using Your API Token</flux:heading>

            <div class="mt-4 space-y-3">
                <div>
                    <flux:text class="font-medium text-primary ">Authentication</flux:text>
                    <flux:text class="text-sm text-tertiary  mt-1">
                        Include your token in the Authorization header of your API requests:
                    </flux:text>
                    <div class="mt-2 p-3 bg-surface  rounded font-mono text-sm">
                        Authorization: Bearer YOUR_API_TOKEN
                    </div>
                </div>

                <div>
                    <flux:text class="font-medium text-primary ">Example Request</flux:text>
                    <div class="mt-2 p-3 bg-surface  rounded font-mono text-xs overflow-x-auto">
curl -X POST {{ url('/api/v1/triggers/{uuid}') }} \<br>
  -H "Authorization: Bearer YOUR_API_TOKEN" \<br>
  -H "Content-Type: application/json" \<br>
  -d '{"input": "What is quantum computing?"}'
                    </div>
                </div>

                <div>
                    <flux:text class="font-medium text-primary ">Token Abilities</flux:text>
                    <div class="mt-2 space-y-3">
                        @foreach($scopesByCategory as $category => $scopes)
                            <div>
                                <div class="text-sm font-medium text-secondary ">{{ $category }}</div>
                                <ul class="mt-1 space-y-1 text-sm text-tertiary  pl-4">
                                    @foreach($scopes as $ability => $description)
                                        <li><strong>{{ $ability }}</strong> - {{ $description }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
