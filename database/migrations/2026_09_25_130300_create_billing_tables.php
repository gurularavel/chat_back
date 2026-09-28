<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('price_per_seat', 10, 2);
            $table->string('currency', 3)->default('AZN');
            $table->string('interval')->default('month'); // month, year
            $table->unsignedInteger('min_seats')->default(1);
            $table->unsignedInteger('max_seats')->nullable();
            $table->json('limits');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_trial_plan')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->text('token'); // encrypted cast
            $table->string('masked_pan')->nullable();
            $table->string('brand')->nullable();
            $table->string('expiry', 7)->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedInteger('seats')->default(1);
            $table->unsignedInteger('pending_seats')->nullable(); // seat decrease applied at next renewal
            $table->string('status')->default('trialing'); // trialing, active, past_due, canceled, expired
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedTinyInteger('renewal_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway');
            $table->string('gateway_order_id')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('AZN');
            $table->string('status')->default('pending'); // pending, paid, failed, refunded
            $table->string('type'); // initial, renewal, seat_upgrade
            $table->json('payload')->nullable(); // what we intend to apply on success (plan, seats)
            $table->json('raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->json('lines');
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('AZN');
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('plans');
    }
};
