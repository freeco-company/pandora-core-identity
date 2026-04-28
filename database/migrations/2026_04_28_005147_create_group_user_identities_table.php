<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_user_identities — 多 provider lookup（type, value）→ group_user_id。
 *
 * 結構刻意對齊婕樂纖既有 customer_identities（ADR-001 §1.2.5），方便：
 *   1. Step 1 鏡寫時 schema 1:1 mapping，不用 transformer
 *   2. Step 4 cutover 時母艦 read 改成走 platform，最小邏輯改動
 *
 * type 用 string 而非 enum，未來新增 provider（Apple / Facebook / 客戶系統）
 * 不用 ALTER TABLE。raw_payload 留 OAuth callback 原始 JSON 供 debug。
 *
 * UNIQUE(type, value) — 同一個 LINE userId 不會分散在多個 group_user。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user_identities', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('group_user_id');
            $table->foreign('group_user_id')
                ->references('id')->on('group_users')
                ->cascadeOnDelete();

            $table->string('type', 24);   // email / phone / google / line / apple
            $table->string('value', 255);

            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);

            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['group_user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user_identities');
    }
};
