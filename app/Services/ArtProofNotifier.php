<?php

namespace App\Services;

use App\Models\ArtProof;

/** Avisos da aprovação de arte: pro cliente (o link) e pra loja (a resposta), pelo WhatsApp conectado da loja. */
class ArtProofNotifier
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | failed */
    public function sendToCustomer(ArtProof $proof): array
    {
        $proof->loadMissing(['quote.customer', 'company', 'files']);
        $first = explode(' ', trim((string) $proof->quote->customer?->name))[0];
        $store = $proof->company->name ?: 'loja';
        $options = $proof->choose_one ? $proof->files->count() : 0;

        $message = implode("\n", array_filter([
            'Olá'.($first !== '' ? ", {$first}" : '')."! Aqui é da {$store}.",
            $options > 1
                ? "Preparamos {$options} opções de arte para o seu pedido. Escolha a que você mais gostou e aprove antes de começarmos a produzir:"
                : 'Preparamos a arte do seu pedido para você conferir e aprovar antes de começarmos a produzir:',
            $proof->publicUrl(),
            '',
            'Confira tudo com atenção (textos, nomes, cores, tamanho). Estando certo, é só aprovar pelo link. Se precisar de algum ajuste, peça por lá mesmo.',
        ], fn ($line) => $line !== null));

        return $this->messenger->deliver($proof->company, $proof->quote->customer?->phone, $message);
    }

    /** Aviso pra própria loja quando o cliente responde (só chega se o WhatsApp da loja estiver conectado). */
    public function notifyStore(ArtProof $proof): bool
    {
        $proof->loadMissing(['quote.customer', 'company', 'files']);
        $who = trim((string) $proof->quote->customer?->name) ?: 'O cliente';
        $order = trim(explode('|__JSON__|', (string) $proof->quote->name)[0]) ?: "Pedido #{$proof->quote_id}";
        $link = $proof->company->publicOrigin().'/quotes/'.$proof->quote_id;
        $option = $proof->chosenOptionNumber();
        $chosen = $option ? " Escolheu a opção {$option} (".$proof->files->firstWhere('id', $proof->chosen_file_id)?->original_name.').' : '';

        $message = $proof->status === ArtProof::APPROVED
            ? "✅ {$who} aprovou a arte (versão {$proof->version}) do pedido \"{$order}\".{$chosen} Aprovado por: {$proof->approver_name}.\n{$link}"
            : "✏️ {$who} pediu ajustes na arte (versão {$proof->version}) do pedido \"{$order}\":\n\"{$proof->customer_comment}\"\n{$link}";

        return $this->messenger->notifyStore($proof->company, $message);
    }
}
