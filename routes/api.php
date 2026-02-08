<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/**
 * Get authenticated user
 *
 * Retrieve the currently authenticated user's information including profile details,
 * email, and account metadata. Commonly used to validate API tokens and verify authentication.
 *
 * ## Example Usage
 *
 * This endpoint is used for token validation in:
 * - **Chrome Extension** ([github.com/promptlyagentai/chrome-extension](https://github.com/promptlyagentai/chrome-extension)) - Validates token before saving knowledge
 * - **Trigger API Client** ([github.com/promptlyagentai/trigger-api-client](https://github.com/promptlyagentai/trigger-api-client)) - Verifies authentication on startup
 *
 * @group Authentication
 *
 * @authenticated
 *
 * @response 200 scenario="Success" {"id": 1, "name": "John Doe", "email": "john@example.com", "email_verified_at": "2024-01-01T00:00:00Z", "created_at": "2024-01-01T00:00:00Z", "updated_at": "2024-01-01T00:00:00Z"}
 * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
 *
 * @responseField id integer User ID
 * @responseField name string User's full name
 * @responseField email string User's email address
 * @responseField email_verified_at string Email verification timestamp (ISO 8601, null if not verified)
 * @responseField created_at string Account creation timestamp (ISO 8601)
 * @responseField updated_at string Last update timestamp (ISO 8601)
 */
Route::middleware(['auth:sanctum', 'throttle:60,1'])->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Tiered Rate Limiting Strategy
|--------------------------------------------------------------------------
| 🔴 Expensive (10/min): AI operations, file uploads, streaming
| 🟡 Moderate (60/min): Search, reprocessing
| 🟢 Read-only (300/min): GET requests for viewing/listing
*/

// 🔴 Expensive Operations: Input Trigger Executions (10 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:10,1'])->prefix('v1/triggers')->name('api.triggers.')->group(function () {
    Route::post('/{trigger}/invoke', [\App\Http\Controllers\Api\InputTriggerController::class, 'invoke'])
        ->middleware('trigger.ip.whitelist')
        ->name('invoke');
    Route::match(['get', 'post'], '/{trigger}/stream', [\App\Http\Controllers\Api\InputTriggerController::class, 'stream'])
        ->middleware('trigger.ip.whitelist')
        ->name('stream');
});

// 🟢 Read Operations: Trigger Metadata (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/triggers')->name('api.triggers.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\InputTriggerController::class, 'index'])->name('index');
    Route::get('/{trigger}', [\App\Http\Controllers\Api\InputTriggerController::class, 'show'])->name('show');
    Route::get('/{trigger}/session', [\App\Http\Controllers\Api\InputTriggerController::class, 'resolveSession'])->name('session');
    Route::get('/{trigger}/validate-session', [\App\Http\Controllers\Api\InputTriggerController::class, 'validateSession'])->name('validate-session');
});

// 🔴 Expensive Operations: Chat Streaming (10 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:10,1'])->prefix('v1/chat')->name('api.chat.')->group(function () {
    Route::post('/stream', [\App\Http\Controllers\Api\ApiChatController::class, 'stream'])->name('stream');
    Route::post('/', [\App\Http\Controllers\Api\ApiChatController::class, 'send'])->name('send');
});

// 🟡 Moderate Operations: Session Management (60 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('v1/chat')->name('api.chat.')->group(function () {
    Route::post('/sessions/{id}/keep', [\App\Http\Controllers\Api\ApiChatController::class, 'toggleKeep'])->name('sessions.keep');
    Route::post('/sessions/{id}/archive', [\App\Http\Controllers\Api\ApiChatController::class, 'archive'])->name('sessions.archive');
    Route::post('/sessions/{id}/unarchive', [\App\Http\Controllers\Api\ApiChatController::class, 'unarchive'])->name('sessions.unarchive');
    Route::post('/sessions/{id}/share', [\App\Http\Controllers\Api\ApiChatController::class, 'share'])->name('sessions.share');
    Route::post('/sessions/{id}/unshare', [\App\Http\Controllers\Api\ApiChatController::class, 'unshare'])->name('sessions.unshare');
    Route::delete('/sessions/{id}', [\App\Http\Controllers\Api\ApiChatController::class, 'destroy'])->name('sessions.destroy');
});

