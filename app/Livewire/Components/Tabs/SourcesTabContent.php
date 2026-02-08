<?php

namespace App\Livewire\Components\Tabs;

use App\Models\AgentSource;
use App\Models\ChatInteractionAttachment;
use App\Models\ChatInteractionKnowledgeSource;
use App\Models\ChatInteractionSource;
use App\Models\Source;
use Livewire\Component;

class SourcesTabContent extends Component
{
    public $executionId;

    public $interactionId;

    public $sources = [];

    public $timeline = [];

    public $interactionQuery = null;

    public $showAsTimelineItem = false;

    public $interactionQuestion = null;

    public $interactionTimestamp = null;

    // Modal state for markdown preview
    public $showPreviewModal = false;

    public $previewDocumentId = null;

    protected $listeners = [
        'refreshSources' => 'loadSources',
        'source-created' => 'handleSourceCreated',
        'chat-interaction-source-created' => 'handleChatInteractionSourceCreated',
    ];

    public function mount($executionId = null, $interactionId = null, $showAsTimelineItem = false, $interactionQuestion = null, $interactionTimestamp = null)
    {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;
        $this->showAsTimelineItem = $showAsTimelineItem;
        $this->interactionQuestion = $interactionQuestion;
        $this->interactionTimestamp = $interactionTimestamp;
        $this->loadSources();
    }

    public function refreshSources()
    {
        $this->loadSources();
    }

    /**
     * Handle real-time source creation events
     */
    public function handleSourceCreated($sourceData = [])
    {
        $this->loadSources();
    }

    /**
     * Handle real-time chat interaction source creation events
     */
    public function handleChatInteractionSourceCreated($cisData = [])
    {
        if (isset($cisData['chat_interaction_id']) && $cisData['chat_interaction_id'] == $this->interactionId) {
            $this->loadSources();
        }
    }

