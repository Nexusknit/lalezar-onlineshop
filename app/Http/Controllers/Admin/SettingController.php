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
    private const GROUPS = ['general', 'payment', 'shipping', 'accounting'];

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
                'accounting_provider' => config('accounting.provider', 'generic_rest'),
                'accounting_base_url' => config('accounting.base_url'),
                'accounting_health_path' => config('accounting.health_path', '/health'),
                'accounting_products_path' => config('accounting.products_path', '/products'),
                'accounting_invoices_path' => config('accounting.invoices_path', '/invoices'),
                'accounting_credentials_configured' => trim((string) config('accounting.token', '')) !== ''
                    || trim((string) config('accounting.api_key', '')) !== '',
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

        if ($data['group'] === 'accounting') {
            validator($data['settings'], [
                'enabled' => ['sometimes', 'boolean'],
                'provider' => ['sometimes', Rule::in(['generic_rest'])],
                'base_url' => ['nullable', 'url:http,https', 'max:500'],
                'health_path' => ['sometimes', 'string', 'max:200', 'regex:/^\\//'],
                'products_path' => ['sometimes', 'string', 'max:200', 'regex:/^\\//'],
                'invoices_path' => ['sometimes', 'string', 'max:200', 'regex:/^\\//'],
                'product_sync_enabled' => ['sometimes', 'boolean'],
                'invoice_sync_enabled' => ['sometimes', 'boolean'],
                'automatic_product_sync' => ['sometimes', 'boolean'],
            ])->validate();

            $forbiddenKeys = array_intersect(
                array_keys($data['settings']),
                ['token', 'api_key', 'password', 'secret', 'credentials']
            );
            abort_if($forbiddenKeys !== [], 422, 'Accounting credentials must be managed through environment variables.');
        }

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
