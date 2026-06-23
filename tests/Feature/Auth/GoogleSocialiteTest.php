<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

function mockGoogleCallbackUser(string $email): void
{
    $googleUser = Mockery::mock();
    $googleUser->shouldReceive('getEmail')->andReturn($email);
    $googleUser->shouldReceive('getName')->andReturn('Test User');
    $googleUser->shouldReceive('getNickname')->andReturn(null);

    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);
}

test('google callback allows users from configured domains', function () {
    config(['services.google.allowed_domains' => ['example.com']]);
    mockGoogleCallbackUser('alice@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'alice@example.com',
    ]);
});

test('google callback rejects users outside configured domains', function () {
    config(['services.google.allowed_domains' => ['example.com']]);
    mockGoogleCallbackUser('person@example.org');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'person@example.org',
    ]);
});

test('google callback allows any valid email when no domains are configured', function () {
    config(['services.google.allowed_domains' => []]);
    mockGoogleCallbackUser('person@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'person@example.com',
    ]);
});
