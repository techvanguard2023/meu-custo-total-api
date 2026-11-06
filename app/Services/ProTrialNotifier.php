<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformFeedback;
use App\Notifications\ProTrialMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mensagens do teste grátis do Pro, na ordem:
 *  - welcome: logo que começa (texto diferente pra campanha das lojas antigas);
 *  - day3:    3º dia, só se a loja ainda não fez nenhum orçamento;
 *  - mid:     7º dia, os recursos do Pro;
 *  - ending:  faltando 3 dias (e-mail + WhatsApp do dono);
 *  - ended:   quando acaba, se não assinou — cria o desconto de 30% no 1º mês, válido por 7 dias (e-mail + WhatsApp).
 *
 * Cada uma sai uma vez (companies.pro_trial_notices). Se a loja pulou uma etapa (ex: o servidor ficou parado), a
 * atrasada é marcada como pulada — nunca chegam duas de uma vez. Quem assinou durante o teste não recebe mais nada.
 * As do WhatsApp só saem das 9h às 20h (Brasília); fora disso esperam a próxima rodada.
 */
class ProTrialNotifier
{
    public const OFFER_PERCENT = 30;

    public const OFFER_DAYS = 7;

    public function __construct(private AdminDiscount $discounts, private PlatformWhatsApp $whatsapp) {}

    /** Passa pelas lojas com teste em andamento ou recém-terminado. Devolve [loja => etapa enviada]. */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $sent = [];

        Company::whereNotNull('pro_trial_started_at')
            ->where('pro_trial_ends_at', '>=', $now->copy()->subDays(3))
            ->each(function (Company $company) use ($now, &$sent) {
                try {
                    if ($stage = $this->process($company, $now)) {
                        $sent[$company->id] = $stage;
                    }
                } catch (Throwable $e) {
                    Log::warning("Teste do Pro: mensagem da loja {$company->id} falhou: ".$e->getMessage());
                }
            });

