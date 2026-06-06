<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    private const GROUPS = ['general', 'payment', 'shipping'];

    public function __construct()
    {
        $this->middleware('permission:setting.all')->only('index');
        $this->middleware('permission:setting.update')->only('update');
    }

    public function index(): JsonResponse
    {
        $stored = Setting::query()
            ->whereIn('group', self::GROUPS)
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(fn (Setting $setting) => [
                $setting->key => $setting->value,
            ]));

        return response()->json([
            'settings' => collect(self::GROUPS)->mapWithKeys(fn (string $group) => [
                $group => $stored->get($group, collect()),
            ]),
            'environment' => [
                'site_name' => config('app.name'),
                'site_url' => config('app.url'),
                'payment_driver' => config('payment.default', env('PAYMENT_DRIVER', 'mock')),
                'sms_driver' => config('otp.driver', env('OTP_DRIVER', 'log')),
                'secrets_managed_by_env' => true,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', Rule::in(self::GROUPS)],
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        DB::transaction(function () use ($data): void {
            foreach ($data['settings'] as $key => $value) {
                abort_unless(preg_match('/^[a-z][a-z0-9_]{1,99}$/', (string) $key), 422, 'Invalid setting key.');

                Setting::query()->updateOrCreate(
                    ['group' => $data['group'], 'key' => $key],
                    [
                        'value' => $value,
                        'type' => $this->resolveType($value),
                        'is_public' => $data['group'] === 'general',
                    ]
                );
            }
        });

        return $this->index();
    }

    private function resolveType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value) => 'array',
            default => 'string',
        };
    }
}