// 🟢 Read Operations: Chat Sessions (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/chat')->name('api.chat.')->group(function () {
    Route::get('/sessions', [\App\Http\Controllers\Api\ApiChatController::class, 'index'])->name('sessions.index');
    Route::get('/sessions/{id}', [\App\Http\Controllers\Api\ApiChatController::class, 'show'])->name('sessions.show');
});

// 🟢 Read Operations: Agents (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/agents')->name('api.agents.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\AgentController::class, 'index'])->name('index');
    Route::get('/{id}', [\App\Http\Controllers\Api\AgentController::class, 'show'])->name('show');
    Route::get('/{id}/tools', [\App\Http\Controllers\Api\ToolsController::class, 'agentTools'])->name('tools');
});

// 🟢 Read Operations: Tools (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/tools')->name('api.tools.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ToolsController::class, 'index'])->name('index');
});

/*
|--------------------------------------------------------------------------
| Knowledge Management API v1 - Tiered Rate Limiting
|--------------------------------------------------------------------------
*/

// 🔴 Expensive Operations: File uploads, URL extraction, RAG streaming (10 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:10,1'])->prefix('v1/knowledge')->name('api.v1.knowledge.')->group(function () {
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeApiController::class)->group(function () {
        Route::post('/', 'store')->name('store'); // File uploads
        Route::post('/extract-url', 'extractUrl')->name('extract-url'); // SSRF risk
    });

    // RAG Operations (expensive AI operations)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeRagController::class)->prefix('rag')->name('rag.')->group(function () {
        Route::post('/query', 'query')->name('query');
        Route::post('/context', 'context')->name('context');
        Route::post('/stream', 'stream')->name('stream');
    });

    // Bulk operations (high DB load)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeBulkController::class)->prefix('bulk')->name('bulk.')->group(function () {
        Route::post('/delete', 'delete')->name('delete');
        Route::post('/assign-tag', 'assignTag')->name('assign-tag');
    });

    // Embedding regeneration (expensive)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeEmbeddingController::class)->prefix('embeddings')->name('embeddings.')->group(function () {
        Route::post('/regenerate', 'regenerateAll')->name('regenerate');
        Route::post('/{document}/regenerate', 'regenerateDocument')->name('regenerate-document');
    });
});

// 🟡 Moderate Operations: Search, reprocessing (60 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('v1/knowledge')->name('api.v1.knowledge.')->group(function () {
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeApiController::class)->group(function () {
        Route::post('/{document}/reprocess', 'reprocess')->name('reprocess');
        Route::post('/{document}/refresh', 'refresh')->name('refresh');
    });

    // Search operations
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeSearchController::class)->group(function () {
        Route::post('/search', 'search')->name('search');
        Route::post('/semantic-search', 'semanticSearch')->name('semantic-search');
        Route::post('/hybrid-search', 'hybridSearch')->name('hybrid-search');
        Route::get('/{document}/similar', 'similarDocuments')->name('similar');
    });

    // Tag operations (write operations)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeTagController::class)->group(function () {
        Route::post('/tags', 'store')->name('tags.store');
    });
    Route::post('/{document}/tags', [\App\Http\Controllers\Api\V1\Knowledge\KnowledgeTagController::class, 'addToDocument'])->name('document.tags.add');

    // Agent assignment (write operations)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeAgentController::class)->group(function () {
        Route::post('/{document}/assign-agent', 'assign')->name('agent.assign');
        Route::delete('/{document}/unassign-agent/{agent}', 'unassign')->name('agent.unassign');
    });

    // Document updates/deletes (write operations)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeApiController::class)->group(function () {
        Route::put('/{document}', 'update')->name('update');
        Route::delete('/{document}', 'destroy')->name('destroy');
    });
});

