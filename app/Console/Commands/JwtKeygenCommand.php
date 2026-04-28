<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * php artisan pandora:jwt:keygen
 *
 * 產生 RS256 keypair 給 dev / staging 用。Production 由 KMS 注入（ADR-006）。
 */
class JwtKeygenCommand extends Command
{
    protected $signature = 'pandora:jwt:keygen
        {--force : Overwrite existing keypair}';

    protected $description = 'Generate RS256 keypair for JWT signing (dev/staging only)';

    public function handle(): int
    {
        $privatePath = base_path((string) config('pandora_jwt.private_key_path'));
        $publicPath = base_path((string) config('pandora_jwt.public_key_path'));

        $dir = dirname($privatePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::exists($privatePath) && ! $this->option('force')) {
            $this->error("Keypair already exists at {$privatePath}. Use --force to overwrite.");

            return self::FAILURE;
        }

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);
        if ($resource === false) {
            $this->error('Failed to generate RSA keypair.');

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['key'])) {
            $this->error('Failed to export public key.');

            return self::FAILURE;
        }
        $publicPem = $details['key'];

        File::put($privatePath, $privatePem);
        File::chmod($privatePath, 0600);
        File::put($publicPath, $publicPem);
        File::chmod($publicPath, 0644);

        $this->info("✔ Private key: {$privatePath}");
        $this->info("✔ Public  key: {$publicPath}");
        $this->warn('⚠ Production: do NOT commit. Use AWS KMS (ADR-006) to inject keys.');

        return self::SUCCESS;
    }
}
