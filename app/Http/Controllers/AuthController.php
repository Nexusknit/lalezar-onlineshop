<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Auth\OtpNotificationService;
use App\Support\Phone\IranPhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(
        protected OtpNotificationService $otpNotificationService
    )
    {
    }

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

        $phone = IranPhoneNormalizer::normalizeOrFail($data['phone']);
        $this->abortIfOtpLocked($phone);

        $token = (string) random_int(100_000, 999_999);
        $ttlMinutes = max(1, (int) config('otp.ttl_minutes', 5));

        $challenge = [
            'phone' => $phone,
            'token' => $token,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'attempts' => 0,
        ];

        Cache::put($this->challengeKey($phone), $challenge, now()->addMinutes($ttlMinutes));
        try {
            $this->otpNotificationService->send($phone, $token);
        } catch (\Throwable $exception) {
            Cache::forget($this->challengeKey($phone));
            throw $exception;
        }

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

        $phone = IranPhoneNormalizer::normalizeOrFail($data['phone']);
        $this->abortIfOtpLocked($phone);

        $challenge = Cache::get($this->challengeKey($phone));

        if (! $challenge) {
            abort(422, 'No verification challenge found. Please request a new code.');
        }

        if (($challenge['phone'] ?? null) !== $phone || ! hash_equals((string) ($challenge['token'] ?? ''), (string) $data['token'])) {
            $this->registerFailedOtpAttempt($phone, $challenge);

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

        $expiresAt = now()->addMinutes(max((int) config('sanctum.expiration', 43200), 1));
        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
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
            'expires_at' => $expiresAt->toISOString(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    protected function resolveEmail(array $data): string
    {
        if (! empty($data['email'])) {
            return $data['email'];
        }

        $numericPhone = preg_replace('/\D+/', '', IranPhoneNormalizer::normalize($data['phone']) ?? $data['phone']);
        $fallback = $numericPhone !== '' ? $numericPhone : Str::lower(Str::random(10));

        return sprintf('%s@phone.local', $fallback);
    }

    protected function challengeKey(string $phone): string
    {
        $normalized = IranPhoneNormalizer::normalize($phone) ?? preg_replace('/\D+/', '', $phone);

        return 'auth:challenge:'.$normalized;
    }

    protected function lockKey(string $phone): string
    {
        return 'auth:otp-lock:'.(IranPhoneNormalizer::normalize($phone) ?? preg_replace('/\D+/', '', $phone));
    }

    protected function abortIfOtpLocked(string $phone): void
    {
        if (Cache::has($this->lockKey($phone))) {
            abort(429, 'Too many invalid verification attempts. Please request a new code later.');
        }
    }

    /**
     * @param  array<string, mixed>  $challenge
     */
    protected function registerFailedOtpAttempt(string $phone, array $challenge): void
    {
        $maxAttempts = max(1, (int) config('otp.max_verify_attempts', 5));
        $lockMinutes = max(1, (int) config('otp.lock_minutes', 15));
        $attempts = (int) ($challenge['attempts'] ?? 0) + 1;

        if ($attempts >= $maxAttempts) {
            Cache::forget($this->challengeKey($phone));
            Cache::put($this->lockKey($phone), [
                'phone' => $phone,
                'locked_at' => now()->toISOString(),
                'attempts' => $attempts,
            ], now()->addMinutes($lockMinutes));

            return;
        }

        $challenge['attempts'] = $attempts;
        $expiresAt = $challenge['expires_at'] ?? now()->addMinutes(max(1, (int) config('otp.ttl_minutes', 5)));
        $expiration = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse($expiresAt);

        Cache::put($this->challengeKey($phone), $challenge, $expiration->isFuture() ? $expiration : now()->addMinute());
    }
}
