<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_user_merge_log — audit trail of every group_user merge.
 *
 * 對應母艦 customer_merge_log，但 FK 改為 UUID。為什麼留 log 而不是
 * soft-delete 被合併的 user：
 *   - 合併後對方 row 完全砍掉（避免殭屍 row 出現在 query / API 列表）
 *   - 但要保留歷史，方便：
 *     - 客服查詢「我之前的訂單呢」（platform → 各 App 反查）
 *     - 萬一錯誤合併能查出來手動還原
 *     - dedupe / merge UI 跳過已合併過的對
 *
 * surviving_group_user_id 不加外鍵，避免被合併方 cascade delete 連動清掉。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user_merge_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('surviving_group_user_id');
            $table->uuid('absorbed_group_user_id');

            $table->string('absorbed_email', 255)->nullable();
            $table->string('absorbed_phone', 32)->nullable();
            // 將被合併方各 provider identity 一併備份在 JSON，避免追加 column
            $table->json('absorbed_identities')->nullable();

            // 'auto:phone+placeholder' / 'manual:platform-ui' / 'auto:email-match' / 'self-service'
            $table->string('reason', 64);

            // 合併前雙方關鍵狀態的 snapshot（debug / 還原用）
            $table->json('snapshot')->nullable();

            // 'system' / 'admin:<id>' / 'self:<group_user_id>'
            $table->string('actor', 64)->nullable();

            $table->timestamps();

            $table->index('surviving_group_user_id');
            $table->index('absorbed_group_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user_merge_log');
    }
};
