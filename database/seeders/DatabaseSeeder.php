<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Workspaces\WorkspaceService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        // Demo accounts with known passwords are for local development only.
        // In production create the superadmin with: php artisan chat:superadmin you@example.com
        if (app()->isProduction()) {
            return;
        }

        User::updateOrCreate(['email' => 'admin@chat.test'], [
            'name' => 'Super Admin',
            'password' => 'password',
            'locale' => 'az',
        ])->forceFill(['is_superadmin' => true, 'email_verified_at' => now()])->save();

        if (! User::where('email', 'demo@chat.test')->exists()) {
            $demo = User::create(['name' => 'Demo Owner', 'email' => 'demo@chat.test', 'password' => 'password', 'locale' => 'az']);
            $demo->forceFill(['email_verified_at' => now()])->save();
            app(WorkspaceService::class)->create($demo, 'Demo Company');
        }
    }
}
