<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 補 group_users password / email_verified_at。
 *
 * #2 schema 故意先不放這兩欄（identity service 設計上 password 是
 * email-provider 限定屬性，不是 user 的本質欄位）。但實作層面放在
 * group_users 比另外建 email_credentials 表更輕，先這樣，未來必要時拆。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('birthday');
            $table->timestamp('email_verified_at')->nullable()->after('password');
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_users', function (Blueprint $table) {
            $table->dropColumn(['password', 'email_verified_at', 'email_verification_token']);
        });
    }
};
