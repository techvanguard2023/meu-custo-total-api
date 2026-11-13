<?php

namespace App\Console\Commands;

use App\Models\AlexaReminder;
use App\Services\Alexa\AlexaApi;
use Illuminate\Console\Command;

/**
 * De hora em hora: pede à Amazon pra acordar a skill de cada pessoa com resumo diário. A Amazon responde com um
 * Messaging.MessageReceived (AlexaController), que refaz o texto do lembrete com as entregas do dia em que ele toca.
 */
class SendAlexaDailySummaries extends Command
{
    protected $signature = 'alexa:daily-summaries';

    protected $description = 'Atualiza o texto dos lembretes diários da Alexa com as entregas do dia';

    public function handle(AlexaApi $api): int
    {
        if (! AlexaApi::messagingConfigured()) {
            $this->info('Skill Messaging não configurado (ALEXA_MESSAGING_CLIENT_ID/SECRET): nada a fazer.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        AlexaReminder::query()->orderBy('id')->each(function (AlexaReminder $reminder) use ($api, &$sent, &$failed) {
            try {
                $api->sendSkillMessage($reminder->api_endpoint, $reminder->alexa_user_id, ['type' => 'daily_summary', 'reminder_id' => $reminder->id]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
            }
        });

        $this->info("Resumos pedidos: {$sent}".($failed ? " (falharam {$failed})" : '').'.');

        return self::SUCCESS;
    }
}
