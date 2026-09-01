<?php

namespace App\Support\OutlookAddin;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

final class EntraAccessTokenValidator
{
    public function __construct(
        private readonly OutlookAddinConfiguration $configuration,
    ) {}

    public function validate(string $token): VerifiedEntraIdentity
    {
        $this->configuration->assertReady();

        $token = trim($token);
        $maximumLength = (int) config('outlook_addin.token.maximum_length', 16384);
        if ($token === '' || strlen($token) > $maximumLength) {
            throw $this->unauthorized();
        }

        $header = $this->unverifiedHeader($token);
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw $this->unauthorized();
        }

        try {
            $keys = $this->keys();
            if (! array_key_exists($header['kid'], $keys)) {
                $keys = $this->keys(refresh: true);
            }

            $previousLeeway = JWT::$leeway;
            JWT::$leeway = (int) config('outlook_addin.token.clock_skew_seconds', 60);
            try {
                $claims = JWT::decode($token, $keys);
            } finally {
                JWT::$leeway = $previousLeeway;
            }
        } catch (OutlookAddinException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unauthorized($exception);
        }

        return $this->validateClaims($claims);
    }

    /** @return array<string, mixed> */
    private function unverifiedHeader(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw $this->unauthorized();
        }

        $decoded = $this->base64UrlDecode($segments[0]);
        $header = json_decode($decoded, true);

        return is_array($header) ? $header : throw $this->unauthorized();
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : throw $this->unauthorized();
    }

    /** @return array<string, Key> */
    private function keys(bool $refresh = false): array
    {
        $tenantId = (string) config('outlook_addin.entra.tenant_id');
        $cacheKey = 'outlook-addin:jwks:'.hash('sha256', $tenantId);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('outlook_addin.token.jwks_cache_seconds', 21600)),
            function () use ($tenantId): array {
                $response = Http::acceptJson()
                    ->timeout(5)
                    ->connectTimeout(3)
                    ->get("https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys");

                if (! $response->successful() || ! is_array($response->json('keys'))) {
                    throw new OutlookAddinException(
                        'Die Microsoft-Signaturschlüssel konnten nicht sicher geladen werden.',
                        503,
                        'outlook_addin_identity_provider_unavailable',
                    );
                }

                return $response->json();
            },
        );

        try {
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable $exception) {
            throw new OutlookAddinException(
                'Die Microsoft-Signaturschlüssel sind ungültig.',
                503,
                'outlook_addin_identity_provider_invalid',
                $exception,
            );
        }
    }

    private function validateClaims(stdClass $claims): VerifiedEntraIdentity
    {
        $tenantId = (string) config('outlook_addin.entra.tenant_id');
        $expectedIssuer = "https://login.microsoftonline.com/{$tenantId}/v2.0";
        $audience = (string) config('outlook_addin.entra.audience');
        $scope = (string) config('outlook_addin.entra.scope');
        $authorizedClientId = (string) config('outlook_addin.entra.client_id');

        $audiences = is_array($claims->aud ?? null)
            ? $claims->aud
            : [(string) ($claims->aud ?? '')];
        $scopes = preg_split('/\s+/', trim((string) ($claims->scp ?? ''))) ?: [];
        $objectId = trim((string) ($claims->oid ?? ''));
        $authorizedParty = trim((string) ($claims->azp ?? $claims->appid ?? ''));
        $principal = trim((string) (
            $claims->preferred_username
                ?? $claims->upn
                ?? $claims->email
                ?? ''
        ));

        if (! hash_equals($tenantId, (string) ($claims->tid ?? ''))
            || ! hash_equals($expectedIssuer, rtrim((string) ($claims->iss ?? ''), '/'))
            || ! in_array($audience, $audiences, true)
            || ! in_array($scope, $scopes, true)
            || ! hash_equals($authorizedClientId, $authorizedParty)
            || ! preg_match('/^[0-9a-f-]{36}$/i', $objectId)
            || filter_var($principal, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->unauthorized();
        }

        return new VerifiedEntraIdentity(
            tenantId: $tenantId,
            objectId: strtolower($objectId),
            principal: strtolower($principal),
            displayName: trim((string) ($claims->name ?? '')),
        );
    }

    private function unauthorized(?Throwable $previous = null): OutlookAddinException
    {
        return new OutlookAddinException(
            'Die Microsoft-Anmeldung konnte nicht bestätigt werden.',
            401,
            'outlook_addin_unauthorized',
            $previous,
        );
    }
}
