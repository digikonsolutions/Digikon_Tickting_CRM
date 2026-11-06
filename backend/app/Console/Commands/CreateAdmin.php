<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {name} {email}';

    protected $description = 'Create an administrator account';

    public function handle(): int
    {
        $data = [
            'name' => trim((string) $this->argument('name')),
            'email' => Str::lower(trim((string) $this->argument('email'))),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Confirm password');
        $passwordValidator = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => [
                'required',
                'string',
                'max:128',
                'confirmed',
                PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            ...$data,
            'password' => $password,
            'role' => Role::Admin,
            'agency_id' => null,
            'is_active' => true,
        ]);

        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
