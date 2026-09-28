<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('chat:superadmin {email} {--name=Super Admin}')]
#[Description('Create a superadmin user, or promote an existing user to superadmin')]
class CreateSuperadmin extends Command
{
    public function handle(): int
    {
        $email = strtolower($this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $password = $this->secret('Password (min. 8 characters)');
            if (strlen((string) $password) < 8) {
                $this->error('Password must be at least 8 characters.');

                return self::FAILURE;
            }

            $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'locale' => 'az']);
        }

        $user->forceFill(['is_superadmin' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("{$email} is now a superadmin.");

        return self::SUCCESS;
    }
}
