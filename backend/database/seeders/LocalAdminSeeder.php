<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Local admin seeding is only available in the local environment.');

            return;
        }

        $data = [
            'name' => env('LOCAL_ADMIN_NAME'),
            'email' => Str::lower(trim((string) env('LOCAL_ADMIN_EMAIL'))),
            'password' => env('LOCAL_ADMIN_PASSWORD'),
        ];

        if (! $data['name'] || ! $data['email'] || ! $data['password']) {
            $this->command?->info('Skipping local admin seed; configure LOCAL_ADMIN_NAME, LOCAL_ADMIN_EMAIL, and LOCAL_ADMIN_PASSWORD to seed one.');

            return;
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'max:128', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        User::create([
            ...$data,
            'role' => Role::Admin,
            'agency_id' => null,
            'is_active' => true,
        ]);
    }
}
