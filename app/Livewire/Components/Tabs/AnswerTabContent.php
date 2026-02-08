<?php

namespace App\Livewire\Components\Tabs;

use App\Models\ChatInteraction;
use App\Services\InlineLinkProcessor;
use Livewire\Attributes\On;
use Livewire\Component;

class AnswerTabContent extends Component
{
    public $interactionId;

    public $processedResponse = '';

    public $rawResponse = '';

    public function mount($interactionId)
    {
        $this->interactionId = $interactionId;
        $this->loadProcessedResponse();
    }

    protected function loadProcessedResponse()
    {
        $interaction = ChatInteraction::with('execution', 'chatSession')->find($this->interactionId);
        if ($interaction && $interaction->answer) {
            $this->rawResponse = $interaction->answer;

            // Resolve internal URLs (asset:// and attachment:)
            $resolver = app(MarkdownUrlResolver::class);
            $resolver->setUser(auth()->id())
                ->setInteractionId($this->interactionId)
                ->setChatSessionId($interaction->chat_session_id);

            $resolvedMarkdown = $resolver->resolve($interaction->answer);

            if ($interaction->execution) {
                $processor = app(InlineLinkProcessor::class);
                $processedMarkdown = $processor->processAgentResponse($resolvedMarkdown, $interaction->execution);
                $this->processedResponse = $processor->enrichMarkdownForDisplay($processedMarkdown);
            } else {
                $this->processedResponse = \Illuminate\Support\Str::markdown($resolvedMarkdown);
            }
        }
    }

    #[On('answer-streamed')]
    public function refreshContent()
    {
        $this->loadProcessedResponse();
    }

    public function copyToClipboard()
    {
        if (empty($this->rawResponse)) {
            $this->dispatch('notify', [
                'message' => 'No content available to copy',
                'type' => 'error',
            ]);

            return;
        }

        $this->dispatch('copy-content-to-clipboard', [
            'content' => json_encode($this->rawResponse),
            'successMessage' => 'Answer copied to clipboard',
        ]);
    }

    public function render()
    {
        return view('livewire.components.tabs.answer-tab-content');
    }
}
