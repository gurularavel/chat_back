<?php

namespace App\Services\Chat;

use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\VisitorConversationUpdated;
use App\Events\VisitorMessageCreated;
use App\Jobs\AnswerVisitorMessage;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Widget;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use Throwable;

/**
 * All state changes of a conversation go through here so that
 * persistence, broadcasting and AI dispatching stay consistent.
 */
class ConversationService
{
    public function __construct(
        private OperatorAssigner $assigner,
        private PlanLimits $limits,
    ) {}

    public function openFor(Visitor $visitor): ?Conversation
    {
        return Conversation::withoutGlobalScopes()
            ->where('visitor_id', $visitor->id)
            ->where('status', '!=', ConversationStatus::Closed)
            ->latest('id')
            ->first();
    }

    public function start(Visitor $visitor, ?int $departmentId = null): Conversation
    {
        $conversation = Conversation::create([
            'workspace_id' => $visitor->workspace_id,
            'widget_id' => $visitor->widget_id,
            'visitor_id' => $visitor->id,
            'department_id' => $departmentId,
            'status' => ConversationStatus::Ai,
            'locale' => $visitor->locale,
            'last_message_at' => now(),
        ]);

        $this->realtime(new ConversationUpdated($conversation));

        return $conversation;
    }

    public function addVisitorMessage(Conversation $conversation, string $body): Message
    {
        if ($conversation->status === ConversationStatus::Closed) {
            $conversation->update(['status' => ConversationStatus::Ai, 'closed_at' => null, 'assigned_user_id' => null]);
        }

        $this->resumeAiIfNoOperator($conversation);

        $message = $this->store($conversation, SenderType::Visitor, $body, $conversation->visitor_id);

        if ($conversation->status === ConversationStatus::Ai) {
            AnswerVisitorMessage::dispatch($conversation->id, $message->id);
        }

        return $message;
    }

    public function addOperatorMessage(Conversation $conversation, User $operator, string $body): Message
    {
        // The first reply after a handoff stops the SLA clock.
        $firstResponse = $conversation->awaitsFirstResponse();
        if ($firstResponse) {
            $conversation->forceFill(['first_response_at' => now()]);
        }

        // Replying takes over the conversation from the AI.
        if ($conversation->status !== ConversationStatus::Human || $conversation->assigned_user_id !== $operator->id) {
            $this->claim($conversation, $operator);
        } elseif ($firstResponse) {
            $conversation->save();
            $this->broadcastUpdate($conversation);
        }

        return $this->store($conversation, SenderType::Operator, $body, $operator->id);
    }

    public function addAiMessage(Conversation $conversation, string $body, array $sources = [], array $meta = []): Message
    {
        return $this->store($conversation, SenderType::Ai, $body, null, $sources, $meta);
    }

    public function addSystemMessage(Conversation $conversation, string $body): Message
    {
        return $this->store($conversation, SenderType::System, $body);
    }

    public function claim(Conversation $conversation, User $operator): void
    {
        $alreadyHis = $conversation->status === ConversationStatus::Human && $conversation->assigned_user_id === $operator->id;

        $conversation->update([
            'status' => ConversationStatus::Human,
            'assigned_user_id' => $operator->id,
            'was_handed_off' => true,
            'closed_at' => null,
        ]);

        if (! $alreadyHis) {
            $this->addSystemMessage($conversation, $this->text($conversation, 'operator_joined', ['name' => $operator->name]));
        }

        $this->broadcastUpdate($conversation);
    }

    public function transfer(Conversation $conversation, User $to): void
    {
        $conversation->update(['assigned_user_id' => $to->id, 'status' => ConversationStatus::Human]);
        $this->addSystemMessage($conversation, $this->text($conversation, 'transferred', ['name' => $to->name]));
        $this->broadcastUpdate($conversation);
    }

    /** Operator hands the chat back to the AI assistant. */
    public function returnToAi(Conversation $conversation): void
    {
        $this->stopSlaClock($conversation);
        $conversation->update(['status' => ConversationStatus::Ai, 'assigned_user_id' => null]);
        $this->broadcastUpdate($conversation);
    }

    public function close(Conversation $conversation): void
    {
        $this->stopSlaClock($conversation);
        $conversation->update(['status' => ConversationStatus::Closed, 'closed_at' => now()]);
        $this->broadcastUpdate($conversation);
    }

