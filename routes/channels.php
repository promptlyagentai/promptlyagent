<?php

use App\Models\ChatSession;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat-session.{sessionId}', function ($user, $sessionId) {
    $session = ChatSession::find($sessionId);

    return $session !== null;
});

Broadcast::channel('status-stream.{interactionId}', function ($user, $interactionId) {
    return true;
});
