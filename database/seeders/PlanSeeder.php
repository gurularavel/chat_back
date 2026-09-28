<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'trial',
                'name' => ['az' => 'Sınaq', 'en' => 'Trial', 'ru' => 'Пробный'],
                'price_per_seat' => 0,
                'min_seats' => 1,
                'max_seats' => 3,
                'limits' => ['widgets' => 1, 'documents' => 5, 'max_pdf_mb' => 10, 'total_storage_mb' => 50, 'conversations_per_month' => 200],
                'features' => ['ai_answers', 'live_chat'],
                'is_trial_plan' => true,
                'sort' => 0,
            ],
            [
                'code' => 'starter',
                'name' => ['az' => 'Başlanğıc', 'en' => 'Starter', 'ru' => 'Старт'],
                'description' => ['az' => 'Kiçik komandalar üçün', 'en' => 'For small teams', 'ru' => 'Для небольших команд'],
                'price_per_seat' => 15,
                'min_seats' => 1,
                'max_seats' => 5,
                'limits' => ['widgets' => 1, 'documents' => 20, 'max_pdf_mb' => 20, 'total_storage_mb' => 200, 'conversations_per_month' => 1000],
                'features' => ['ai_answers', 'live_chat', 'email_support'],
                'sort' => 1,
            ],
            [
                'code' => 'business',
                'name' => ['az' => 'Biznes', 'en' => 'Business', 'ru' => 'Бизнес'],
                'description' => ['az' => 'Böyüyən komandalar üçün', 'en' => 'For growing teams', 'ru' => 'Для растущих команд'],
                'price_per_seat' => 29,
                'min_seats' => 2,
                'max_seats' => 50,
                'limits' => ['widgets' => 5, 'documents' => 200, 'max_pdf_mb' => 50, 'total_storage_mb' => 2000, 'conversations_per_month' => 10000],
                'features' => ['ai_answers', 'live_chat', 'multiple_widgets', 'priority_support'],
                'sort' => 2,
            ],
            [
                'code' => 'enterprise',
                'name' => ['az' => 'Korporativ', 'en' => 'Enterprise', 'ru' => 'Корпоративный'],
                'description' => ['az' => 'Limitsiz imkanlar', 'en' => 'Unlimited scale', 'ru' => 'Без ограничений'],
                'price_per_seat' => 49,
                'min_seats' => 5,
                'max_seats' => null,
                'limits' => ['widgets' => null, 'documents' => null, 'max_pdf_mb' => 100, 'total_storage_mb' => null, 'conversations_per_month' => null],
                'features' => ['ai_answers', 'live_chat', 'multiple_widgets', 'priority_support', 'sla'],
                'sort' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['currency' => 'AZN', 'interval' => 'month', 'is_active' => true]);
        }
    }
}