        return $sent;
    }

    /** Manda a mensagem devida agora (no máximo uma). Devolve a etapa enviada ou null. */
    public function process(Company $company, Carbon $now): ?string
    {
        $notices = $company->pro_trial_notices ?? [];
        // Assinou: o teste cumpriu o papel, não manda mais nada
        if ($company->subscribed('default')) {
            return null;
        }

        $due = $this->dueStages($company, $now);
        $pending = array_values(array_filter($due, fn ($s) => ! isset($notices[$s])));
        if (! $pending) {
            return null;
        }

        // Só a mais recente vale; as anteriores que ficaram pra trás são puladas
        $stage = array_pop($pending);
        foreach ($pending as $old) {
            $notices[$old] = 'skipped:'.$now->toIso8601String();
        }

        // 3º dia só pra quem ainda não fez orçamento
        if ($stage === 'day3' && $company->quotes()->exists()) {
            $notices[$stage] = 'skipped:'.$now->toIso8601String();
            $company->forceFill(['pro_trial_notices' => $notices])->save();

            return null;
        }

        // WhatsApp em horário comercial: fora dele, espera (as puladas já ficam gravadas)
        $withWhatsapp = in_array($stage, ['ending', 'ended'], true);
        $hour = (int) $now->copy()->timezone('America/Sao_Paulo')->format('G');
        if ($withWhatsapp && ($hour < 9 || $hour >= 20)) {
            $company->forceFill(['pro_trial_notices' => $notices])->save();

            return null;
        }

        $info = $this->info($company, $now, $stage);
        $owner = $company->users()->where('role', 'owner')->orderBy('id')->first();
        // Fim do teste: o e-mail pergunta em 1 clique o que pesou (link sem login, /opiniao/{token})
        if ($stage === 'ended' && $owner) {
            $info['feedback_url'] = app(PlatformFeedbackService::class)->linkUrl($owner, PlatformFeedback::KIND_TRIAL_EXIT);
        }
        if ($owner?->email) {
            $owner->notify(new ProTrialMessage($stage, $company, $info));
        }
        $whatsappStatus = $withWhatsapp ? $this->whatsapp->sendToOwner($company, $this->whatsappText($stage, $company, $info))['status'] : null;

        $notices[$stage] = $now->toIso8601String();
        if ($whatsappStatus) {
            $notices[$stage.'_whatsapp'] = $whatsappStatus;
        }
        $company->forceFill(['pro_trial_notices' => $notices])->save();

        return $stage;
    }

    /** Etapas cujo momento já chegou, em ordem. */
    public function dueStages(Company $company, Carbon $now): array
    {
        $start = $company->pro_trial_started_at->copy()->timezone('America/Sao_Paulo')->startOfDay();
        $end = $company->pro_trial_ends_at;
        $local = $now->copy()->timezone('America/Sao_Paulo');
        $stages = [];

        if ($company->pro_trial_ended_at) {
            // Terminado: só a mensagem de fim (até 3 dias depois)
            return $now->lte($end->copy()->addDays(3)) ? ['ended'] : [];
        }
        if ($end->isPast()) {
            return []; // acabou, mas o trial:expire ainda não processou
        }

        $stages[] = 'welcome';
        if ($local->gte($start->copy()->addDays(2))) {
            $stages[] = 'day3';
        }
        if ($local->gte($start->copy()->addDays(6))) {
            $stages[] = 'mid';
        }
        // "Faltam 3 dias": a partir de 00:00 do antepenúltimo dia
        if ($local->gte($end->copy()->timezone('America/Sao_Paulo')->startOfDay()->subDays(2))) {
            $stages[] = 'ending';
        }

        return $stages;
    }

    private function info(Company $company, Carbon $now, string $stage): array
    {
        $offer = null;
        if ($stage === 'ended') {
            $offer = $this->offer($company, $now);
        }
        $start = $company->pro_trial_started_at->copy()->timezone('America/Sao_Paulo')->startOfDay();
        $end = $company->pro_trial_ends_at->copy()->timezone('America/Sao_Paulo');

        return [
            'until' => $end->format('d/m'),
            'days_left' => max(1, (int) ceil($now->diffInSeconds($company->pro_trial_ends_at, false) / 86400)),
            'total_days' => (int) round($start->diffInDays($end->copy()->endOfDay())),
            'offer_label' => $offer ? $this->discounts->label($offer) : null,
            'offer_until' => $offer && ! empty($offer['expires_at']) ? Carbon::parse($offer['expires_at'])->timezone('America/Sao_Paulo')->format('d/m') : null,
            'domain' => $company->custom_domain ?: null,
        ];
    }

    /**
     * Desconto do fim do teste: 30% no 1º mês, usado sozinho no checkout até 7 dias depois. Se o admin já deu um
     * desconto pra loja, não troca (fica o dele).
     */
    private function offer(Company $company, Carbon $now): ?array
    {
        if ($company->admin_discount && ! AdminDiscount::expired($company->admin_discount)) {
            return ($company->admin_discount['source'] ?? null) === 'trial_end' ? $company->admin_discount : null;
        }
        if (! config('cashier.secret')) {
            return null;
        }

        try {
            return $this->discounts->give($company, [
                'type' => 'percent',
                'value' => self::OFFER_PERCENT,
                'duration' => 'once',
                'note' => 'Oferta do fim do teste grátis',
            ], $now->copy()->timezone('America/Sao_Paulo')->addDays(self::OFFER_DAYS)->endOfDay()->utc(), 'trial_end');
        } catch (Throwable $e) {
            Log::warning("Teste do Pro: desconto do fim não criado (loja {$company->id}): ".$e->getMessage());

            return null;
        }
    }

    private function whatsappText(string $stage, Company $company, array $info): string
    {
        $app = rtrim((string) config('services.frontend_url'), '/');

        if ($stage === 'ending') {
            return implode("\n", array_filter([
                "⏳ *Seu teste do Pro termina em {$info['until']}*",
                '',
                "Oi! Em {$info['until']} a *{$company->name}* volta para o plano Grátis. Seus dados ficam, mas param o bot do WhatsApp, as ofertas nos grupos, os cupons e o cashback".($info['domain'] ? ", e o domínio próprio é desligado" : '').'.',
                '',
                $info['days_left'] >= 3 ? "Assinando agora, você só paga a partir de {$info['until']}." : 'Assine para continuar sem interrupção.',
                "👉 {$app}/plans",
                '',
                '— Equipe Meu Custo Total',
            ], fn ($l) => $l !== null));
        }

        return implode("\n", array_filter([
            '🎁 *Seu teste do Pro terminou*',
            '',
            "A *{$company->name}* voltou para o plano Grátis — nada foi apagado.",
            $info['offer_label'] ? "Para voltar ao Pro, você tem *{$info['offer_label']}* assinando até {$info['offer_until']}. Já está aplicado, sem código." : null,
            '',
            "👉 {$app}/plans",
            '',
            '— Equipe Meu Custo Total',
        ], fn ($l) => $l !== null));
    }
}
