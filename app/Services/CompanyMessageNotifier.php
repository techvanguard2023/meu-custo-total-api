<?php

namespace App\Services;

use App\Models\Company;
use App\Support\Background;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * WhatsApp da mensagem do admin pra uma loja (ficha da loja → "Enviar mensagem"): vai pro telefone do dono, pelo
 * número da plataforma (PLATFORM_WAHA_SESSION — o mesmo dos avisos de suporte). O resultado fica gravado na
 * mensagem (whatsapp_status) pra ficha mostrar se chegou.
 */
class CompanyMessageNotifier
{
    public function __construct(private WahaClient $waha) {}

    public function notify(int $announcementId): void
    {
        DB::table('platform_announcements')->where('id', $announcementId)->update(['whatsapp_status' => 'Enviando…']);
        Background::run(static fn () => app(self::class)->send($announcementId));
    }

    public function send(int $announcementId): void
    {
        $row = DB::table('platform_announcements')->find($announcementId);
        $company = $row?->company_id ? Company::find($row->company_id) : null;
        if (! $row || ! $company) {
            return;
        }

        $status = $this->deliver($company, $row);
        DB::table('platform_announcements')->where('id', $announcementId)->update(['whatsapp_status' => Str::limit($status, 250)]);
    }

    /** Devolve o que aconteceu, em texto pra ficha da loja. */
    private function deliver(Company $company, object $row): string
    {
        $session = (string) config('services.platform.support_waha_session');
        if ($session === '' || ! $this->waha->isConfigured()) {
            return 'Não enviado: WhatsApp da plataforma não configurado (PLATFORM_WAHA_SESSION)';
        }

        $owner = $company->users()->where('role', 'owner')->orderBy('id')->first();
        if (! $owner?->phone) {
            return 'Não enviado: o dono não tem telefone no cadastro';
        }

        try {
            $chatId = $this->waha->chatIdFor($session, $owner->phone);
            if (! $chatId) {
                return "Não enviado: {$owner->phone} não tem WhatsApp";
            }
            $this->waha->sendText($session, $chatId, $this->text($row), true);

            return "Enviado para {$owner->name} ({$owner->phone})";
        } catch (Throwable $e) {
            return 'Falhou: '.$e->getMessage();
        }
    }

    public function text(object $row): string
    {
        $link = $row->link_url
            ? (str_starts_with($row->link_url, '/') ? rtrim((string) config('services.frontend_url'), '/').$row->link_url : $row->link_url)
            : null;

        return implode("\n", array_filter([
            '📢 *Mensagem da equipe Meu Custo Total*',
            '',
            "*{$row->title}*",
            $row->message ?: null,
            $link ? '' : null,
            $link ? ($row->link_label ? "{$row->link_label}: {$link}" : $link) : null,
        ], fn ($line) => $line !== null));
    }
}
