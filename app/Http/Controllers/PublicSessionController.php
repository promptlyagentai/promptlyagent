<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;

class PublicSessionController extends Controller
{
    /**
     * Display a publicly shared chat session
     */
    public function show(string $uuid)
    {
        // Find session by UUID
        $session = ChatSession::where('uuid', $uuid)
            ->with([
                'interactions' => function ($query) {
                    $query->orderBy('created_at', 'asc');
                },
                'interactions.agent:id,name',
                'interactions.sources.source:id,title,url,domain',
                'interactions.attachments',
            ])
            ->firstOrFail();

        // Check if session is publicly accessible
        if (! $session->isPublic()) {
            abort(404, 'This session is not publicly accessible or has expired.');
        }

        // Return view with session data
        return view('public.session', [
            'session' => $session,
            'interactions' => $session->interactions,
        ]);
    }
}
