<?php

namespace App\Livewire\Components\Modals;

use App\Models\ChatSession;
use Livewire\Component;

class ShareSessionModal extends Component
{
    public bool $show = false;

    public ?int $sessionId = null;

    public string $publicUrl = '';

    public bool $isPublic = false;

    protected $listeners = [
        'show-share-modal' => 'openModal',
        'close-share-modal' => 'closeModal',
    ];

    public function openModal($data)
    {
        $this->sessionId = $data['sessionId'] ?? null;
        $this->publicUrl = $data['publicUrl'] ?? '';
        $this->isPublic = $data['isPublic'] ?? false;
        $this->show = true;
    }

    public function closeModal()
    {
        $this->show = false;
        $this->reset(['sessionId', 'publicUrl', 'isPublic']);
    }

    public function toggleShare()
    {
        if (! $this->sessionId) {
            session()->flash('message', 'No session to share.');
            session()->flash('message-type', 'error');

            return;
        }

        $session = ChatSession::find($this->sessionId);

        if (! $session) {
            session()->flash('message', 'Session not found.');
            session()->flash('message-type', 'error');

            return;
        }

        // SECURITY: Verify session ownership before allowing share toggle
        if ($session->user_id !== auth()->id()) {
            session()->flash('message', 'You do not have permission to modify this session.');
            session()->flash('message-type', 'error');

            return;
        }

        if ($session->is_public) {
            // Unshare
            $session->makePrivate();
            $this->isPublic = false;

            session()->flash('message', 'Session is now private.');
            session()->flash('message-type', 'success');

            $this->closeModal();

            // Notify parent to refresh after modal closes
            $this->dispatch('sessions-updated')->to('chat-research-interface');
        } else {
            // Share
            $session->makePublic();

            session()->flash('message', 'Session is now publicly shared!');
            session()->flash('message-type', 'success');

            // Update local state to show public URL and copy button
            $this->publicUrl = $session->getPublicUrl();
            $this->isPublic = true;

            // Notify parent to refresh button color
            $this->dispatch('sessions-updated')->to('chat-research-interface');
        }
    }

    public function render()
    {
        return view('livewire.components.modals.share-session-modal');
    }
}
