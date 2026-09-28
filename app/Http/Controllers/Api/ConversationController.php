<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversations) {}

    /** Inbox list. filter: ai | pending | mine | open | closed | all */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(['ai', 'pending', 'mine', 'open', 'closed', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $query = Conversation::with(['visitor', 'assignee', 'department', 'latestMessage'])->orderByDesc('last_message_at');

        match ($request->input('filter', 'open')) {
            'ai' => $query->where('status', ConversationStatus::Ai),
            'pending' => $query->where('status', ConversationStatus::PendingHuman),
            'mine' => $query->where('assigned_user_id', $request->user()->id)->where('status', '!=', ConversationStatus::Closed),
            'open' => $query->where('status', '!=', ConversationStatus::Closed),
            'closed' => $query->where('status', ConversationStatus::Closed),
            default => null,
        };

        if ($departmentId = $request->integer('department_id')) {
            $query->where('department_id', $departmentId);
        }

        if ($search = $request->input('search')) {
            $query->whereHas('visitor', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"));
        }

        return ConversationResource::collection($query->paginate(30));
    }

    public function counts(Request $request): JsonResponse
    {
        $counts = Conversation::where('status', '!=', ConversationStatus::Closed)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'ai' => (int) ($counts['ai'] ?? 0),
            'pending' => (int) ($counts['pending_human'] ?? 0),
            'human' => (int) ($counts['human'] ?? 0),
            'mine' => Conversation::where('assigned_user_id', $request->user()->id)->where('status', '!=', ConversationStatus::Closed)->count(),
        ]);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load(['visitor', 'assignee', 'department', 'widget']);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages' => MessageResource::collection($conversation->messages()->with('sender')->orderBy('id')->limit(500)->get()),
            'previous_conversations' => Conversation::where('visitor_id', $conversation->visitor_id)
                ->whereKeyNot($conversation->id)->latest()->limit(10)->get(['id', 'status', 'created_at']),
        ]);
    }

    public function reply(Request $request, Conversation $conversation): MessageResource
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $message = $this->conversations->addOperatorMessage($conversation, $request->user(), $data['body']);

        return new MessageResource($message->load('sender'));
    }

    public function claim(Request $request, Conversation $conversation): ConversationResource
    {
        $this->conversations->claim($conversation, $request->user());

        return new ConversationResource($conversation->fresh(['visitor', 'assignee', 'department']));
    }

    public function transfer(Request $request, Conversation $conversation): ConversationResource
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $workspace = $request->attributes->get('workspace');
        $to = $workspace->members()->whereKey($data['user_id'])->firstOrFail();

        $this->conversations->transfer($conversation, User::find($to->id));

        return new ConversationResource($conversation->fresh(['visitor', 'assignee', 'department']));
    }

    public function returnToAi(Conversation $conversation): ConversationResource
    {
        $this->conversations->returnToAi($conversation);

        return new ConversationResource($conversation->fresh(['visitor', 'assignee', 'department']));
    }

    public function close(Conversation $conversation): ConversationResource
    {
        $this->conversations->close($conversation);

        return new ConversationResource($conversation->fresh(['visitor', 'assignee', 'department']));
    }
}
