<?php

use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Models\User;
use Tests\Feature\Api\V1\Concerns\AssertsApiResponses;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class, AssertsApiResponses::class);

test('can list chat sessions with chat:view ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson('/api/v1/chat/sessions');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $session->id]);
});

test('returns 403 without chat:view ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['agent:view']);

    $response = $this->getJson('/api/v1/chat/sessions');

    $this->assertForbidden($response);
});

test('can view own chat session with chat:view ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

    $this->assertApiSuccess($response);
    $this->assertChatSessionStructure($response);
    $response->assertJson(['session' => ['id' => $session->id]]);
});

test('cannot view another users chat session', function () {
    $owner = User::factory()->create();
    $session = ChatSession::factory()->create(['user_id' => $owner->id]);

    $otherUser = $this->actingAsApiUserWithAbilities(['chat:view']);

    $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

    $this->assertForbidden($response);
});

test('returns 404 for non-existent session', function () {
    $this->actingAsApiUserWithAbilities(['chat:view']);

    $response = $this->getJson('/api/v1/chat/sessions/99999');

    $this->assertNotFound($response);
});

test('session includes interactions and sources', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);
    ChatInteraction::factory()->create([
        'chat_session_id' => $session->id,
        'user_id' => $user->id,
    ]);

    $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

    $this->assertApiSuccess($response);
    $response->assertJsonStructure(['interactions']);
});

test('can filter sessions by source_type', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    $apiSession = ChatSession::factory()->create([
        'user_id' => $user->id,
        'source_type' => 'api',
    ]);
    $webSession = ChatSession::factory()->create([
        'user_id' => $user->id,
        'source_type' => 'web',
    ]);

    $response = $this->getJson('/api/v1/chat/sessions?source_type=api');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $apiSession->id]);
    $response->assertJsonMissing(['id' => $webSession->id]);
});

test('can paginate sessions', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    ChatSession::factory()->count(5)->create(['user_id' => $user->id]);

    $response = $this->getJson('/api/v1/chat/sessions?per_page=2');

    $this->assertApiSuccess($response);
    $response->assertJsonStructure(['sessions', 'filters']);
    expect(count($response->json('sessions')))->toBe(2);
});
