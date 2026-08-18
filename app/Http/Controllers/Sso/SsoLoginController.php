<?php

namespace App\Http\Controllers\Sso;

use App\Application\Sso\CentralLoginUrl;
use App\Application\Sso\PermissionService;
use App\Application\Sso\SsoTokenValidator;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SsoLoginController extends Controller
{
    public function __invoke(
        Request $request,
        SsoTokenValidator $validator,
        PermissionService $permissions,
        CentralLoginUrl $central,
    ) {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return redirect()->away($central->loginUrl());
        }

        try {
            $claims = $validator->validate($token);
            $userId = $validator->userId($claims);
            $map = $permissions->fromTokenClaims($claims);

            if ($map === null) {
                $map = $permissions->fetch(
                    $userId,
                    (string) config('gpha_sso.module_id')
                );
            } else {
                $permissions->cache(
                    $userId,
                    (string) config('gpha_sso.module_id'),
                    $map
                );
            }

            if (! collect($map)->contains(
                fn ($value): bool => is_array($value) && $value !== []
            )) {
                return response()->view('errors.sso-access', [
                    'title' => 'EMS access has not been assigned',
                    'message' => 'Your Central Login account has no permissions assigned for EMS.',
                ], 403);
            }
        } catch (RuntimeException $exception) {
            Log::warning('EMS SSO sign-in failed', [
                'reason' => $exception->getMessage(),
                'token_fingerprint' => substr(hash('sha256', $token), 0, 12),
            ]);

            return response()->view('errors.sso-access', [
                'title' => 'Central Login sign-in failed',
                'message' => $exception->getMessage(),
            ], str_contains(
                strtolower($exception->getMessage()),
                'permission'
            ) ? 503 : 401);
        }

        $username = (string) (
            $claims['username']
            ?? $claims['unique_name']
            ?? $userId
        );

        $name = (string) (
            $claims['Staff Name']
            ?? $claims['fullname']
            ?? $username
        );

        $email = $this->email($claims, $userId);

        $user = DB::transaction(
            function () use (
                $claims,
                $email,
                $name,
                $userId,
                $username
            ): User {
                $user = User::firstOrNew([
                    'sso_user_id' => $userId,
                ]);

                $user->fill([
                    'sso_username' => $username,
                    'name' => $name,
                    'email' => $email,
                    'sso_claims' => $claims,
                    'branch_ids' => array_values(
                        (array) ($claims['branch_id'] ?? [])
                    ),
                    'branch_codes' => array_values(
                        (array) ($claims['branch_code'] ?? [])
                    ),
                    'branch_names' => array_values(
                        (array) ($claims['branch_name'] ?? [])
                    ),
                ]);

                if (! $user->exists) {
                    $user->password = Str::random(48);
                }

                $user->save();

                return $user;
            }
        );

        auth()->login($user);

        $request->session()->regenerate();

        $request->session()->put([
            'sso.module_id' => config('gpha_sso.module_id'),
            'sso.permissions' => $map,
            'sso.permissions_synced_at' => now()->timestamp,

            'sso.branches' => [
                'ids' => $user->branch_ids ?? [],
                'codes' => $user->branch_codes ?? [],
                'names' => $user->branch_names ?? [],
            ],

            'sso.active_branch_code' =>
                ($user->branch_codes ?? [])[0] ?? null,
        ]);

        /*
         * Laravel stores the URL that originally triggered authentication
         * in the session as "url.intended".
         *
         * Because EMS runs behind nginx/IIS, that URL may sometimes contain
         * the internal IIS address:
         *
         *     https://172.16.0.81/ems/reports
         *
         * Before redirecting the user after SSO login, convert the intended
         * URL to the public EMS origin:
         *
         *     https://gpha-apps.ghanaports.net/ems/reports
         *
         * The page path and query string are preserved.
         */
        $intended = $request->session()->get('url.intended');

        if (is_string($intended) && $intended !== '') {
            $request->session()->put(
                'url.intended',
                $this->normaliseIntendedUrl($intended)
            );
        }

        return redirect()->intended(
            rtrim((string) config('app.url'), '/') . '/dashboard'
        );
    }

    /**
     * Convert an intended EMS URL to the configured public EMS origin.
     *
     * Only URLs that point to an EMS path are preserved. Anything else
     * falls back safely to the EMS dashboard.
     */
    private function normaliseIntendedUrl(string $url): string
    {
        $appUrl = rtrim(
            (string) config('app.url'),
            '/'
        );

        $appParts = parse_url($appUrl);
        $urlParts = parse_url($url);

        if (
            $appParts === false ||
            $urlParts === false
        ) {
            return $appUrl . '/dashboard';
        }

        $appPath = rtrim(
            $appParts['path'] ?? '/ems',
            '/'
        );

        $path = $urlParts['path'] ?? '';

        /*
         * Only allow destinations inside the EMS application.
         *
         * Examples:
         *
         * /ems/reports
         * /ems/operations/mileage
         */
        if (
            $path !== $appPath &&
            ! str_starts_with($path, $appPath . '/')
        ) {
            return $appUrl . '/dashboard';
        }

        $relativePath = substr(
            $path,
            strlen($appPath)
        );

        $query = isset($urlParts['query'])
            ? '?' . $urlParts['query']
            : '';

        return $appUrl . $relativePath . $query;
    }

    private function email(
        array $claims,
        string $userId
    ): string {
        foreach (
            ['email', 'Email', 'emailaddress', 'mail']
            as $key
        ) {
            if (
                filter_var(
                    $claims[$key] ?? null,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                return $claims[$key];
            }
        }

        return $userId . '@sso.gpha.local';
    }
}