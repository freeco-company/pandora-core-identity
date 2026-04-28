<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Publisher (ADR-007 / Phase 1)
    |--------------------------------------------------------------------------
    |
    | platform 把 GroupUser 變動推給 consumer 的開關 + registry。
    | enabled=false 時：outbox 仍會寫入（保留 audit trail），但 dispatcher
    | 空轉不真的 POST。dev 預設關，prod 透過 env 開。
    |
    | Consumer 走 env-per-consumer，未來若超過 5 個再考慮搬 DB（YAGNI）。
    | 每個 consumer 必須 enabled=true 且 url+secret 都有值才會被 dispatcher
    | 視為 active；任一缺失即 skip（不寫進 outbox），避免送到一半的 config
    | 造成大量 dead_letter。
    |
    */

    'publisher_enabled' => env('IDENTITY_PUBLISHER_ENABLED', false),

    /*
     * 重試曲線（秒）。第 N 次失敗後等待 retry_backoff_seconds[N-1] 秒再試。
     * 達到 max_retries 後標 dead_letter，不再重送。
     */
    'max_retries' => 5,
    'retry_backoff_seconds' => [60, 300, 900, 3600, 21600],  // 1m / 5m / 15m / 1h / 6h

    /*
     * HTTP timeout（秒）。短一點避免一個 consumer 慢拖累整批。
     */
    'http_timeout' => 10,

    /*
     * 簽章 / replay window（秒）。receiver 收到 webhook 後，
     * 若 X-Pandora-Timestamp 與當下相差超過此值即拒絕（防 replay）。
     */
    'signature_window_seconds' => 300,  // 5 min

    'consumers' => [
        'pandora_js_store' => [
            'enabled' => env('IDENTITY_CONSUMER_PANDORA_JS_STORE_ENABLED', false),
            'url' => env('IDENTITY_CONSUMER_PANDORA_JS_STORE_URL'),
            'secret' => env('IDENTITY_CONSUMER_PANDORA_JS_STORE_SECRET'),
        ],
        'dodo' => [
            'enabled' => env('IDENTITY_CONSUMER_DODO_ENABLED', false),
            'url' => env('IDENTITY_CONSUMER_DODO_URL'),
            'secret' => env('IDENTITY_CONSUMER_DODO_SECRET'),
        ],
    ],

];
