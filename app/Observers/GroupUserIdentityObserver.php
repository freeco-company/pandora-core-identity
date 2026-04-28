<?php

namespace App\Observers;

use App\Models\GroupUserIdentity;
use App\Services\Webhook\OutboxEventDispatcher;

/**
 * Identity 變動（新加 OAuth provider / verify email / unlink）也算 user 變動，
 * 推 user.upserted 給 consumer。consumer 端拿 full payload 重寫。
 */
class GroupUserIdentityObserver
{
    public function __construct(private OutboxEventDispatcher $dispatcher) {}

    public function created(GroupUserIdentity $identity): void
    {
        $this->publish($identity);
    }

    public function updated(GroupUserIdentity $identity): void
    {
        $this->publish($identity);
    }

    public function deleted(GroupUserIdentity $identity): void
    {
        $this->publish($identity);
    }

    private function publish(GroupUserIdentity $identity): void
    {
        $user = $identity->user()->first();
        if ($user !== null) {
            $this->dispatcher->publishUserUpserted($user);
        }
    }
}
