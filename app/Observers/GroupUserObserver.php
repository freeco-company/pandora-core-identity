<?php

namespace App\Observers;

use App\Models\GroupUser;
use App\Services\Webhook\OutboxEventDispatcher;

/**
 * GroupUser create / update → 寫 outbox（transactional outbox pattern）。
 *
 * 故意不在 deleting/deleted hook 寫 — 真正的「停用」走 status='suspended'
 * 並透過 update event 推出去；soft delete 是內部刪除標記，不應推 consumer。
 */
class GroupUserObserver
{
    public function __construct(private OutboxEventDispatcher $dispatcher) {}

    public function created(GroupUser $user): void
    {
        $this->dispatcher->publishUserUpserted($user);
    }

    public function updated(GroupUser $user): void
    {
        $this->dispatcher->publishUserUpserted($user);
    }
}
