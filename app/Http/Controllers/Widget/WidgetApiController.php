<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Widget;
use App\Services\Billing\PlanLimits;
use App\Services\Chat\ConversationService;
use App\Services\Chat\OperatorAssigner;
use App\Support\Branding;
use App\Support\CurrentWorkspace;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/**
 * Public, token-based API used by the embeddable widget (no session / cookies).
 * Visitors identify with the X-Visitor-Token header issued by session().
 */
class WidgetApiController extends Controller
{
    public function __construct(
        private ConversationService $conversations,
        private PlanLimits $limits,
        private OperatorAssigner $assigner,
        private CurrentWorkspace $current,
    ) {}

    public function config(Request $request, string $key): JsonResponse
    {
        $widget = $this->widget($key);
        $operatorsOnline = $this->assigner->anyOnline($widget->workspace_id);
        $enabled = $widget->is_active
            && $widget->allowsHost($request->query('host'))
            && $this->limits->isServiceActive($widget->workspace)
            && ($operatorsOnline || ! $widget->hide_when_offline);

        return response()->json([
            'enabled' => $enabled,
            'operators_online' => $enabled && $operatorsOnline,
            'branding' => Branding::toArray(),
            'departments' => $this->departments($widget),
        ] + $widget->publicConfig($this->locale($request->query('lang'))));
    }

    public function session(Request $request, string $key): JsonResponse
    {
        $widget = $this->widget($key);
        $data = $request->validate([
            'visitor_token' => ['nullable', 'string', 'size:48'],
            'host' => ['nullable', 'string', 'max:190'],
            'url' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        if (! $widget->is_active || ! $widget->allowsHost($data['host'] ?? null)) {
            abort(403, 'Widget is not allowed on this domain.');
        }

        $visitor = ! empty($data['visitor_token']) ? $this->findVisitor($widget, $data['visitor_token']) : null;
        $token = null;
        if (! $visitor) {
            $token = Str::random(48);
            $visitor = Visitor::create([
                'workspace_id' => $widget->workspace_id,
                'widget_id' => $widget->id,
                'token' => hash('sha256', $token),
            ]);
        }

        $visitor->update([
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'current_url' => $data['url'] ?? $visitor->current_url,
            'locale' => $this->locale($data['locale'] ?? null),
            'last_seen_at' => now(),
        ]);

        $conversation = $this->conversations->openFor($visitor);

        return response()->json([
            'visitor_token' => $token ?? $data['visitor_token'],
            'visitor' => ['id' => $visitor->id, 'name' => $visitor->name, 'email' => $visitor->email],
            'conversation' => $conversation ? $this->conversationPayload($conversation) : null,
            'messages' => $conversation ? $this->messagesFor($conversation) : [],
            'operators_online' => $this->assigner->anyOnline($widget->workspace_id),
            'realtime' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                'channel' => 'visitor.'.$visitor->id,
            ],
        ]);
    }

    public function messages(Request $request, string $key): JsonResponse
    {
        [$widget, $visitor] = $this->authenticate($request, $key);
        $conversation = $this->conversations->openFor($visitor);

        return response()->json([
            'conversation' => $conversation ? $this->conversationPayload($conversation) : null,
            'messages' => $conversation ? $this->messagesFor($conversation, (int) $request->query('after', 0)) : [],
        ]);
    }

    public function send(Request $request, string $key): JsonResponse
    {
        [$widget, $visitor] = $this->authenticate($request, $key);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.config('chat.rag.max_visitor_message_chars')],
            'url' => ['nullable', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'department_id' => ['nullable', 'integer'],
        ]);

        if (! $this->limits->isServiceActive($widget->workspace)) {
            abort(503, 'Chat is currently unavailable.');
        }

