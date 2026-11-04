<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Notifications\SupportReplyToUser;
use App\Notifications\SupportTicketToAdmin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * Avisos dos chamados de suporte. Mensagem da loja → e-mail (PLATFORM_ADMIN_EMAILS) e WhatsApp
 * (PLATFORM_SUPPORT_WHATSAPP, enviado pela sessão PLATFORM_WAHA_SESSION) pra plataforma.
 * Resposta do suporte → e-mail pra quem abriu o chamado (o contador em "Ajuda e suporte" também acende).
 * Tudo roda depois da resposta (defer) e falha em silêncio no log: aviso que não saiu não pode
 * impedir a pessoa de abrir o chamado.
 */
class SupportNotifier
{
    public function __construct(private WahaClient $waha) {}

    public function toPlatform(SupportTicket $ticket, SupportMessage $message, bool $isNew): void
    {
        defer(function () use ($ticket, $message, $isNew) {
            $ticket->loadMissing(['company:id,name', 'user:id,name,email']);

            $emails = config('services.platform.admin_emails', []);
            if ($emails) {
                try {
                    Notification::route('mail', $emails)->notify(new SupportTicketToAdmin($ticket, $message, $isNew));
                } catch (\Throwable $e) {
                    Log::warning("Suporte: e-mail do chamado #{$ticket->id} não enviado: ".$e->getMessage());
                }
            }

            $this->whatsappToPlatform($ticket, $message, $isNew);
        });
    }

    public function toUser(SupportTicket $ticket, SupportMessage $message): void
    {
        defer(function () use ($ticket, $message) {
            // Quem abriu; se a conta dela foi removida da equipe, o dono da loja
            $recipient = $ticket->user ?? $ticket->company?->users()->where('role', 'owner')->first();
            if (! $recipient?->email) {
                return;
            }

            try {
                $recipient->notify(new SupportReplyToUser($ticket, $message));
            } catch (\Throwable $e) {
                Log::warning("Suporte: e-mail da resposta do chamado #{$ticket->id} não enviado: ".$e->getMessage());
            }
        });
    }

    private function whatsappToPlatform(SupportTicket $ticket, SupportMessage $message, bool $isNew): void
    {
        $session = (string) config('services.platform.support_waha_session');
        $numbers = config('services.platform.support_whatsapp', []);
        if ($session === '' || ! $numbers || ! $this->waha->isConfigured()) {
            return;
        }

        $who = $ticket->user?->name ?? 'alguém da loja';
        $text = implode("\n", array_filter([
            $isNew
                ? "🆘 *Chamado novo #{$ticket->id}* — {$ticket->company?->name}"
                : "💬 *Resposta no chamado #{$ticket->id}* — {$ticket->company?->name}",
            "{$ticket->categoryLabel()}: {$ticket->subject}",
            "{$who}: ".Str::limit($message->body, 500),
            '',
            rtrim(config('services.frontend_url'), '/')."/admin/suporte/{$ticket->id}",
        ], fn ($line) => $line !== null));

        foreach ($numbers as $number) {
            try {
                $chatId = $this->waha->chatIdFor($session, $number);
                if ($chatId) {
                    $this->waha->sendText($session, $chatId, $text);
                }
            } catch (\Throwable $e) {
                Log::warning("Suporte: WhatsApp do chamado #{$ticket->id} não enviado: ".$e->getMessage());
            }
        }
    }
}
