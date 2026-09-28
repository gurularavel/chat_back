<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Cross-tenant conversation flow for the superadmin. */
class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'workspace_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'handed_off' => ['nullable', 'boolean'],
        ]);

        return ConversationResource::collection(
            Conversation::with(['workspace', 'visitor', 'assignee', 'latestMessage'])
                ->when($request->input('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
                ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->has('handed_off'), fn ($q) => $q->where('was_handed_off', $request->boolean('handed_off')))
                ->latest('last_message_at')
                ->paginate(40)
        );
    }

    public function show(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'conversation' => new ConversationResource($conversation->load(['workspace', 'visitor', 'assignee'])),
            'messages' => MessageResource::collection($conversation->messages()->with('sender')->orderBy('id')->get()),
        ]);
    }
}
