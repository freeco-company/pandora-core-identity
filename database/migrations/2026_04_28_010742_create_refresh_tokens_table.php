<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * refresh_tokens — opaque refresh token storage with rotation + reuse detection.
 *
 * 設計重點（OAuth 2.1 best practice）：
 *   - token 不存明文，存 SHA-256 hash（DB 漏 ≠ token 失效，需要原始 token 才能用）
 *   - family_id 同一條 refresh chain 共用，任何一個 token 被重用 → 整條 family 撤銷
 *   - replaced_by_id 留 chain 結構，方便 audit 與 chain revocation
 *   - product_code 綁定，refresh 不能跨 product 用（婕樂纖簽的 token 不能拿去 dodo refresh）
 *
 * ADR-001 §2.1 Token / §2.3 認證流程。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('group_user_id');
            $table->foreign('group_user_id')
                ->references('id')->on('group_users')
                ->cascadeOnDelete();

            // 一條 refresh chain 共用同一個 family_id；reuse → revoke whole family
            $table->uuid('family_id')->index();

            // SHA-256 hex of opaque token（64 chars），不存明文
            $table->char('token_hash', 64)->unique();

            $table->string('product_code', 32);

            // 替換鏈：本 token rotate 後產生的下一個 token 的 id
            $table->unsignedBigInteger('replaced_by_id')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();   // rotated successfully
            $table->timestamp('revoked_at')->nullable(); // explicit revoke or reuse-triggered
            $table->string('revoked_reason', 64)->nullable(); // 'rotated' / 'reuse_detected' / 'logout' / 'admin'

            // optional client info（給 audit 與 abnormal session detection 用）
            $table->string('issued_ip', 45)->nullable();
            $table->text('issued_user_agent')->nullable();

            $table->timestamps();

            $table->index(['group_user_id', 'product_code']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
