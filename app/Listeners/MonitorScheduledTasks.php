<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Saúde do sistema (área admin): guarda o último resultado de cada tarefa agendada — terminou, falhou ou foi
 * pulada (ex: trava do withoutOverlapping presa, como no envio de ofertas que não saiu).
 */
class MonitorScheduledTasks
{
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->store($event->task, 'ok', round($event->runtime, 2));
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->store($event->task, 'failed', null, mb_substr($event->exception->getMessage(), 0, 200));
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        $this->store($event->task, 'skipped');
    }

    public static function key(Event $task): string
    {
        // "php artisan offers:send" → "offers:send"
        return trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $task->command)) ?: (string) $task->description;
    }

    private function store(Event $task, string $status, ?float $runtime = null, ?string $error = null): void
    {
        $runs = Cache::get('schedule_runs', []);
        $key = self::key($task);
        $runs[$key] = [
            'status' => $status,
            'at' => now()->toIso8601String(),
            'runtime' => $runtime,
            'error' => $error,
            // Última vez que terminou bem (pulado/falhou não apagam essa data)
            'last_ok_at' => $status === 'ok' ? now()->toIso8601String() : ($runs[$key]['last_ok_at'] ?? null),
        ];
        Cache::forever('schedule_runs', $runs);
        // Desde quando o monitor registra: tarefa que nunca rodou desde então não é "atrasada" (ex: logo após o deploy)
        Cache::add('schedule_monitor_since', now()->toIso8601String());
    }
}
