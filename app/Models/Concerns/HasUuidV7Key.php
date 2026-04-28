<?php

namespace App\Models\Concerns;

use Symfony\Component\Uid\Uuid;

/**
 * 給 Eloquent model 用的 UUID v7 主鍵 trait。
 *
 * - v7 = time-ordered，B-tree 友善（避免亂序 insert 造成 page split）
 * - 不洩漏業務量（不像 auto-increment 可由 id 推估註冊速率）
 * - 跨產品永不變，未來轉移 / 合併不需要重新發 id
 *
 * ADR-001 §2.1 拍板。
 */
trait HasUuidV7Key
{
    public static function bootHasUuidV7Key(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Uuid::v7();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
