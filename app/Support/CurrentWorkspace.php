<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * Holds the workspace the current request (or job) is acting in.
 * Models using BelongsToWorkspace are scoped to it while it is set.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function check(): bool
    {
        return $this->workspace !== null;
    }

    /**
     * Run a callback with a different (or no) workspace, restoring afterwards.
     */
    public function runAs(?Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
