<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_user_consents — 個資同意歷史，符合個資法 §11 / §27。
 *
 * 設計重點：
 *   - 一個 (group_user_id, consent_type) 可以有多筆紀錄（granted/revoked
 *     交替），保留完整時序，最新一筆 revoked_at IS NULL 即為當前狀態
 *   - version 紀錄使用者同意當下的條款版本（之後改條款不影響歷史）
 *   - source 紀錄是哪個 App 收的同意（fp / dodo / fairy-academy ...）
 *
 * consent_type 範例：
 *   - 'tos'            服務條款
 *   - 'privacy'        隱私權政策
 *   - 'marketing_email' 行銷信
 *   - 'data_export'    跨 App 資料同步
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user_consents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('group_user_id');
            $table->foreign('group_user_id')
                ->references('id')->on('group_users')
                ->cascadeOnDelete();

            $table->string('consent_type', 48);
            $table->string('version', 32);

            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            // 'fp' / 'dodo' / 'fairy-academy' / 'platform'
            $table->string('source', 32);

            // optional client info: ip / ua（給法務查詢用）
            $table->string('granted_ip', 45)->nullable();
            $table->text('granted_user_agent')->nullable();

            $table->timestamps();

            $table->index(['group_user_id', 'consent_type']);
            $table->index(['consent_type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user_consents');
    }
};
