<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminAction
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'otp',
        'authorization',
        'file',
        'files',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldAudit($request, $response)) {
            return $response;
        }

        try {
            $target = $this->resolveTarget($request);

            AuditLog::query()->create([
                'actor_id' => $request->user()?->id,
                'auditable_type' => $target?->getMorphClass(),
                'auditable_id' => $target?->getKey(),
                'event' => $request->route()?->getName() ?? $request->path(),
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'input' => $this->sanitize($request->except(self::SENSITIVE_KEYS)),
                    'route_parameters' => $this->routeParameters($request),
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function shouldAudit(Request $request, Response $response): bool
    {
        return str_starts_with($request->path(), 'api/admin/')
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->getStatusCode() < 500;
    }

    private function resolveTarget(Request $request): ?Model
    {
        foreach ($request->route()?->parametersWithoutNulls() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParameters(Request $request): array
    {
        $parameters = [];

        foreach ($request->route()?->parametersWithoutNulls() ?? [] as $key => $parameter) {
            if ($parameter instanceof Model) {
                $parameters[$key] = [
                    'type' => $parameter->getMorphClass(),
                    'id' => $parameter->getKey(),
                ];

                continue;
            }

            $parameters[$key] = $parameter;
        }

        return $parameters;
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->reject(fn ($item, $key): bool => in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true))
                ->map(fn ($item): mixed => $this->sanitize($item))
                ->all();
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 500);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return Arr::accessible($value) ? $this->sanitize((array) $value) : '[unserializable]';
    }
}
