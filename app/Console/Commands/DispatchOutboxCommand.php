<?php

namespace App\Console\Commands;

use App\Services\Webhook\WebhookDispatchService;
use Illuminate\Console\Command;

class DispatchOutboxCommand extends Command
{
    protected $signature = 'identity:dispatch-pending {--batch=100}';

    protected $description = 'POST pending platform_outbox_events 到 consumer webhook（ADR-007 Phase 1）';

    public function handle(WebhookDispatchService $service): int
    {
        $stats = $service->dispatchPending((int) $this->option('batch'));

        $this->info(sprintf(
            'attempted=%d sent=%d failed=%d dead_letter=%d skipped=%d',
            $stats['attempted'],
            $stats['sent'],
            $stats['failed'],
            $stats['dead_letter'],
            $stats['skipped']
        ));

        return self::SUCCESS;
    }
}
