<?php

use App\Models\Agent;
use App\Models\User;
use Tests\Feature\Api\V1\Concerns\AssertsApiResponses;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class, AssertsApiResponses::class);

test('can list agents with agent:view ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['agent:view']);
    $agent = Agent::factory()->public()->create();

    $response = $this->getJson('/api/v1/agents');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $agent->id]);
});

test('returns 403 without agent:view ability', function () {
    $this->actingAsApiUserWithAbilities(['chat:view']);

    $response = $this->getJson('/api/v1/agents');

    $this->assertForbidden($response);
});

test('can view specific agent with agent:view ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['agent:view']);
    $agent = Agent::factory()->public()->create();

    $response = $this->getJson("/api/v1/agents/{$agent->id}");

    $this->assertApiSuccess($response);
    $this->assertAgentStructure($response);
    $response->assertJson(['agent' => ['id' => $agent->id]]);
});

test('returns 404 for non-existent agent', function () {
    $this->actingAsApiUserWithAbilities(['agent:view']);

    $response = $this->getJson('/api/v1/agents/99999');

    $this->assertNotFound($response);
});

test('can view public agents from any user', function () {
    $owner = User::factory()->create();
    $agent = Agent::factory()->public()->create(['created_by' => $owner->id]);

    $viewer = $this->actingAsApiUserWithAbilities(['agent:view']);

    $response = $this->getJson("/api/v1/agents/{$agent->id}");

    $this->assertApiSuccess($response);
    $response->assertJson(['agent' => ['id' => $agent->id]]);
});

test('cannot view inactive agents in listing', function () {
    $user = $this->actingAsApiUserWithAbilities(['agent:view']);
    $activeAgent = Agent::factory()->public()->create();
    $inactiveAgent = Agent::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/agents');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $activeAgent->id]);
    $response->assertJsonMissing(['id' => $inactiveAgent->id]);
});

test('returns agent with tools list', function () {
    $user = $this->actingAsApiUserWithAbilities(['agent:view']);
    $agent = Agent::factory()->public()->create();

    $response = $this->getJson("/api/v1/agents/{$agent->id}");

    $this->assertApiSuccess($response);
    $response->assertJsonStructure(['agent' => ['id', 'name', 'tools']]);
});
