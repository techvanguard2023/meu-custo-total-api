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
        $tasks = collect($schedule->events())->map(function ($event) use ($runs, $monitorSince) {
            $key = MonitorScheduledTasks::key($event);
            $run = $runs[$key] ?? null;
            $timezone = $event->timezone ?: config('app.timezone');
            $previous = Carbon::instance((new CronExpression($event->expression))->getPreviousRunDate(now($timezone), 0, true))->utc();
            $lastOk = isset($run['last_ok_at']) ? Carbon::parse($run['last_ok_at']) : null;
            // Atrasada: deveria ter terminado bem na última janela (com 3 min de folga) e não terminou — só conta
            // se o monitor já estava registrando naquela hora
            $late = $previous->lt(now()->subMinutes(3))
                && ($lastOk ? $lastOk->lt($previous->copy()->subMinutes(2)) : ($monitorSince && $monitorSince->lt($previous)));

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
            ],
            'whatsapp' => $whatsapp,
            'environment' => [
                'app_env' => config('app.env'),
                'app_debug' => (bool) config('app.debug'),
                'app_url' => config('app.url'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
                'timezone' => config('app.timezone'),
            ],
            'finance_synced_at' => Cache::get('platform_finance_synced_at'),
        ]);
    }
}
