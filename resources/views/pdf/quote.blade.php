<?php
/**
 * Layout simples de orçamento em PDF — usado tanto no botão "Baixar PDF" da
 * tela do orçamento quanto no envio automático pelo WhatsApp (QuotePdfNotifier).
 */
$brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 12px; color: #1e293b; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 18px; }
    .company-name { font-size: 18px; font-weight: bold; color: #1e293b; }
    .quote-title { font-size: 16px; font-weight: bold; color: #4f46e5; text-align: right; }
    .quote-meta { font-size: 11px; color: #64748b; text-align: right; margin-top: 2px; }
    .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin: 16px 0 6px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 4px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { font-size: 10px; text-transform: uppercase; color: #64748b; }
    .text-right { text-align: right; }
    .total-row td { border-bottom: none; padding-top: 10px; font-size: 14px; font-weight: bold; }
    .footer { margin-top: 28px; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; background: #eef2ff; color: #4f46e5; }
</style>
</head>
<body>
    <div class="header">
        <div>
            <div class="company-name">{{ $quote->company->name }}</div>
            @if($quote->company->phone)
                <div class="quote-meta">{{ $quote->company->phone }}</div>
            @endif
        </div>
        <div>
            <div class="quote-title">Orçamento #{{ $quote->id }}</div>
            <div class="quote-meta">{{ $quote->created_at?->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="section-title">Cliente</div>
    <div>{{ $quote->customer?->name ?? 'Não informado' }}</div>
    @if($quote->customer?->phone)
        <div>{{ $quote->customer->phone }}</div>
    @endif

    <div class="section-title">Itens</div>
    <table>
        <thead>
            <tr>
                <th>Descrição</th>
                <th class="text-right">Qtd.</th>
                <th class="text-right">Valor unit.</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($quote->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">{{ $brl($item->unit_price) }}</td>
                    <td class="text-right">{{ $brl($item->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td>{{ $quote->name }}</td>
                    <td class="text-right">{{ $quote->quantity ?? 1 }}</td>
                    <td class="text-right">{{ $brl($quote->unit_price) }}</td>
                    <td class="text-right">{{ $brl($quote->final_price) }}</td>
                </tr>
            @endforelse
            <tr class="total-row">
                <td colspan="3">Total</td>
                <td class="text-right">{{ $brl($quote->final_price) }}</td>
            </tr>
        </tbody>
    </table>

    @if($quote->delivery_days)
        <div class="section-title">Prazo de entrega</div>
        <div>{{ $quote->delivery_days }} {{ $quote->delivery_days == 1 ? 'dia' : 'dias' }} após a confirmação</div>
    @endif

    @if($quote->notes)
        <div class="section-title">Observações</div>
        <div>{{ $quote->notes }}</div>
    @endif

    <div class="footer">
        Orçamento gerado por {{ $quote->company->name }} — valores sujeitos a confirmação de disponibilidade no momento do fechamento do pedido.
    </div>
</body>
</html>
