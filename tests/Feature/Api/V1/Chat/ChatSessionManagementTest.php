<?php

use App\Models\ChatSession;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class);

test('can toggle keep flag with chat:manage ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_kept' => false,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/keep");

    $this->assertApiSuccess($response);
    expect($session->fresh()->is_kept)->toBeTrue();
});

test('returns 403 without chat:manage ability for keep action', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:view']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/keep");

    $this->assertForbidden($response);
});

test('can archive session with chat:manage ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_kept' => false,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/archive");

    $this->assertApiSuccess($response);
    expect($session->fresh()->archived_at)->not()->toBeNull();
});

test('cannot archive kept session', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_kept' => true,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/archive");

    $this->assertValidationError($response);
    $response->assertJsonFragment(['error' => 'Cannot Archive Kept Session']);
});

test('can unarchive session', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'archived_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/unarchive");

    $this->assertApiSuccess($response);
    expect($session->fresh()->archived_at)->toBeNull();
});

test('can share session publicly', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_public' => false,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/share");

    $this->assertApiSuccess($response);
    expect($session->fresh()->is_public)->toBeTrue();
});

test('can share session with expiration', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_public' => false,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/share", [
        'expires_in_days' => 7,
    ]);

    $this->assertApiSuccess($response);
    $session->refresh();
    expect($session->is_public)->toBeTrue();
    expect($session->public_expires_at)->not->toBeNull();
});

test('can unshare session', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create([
        'user_id' => $user->id,
        'is_public' => true,
    ]);

    $response = $this->postJson("/api/v1/chat/sessions/{$session->id}/unshare");

    $this->assertApiSuccess($response);
    expect($session->fresh()->is_public)->toBeFalse();
});

test('can delete session with chat:delete ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:delete']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);

    $response = $this->deleteJson("/api/v1/chat/sessions/{$session->id}");

    $this->assertApiSuccess($response, 200);
    $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
});

test('returns 403 without chat:delete ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['chat:manage']);
    $session = ChatSession::factory()->create(['user_id' => $user->id]);

    $response = $this->deleteJson("/api/v1/chat/sessions/{$session->id}");

    $this->assertForbidden($response);
});
