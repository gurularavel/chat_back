<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A plan now has a monthly and an optional yearly price; the customer picks the interval.
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('yearly_price_per_seat', 10, 2)->nullable()->after('price_per_seat'); // null = no yearly option
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('interval')->default('month')->after('seats'); // month, year
            // Given by a superadmin without payment: never charged, expires at the period end.
            $table->boolean('is_complimentary')->default(false)->after('status');
            $table->foreignId('granted_by')->nullable()->after('is_complimentary')->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable()->after('granted_by');
        });

        DB::table('subscriptions')->update([
            'interval' => DB::raw('(select plans.interval from plans where plans.id = subscriptions.plan_id)'),
        ]);
        DB::table('plans')->where('interval', 'year')->update(['yearly_price_per_seat' => DB::raw('price_per_seat')]);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('interval');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('interval')->default('month')->after('currency');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['interval', 'is_complimentary', 'granted_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('yearly_price_per_seat');
        });
    }
};
