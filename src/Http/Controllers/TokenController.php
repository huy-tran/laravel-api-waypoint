<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Http\Controllers;

use Hygo\ApiWaypoint\Contracts\ResolvesWaypointUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * Mints a short-lived Sanctum token for a declared waypoint role.
 *
 * Two rules make this safe to expose:
 *
 *  - only role names present in config('api-waypoint.tokens.roles') are accepted,
 *  - the user the token is minted for must have the waypoint email for that role,
 *    checked here rather than trusted from the resolver, so a real customer
 *    account cannot be impersonated even by a mistaken resolver.
 */
class TokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('api-waypoint.tokens.enabled', true)) {
            abort(404);
        }

        if (! class_exists(Sanctum::class)) {
            return response()->json([
                'message' => 'Token minting requires laravel/sanctum, which is not installed.',
                'code' => 'waypoint.sanctum_missing',
            ], 501);
        }

        /** @var array<string, array<string, mixed>> $roles */
        $roles = (array) config('api-waypoint.tokens.roles', []);
        $role = (string) $request->input('role', '');

        if (! isset($roles[$role])) {
            return response()->json([
                'message' => sprintf('Role `%s` is not an allowed waypoint role.', $role),
                'code' => 'waypoint.role_not_allowed',
                'allowed_roles' => array_keys($roles),
            ], 422);
        }

        $definition = $roles[$role];
        $email = $this->emailFor($role);
        $user = $this->resolveUser($definition, $email, $role);

        if (! $this->emailMatches($user, $email)) {
            return response()->json([
                'message' => sprintf(
                    'The resolver for role `%s` returned a user whose email is not the waypoint address for that role. '
                    .'Refusing to mint a token for a non-waypoint account.',
                    $role
                ),
                'code' => 'waypoint.resolver_returned_foreign_user',
            ], 422);
        }

        $abilities = $this->abilities($request, $definition);
        $expiresAt = Carbon::now()->addMinutes($this->ttl($request));

        // Revoke first, so the tokens table does not grow by one row per click.
        $this->revokePrevious($user, $role);

        $token = $user->createToken($this->tokenName($role), $abilities, $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'header' => 'Authorization',
            'value_template' => 'Bearer {token}',
            'role' => $role,
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->getAttribute('name'),
                'email' => $email,
            ],
            'abilities' => $abilities,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function resolveUser(array $definition, string $email, string $role): Authenticatable
    {
        $resolver = $definition['resolver'] ?? null;

        if (! is_string($resolver) || ! class_exists($resolver)) {
            throw new RuntimeException(
                sprintf('Waypoint role [%s] has no usable resolver. Set one in config("api-waypoint.tokens.roles").', $role)
            );
        }

        $instance = app($resolver);

        if (! $instance instanceof ResolvesWaypointUser) {
            throw new RuntimeException(
                sprintf('[%s] must implement %s.', $resolver, ResolvesWaypointUser::class)
            );
        }

        return $instance->resolve($email, $role);
    }

    /**
     * waypoint+{role}@{host}, so waypoint users are identifiable at a glance and
     * prunable with one query.
     */
    protected function emailFor(string $role): string
    {
        $pattern = (string) config('api-waypoint.tokens.email_pattern', 'waypoint+{role}@{host}');
        $host = parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

        return str_replace(['{role}', '{host}'], [$role, (string) $host], $pattern);
    }

    protected function emailMatches(Authenticatable $user, string $email): bool
    {
        $actual = $user->getAttribute('email');

        return is_string($actual) && strcasecmp($actual, $email) === 0;
    }

    /**
     * A request may narrow the declared abilities but never widen them.
     *
     * @param array<string, mixed> $definition
     * @return array<int, string>
     */
    protected function abilities(Request $request, array $definition): array
    {
        $declared = array_values(array_map('strval', (array) Arr::get($definition, 'abilities', ['*'])));
        $requested = $request->input('abilities');

        if (! is_array($requested) || $requested === []) {
            return $declared;
        }

        if (in_array('*', $declared, true)) {
            return array_values(array_map('strval', $requested));
        }

        $narrowed = array_values(array_intersect($declared, array_map('strval', $requested)));

        return $narrowed === [] ? $declared : $narrowed;
    }

    protected function ttl(Request $request): int
    {
        $max = (int) config('api-waypoint.tokens.max_ttl_minutes', 240);
        $default = (int) config('api-waypoint.tokens.default_ttl_minutes', 60);
        $requested = (int) $request->input('ttl_minutes', $default);

        return max(1, min($requested, $max));
    }

    protected function tokenName(string $role): string
    {
        return 'api-waypoint:'.$role;
    }

    protected function revokePrevious(Authenticatable $user, string $role): void
    {
        if (! method_exists($user, 'tokens')) {
            return;
        }

        $user->tokens()->where('name', $this->tokenName($role))->delete();
    }
}
