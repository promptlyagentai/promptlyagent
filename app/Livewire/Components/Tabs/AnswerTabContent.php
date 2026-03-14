<?php

namespace App\Livewire\Components\Tabs;

use App\Models\ChatInteraction;
use App\Services\InlineLinkProcessor;
use Illuminate\Support\Str;
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

            // Client-side marked.js handles internal URL resolution (asset://, attachment://)
            // No server-side URL resolution needed for browser display
            $markdown = $interaction->answer;

            if ($interaction->execution) {
                $processor = app(InlineLinkProcessor::class);
                $processedMarkdown = $processor->processAgentResponse($markdown, $interaction->execution);
                $this->processedResponse = $processor->enrichMarkdownForDisplay($processedMarkdown);
            } else {
                $this->processedResponse = Str::markdown($markdown);
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
