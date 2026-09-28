<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder) {
            $current = app(CurrentWorkspace::class);

            if ($current->check()) {
                $builder->where($builder->qualifyColumn('workspace_id'), $current->id());
            }
        });

        static::creating(function ($model) {
            $current = app(CurrentWorkspace::class);

            if (! $model->workspace_id && $current->check()) {
                $model->workspace_id = $current->id();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
