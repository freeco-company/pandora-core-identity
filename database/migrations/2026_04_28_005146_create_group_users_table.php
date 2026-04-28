<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_users — 集團通用使用者主表（Pandora Core 單一身份源）。
 *
 * 設計重點：
 *   - PK 用 UUID v7（time-ordered，B-tree 友善，跨產品永不變，
 *     不洩漏業務量；ADR-001 §2.1）
 *   - email_canonical / phone_canonical 為 lowercase + trim 後的標準化值，
 *     用於 lookup 與 dedupe；原始輸入留在 group_user_identities.raw_payload
 *   - status 採 string 而非 enum，避免新增狀態要 ALTER TABLE
 *   - soft delete：個資法 §11 一鍵刪除走軟刪 + 30 天冷卻
 *   - profile 欄位（display_name / gender / birthday）只放跨 App 共用的部分，
 *     產品自治資料（推薦碼、加盟狀態、訂閱、AI 對話）留在各產品 DB
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('email_canonical', 255)->nullable();
            $table->string('phone_canonical', 32)->nullable();

            $table->string('display_name', 100)->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('birthday')->nullable();

            // 'active' / 'suspended' / 'pending_verification'
            $table->string('status', 24)->default('active');

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('email_canonical');
            $table->unique('phone_canonical');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_users');
    }
};
