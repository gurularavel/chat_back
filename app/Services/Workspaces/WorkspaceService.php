<?php

namespace App\Services\Workspaces;

use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Widget;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    /** New workspace with owner, trial subscription, AI settings and a default widget. */
    public function create(User $owner, string $name, ?string $locale = null): Workspace
    {
        return DB::transaction(function () use ($owner, $name, $locale) {
            $trialEnds = now()->addDays(config('chat.trial_days'));

            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->id,
                'locale' => $locale ?? $owner->locale ?? 'az',
                'trial_ends_at' => $trialEnds,
            ]);

            $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

            $plan = Plan::where('is_trial_plan', true)->first() ?? Plan::orderBy('sort')->firstOrFail();

            Subscription::create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'seats' => max($plan->min_seats, 3),
                'status' => SubscriptionStatus::Trialing,
                'current_period_start' => now(),
                'current_period_end' => $trialEnds,
            ]);

            AiSetting::create(['workspace_id' => $workspace->id]);

            Widget::create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'texts' => collect(config('chat.locales'))->mapWithKeys(fn (string $locale) => [$locale => ['title' => $name]])->all(),
            ]);

            $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }

    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        $workspace->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);

        if (! $user->current_workspace_id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;
        while (Workspace::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
