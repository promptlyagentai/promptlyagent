<?php

namespace App\Models;

use App\Services\EventStreamNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ChatInteractionKnowledgeSource extends Model
{
    protected $fillable = [
        'chat_interaction_id',
        'knowledge_document_id',
        'relevance_score',
        'discovery_method',
        'discovery_tool',
        'content_excerpt',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Relationship to chat interaction
     */
    public function chatInteraction(): BelongsTo
    {
        return $this->belongsTo(ChatInteraction::class);
    }

    /**
     * Relationship to knowledge document
     */
    public function knowledgeDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class);
    }

    /**
     * Create or update a chat interaction knowledge source
     */
    public static function createOrUpdate(
        int $chatInteractionId,
        int $knowledgeDocumentId,
        float $relevanceScore,
        string $contentExcerpt,
        string $discoveryMethod = 'knowledge_search',
        string $discoveryTool = 'KnowledgeRAGTool',
        array $metadata = []
    ): ?self {
        Log::info('ChatInteractionKnowledgeSource::createOrUpdate called', [
            'chat_interaction_id' => $chatInteractionId,
            'knowledge_document_id' => $knowledgeDocumentId,
            'relevance_score' => $relevanceScore,
            'discovery_method' => $discoveryMethod,
            'discovery_tool' => $discoveryTool,
            'content_excerpt_length' => strlen($contentExcerpt),
            'metadata_keys' => array_keys($metadata),
        ]);

        // Check relevance threshold - don't track sources below the threshold
        $minRelevanceThreshold = config('knowledge.search.internal_knowledge_threshold', 0.65);
        if ($relevanceScore < $minRelevanceThreshold) {
            Log::info('Skipping knowledge source tracking due to low relevance score', [
                'chat_interaction_id' => $chatInteractionId,
                'knowledge_document_id' => $knowledgeDocumentId,
                'relevance_score' => $relevanceScore,
                'min_threshold' => $minRelevanceThreshold,
                'discovery_method' => $discoveryMethod,
                'discovery_tool' => $discoveryTool,
            ]);

            return null;
        }

        // Verify ChatInteraction exists
        $interactionExists = \App\Models\ChatInteraction::where('id', $chatInteractionId)->exists();
        Log::info('ChatInteraction existence check', [
            'chat_interaction_id' => $chatInteractionId,
            'exists' => $interactionExists,
        ]);

        if (! $interactionExists) {
            Log::error('ChatInteraction does not exist - cannot create knowledge source', [
                'chat_interaction_id' => $chatInteractionId,
                'knowledge_document_id' => $knowledgeDocumentId,
            ]);
            throw new \Exception("ChatInteraction with ID {$chatInteractionId} does not exist");
        }

        // Verify KnowledgeDocument exists
        $documentExists = \App\Models\KnowledgeDocument::where('id', $knowledgeDocumentId)->exists();
        Log::info('KnowledgeDocument existence check', [
            'knowledge_document_id' => $knowledgeDocumentId,
            'exists' => $documentExists,
        ]);

        if (! $documentExists) {
            Log::error('KnowledgeDocument does not exist - cannot create knowledge source', [
                'chat_interaction_id' => $chatInteractionId,
                'knowledge_document_id' => $knowledgeDocumentId,
            ]);
            throw new \Exception("KnowledgeDocument with ID {$knowledgeDocumentId} does not exist");
        }

        // Try to find existing record
        Log::info('Searching for existing ChatInteractionKnowledgeSource record', [
            'chat_interaction_id' => $chatInteractionId,
            'knowledge_document_id' => $knowledgeDocumentId,
        ]);

        $record = self::where('chat_interaction_id', $chatInteractionId)
            ->where('knowledge_document_id', $knowledgeDocumentId)
            ->first();

        Log::info('Existing record search result', [
            'found_existing_record' => $record ? true : false,
            'existing_record_id' => $record ? $record->id : null,
            'existing_relevance_score' => $record ? $record->relevance_score : null,
        ]);

        $data = [
            'relevance_score' => $relevanceScore,
            'discovery_method' => $discoveryMethod,
            'discovery_tool' => $discoveryTool,
            'content_excerpt' => $contentExcerpt,
            'metadata' => $metadata,
        ];

        if ($record) {
            // Update existing record if new relevance score is higher
            if ($relevanceScore > $record->relevance_score) {
                Log::info('Updating existing record with higher relevance score', [
                    'record_id' => $record->id,
                    'old_score' => $record->relevance_score,
                    'new_score' => $relevanceScore,
                ]);

                $record->update($data);

                Log::info('Updated chat interaction knowledge source with higher relevance', [
                    'chat_interaction_id' => $chatInteractionId,
                    'knowledge_document_id' => $knowledgeDocumentId,
                    'record_id' => $record->id,
                    'old_score' => $record->relevance_score,
                    'new_score' => $relevanceScore,
                ]);
            } else {
                Log::info('Keeping existing record (new score not higher)', [
                    'record_id' => $record->id,
                    'existing_score' => $record->relevance_score,
                    'new_score' => $relevanceScore,
                ]);
            }

            return $record;
        } else {
            Log::info('Creating new ChatInteractionKnowledgeSource record', [
                'chat_interaction_id' => $chatInteractionId,
                'knowledge_document_id' => $knowledgeDocumentId,
                'data' => $data,
            ]);

            // Create new record
            $data['chat_interaction_id'] = $chatInteractionId;
            $data['knowledge_document_id'] = $knowledgeDocumentId;

            try {
                $record = self::create($data);

                Log::info('Successfully created chat interaction knowledge source', [
                    'chat_interaction_id' => $chatInteractionId,
                    'knowledge_document_id' => $knowledgeDocumentId,
                    'record_id' => $record->id,
                    'relevance_score' => $relevanceScore,
                    'discovery_method' => $discoveryMethod,
                    'discovery_tool' => $discoveryTool,
                    'created_at' => $record->created_at,
                ]);

                // Send real-time knowledge source added event
                try {
                    EventStreamNotifier::knowledgeSourceAdded($chatInteractionId, [
                        'document_id' => $knowledgeDocumentId,
                        'title' => $record->knowledgeDocument->title ?? 'Untitled Document',
                        'source_type' => 'knowledge',
                        'is_expired' => $record->knowledgeDocument->ttl_expires_at ?
                            $record->knowledgeDocument->ttl_expires_at->isPast() : false,
                        'preview_url' => route('knowledge.preview', ['document' => $knowledgeDocumentId]),
                        'content_excerpt' => $contentExcerpt,
                        'tags' => $record->knowledgeDocument->tags->pluck('name')->toArray(),
                        'created_at' => $record->knowledgeDocument->created_at,
                    ]);

                    Log::info('Successfully sent EventStreamNotifier::knowledgeSourceAdded', [
                        'chat_interaction_id' => $chatInteractionId,
                        'record_id' => $record->id,
                    ]);

                } catch (\Exception $eventError) {
                    Log::error('Failed to send knowledgeSourceAdded event', [
                        'chat_interaction_id' => $chatInteractionId,
                        'record_id' => $record->id,
                        'error' => $eventError->getMessage(),
                    ]);
                }

                return $record;

            } catch (\Exception $createError) {
                Log::error('Failed to create ChatInteractionKnowledgeSource record', [
                    'chat_interaction_id' => $chatInteractionId,
                    'knowledge_document_id' => $knowledgeDocumentId,
                    'data' => $data,
                    'error' => $createError->getMessage(),
                    'trace' => $createError->getTraceAsString(),
                ]);
                throw $createError;
            }
        }
    }
}
