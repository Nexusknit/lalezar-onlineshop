<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/auth/login',
        operationId: 'authLogin',
        summary: 'Request a one-time verification code',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '09120000000'),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verification code generated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Verification code sent successfully.'),
                        new OA\Property(property: 'token', type: 'string', example: '123456', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        abort_if($phone === null, 422, 'Phone number format is invalid.');

        $token = (string) random_int(100_000, 999_999);

        $challenge = [
            'phone' => $phone,
            'token' => $token,
            'expires_at' => now()->addMinutes(5),
        ];

        Cache::put($this->challengeKey($phone), $challenge, now()->addMinutes(5));

        $payload = [
            'message' => 'Verification code sent successfully.',
        ];

        if (app()->environment(['local', 'development'])) {
            $payload['token'] = $token;
        }

        return response()->json($payload);
    }

    #[OA\Post(
        path: '/api/auth/verify',
        operationId: 'authVerify',
        summary: 'Verify the code and obtain an access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'token'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '09120000000'),
                    new OA\Property(property: 'token', type: 'string', example: '123456'),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User authenticated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Authentication successful.'),
                        new OA\Property(property: 'token', type: 'string', example: '1|X7Y8Z...'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Invalid or expired code'),
        ]
    )]
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'token' => ['required', 'string', 'size:6'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        abort_if($phone === null, 422, 'Phone number format is invalid.');

        $challenge = Cache::get($this->challengeKey($phone));

        if (! $challenge) {
            abort(422, 'No verification challenge found. Please request a new code.');
        }

        if (($challenge['phone'] ?? null) !== $phone || ! hash_equals((string) ($challenge['token'] ?? ''), (string) $data['token'])) {
            abort(422, 'Invalid phone number or verification code.');
        }

        $expiresAt = $challenge['expires_at'] ?? null;

        if ($expiresAt) {
            $expiration = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse($expiresAt);

            if (now()->greaterThan($expiration)) {
                Cache::forget($this->challengeKey($phone));

                abort(422, 'Verification code has expired. Please request a new code.');
            }
        }

        $user = User::query()->firstOrNew([
            'phone' => $phone,
        ]);

        if (! $user->exists) {
            $user->fill([
                'name' => $data['name'] ?? 'User '.Str::upper(Str::random(6)),
                'email' => $this->resolveEmail($data),
                'password' => Hash::make(Str::random(32)),
            ]);
        } else {
            if (! empty($data['name'])) {
                $user->name = $data['name'];
            }

            if (! empty($data['email'])) {
                $user->email = $data['email'];
            }
        }

        $user->save();

        if ($user->accessibility === false) {
            abort(403, 'Your account access has been disabled.');
        }

        Cache::forget($this->challengeKey($phone));

        $token = $user->createToken('auth-token')->plainTextToken;
        $user->load([
            'roles:id,name,slug',
            'roles.permissions:id,name,slug',
            'permissions:id,name,slug',
        ]);

        $permissions = $user->roles
            ->flatMap->permissions
            ->concat($user->permissions)
            ->unique('id')
            ->values()
            ->all();

        return response()->json([
            'message' => 'Authentication successful.',
            'user' => $user,
            'permissions' => $permissions,
            'token' => $token,
        ]);
    }

    protected function resolveEmail(array $data): string
    {
        if (! empty($data['email'])) {
            return $data['email'];
        }

        $numericPhone = preg_replace('/\D+/', '', $data['phone']);
        $fallback = $numericPhone !== '' ? $numericPhone : Str::lower(Str::random(10));

        return sprintf('%s@phone.local', $fallback);
    }

    protected function challengeKey(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return 'auth:challenge:'.$normalized;
    }

    protected function normalizePhone(string $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        if (! preg_match('/^\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }
}
