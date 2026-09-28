<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Invoices are now issued before payment (renewal notices), so the payment comes later.
            $table->foreignId('payment_id')->nullable()->change();
            $table->foreignId('subscription_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
            $table->string('status')->default('paid')->after('number'); // open, paid, void
            $table->string('type')->nullable()->after('status'); // initial, renewal, seat_upgrade
            $table->timestamp('period_start')->nullable()->after('type');
            $table->timestamp('period_end')->nullable()->after('period_start');
            $table->timestamp('due_at')->nullable()->after('period_end');
            $table->timestamp('paid_at')->nullable()->after('due_at');
            $table->decimal('subtotal', 10, 2)->default(0)->after('lines');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('subtotal');
            $table->decimal('tax', 10, 2)->default(0)->after('tax_rate');
            $table->json('seller')->nullable();
            $table->json('buyer')->nullable();
            $table->json('reminders')->nullable(); // reminder stages already emailed
            $table->index(['status', 'due_at']);
        });

        DB::table('invoices')->update(['paid_at' => DB::raw('created_at'), 'subtotal' => DB::raw('total')]);

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('timezone');
            $table->string('billing_email')->nullable()->after('billing_name');
            $table->string('billing_tax_id', 40)->nullable()->after('billing_email');
            $table->string('billing_address', 500)->nullable()->after('billing_tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['billing_name', 'billing_email', 'billing_tax_id', 'billing_address']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn(['status', 'type', 'period_start', 'period_end', 'due_at', 'paid_at', 'subtotal', 'tax_rate', 'tax', 'seller', 'buyer', 'reminders']);
        });
    }
};
