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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Saúde do sistema (área admin): agendador (cada tarefa: última vez que rodou, se falhou ou foi pulada por trava
 * presa), filas de envio, sessões do WhatsApp e configuração do servidor.
 */
class SystemController extends Controller
{
    /**
     * Ofertas que falharam nas últimas 24 h, uma linha por entrega (grupo ou canal do WhatsApp): de qual lojista,
     * qual oferta, para onde ia e o que o WhatsApp respondeu. As mais recentes primeiro.
     */
    public function failedOffers()
    {
        $rows = DB::table('offer_deliveries as d')
            ->leftJoin('companies as c', 'c.id', '=', 'd.company_id')
            ->leftJoin('offer_posts as p', 'p.id', '=', 'd.offer_post_id')
            ->leftJoin('offer_series as s', 's.id', '=', 'd.offer_series_id')
            ->where('d.status', 'failed')
            ->where('d.updated_at', '>=', now()->subDay())
            ->orderByDesc('d.updated_at')
            ->limit(200)
            ->get(['d.id', 'd.company_id', 'c.name as company_name', 'p.product_name', 's.name as series_name', 'd.target_label', 'd.error', 'd.updated_at']);

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'company' => $r->company_id ? ['id' => (int) $r->company_id, 'name' => $r->company_name ?? 'Loja removida'] : null,
                'offer' => $r->product_name,
                'series' => $r->series_name,
                'target' => $r->target_label,
                'error' => $r->error ?: 'Sem mensagem de erro registrada.',
                'at' => Carbon::parse($r->updated_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Tarefas da fila que falharam nos últimos 7 dias. O banco só guarda o conteúdo da tarefa; daqui sai o nome dela,
     * a mensagem de erro e — quando a tarefa carregava um registro (loja, pedido, usuário…) — de qual lojista é.
     */
    public function failedJobs()
    {
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->orderByDesc('failed_at')->limit(100)->get();
        $names = [];

        $items = $rows->map(function ($row) use (&$names) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $command = (string) ($payload['data']['command'] ?? '');
            $display = (string) ($payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Tarefa'));
            // Notificação, e-mail ou evento na fila: o nome útil é o da classe de dentro, não o do envelope
            if (preg_match('/O:\d+:"(App\\\\(?:Notifications|Mail|Listeners|Events)\\\\[A-Za-z0-9_\\\\]+)"/', $command, $m)) {
                $display = $m[1];
            }

            [$companyId, $subject] = $this->jobOwner($command);
            if ($companyId && ! array_key_exists($companyId, $names)) {
                $names[$companyId] = DB::table('companies')->where('id', $companyId)->value('name');
            }

            $exception = (string) $row->exception;
            $first = trim((string) strtok($exception, "\n"));
            $first = preg_replace('/ in \/\S+:\d+$/', '', $first) ?? $first;

            return [
                'id' => $row->id,
                'job' => class_basename($display),
                'queue' => $row->queue,
                'company' => $companyId ? ['id' => $companyId, 'name' => $names[$companyId] ?? 'Loja removida'] : null,
                'subject' => $subject,
                'error' => mb_substr($first, 0, 400),
                'detail' => mb_substr($exception, 0, 3000),
                'at' => Carbon::parse($row->failed_at)->toIso8601String(),
            ];
        })->all();

        return response()->json(['items' => $items]);
    }

    /**
     * Procura, dentro da tarefa guardada, um registro que ela carregava (Company, Quote, User…) e descobre a loja
     * dele. É um palpite em cima do texto serializado — devolve [null, null] quando não acha.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function jobOwner(string $command): array
    {
        if (! preg_match_all('/"class";s:\d+:"([^"]+)";s:2:"id";(?:i:(\d+);|a:\d+:\{i:0;i:(\d+);)/', $command, $matches, PREG_SET_ORDER)) {
            return [null, null];
        }

        foreach ($matches as $m) {
            $class = $m[1];
            $id = (int) ($m[2] !== '' ? $m[2] : ($m[3] ?? 0));
            if (! $id || ! str_starts_with($class, 'App\\Models\\') || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $label = class_basename($class).' #'.$id;
            try {
                if ($class === Company::class) {
                    return [$id, $label];
                }
                $table = (new $class)->getTable();
                if (Schema::hasColumn($table, 'company_id')) {
                    $companyId = DB::table($table)->where('id', $id)->value('company_id');
                    if ($companyId) {
                        return [(int) $companyId, $label];
                    }
                }
            } catch (Throwable) {
                // tabela/registro que não dá pra consultar: segue procurando
            }
        }

        return [null, null];
    }

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
                    ->map(fn ($c) => [
                        'company_id' => $c->id,
                        'company' => $c->name,
                        'session' => $c->whatsapp_session_name,
                        'status' => $statuses[$c->whatsapp_session_name]['status'] ?? 'NÃO EXISTE NA WAHA',
                        // o que a situação quer dizer (null = funcionando)
                        'problem' => isset($statuses[$c->whatsapp_session_name]) ? $statuses[$c->whatsapp_session_name]['problem'] : WahaClient::problem(null),
                    ])
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
