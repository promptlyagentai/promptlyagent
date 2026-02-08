<?php

namespace App\Livewire;

use App\Models\IntegrationToken;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;

class ManageCredentials extends Component
{
    public string $providerId = '';

    public string $mode = 'list'; // 'list', 'create', 'edit'

    public ?string $editingTokenId = null;

    public array $tokens = [];

    public array $formData = [];

    public bool $isOpen = false;

    #[On('openCredentialsModal')]
    public function openModal(string $providerId): void
    {
        $this->providerId = $providerId;
        $this->mode = 'list';
        $this->isOpen = true;
        $this->loadTokens();
    }

    public function loadTokens(): void
    {
        $this->tokens = IntegrationToken::where('user_id', Auth::id())
            ->where('provider_id', $this->providerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function showCreateForm(): void
    {
        $this->mode = 'create';
        $this->formData = [
            'name' => '',
            'token' => '',
        ];
    }

    public function showEditForm(string $tokenId): void
    {
        $token = IntegrationToken::where('id', $tokenId)
            ->where('user_id', Auth::id())
            ->where('provider_id', $this->providerId)
            ->firstOrFail();

        $this->editingTokenId = $tokenId;
        $this->mode = 'edit';
        $this->formData = [
            'name' => $token->provider_name ?? '',
            'token' => '', // Don't pre-fill token for security
        ];
    }

    public function saveToken(): void
    {
        $rateLimitKey = 'save-token:'.Auth::id();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            session()->flash('error', "Too many attempts. Please try again in {$seconds} seconds.");

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        $registry = app(ProviderRegistry::class);
        $provider = $registry->get($this->providerId);

        if (! $provider) {
            session()->flash('error', 'Provider not found');

            return;
        }

        $this->validate([
            'formData.name' => 'nullable|string|max:255',
            'formData.token' => 'required|string',
        ]);

        try {
            $authTypes = $provider->getSupportedAuthTypes();
            $authType = $authTypes[0] ?? 'bearer_token';

            if ($this->mode === 'create') {
                // Create new token
                if ($authType === 'bearer_token' && method_exists($provider, 'createFromBearerToken')) {
                    $provider->createFromBearerToken(
                        Auth::user(),
                        $this->formData['token'],
                        $this->formData['name']
                    );
                } elseif ($authType === 'api_key' && method_exists($provider, 'createFromApiKey')) {
                    $provider->createFromApiKey(
                        Auth::user(),
                        $this->formData['token'],
                        $this->formData['name']
                    );
                } else {
                    throw new \Exception('Unsupported authentication type');
                }

                session()->flash('success', 'Credentials created successfully');
            } else {
                // Update existing token
                $token = IntegrationToken::where('id', $this->editingTokenId)
                    ->where('user_id', Auth::id())
                    ->where('provider_id', $this->providerId)
                    ->firstOrFail();

                if (method_exists($provider, 'updateBearerToken')) {
                    $provider->updateBearerToken($token, $this->formData['token']);
                }

                if (! empty($this->formData['name'])) {
                    $token->provider_name = $this->formData['name'];
                    $token->save();
                }

                session()->flash('success', 'Credentials updated successfully');
            }

            $this->loadTokens();
            $this->mode = 'list';
            $this->formData = [];
            $this->editingTokenId = null;

        } catch (\Exception $e) {
            Log::error('Failed to save credentials', [
                'provider_id' => $this->providerId,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', 'Failed to save credentials: '.$e->getMessage());
        }
    }

    public function deleteToken(string $tokenId): void
    {
        $rateLimitKey = 'delete-token:'.Auth::id();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            session()->flash('error', "Too many attempts. Please try again in {$seconds} seconds.");

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            $token = IntegrationToken::where('id', $tokenId)
                ->where('user_id', Auth::id())
                ->where('provider_id', $this->providerId)
                ->firstOrFail();

            // Check if token is being used by any integrations
            $integrationsCount = $token->integrations()->count();
            if ($integrationsCount > 0) {
                session()->flash('error', "Cannot delete credentials that are being used by {$integrationsCount} integration(s)");

                return;
            }

            $registry = app(ProviderRegistry::class);
            $provider = $registry->get($this->providerId);
            if ($provider && method_exists($provider, 'revokeToken')) {
                try {
                    $provider->revokeToken($token);
                } catch (\Exception $e) {
                    Log::warning('Failed to revoke token with provider', [
                        'token_id' => $token->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $token->delete();

            session()->flash('success', 'Credentials deleted successfully');
            $this->loadTokens();

        } catch (\Exception $e) {
            Log::error('Failed to delete credentials', [
                'token_id' => $tokenId,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', 'Failed to delete credentials: '.$e->getMessage());
        }
    }

    public function testConnection(string $tokenId): void
    {
        try {
            $token = IntegrationToken::where('id', $tokenId)
                ->where('user_id', Auth::id())
                ->where('provider_id', $this->providerId)
                ->firstOrFail();

            $registry = app(ProviderRegistry::class);
            $provider = $registry->get($this->providerId);
            if (! $provider) {
                session()->flash('error', 'Provider not found');

                return;
            }

            $success = $provider->testConnection($token);

            if ($success) {
                $token->markAsActive();
                session()->flash('success', 'Connection test successful');
            } else {
                $token->markAsError('Connection test failed');
                session()->flash('error', 'Connection test failed');
            }

            $this->loadTokens();

        } catch (\Exception $e) {
            Log::error('Connection test failed', [
                'token_id' => $tokenId,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', 'Connection test failed: '.$e->getMessage());
        }
    }

    public function initiateOAuth(): void
    {
        $registry = app(ProviderRegistry::class);
        $provider = $registry->get($this->providerId);

        if (! $provider) {
            session()->flash('error', 'Provider not found');

            return;
        }

        // Close modal and redirect to OAuth flow
        $this->isOpen = false;

        // Redirect to OAuth initiation
        $this->redirect(route('integrations.initiate-auth', [
            'provider' => $this->providerId,
            'authType' => 'oauth2',
        ]));
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->mode = 'list';
        $this->formData = [];
        $this->editingTokenId = null;
    }

    public function render()
    {
        $provider = null;
        $hasConfiguredApp = true;
        $setupInstructions = null;
        $appManifest = null;

        if ($this->providerId) {
            $registry = app(ProviderRegistry::class);
            $provider = $registry->get($this->providerId);

            // Check if provider requires app configuration (OAuth providers)
            if ($provider && method_exists($provider, 'hasConfiguredApp')) {
                $hasConfiguredApp = $provider->hasConfiguredApp();

                // Get setup instructions if app is not configured
                if (! $hasConfiguredApp) {
                    if (method_exists($provider, 'getOAuthSetupInstructions')) {
                        $setupInstructions = $provider->getOAuthSetupInstructions();
                    }
                    if (method_exists($provider, 'getAppManifest')) {
                        $appManifest = $provider->getAppManifest();
                    }
                }
            }
        }

        return view('livewire.manage-credentials', [
            'provider' => $provider,
            'hasConfiguredApp' => $hasConfiguredApp,
            'setupInstructions' => $setupInstructions,
            'appManifest' => $appManifest,
        ]);
    }
}
