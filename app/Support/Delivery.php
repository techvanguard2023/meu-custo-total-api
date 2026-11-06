<?php

namespace App\Support;

use App\Models\Quote;
use Illuminate\Support\Carbon;

/**
 * Data de entrega dos pedidos (Agenda): forma de entrega, data a partir de um prazo em dias e o texto curto usado
 * no PDF, nas mensagens do WhatsApp e no "Meus pedidos". Datas no fuso de Brasília (como o resto do sistema).
 */
class Delivery
{
    public const TZ = 'America/Sao_Paulo';

    public const METHOD_PICKUP = 'pickup';

    public const METHOD_LOCAL = 'local';

    public const METHOD_SHIPPING = 'shipping';

    public const METHODS = [self::METHOD_PICKUP, self::METHOD_LOCAL, self::METHOD_SHIPPING];

    /** Os mesmos de src/lib/delivery.ts no frontend. */
    public const METHOD_LABELS = [
        self::METHOD_PICKUP => 'Retirada',
        self::METHOD_LOCAL => 'Entrega',
        self::METHOD_SHIPPING => 'Envio',
    ];

    private const WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    /** Hoje (Brasília) + N dias; úteis pula sábado e domingo. */
    public static function fromDays(int $days, bool $businessDays = false, ?Carbon $from = null): Carbon
    {
        $date = ($from ?? now())->copy()->timezone(self::TZ)->startOfDay();
        if (! $businessDays) {
            return $date->addDays($days);
        }
        while ($days > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }

    /** "sáb, 15/11 às 15:00" (sem hora: "sáb, 15/11"). Com o ano quando não é o atual. */
    public static function dateLabel(?string $date, ?string $time = null): ?string
    {
        if (! $date) {
            return null;
        }
        $d = Carbon::parse($date, self::TZ);
        $label = self::WEEKDAYS[$d->dayOfWeek].', '.$d->format($d->year === now(self::TZ)->year ? 'd/m' : 'd/m/Y');

        return $time ? "{$label} às {$time}" : $label;
    }

    /** "Retirada · sáb, 15/11 às 15:00" — o que vai no PDF, nas mensagens e no "Meus pedidos". */
    public static function label(Quote $quote): ?string
    {
        $date = self::dateLabel($quote->delivery_date?->toDateString(), $quote->delivery_time);
        $method = self::METHOD_LABELS[$quote->delivery_method] ?? null;

        return $date || $method ? implode(' · ', array_filter([$method, $date])) : null;
    }
}
