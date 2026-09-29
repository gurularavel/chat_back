<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target for the first operator reply after a handoff; null = SLA off.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedSmallInteger('sla_first_response_minutes')->nullable()->after('timezone');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('handoff_at')->nullable()->after('was_handed_off');
            $table->timestamp('first_response_at')->nullable()->after('handoff_at');
            $table->timestamp('sla_due_at')->nullable()->after('first_response_at');
            $table->timestamp('sla_breached_at')->nullable()->after('sla_due_at');
            $table->index(['sla_due_at', 'first_response_at', 'sla_breached_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['sla_due_at', 'first_response_at', 'sla_breached_at']);
            $table->dropColumn(['handoff_at', 'first_response_at', 'sla_due_at', 'sla_breached_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('sla_first_response_minutes');
        });
    }
};
