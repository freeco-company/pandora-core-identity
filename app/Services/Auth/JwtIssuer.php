<?php

namespace App\Services\Auth;

use App\Models\GroupUser;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * RS256 access token 簽發 / 驗證。
 *
 * Token 內容（ADR-001 §2.1）：
 *   iss       pandora-core
 *   sub       group_user_id (UUID v7)
 *   aud       product_code
 *   exp/iat
 *   jti       UUID v7（給 token blacklist 用）
 *   scopes    array
 *   product_code  顯式重複 aud（讓非 PHP App 不用解析 aud 陣列）
 */
class JwtIssuer
{
    private ?Configuration $config = null;

    /**
     * @param  list<string>  $scopes
     */
    public function issueAccessToken(GroupUser $user, string $productCode, array $scopes = []): string
    {
        $this->ensureProductAllowed($productCode);

        $now = new DateTimeImmutable;
        $ttl = (int) config('pandora_jwt.access_ttl');

        $token = $this->config()->builder()
            ->issuedBy((string) config('pandora_jwt.issuer'))
            ->relatedTo($user->id)
            ->permittedFor($productCode)
            ->identifiedBy((string) Uuid::v7())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$ttl} seconds"))
            ->withClaim('scopes', $scopes)
            ->withClaim('product_code', $productCode)
            ->getToken($this->config()->signer(), $this->config()->signingKey());

        return $token->toString();
    }

    /**
     * @throws RuntimeException when token is invalid / expired / wrong issuer / wrong audience
     */
    public function verify(string $jwt, string $expectedProduct): Plain
    {
        $config = $this->config();

        $token = $config->parser()->parse($jwt);
        if (! $token instanceof Plain) {
            throw new RuntimeException('Token is not a Plain JWT.');
        }

        $constraints = [
            new SignedWith($config->signer(), $config->verificationKey()),
            new IssuedBy((string) config('pandora_jwt.issuer')),
            new PermittedFor($expectedProduct),
            new StrictValidAt(SystemClock::fromUTC()),
        ];

        try {
            $config->validator()->assert($token, ...$constraints);
        } catch (\Throwable $e) {
            throw new RuntimeException('JWT validation failed: '.$e->getMessage(), 0, $e);
        }

        return $token;
    }

    public function getPublicKeyPem(): string
    {
        $path = base_path((string) config('pandora_jwt.public_key_path'));
        if (! File::exists($path)) {
            throw new RuntimeException("Public key not found at {$path}. Run: php artisan pandora:jwt:keygen");
        }

        return (string) File::get($path);
    }

    private function ensureProductAllowed(string $code): void
    {
        $allowed = (array) config('pandora_jwt.allowed_products');
        if (! in_array($code, $allowed, true)) {
            throw new InvalidArgumentException("Product code '{$code}' not in allowed list.");
        }
    }

    private function config(): Configuration
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $privPath = base_path((string) config('pandora_jwt.private_key_path'));
        $pubPath = base_path((string) config('pandora_jwt.public_key_path'));

        if (! File::exists($privPath) || ! File::exists($pubPath)) {
            throw new RuntimeException(
                'JWT keypair missing. Run: php artisan pandora:jwt:keygen'
            );
        }

        $this->config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::file($privPath),
            InMemory::file($pubPath),
        );

        return $this->config;
    }
}
