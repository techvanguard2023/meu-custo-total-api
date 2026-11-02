<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfferSchedule;
use App\Models\OfferSeries;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envio automático das ofertas. Nos dias e horários da agenda, os N produtos marcados que estão há mais
 * tempo sem divulgar entram na fila (rodízio — o mesmo produto não se repete enquanto houver outros).
 */
class OfferScheduler
{
    /** Atraso aceito pra disparar um horário (agendador parado por alguns minutos não perde o envio). */
    private const TOLERANCE_MINUTES = 30;

    public function __construct(private OfferPublisher $publisher, private WahaClient $waha) {}

    public function forCompany(Company $company): OfferSchedule
    {
        return OfferSchedule::firstOrNew(['company_id' => $company->id], OfferSchedule::DEFAULTS);
    }

    /** Produtos na rodada, na ordem do rodízio (nunca divulgados primeiro, depois os mais antigos). */
    public function eligibleProducts(Company $company, OfferSchedule $schedule): Collection
    {
        return Product::where('company_id', $company->id)
            ->where('share_in_offers', true)
            ->where('active', true)
            ->where('show_in_catalog', true)
            ->get()
            ->filter(fn (Product $p) => ! $schedule->only_in_stock || $p->made_to_order || $p->total_stock > 0)
            ->sortBy(fn (Product $p) => [$p->offers_last_sent_at?->timestamp ?? 0, $p->id])
            ->values();
    }

    /** Horário da agenda que está valendo agora e ainda não foi disparado (ex: "2026-11-03 10:00"). */
    public function dueSlot(OfferSchedule $schedule, Carbon $now): ?string
    {
        if (! in_array($now->dayOfWeekIso, $schedule->days ?? [], true)) {
            return null;
        }

        $slot = collect($schedule->times ?? [])
            ->map(fn (string $time) => $now->copy()->setTimeFromTimeString($time))
            ->filter(fn (Carbon $at) => $at->lte($now) && $at->diffInMinutes($now) <= self::TOLERANCE_MINUTES)
            ->sort()
            ->last();

        $key = $slot?->format('Y-m-d H:i');

        return $key && $key !== $schedule->last_slot ? $key : null;
    }

    /** Próximo horário da agenda (na hora da loja), pra tela mostrar "Próximo envio: …". */
    public function nextRun(OfferSchedule $schedule, Carbon $now): ?Carbon
    {
        if (! $schedule->enabled || empty($schedule->days) || empty($schedule->times)) {
            return null;
        }

        for ($i = 0; $i <= 7; $i++) {
            $day = $now->copy()->startOfDay()->addDays($i);
            if (! in_array($day->dayOfWeekIso, $schedule->days, true)) {
                continue;
            }
            $next = collect($schedule->times)
                ->map(fn (string $time) => $day->copy()->setTimeFromTimeString($time))
                ->filter(fn (Carbon $at) => $at->gt($now) && ($at->format('Y-m-d H:i') !== $schedule->last_slot))
                ->sort()
                ->first();
            if ($next) {
                return $next;
            }
        }

        return null;
    }

    /** Roda a agenda da loja agora, se for a hora. Devolve quantos produtos entraram na fila. */
    public function run(Company $company, ?Carbon $now = null): int
    {
        $schedule = OfferSchedule::where('company_id', $company->id)->where('enabled', true)->first();
        if (! $schedule) {
            return 0;
        }

        $now ??= now($company->timezone ?: 'America/Sao_Paulo');
        $slot = $this->dueSlot($schedule, $now);
        if (! $slot) {
            return 0;
        }

        if (! OfferSeries::where('company_id', $company->id)->where('active', true)->exists()) {
            $schedule->update(['last_slot' => $slot]);

            return 0;
        }

        // WhatsApp fora (ou o WAHA sem responder) não perde o horário: tenta de novo a cada minuto, dentro da tolerância
        $session = $company->whatsapp_session_name;
        try {
            $connected = $session && $this->waha->isConfigured() && (($this->waha->getSession($session)['status'] ?? null) === 'WORKING');
        } catch (Throwable $e) {
            $connected = false;
        }
        if (! $connected) {
            Log::info("Ofertas: horário {$slot} da empresa {$company->id} aguardando — WhatsApp desconectado.");

            return 0;
        }

        // Marca o horário antes de enfileirar: daqui em diante ele não é tentado de novo (nada sai em dobro)
        $schedule->update(['last_slot' => $slot]);

        $products = $this->eligibleProducts($company, $schedule)->take($schedule->products_per_run);
        foreach ($products as $product) {
            $this->publisher->queue($company, $product, 'schedule');
        }

        return $products->count();
    }
}
