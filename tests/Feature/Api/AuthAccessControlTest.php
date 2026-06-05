<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_user_cannot_complete_verify_step(): void
    {
        $phone = '09120000003';
        $token = '123456';

        User::factory()->create([
            'phone' => $phone,
            'accessibility' => false,
        ]);

        Cache::put(
            $this->challengeKey($phone),
            [
                'phone' => $phone,
                'token' => $token,
                'expires_at' => now()->addMinutes(5),
            ],
            now()->addMinutes(5)
        );

        $this->postJson('/api/auth/verify', [
            'phone' => $phone,
            'token' => $token,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account access has been disabled.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verify_returns_user_with_roles_and_permissions_contract(): void
    {
        $phone = '09120000009';
        $token = '654321';

        $rolePermission = Permission::query()->create([
            'name' => 'News All',
            'slug' => 'news.all',
            'guard_name' => 'web',
        ]);
        $directPermission = Permission::query()->create([
            'name' => 'Product Update',
            'slug' => 'product.update',
            'guard_name' => 'web',
        ]);

        $role = Role::query()->create([
            'name' => 'Editor',
            'slug' => 'editor',
            'guard_name' => 'web',
        ]);
        $role->permissions()->sync([$rolePermission->id]);

        $user = User::factory()->create([
            'phone' => $phone,
            'accessibility' => true,
        ]);
        $user->roles()->sync([$role->id]);
        $user->permissions()->sync([$directPermission->id]);

        Cache::put(
            $this->challengeKey($phone),
            [
                'phone' => $phone,
                'token' => $token,
                'expires_at' => now()->addMinutes(5),
            ],
            now()->addMinutes(5)
        );

        $response = $this->postJson('/api/auth/verify', [
            'phone' => $phone,
            'token' => $token,
        ])->assertOk();

        $response
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0.slug', 'editor')
            ->assertJsonPath('user.roles.0.permissions.0.slug', 'news.all');

        $permissionSlugs = collect($response->json('permissions'))->pluck('slug');
        $this->assertTrue($permissionSlugs->contains('news.all'));
        $this->assertTrue($permissionSlugs->contains('product.update'));
    }

    public function test_verify_issues_expiring_token_and_logout_revokes_it(): void
    {
        $phone = '09120000019';
        $otp = '123123';

        Cache::put(
            $this->challengeKey($phone),
            [
                'phone' => $phone,
                'token' => $otp,
                'expires_at' => now()->addMinutes(5),
            ],
            now()->addMinutes(5)
        );

        $verify = $this->postJson('/api/auth/verify', [
            'phone' => $phone,
            'token' => $otp,
        ])->assertOk();

        $this->assertNotNull($verify->json('expires_at'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull(DB::table('personal_access_tokens')->value('expires_at'));

        $this->withHeader('Authorization', 'Bearer '.$verify->json('token'))
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_user_seeder_does_not_use_public_default_password(): void
    {
        config()->set('security.admin_seed.enabled', true);
        config()->set('security.admin_seed.email', 'admin@lalezar.local');
        config()->set('security.admin_seed.password', null);

        Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'guard_name' => 'web',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin@lalezar.local')->firstOrFail();

        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertTrue($admin->roles()->where('slug', 'super-admin')->exists());
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        RateLimiter::clear('auth-login:127.0.0.1:09120000088');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'phone' => '09120000088',
            ])->assertOk();
        }

        $this->postJson('/api/auth/login', [
            'phone' => '09120000088',
        ])->assertStatus(429);
    }

    public function test_verify_is_rate_limited_after_ten_attempts(): void
    {
        config()->set('otp.max_verify_attempts', 99);

        $phone = '09120000077';
        $correctToken = '123456';

        RateLimiter::clear('auth-verify:127.0.0.1:09120000077');

        Cache::put(
            $this->challengeKey($phone),
            [
                'phone' => $phone,
                'token' => $correctToken,
                'expires_at' => now()->addMinutes(5),
            ],
            now()->addMinutes(5)
        );

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/verify', [
                'phone' => $phone,
                'token' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/verify', [
            'phone' => $phone,
            'token' => '000000',
        ])->assertStatus(429);
    }

    public function test_otp_challenge_locks_after_repeated_invalid_codes(): void
    {
        config()->set('otp.max_verify_attempts', 3);
        config()->set('otp.lock_minutes', 15);

        $phone = '09120000078';
        $correctToken = '123456';

        Cache::put(
            $this->challengeKey($phone),
            [
                'phone' => $phone,
                'token' => $correctToken,
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
            ],
            now()->addMinutes(5)
        );

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/verify', [
                'phone' => $phone,
                'token' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/verify', [
            'phone' => $phone,
            'token' => $correctToken,
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many invalid verification attempts. Please request a new code later.');

        $this->postJson('/api/auth/login', [
            'phone' => $phone,
        ])->assertStatus(429);
    }

    public function test_login_and_verify_accept_normalized_phone_input(): void
    {
        $formattedPhone = '+98 (912) 000-0066';

        $this->postJson('/api/auth/login', [
            'phone' => $formattedPhone,
        ])->assertOk();

        $challenge = Cache::get($this->challengeKey('09120000066'));
        $this->assertIsArray($challenge);
        $token = (string) ($challenge['token'] ?? '');
        $this->assertNotSame('', $token);

        $verify = $this->postJson('/api/auth/verify', [
            'phone' => '989120000066',
            'token' => $token,
        ])->assertOk();

        $this->assertSame('09120000066', $verify->json('user.phone'));
    }

    public function test_login_rejects_non_iranian_mobile_number(): void
    {
        $this->postJson('/api/auth/login', [
            'phone' => '02112345678',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_profile_update_normalizes_mobile_number(): void
    {
        $user = User::factory()->create([
            'phone' => '09120000031',
            'accessibility' => true,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/profile', [
            'phone' => '+98 912 000 0032',
        ])
            ->assertOk()
            ->assertJsonPath('phone', '09120000032');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '09120000032',
        ]);
    }

    protected function challengeKey(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return 'auth:challenge:'.$normalized;
    }
}
