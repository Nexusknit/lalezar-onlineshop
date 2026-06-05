<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! (bool) config('security.admin_seed.enabled', false)) {
            return;
        }

        $email = (string) config('security.admin_seed.email', 'admin@lalezar.local');
        $password = config('security.admin_seed.password');

        if (! is_string($password) || trim($password) === '') {
            $password = Str::password(32);

            $this->command?->warn('ADMIN_SEED_PASSWORD is not set; generated a random password for the seeded admin.');
        }

        $this->ensureSafePassword($password);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('security.admin_seed.name', 'Administrator'),
                'password' => Hash::make($password),
                'phone' => (string) config('security.admin_seed.phone', '09120000000'),
                'accessibility' => true,
            ],
        );

        $role = Role::query()->where('slug', 'super-admin')->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    private function ensureSafePassword(string $password): void
    {
        $unsafePasswords = array_map(
            static fn (string $value): string => strtolower($value),
            (array) config('security.admin_seed.unsafe_passwords', [])
        );

        if (in_array(strtolower($password), $unsafePasswords, true)) {
            throw new RuntimeException('ADMIN_SEED_PASSWORD is too weak for an administrator account.');
        }
    }
}
