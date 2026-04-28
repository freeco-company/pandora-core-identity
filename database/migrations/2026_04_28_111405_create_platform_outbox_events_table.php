<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_outbox_events — Identity 變動的 webhook 出口（ADR-007 §2.4 / Phase 1）。
 *
 * Pattern：transactional outbox。GroupUser / GroupUserIdentity 變動時在同一個
 * transaction 內寫一筆 row，由背景 worker 撈 pending → POST consumer webhook。
 *
 * 每個 consumer 各寫一筆（不是一筆對多 consumer），原因：
 *   - 重試 / dead_letter 狀態 per-consumer 獨立
 *   - 一個 consumer 掛掉不影響其他 consumer 的進度
 *   - event_id 同 row 一一對應，receiver 端 nonce 去重最簡單
 *
 * UNIQUE(event_id) 防止 dispatcher 在 race condition 下重送（DB 層保險）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_outbox_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('event_id')->unique();   // per-row UUID v7，receiver 端 dedup 用
            $table->string('consumer', 64);        // 'pandora_js_store' / 'dodo'
            $table->string('type', 64);            // 'user.upserted' / 'user.suspended' / 'user.merged'
            $table->json('payload');

            $table->string('status', 16)->default('pending'); // pending / sent / failed / dead_letter
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['consumer', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_outbox_events');
    }
};
