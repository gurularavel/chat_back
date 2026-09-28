<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Per-language overrides of widget texts: {"az": {"greeting": "…"}, "en": {…}}. */
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->json('texts')->nullable()->after('appearance');
        });

        // The header title (one for all languages) and greeting (per language) move into texts.
        DB::table('widgets')->orderBy('id')->each(function (object $widget) {
            $appearance = json_decode($widget->appearance ?? '{}', true) ?: [];
            $texts = [];

            foreach (config('chat.locales') as $locale) {
                if (! empty($appearance['title'])) {
                    $texts[$locale]['title'] = $appearance['title'];
                }
                if (! empty($appearance['greeting'][$locale])) {
                    $texts[$locale]['greeting'] = $appearance['greeting'][$locale];
                }
            }
            unset($appearance['title'], $appearance['greeting']);

            DB::table('widgets')->where('id', $widget->id)->update([
                'texts' => $texts ? json_encode($texts, JSON_UNESCAPED_UNICODE) : null,
                'appearance' => json_encode($appearance, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('texts');
        });
    }
};
