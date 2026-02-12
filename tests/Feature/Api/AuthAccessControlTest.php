<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    protected function challengeKey(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return 'auth:challenge:'.$normalized;
    }
}
