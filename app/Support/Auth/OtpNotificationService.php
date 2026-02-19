<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpNotificationService
{
    public function send(string $phone, string $token): void
    {
        $provider = strtolower(trim((string) config('otp.provider', 'kavenegar')));

        if ($provider !== 'kavenegar') {
            throw ValidationException::withMessages([
                'phone' => ['OTP provider is not supported.'],
            ]);
        }

        $this->sendWithKavenegar($phone, $token);
    }

    protected function sendWithKavenegar(string $phone, string $token): void
    {
        $enabled = (bool) config('otp.providers.kavenegar.enabled', false);
        $apiKey = trim((string) config('services.kavenegar.api_key', ''));

        if (! $enabled) {
            if (app()->environment(['local', 'development', 'testing'])) {
                Log::info('OTP SMS provider is disabled; fallback to local token response.', [
                    'provider' => 'kavenegar',
                    'phone' => $phone,
                ]);

                return;
            }

            throw ValidationException::withMessages([
                'phone' => ['OTP SMS provider is not enabled.'],
            ]);
        }

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'phone' => ['Kavenegar API key is not configured.'],
            ]);
        }

        $timeout = max(3, (int) config('otp.providers.kavenegar.timeout', 10));
        $template = trim((string) config('otp.providers.kavenegar.template', ''));
        $sender = trim((string) config('otp.providers.kavenegar.sender', ''));

        if ($template !== '') {
            $response = Http::asForm()
                ->timeout($timeout)
                ->post("https://api.kavenegar.com/v1/{$apiKey}/verify/lookup.json", [
                    'receptor' => $phone,
                    'token' => $token,
                    'template' => $template,
                ]);
        } else {
            $messageTemplate = (string) config('otp.providers.kavenegar.message', 'کد تایید ورود شما: :token');
            $message = str_replace(':token', $token, $messageTemplate);

            $payload = [
                'receptor' => $phone,
                'message' => $message,
            ];
            if ($sender !== '') {
                $payload['sender'] = $sender;
            }

            $response = Http::asForm()
                ->timeout($timeout)
                ->post("https://api.kavenegar.com/v1/{$apiKey}/sms/send.json", $payload);
        }

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'phone' => ['Failed to send OTP SMS via Kavenegar.'],
            ]);
        }

        $body = $response->json();
        $status = (int) data_get($body, 'return.status', 0);
        if ($status !== 200) {
            $message = trim((string) data_get($body, 'return.message', ''));

            throw ValidationException::withMessages([
                'phone' => [$message !== '' ? $message : 'Kavenegar rejected OTP delivery request.'],
            ]);
        }
    }
}

