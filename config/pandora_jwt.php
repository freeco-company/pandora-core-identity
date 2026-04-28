<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RS256 Keypair Paths
    |--------------------------------------------------------------------------
    |
    | Dev: 用 ``php artisan pandora:jwt:keygen`` 產生到 storage/keys/。
    | Prod: 應由 KMS（AWS KMS，見 ADR-006）注入 PEM 檔，不應 commit 進 repo。
    |
    */
    'private_key_path' => env('JWT_PRIVATE_KEY_PATH', 'storage/keys/jwt-private.pem'),
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', 'storage/keys/jwt-public.pem'),

    /*
    |--------------------------------------------------------------------------
    | TTLs
    |--------------------------------------------------------------------------
    | access_ttl = 15 minutes（短 TTL 讓被偷的 token 爆炸半徑小）
    | refresh_ttl = 30 days（沿襲一般 mobile App OAuth 規範）
    */
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Issuer / Audience
    |--------------------------------------------------------------------------
    */
    'issuer' => env('JWT_ISSUER', 'pandora-core'),

    /*
    |--------------------------------------------------------------------------
    | Allowed product codes
    |--------------------------------------------------------------------------
    | 簽 token 時 audience 必須在這個 list 裡。新增 App → 新增 code。
    */
    'allowed_products' => ['fp', 'dodo', 'fairy-academy', 'fairy-skin', 'fairy-calendar'],

    /*
    |--------------------------------------------------------------------------
    | Internal Secret (for shadow-mirror & internal-only endpoints)
    |--------------------------------------------------------------------------
    | Dev/staging 用 shared secret header 認證，prod 改 mTLS（ADR-006）。
    */
    'internal_secret' => env('PANDORA_INTERNAL_SECRET'),
];
