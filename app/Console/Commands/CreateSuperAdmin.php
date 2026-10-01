<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-super-admin {--email=} {--name=}')]
#[Description('Create a platform super-admin (has no company membership)')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: text('Email', required: true);
        $name = $this->option('name') ?: text('Name', required: true);
        $password = password('Password', required: true);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        $this->info("Super-admin {$user->email} created. They must enable 2FA on first sign-in.");

        return self::SUCCESS;
    }
}
