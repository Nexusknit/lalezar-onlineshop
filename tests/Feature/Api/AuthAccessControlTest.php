<?php

namespace Tests\Feature\Api;

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

    protected function challengeKey(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return 'auth:challenge:'.$normalized;
    }
}
