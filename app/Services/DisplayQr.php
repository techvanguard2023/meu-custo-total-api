<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Display;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QR Code da placa do expositor. O QR aponta pro nosso link (…/q/CODIGO), que registra de qual
 * expositor veio e leva o cliente pro WhatsApp (mensagem pronta com o código), pro catálogo (com a
 * origem) ou pra uma página com os botões. Como o QR é nosso, dá pra trocar o destino sem reimprimir.
 */
class DisplayQr
{
    public const TYPE_SCAN = 'scan';
    public const TYPE_CLICK = 'click';
    public const TYPE_CONTACT = 'contact';

    /** Link que vai no QR: no domínio próprio da loja quando ativo. */
    public function url(Display $display): ?string
    {
        if (! $display->code) {
            return null;
        }

        $company = $display->company;
        $code = rawurlencode($display->code);

        return $company->hasActiveCustomDomain()
            ? $company->publicOrigin()."/q/{$code}"
            : $company->publicOrigin()."/q/{$company->slug}/{$code}";
    }

    /** Expositor pelo código (sem diferenciar maiúsculas), desde que não esteja encerrado. */
    public function resolve(Company $company, string $code): ?Display
    {
        return $company->displays()
            ->whereRaw('LOWER(code) = ?', [Str::lower(trim($code))])
            ->where('status', '!=', Display::STATUS_ENDED)
            ->first();
    }

    public function whatsappMessage(Display $display): string
    {
        return "Olá! Vi o expositor de vocês no {$display->name} e quero saber mais sobre os produtos personalizados. (Expositor {$display->code})";
    }

    /** WhatsApp da loja (o mesmo do catálogo) com a mensagem pronta — o código vai junto pro bot reconhecer. */
    public function whatsappUrl(Display $display): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $display->company->catalog_whatsapp);
        if ($digits === '') {
            return null;
        }
        $digits = strlen($digits) <= 11 ? '55'.$digits : $digits;

        return "https://wa.me/{$digits}?text=".rawurlencode($this->whatsappMessage($display));
    }

    /** Catálogo com a origem: o catálogo guarda e manda o código na mensagem do pedido. */
    public function catalogUrl(Display $display): string
    {
        return rtrim($display->company->catalogUrl(), '/').'?origem='.rawurlencode($display->code);
    }

    /** "Pessoa diferente" sem guardar dado pessoal: hash do aparelho no dia. */
    private function visitorHash(Request $request, Display $display): string
    {
        return hash('sha256', implode('|', [$request->ip(), (string) $request->userAgent(), now()->toDateString(), $display->id, config('app.key')]));
    }

    public function record(Display $display, string $type, ?string $destination = null, ?Request $request = null, ?string $phone = null): void
    {
        DB::table('display_qr_events')->insert([
            'company_id' => $display->company_id,
            'display_id' => $display->id,
            'type' => $type,
            'destination' => $destination,
            'visitor_hash' => $request ? $this->visitorHash($request, $display) : null,
            'phone_key' => $phone ? Customer::phoneKey($phone) : null,
            'created_at' => now(),
        ]);
    }

    /** Conversa no WhatsApp que veio do QR — conta uma vez por telefone por dia. */
    public function recordContact(Display $display, string $phone): void
    {
        $key = Customer::phoneKey($phone);
        $already = DB::table('display_qr_events')
            ->where('display_id', $display->id)
            ->where('type', self::TYPE_CONTACT)
            ->where('phone_key', $key)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if (! $already) {
            $this->record($display, self::TYPE_CONTACT, 'whatsapp', null, $phone);
        }
    }

    /** Métricas do QR no painel do expositor. */
    public function stats(Display $display, int $days): array
    {
        $timezone = $display->company->timezone ?: 'America/Sao_Paulo';
        $from = now($timezone)->subDays($days - 1)->startOfDay()->utc();
        $events = DB::table('display_qr_events')
            ->where('display_id', $display->id)
            ->where('created_at', '>=', $from)
            ->get(['type', 'destination', 'visitor_hash', 'created_at']);

        $scans = $events->where('type', self::TYPE_SCAN);
        $local = fn ($e) => Carbon::parse($e->created_at, 'UTC')->timezone($timezone);

        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $byDay[now($timezone)->subDays($i)->toDateString()] = 0;
        }
        foreach ($scans as $scan) {
            $day = $local($scan)->toDateString();
            if (isset($byDay[$day])) {
                $byDay[$day]++;
            }
        }

        $weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        $bestWeekday = $scans->countBy(fn ($e) => $local($e)->dayOfWeek)->sortDesc();
        $bestHour = $scans->countBy(fn ($e) => $local($e)->hour)->sortDesc();

        $orders = Quote::where('origin_display_id', $display->id)
            ->where('status', Quote::STATUS_APPROVED)
            ->where('created_at', '>=', $from);

        return [
            'days' => $days,
            'scans' => $scans->count(),
            'unique_visitors' => $scans->pluck('visitor_hash')->filter()->unique()->count(),
            'by_day' => collect($byDay)->map(fn ($count, $date) => ['date' => $date, 'scans' => $count])->values(),
            'clicks' => [
                'whatsapp' => $events->where('type', self::TYPE_CLICK)->where('destination', 'whatsapp')->count(),
                'catalog' => $events->where('type', self::TYPE_CLICK)->where('destination', 'catalog')->count(),
                'instagram' => $events->where('type', self::TYPE_CLICK)->where('destination', 'instagram')->count(),
            ],
            'contacts' => $events->where('type', self::TYPE_CONTACT)->count(),
            'orders_count' => (clone $orders)->count(),
            'orders_total' => round((float) (clone $orders)->sum('final_price'), 2),
            'best_weekday' => $bestWeekday->isNotEmpty() ? $weekdays[$bestWeekday->keys()->first()] : null,
            'best_hour' => $bestHour->isNotEmpty() ? (int) $bestHour->keys()->first() : null,
        ];
    }
}