    public function loadSources()
    {
        $combinedTimeline = [];

        // Load sources from ChatInteractionSource if we have an interaction ID
        if ($this->interactionId) {
            $chatInteractionSources = ChatInteractionSource::with(['source', 'chatInteraction'])
                ->where('chat_interaction_id', $this->interactionId)
                ->get();

            // Get the interaction query for display
            $firstInteractionSource = $chatInteractionSources->first();
            if ($firstInteractionSource && $firstInteractionSource->chatInteraction) {
                $this->interactionQuery = $firstInteractionSource->chatInteraction->question;
            }

            foreach ($chatInteractionSources as $cis) {
                if ($cis->source) {
                    $combinedTimeline[] = [
                        'type' => 'chat_interaction_source',
                        'source' => $cis->source,
                        'chat_interaction_source' => $cis,
                        'title' => $cis->source->title ?: 'Untitled Source',
                        'description' => $cis->source->description,
                        'favicon' => $cis->source->favicon,
                        'domain' => $cis->source->domain,
                        'url' => $cis->source->url,
                        'relevance_score' => $cis->relevance_score,
                        'discovery_method' => $cis->discovery_method,
                        'discovery_tool' => $cis->discovery_tool,
                        'was_scraped' => $cis->was_scraped,
                        'content_category' => $cis->source->content_category,
                        'timestamp' => $cis->discovered_at,
                        'http_status' => $cis->source->http_status,
                        'content_preview' => $cis->source->content_preview,
                        'has_content' => ! empty($cis->source->content_markdown),
                        'expires_at' => $cis->source->expires_at,
                        'is_expired' => ! $cis->source->isValid(),
                    ];
                }
            }

            // Load knowledge sources from ChatInteractionKnowledgeSource
            $knowledgeSources = ChatInteractionKnowledgeSource::with(['knowledgeDocument.tags'])
                ->where('chat_interaction_id', $this->interactionId)
                ->get();

            foreach ($knowledgeSources as $kis) {
                if ($kis->knowledgeDocument) {
                    $combinedTimeline[] = [
                        'type' => 'knowledge_source',
                        'knowledge_source' => $kis,
                        'document' => $kis->knowledgeDocument,
                        'title' => $kis->knowledgeDocument->title ?: 'Untitled Document',
                        'description' => $kis->content_excerpt ?: $kis->knowledgeDocument->description,
                        'favicon' => '📄',
                        'domain' => 'knowledge',
                        'url' => route('knowledge.preview', ['document' => $kis->knowledge_document_id]),
                        'relevance_score' => $kis->relevance_score,
                        'discovery_method' => $kis->discovery_method,
                        'discovery_tool' => $kis->discovery_tool,
                        'was_scraped' => false,
                        'content_category' => 'knowledge',
                        'timestamp' => $kis->created_at,
                        'http_status' => 200,
                        'content_preview' => $kis->content_excerpt,
                        'has_content' => ! empty($kis->knowledgeDocument->content),
                        'tags' => $kis->knowledgeDocument->tags->pluck('name')->toArray(),
                        'is_expired' => $kis->knowledgeDocument->ttl_expires_at ?
                            $kis->knowledgeDocument->ttl_expires_at->isPast() : false,
                        'document_type' => $kis->knowledgeDocument->content_type,
                        'source_type' => $kis->knowledgeDocument->source_type,
                    ];
                }
            }

            // Load attachments (images, documents, audio, video)
            $attachments = ChatInteractionAttachment::where('chat_interaction_id', $this->interactionId)
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($attachments as $attachment) {
                // Determine emoji based on attachment type
                $emoji = match ($attachment->type) {
                    'image' => '🖼️',
                    'document' => '📎',
                    'audio' => '🎵',
                    'video' => '🎬',
                    default => '📎',
                };

                // Build title with source attribution if available
                $title = $attachment->original_filename ?: $attachment->filename;
                if ($attachment->source_title) {
                    $title = $attachment->source_title;
                }

                // Build description with source author
                $description = '';
                if ($attachment->source_author) {
                    $description = "by {$attachment->source_author}";
                }
                if ($attachment->source_description) {
                    $description .= $description ? ' - '.$attachment->source_description : $attachment->source_description;
                }
                if (! $description && $attachment->source_url) {
                    $description = 'Downloaded media';
                }

                $combinedTimeline[] = [
                    'type' => 'attachment',
                    'attachment' => $attachment,
                    'title' => $title,
                    'description' => $description ?: "Attachment ({$attachment->mime_type})",
                    'favicon' => $emoji,
                    'domain' => 'attachment',
                    'url' => $attachment->source_url ?: $attachment->getFileUrl(),
                    'relevance_score' => null,
                    'discovery_method' => $attachment->source_url ? 'external_download' : 'upload',
                    'discovery_tool' => 'create_chat_attachment',
                    'was_scraped' => false,
                    'content_category' => $attachment->type,
                    'timestamp' => $attachment->created_at,
                    'http_status' => 200,
                    'content_preview' => null,
                    'has_content' => true,
                    'file_size' => $attachment->file_size,
                    'mime_type' => $attachment->mime_type,
                    'source_url' => $attachment->source_url,
                    'source_author' => $attachment->source_author,
                    'is_expired' => $attachment->isExpired(),
                ];
            }

            // Also check AgentSources for this interaction's execution if we have no web sources and have an executionId
            if (empty($chatInteractionSources) && $this->executionId) {
                $agentSources = AgentSource::where('execution_id', $this->executionId)->get();

                foreach ($agentSources as $source) {
                    $combinedTimeline[] = [
                        'type' => 'agent_source',
                        'source' => $source,
                        'title' => $source->title ?: 'Untitled Source',
                        'description' => $source->snippet,
                        'favicon' => $source->favicon_url,
                        'domain' => $source->domain,
                        'url' => $source->url,
                        'relevance_score' => $source->quality_score,
                        'discovery_method' => 'agent_execution',
                        'discovery_tool' => 'legacy_system',
                        'was_scraped' => false,
                        'content_category' => $source->source_type,
                        'timestamp' => $source->created_at,
                        'http_status' => 200,
                        'content_preview' => $source->snippet,
                        'has_content' => ! empty($source->snippet),
                        'usage_count' => $source->link_usage_count,
                        'click_count' => $source->click_count,
                        'is_expired' => false,
                    ];
                }
            }
        } else {
            // Fallback to AgentSource if no interaction ID (for backward compatibility)
            $agentSources = AgentSource::where('execution_id', $this->executionId)->get();

            foreach ($agentSources as $source) {
                $combinedTimeline[] = [
                    'type' => 'agent_source',
                    'source' => $source,
                    'title' => $source->title ?: 'Untitled Source',
                    'description' => $source->snippet,
                    'favicon' => $source->favicon_url,
                    'domain' => $source->domain,
                    'url' => $source->url,
                    'relevance_score' => $source->quality_score,
                    'discovery_method' => 'agent_execution',
                    'discovery_tool' => 'legacy_system',
                    'was_scraped' => false,
                    'content_category' => $source->source_type,
                    'timestamp' => $source->created_at,
                    'http_status' => 200,
                    'content_preview' => $source->snippet,
                    'has_content' => ! empty($source->snippet),
                    'usage_count' => $source->link_usage_count,
                    'click_count' => $source->click_count,
                ];
            }
        }

        // Sort by timestamp (newest first)
        usort($combinedTimeline, function ($a, $b) {
            $timestampA = \Carbon\Carbon::parse($a['timestamp']);
            $timestampB = \Carbon\Carbon::parse($b['timestamp']);

            return $timestampB->timestamp <=> $timestampA->timestamp;
        });

        $this->timeline = $combinedTimeline;
        $this->sources = collect($combinedTimeline);
    }

    public function openPreviewModal($documentId)
    {
        $this->previewDocumentId = $documentId;
        $this->showPreviewModal = true;
    }

    public function closePreviewModal()
    {
        $this->showPreviewModal = false;
        $this->previewDocumentId = null;
    }

    public function render()
    {
        return view('livewire.components.tabs.sources-tab-content');
    }
}