        $visitor->fill(array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'current_url' => $data['url'] ?? null,
        ]))->fill(['last_seen_at' => now()])->save();

        $conversation = $this->conversations->openFor($visitor);
        if (! $conversation) {
            if (! $this->limits->canStartConversation($widget->workspace)) {
                abort(402, 'Monthly conversation limit reached.');
            }
            $conversation = $this->conversations->start($visitor, $this->departmentId($widget, $data['department_id'] ?? null));
        }

        $message = $this->conversations->addVisitorMessage($conversation, trim($data['body']));

        return response()->json([
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'message' => $message->toWidgetArray(),
        ], 201);
    }

    /** Offline form when no operator is available. */
    public function contact(Request $request, string $key): JsonResponse
    {
        [$widget, $visitor] = $this->authenticate($request, $key);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:190'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $visitor->update(array_filter(['name' => $data['name'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null]));

        $conversation = $this->conversations->openFor($visitor)
            ?? $this->conversations->start($visitor, $this->departmentId($widget, $data['department_id'] ?? null));
        $this->conversations->addSystemMessage($conversation, trim(__('chat.contact_left', [
            'contact' => implode(', ', array_filter([$data['name'] ?? null, $data['email'] ?? null, $data['phone'] ?? null])),
        ], $conversation->locale).($data['message'] ?? '' ? "\n".$data['message'] : '')));

        if ($conversation->status === ConversationStatus::Ai) {
            $conversation->update(['status' => ConversationStatus::PendingHuman, 'was_handed_off' => true]);
        }

        return response()->json(['ok' => true]);
    }

    public function rate(Request $request, string $key): JsonResponse
    {
        [$widget, $visitor] = $this->authenticate($request, $key);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);

        $conversation = Conversation::withoutGlobalScopes()->where('visitor_id', $visitor->id)->latest('id')->firstOrFail();
        $conversation->update(['rating' => $data['rating']]);

        return response()->json(['ok' => true]);
    }

    /** Pusher-protocol auth for the private visitor.{id} channel. */
    public function broadcastingAuth(Request $request, string $key): JsonResponse
    {
        [$widget, $visitor] = $this->authenticate($request, $key);
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string'],
        ]);

        if ($data['channel_name'] !== 'private-visitor.'.$visitor->id) {
            abort(403);
        }

        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('reverb');

        return response()->json(json_decode($broadcaster->getPusher()->authorizeChannel($data['channel_name'], $data['socket_id']), true));
    }

    private function widget(string $key): Widget
    {
        if (! Str::isUuid($key)) {
            abort(404);
        }

        $widget = Widget::withoutGlobalScopes()->with('workspace')->where('public_key', $key)->firstOrFail();
        $this->current->set($widget->workspace);

        return $widget;
    }

    /** @return array{0: Widget, 1: Visitor} */
    private function authenticate(Request $request, string $key): array
    {
        $widget = $this->widget($key);
        $token = (string) $request->header('X-Visitor-Token');
        $visitor = strlen($token) === 48 ? $this->findVisitor($widget, $token) : null;

        if (! $visitor) {
            abort(401, 'Invalid visitor token.');
        }

        return [$widget, $visitor];
    }

    private function findVisitor(Widget $widget, string $token): ?Visitor
    {
        return Visitor::withoutGlobalScopes()
            ->where('widget_id', $widget->id)
            ->where('token', hash('sha256', $token))
            ->first();
    }

    private function conversationPayload(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'operator_name' => $conversation->assignee?->name,
            'department_id' => $conversation->department_id,
            'rating' => $conversation->rating,
        ];
    }

    /** @return list<array{id: int, name: string}> active departments the visitor can choose from */
    private function departments(Widget $widget): array
    {
        return Department::withoutGlobalScopes()
            ->where('workspace_id', $widget->workspace_id)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }

    /** Ignores ids of other workspaces and inactive departments. */
    private function departmentId(Widget $widget, ?int $id): ?int
    {
        if (! $id) {
            return null;
        }

        return Department::withoutGlobalScopes()
            ->where('workspace_id', $widget->workspace_id)
            ->where('is_active', true)
            ->whereKey($id)
            ->value('id');
    }

    private function messagesFor(Conversation $conversation, int $afterId = 0): array
    {
        return Message::withoutGlobalScopes()
            ->with('sender')
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (Message $m) => $m->toWidgetArray())
            ->all();
    }

    private function locale(?string $locale): string
    {
        $short = strtolower(substr((string) $locale, 0, 2));

        return in_array($short, config('chat.locales'), true) ? $short : 'en';
    }
}