// 🟢 Read Operations: Viewing, downloading, statistics (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/knowledge')->name('api.v1.knowledge.')->group(function () {
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeApiController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/recent', 'recent')->name('recent');
        Route::get('/check-url', 'checkUrl')->name('check-url');
        Route::get('/{document}', 'show')->name('show');
        Route::get('/{document}/download', 'download')->name('download');
    });

    // Embedding status (read-only)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeEmbeddingController::class)->prefix('embeddings')->name('embeddings.')->group(function () {
        Route::get('/status', 'status')->name('status');
        Route::get('/{document}/status', 'documentStatus')->name('document-status');
    });

    // Tags listing (read-only)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeTagController::class)->prefix('tags')->name('tags.')->group(function () {
        Route::get('/', 'index')->name('index');
    });

    // Agent documents (read-only)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeAgentController::class)->group(function () {
        Route::get('/agents/{agent}/documents', 'getAgentDocuments')->name('agent.documents');
    });

    // Statistics (read-only)
    Route::controller(\App\Http\Controllers\Api\V1\Knowledge\KnowledgeStatsController::class)->prefix('stats')->name('stats.')->group(function () {
        Route::get('/overview', 'overview')->name('overview');
        Route::get('/embeddings', 'embeddings')->name('embeddings');
    });
});

/*
|--------------------------------------------------------------------------
| Output Actions API v1 - Audit Trail & Execution Logs
|--------------------------------------------------------------------------
| Provides visibility into output action executions for compliance,
| debugging, and monitoring. All logs are user-scoped for security.
*/

// 🟢 Read Operations: Output Action Logs (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/output-actions')->name('api.v1.output-actions.')->group(function () {
    Route::controller(\App\Http\Controllers\Api\V1\OutputActionController::class)->group(function () {
        Route::get('/{action}/logs', 'logs')->name('logs');
    });
});

Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/output-action-logs')->name('api.v1.output-action-logs.')->group(function () {
    Route::controller(\App\Http\Controllers\Api\V1\OutputActionController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{log}', 'show')->name('show');
    });
});

/*
|--------------------------------------------------------------------------
| Artifact Management API - PWA Support
|--------------------------------------------------------------------------
| Artifact creation and retrieval for PWA interface.
| Moderate rate limit (60/min) for artifact creation.
*/

// 🟡 Moderate Operations: Artifact Creation/Update (60 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('v1/artifacts')->name('api.v1.artifacts.')->group(function () {
    Route::post('/', [\App\Http\Controllers\ArtifactController::class, 'store'])->name('store');
    Route::put('/{artifact}', [\App\Http\Controllers\ArtifactController::class, 'update'])->name('update');
    Route::post('/{artifact}/convert', [\App\Http\Controllers\ArtifactController::class, 'queueConversion'])->name('convert');
});

// 🟢 Read Operations: Artifact Retrieval (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/artifacts')->name('api.v1.artifacts.')->group(function () {
    Route::get('/{artifact}', [\App\Http\Controllers\ArtifactController::class, 'show'])->name('show');
    Route::get('/{artifact}/conversions', [\App\Http\Controllers\ArtifactController::class, 'getConversions'])->name('conversions');
});

// Artifact download with signed URLs (for PWA notifications and secure downloads)
// Uses temporary signed URLs (15 min expiry) - secure, time-limited, no token exposure
Route::middleware(['signed', 'throttle:300,1'])->prefix('v1/artifacts')->name('api.v1.artifacts.')->group(function () {
    Route::get('/{artifact}/conversions/{conversion}/download', [\App\Http\Controllers\ArtifactController::class, 'downloadConversionApi'])
        ->name('conversions.download');
});

// 🟢 Read Operations: Conversion Status (300 requests/minute)
Route::middleware(['auth:sanctum', 'throttle:300,1'])->prefix('v1/conversions')->name('api.v1.conversions.')->group(function () {
    Route::get('/{conversion}', [\App\Http\Controllers\ArtifactController::class, 'getConversionStatus'])->name('status');
});

/*
|--------------------------------------------------------------------------
| Public Chat Sessions (Unauthenticated)
|--------------------------------------------------------------------------
| Public read-only access to shared chat sessions via UUID.
| Rate limited to 300 requests per minute to prevent abuse.
*/

Route::middleware(['throttle:300,1'])->prefix('public')->name('api.public.')->group(function () {
    Route::get('/sessions/{uuid}', [\App\Http\Controllers\Api\PublicChatController::class, 'show'])->name('sessions.show');
});
