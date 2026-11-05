<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Listeners\MonitorScheduledTasks;
use App\Models\Company;
use App\Services\WahaClient;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saúde do sistema (área admin): agendador (cada tarefa: última vez que rodou, se falhou ou foi pulada por trava
 * presa), filas de envio, sessões do WhatsApp e configuração do servidor.
 */
class SystemController extends Controller
{
    public function __invoke(WahaClient $waha)
    {
        // As tarefas ficam em routes/console.php, que o Laravel só carrega ao rodar um comando — numa
        // requisição do site o agendador viria vazio. Carregar os comandos registra as tarefas.
        app(\Illuminate\Contracts\Console\Kernel::class)->all();
        $schedule = app(Schedule::class);

        $runs = Cache::get('schedule_runs', []);
        $monitorSince = ($since = Cache::get('schedule_monitor_since')) ? Carbon::parse($since) : null;
        // Quando cada tarefa apareceu pela primeira vez: tarefa nova (deploy recente) não é cobrada pela janela de
        // antes de existir — senão aparecia "atrasada" até a primeira execução
        $firstSeen = Cache::get('schedule_first_seen', []);
        $seenChanged = false;
        foreach ($schedule->events() as $event) {
            $key = MonitorScheduledTasks::key($event);
            if (! isset($firstSeen[$key])) {
                $firstSeen[$key] = now()->toIso8601String();
                $seenChanged = true;
            }
        }
        if ($seenChanged) {
            Cache::forever('schedule_first_seen', $firstSeen);
        }

        $tasks = collect($schedule->events())->map(function ($event) use ($runs, $monitorSince, $firstSeen) {
            $key = MonitorScheduledTasks::key($event);
            $watchedSince = $monitorSince ? max($monitorSince, Carbon::parse($firstSeen[$key])) : null;
            $run = $runs[$key] ?? null;
            $timezone = $event->timezone ?: config('app.timezone');
            $previous = Carbon::instance((new CronExpression($event->expression))->getPreviousRunDate(now($timezone), 0, true))->utc();
            $lastOk = isset($run['last_ok_at']) ? Carbon::parse($run['last_ok_at']) : null;
            // Atrasada: deveria ter terminado bem na última janela (com 3 min de folga) e não terminou — só conta
            // se o monitor já estava registrando naquela hora
            $late = $previous->lt(now()->subMinutes(3))
                && ($lastOk ? $lastOk->lt($previous->copy()->subMinutes(2)) : ($watchedSince && $watchedSince->lt($previous)));

            return [
                'command' => $key,
                'expression' => $event->expression,
                'timezone' => $timezone,
                'status' => $run['status'] ?? null,
                'last_at' => $run['at'] ?? null,
                'last_ok_at' => $run['last_ok_at'] ?? null,
                'runtime' => $run['runtime'] ?? null,
                'error' => $run['error'] ?? null,
                'late' => $late,
                'next_at' => Carbon::instance($event->nextRunDate())->toIso8601String(),
            ];
        })->values();

        $heartbeat = collect($runs)->pluck('at')->filter()->max();

        // WhatsApp: situação de cada sessão das lojas
        $whatsapp = ['configured' => $waha->isConfigured(), 'error' => null, 'sessions' => []];
        if ($waha->isConfigured()) {
            try {
                $statuses = $waha->listSessions();
                $whatsapp['sessions'] = Company::whereNotNull('whatsapp_session_name')->orderBy('name')->get(['id', 'name', 'whatsapp_session_name'])
                    ->map(fn ($c) => ['company_id' => $c->id, 'company' => $c->name, 'session' => $c->whatsapp_session_name, 'status' => $statuses[$c->whatsapp_session_name] ?? 'NÃO EXISTE NA WAHA'])
                    ->values();
            } catch (Throwable $e) {
                $whatsapp['error'] = mb_substr($e->getMessage(), 0, 200);
            }
        }

        $oldest = fn (string $table, callable $where) => ($at = $where(DB::table($table))->min('created_at')) ? Carbon::parse($at)->toIso8601String() : null;

        return response()->json([
            'scheduler' => [
                'heartbeat' => $heartbeat,
                // Sem nada nos últimos 3 min: o agendador (cron) parou
                'running' => $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3)),
                'tasks' => $tasks,
            ],
            'queues' => [
                'offers_pending' => DB::table('offer_deliveries')->where('status', 'pending')->count(),
                'offers_oldest' => $oldest('offer_deliveries', fn ($q) => $q->where('status', 'pending')),
                'offers_failed_24h' => DB::table('offer_deliveries')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                'cashback_pending' => DB::table('cashback_notifications')->whereNull('sent_at')->whereNull('failed_at')->count(),
                'coupons_pending' => DB::table('coupons')->whereNotNull('automation')->whereNull('notified_at')->where('active', true)->count(),
                'jobs' => DB::table('jobs')->count(),
                'failed_jobs_7d' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count(),
                // QUEUE_BACKGROUND: avisos (PDF, etapas, suporte, senha) vão pra fila em vez de rodar depois da resposta
                'background' => (bool) config('queue.background'),
                'workers' => $this->workers(),
            ],
            'whatsapp' => $whatsapp,
            'environment' => [
                'app_env' => config('app.env'),
                'app_debug' => (bool) config('app.debug'),
                'app_url' => config('app.url'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'cache' => config('cache.default'),
                // Cache no Redis com failover: se o Redis não responde, o app segue no MySQL (mais lento)
                'redis' => $this->redisStatus(),
                'queue' => config('queue.default'),
                'timezone' => config('app.timezone'),
            ],
            'finance_synced_at' => Cache::get('platform_finance_synced_at'),
        ]);
    }

    /**
     * Workers da fila (docker/queue-worker.sh): um por conexão lida — com "failover", Redis e MySQL. Sinal de vida
     * gravado pelo próprio worker (AppServiceProvider::queueHeartbeat); sem sinal há 3 min = parado.
     */
    private function workers(): array
    {
        $default = config('queue.default');
        $connections = $default === 'failover' ? (array) config('queue.connections.failover.connections') : [$default];

        return collect($connections)
            ->reject(fn ($c) => in_array($c, ['sync', 'deferred', 'background', 'null'], true))
            ->map(function (string $connection) {
                $heartbeat = Cache::get("queue-worker:{$connection}");
                try {
                    $size = \Illuminate\Support\Facades\Queue::connection($connection)->size();
                } catch (Throwable) {
                    $size = null; // Redis fora do ar
                }

                return [
                    'connection' => $connection,
                    'last_seen_at' => $heartbeat,
                    'running' => $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3)),
                    'size' => $size,
                ];
            })->values()->all();
    }

    /** null = Redis não está em uso; senão se ele responde agora. */
    private function redisStatus(): ?array
    {
        $stores = config('cache.default') === 'failover' ? (array) config('cache.stores.failover.stores') : [config('cache.default')];
        if (! in_array('redis', $stores, true)) {
            return null;
        }

        try {
            $started = microtime(true);
            \Illuminate\Support\Facades\Redis::connection(config('cache.stores.redis.connection', 'cache'))->ping();

            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => null, 'error' => \Illuminate\Support\Str::limit($e->getMessage(), 150)];
        }
    }
}
