<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->json('social_links')->nullable()->after('pre_chat_form');
            $table->boolean('show_social_links')->default(false)->after('social_links');
            $table->boolean('hide_when_offline')->default(false)->after('show_social_links');
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn(['social_links', 'show_social_links', 'hide_when_offline']);
        });
    }
};