    /**
     * AI could not help (or visitor asked for a human): route to an operator.
     * Returns true when an operator is online.
     */
    public function handoff(Conversation $conversation): bool
    {
        $operator = $this->assigner->pick($conversation->workspace_id, $conversation->department_id);

        $this->startSlaClock($conversation);
        $conversation->update([
            'status' => ConversationStatus::PendingHuman,
            'was_handed_off' => true,
            'assigned_user_id' => $operator?->id,
        ]);

        $this->addSystemMessage($conversation, $operator
            ? $this->text($conversation, 'handoff_waiting')
            : $this->text($conversation, 'handoff_offline'));

        $this->broadcastUpdate($conversation);

        return $operator !== null;
    }

    /**
     * A chat handed to a human must not wait forever: if the assigned operator
     * (or, when unassigned, every operator) is offline, the AI takes the next message.
     * Workspaces in "always hand off" mode keep waiting for a human.
     */
    private function resumeAiIfNoOperator(Conversation $conversation): void
    {
        if (! in_array($conversation->status, [ConversationStatus::Human, ConversationStatus::PendingHuman], true)) {
            return;
        }

        $handoffMode = AiSetting::withoutGlobalScopes()->where('workspace_id', $conversation->workspace_id)->value('handoff_mode');
        if ($handoffMode === 'always') {
            return;
        }

        $operatorAvailable = $conversation->assigned_user_id
            ? $this->assigner->isOnline($conversation->workspace_id, $conversation->assigned_user_id)
            : $this->assigner->anyOnline($conversation->workspace_id, $conversation->department_id);

        if (! $operatorAvailable) {
            $this->stopSlaClock($conversation);
            $conversation->update(['status' => ConversationStatus::Ai, 'assigned_user_id' => null]);
            $this->broadcastUpdate($conversation);
        }
    }

    /** Called by the SLA sweep once the first-response deadline has passed. */
    public function markSlaBreached(Conversation $conversation): void
    {
        $conversation->forceFill(['sla_breached_at' => now()])->save();
        $this->realtime(new ConversationUpdated($conversation));
    }

    /**
     * A handoff starts the wait for a human. A repeated handoff while the visitor is
     * still waiting keeps the original clock; a later handoff starts a new one.
     */
    private function startSlaClock(Conversation $conversation): void
    {
        $waiting = in_array($conversation->status, [ConversationStatus::PendingHuman, ConversationStatus::Human], true);
        if ($waiting && $conversation->awaitsFirstResponse()) {
            return;
        }

        $minutes = $this->limits->slaMinutes(Workspace::findOrFail($conversation->workspace_id));

        $conversation->forceFill([
            'handoff_at' => now(),
            'first_response_at' => null,
            'sla_due_at' => $minutes ? now()->addMinutes($minutes) : null,
            'sla_breached_at' => null,
        ]);
    }

    /**
     * The wait ended without an operator reply (back to the AI, or closed). An unbreached
     * clock is dropped so it counts neither as met nor as missed; a breach stays on record.
     */
    private function stopSlaClock(Conversation $conversation): void
    {
        if ($conversation->awaitsFirstResponse() && ! $conversation->sla_breached_at) {
            $conversation->forceFill(['handoff_at' => null, 'sla_due_at' => null]);
        }
    }

    public function serviceActive(Workspace $workspace): bool
    {
        return $this->limits->isServiceActive($workspace);
    }

    private function store(
        Conversation $conversation,
        SenderType $type,
        string $body,
        ?int $senderId = null,
        array $sources = [],
        array $meta = [],
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'workspace_id' => $conversation->workspace_id,
            'sender_type' => $type,
            'sender_id' => $senderId,
            'body' => $body,
            'sources' => $sources ?: null,
            'meta' => $meta ?: null,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        $this->realtime(new MessageCreated($message));
        $this->realtime(new VisitorMessageCreated($message, $conversation->visitor_id));

        return $message;
    }

    /** Visitor-facing system message in the chat's language, using the widget's own wording if set. */
    private function text(Conversation $conversation, string $key, array $replace = []): string
    {
        $widget = Widget::withoutGlobalScopes()->find($conversation->widget_id);

        return $widget
            ? $widget->text($key, $conversation->locale, $replace)
            : __('chat.'.$key, $replace, $conversation->locale);
    }

    private function broadcastUpdate(Conversation $conversation): void
    {
        $conversation->unsetRelation('assignee');
        $this->realtime(new ConversationUpdated($conversation));
        $this->realtime(new VisitorConversationUpdated($conversation));
    }

    /**
     * Chat events go out immediately (not as queued jobs) so a reply reaches the widget together
     * with the end of the typing indicator. A realtime server outage must not fail the message
     * itself: the widget and inbox catch up by polling.
     */
    private function realtime(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
