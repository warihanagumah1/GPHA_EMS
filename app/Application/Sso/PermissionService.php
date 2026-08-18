<?php

namespace App\Application\Sso;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PermissionService
{
    public function fetch(string $userId, string $moduleId): array
    {
        $ttl = (int) config('gpha_sso.permission_cache_seconds');

        if ($ttl <= 0) {
            return $this->fetchFromCentralLogin($userId, $moduleId);
        }

        return Cache::remember(
            $this->cacheKey($userId, $moduleId),
            $ttl,
            fn (): array => $this->fetchFromCentralLogin($userId, $moduleId),
        );
    }

    public function fromTokenClaims(array $claims): ?array
    {
        foreach (['permissionCodes', 'permission_codes'] as $claim) {
            if (array_key_exists($claim, $claims)) {
                return $this->normalize($this->codes($claims[$claim]));
            }
        }

        return null;
    }

    public function cache(string $userId, string $moduleId, array $permissions): void
    {
        $ttl = (int) config('gpha_sso.permission_cache_seconds');

        if ($ttl > 0) {
            Cache::put($this->cacheKey($userId, $moduleId), $permissions, $ttl);
        }
    }

    public function allows(string $component, string $permission): bool
    {
        $map = session('sso.permissions', []);

        return in_array(
            $this->key($permission),
            (array) ($map[$this->key($component)] ?? []),
            true,
        );
    }

    public function hasAny(): bool
    {
        return collect(session('sso.permissions', []))
            ->contains(fn ($value): bool => is_array($value) && $value !== []);
    }

    private function fetchFromCentralLogin(string $userId, string $moduleId): array
    {
        try {
            $response = Http::connectTimeout(config('gpha_sso.connect_timeout_seconds'))
                ->timeout(config('gpha_sso.timeout_seconds'))
                ->acceptJson()
                ->withHeaders([
                    'X-App-Id' => config('gpha_sso.app_id'),
                    'X-App-Key' => config('gpha_sso.app_key'),
                ])
                ->get(config('gpha_sso.permissions_endpoint'), [
                    'userId' => $userId,
                    'moduleId' => $moduleId,
                ]);
        } catch (Throwable $exception) {
            Log::error('CentralLogin permission lookup failed', [
                'reason' => $exception->getMessage(),
            ]);

            throw new RuntimeException('CentralLogin permissions are temporarily unavailable.');
        }

        if (! $response->successful()) {
            Log::error('CentralLogin permission lookup rejected', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                $response->status() === 401
                    ? 'CentralLogin rejected the EMS trusted application credentials.'
                    : 'CentralLogin permissions are temporarily unavailable.',
            );
        }

        return $this->normalize($this->codes($response->json()));
    }

    private function cacheKey(string $userId, string $moduleId): string
    {
        return 'gpha_sso.permissions.'.hash(
            'sha256',
            implode('|', [(string) config('gpha_sso.app_id'), $moduleId, $userId]),
        );
    }

    private function normalize(array $codes): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            $parts = explode('.', $code);

            if (count($parts) < 3 || strcasecmp($parts[0], config('gpha_sso.app_id')) !== 0) {
                continue;
            }

            $component = $this->key($parts[count($parts) - 2]);
            $permission = $this->key($parts[count($parts) - 1]);
            $normalized[$component][] = $permission;
        }

        return array_map(
            fn (array $permissions): array => array_values(array_unique($permissions)),
            $normalized,
        );
    }

    private function codes(mixed $payload): array
    {
        $found = [];
        $walk = function (mixed $value) use (&$walk, &$found): void {
            if (is_string($value) && substr_count($value, '.') >= 2) {
                $found[] = $value;
            } elseif (is_array($value)) {
                foreach (['permissionCode', 'code', 'fullCode', 'name'] as $key) {
                    if (isset($value[$key]) && is_string($value[$key]) && substr_count($value[$key], '.') >= 2) {
                        $found[] = $value[$key];
                    }
                }

                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };

        $walk($payload);

        return array_values(array_unique($found));
    }

    private function key(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $value) ?? '');
    }
}
