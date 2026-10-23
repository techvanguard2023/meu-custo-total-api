<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Services\ProductRecipe;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use EnforcesPlanLimits;

    public function show(Request $request)
    {
        return $request->user()->company->setting;
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'electricity_rate_kwh' => ['required', 'numeric', 'min:0'],
            'labor_hour_rate' => ['required', 'numeric', 'min:0'],
            'default_failure_rate' => ['required', 'numeric', 'min:0'],
            'default_markup' => ['required', 'numeric', 'min:0'],
            'minimum_order_price' => ['required', 'numeric', 'min:0'],
            // Desconto por valor do pedido (Pro): "a partir de R$ X, Y% de desconto"
            'order_discount_tiers' => ['sometimes', 'nullable', 'array', 'max:5'],
            'order_discount_tiers.*.min_total' => ['required', 'numeric', 'gt:0', 'max:99999999', 'distinct'],
            'order_discount_tiers.*.percent' => ['required', 'numeric', 'gt:0', 'max:95'],
        ]);

        $setting = $request->user()->company->setting;

        if (array_key_exists('order_discount_tiers', $data)) {
            $tiers = collect($data['order_discount_tiers'] ?? [])
                ->map(fn ($t) => ['min_total' => round((float) $t['min_total'], 2), 'percent' => round((float) $t['percent'], 2)])
                ->sortBy('min_total')
                ->values()
                ->all();
            $data['order_discount_tiers'] = $tiers === [] ? null : $tiers;

            // Mesmo critério dos produtos: sair do Pro não trava a tela de custos, só impede criar/alterar faixas.
            if (! $this->isPro($request)) {
                $unchanged = $data['order_discount_tiers'] === $setting->order_discount_tiers;
                abort_unless(
                    $data['order_discount_tiers'] === null || $unchanged,
                    403,
                    '"Desconto por valor do pedido" é um recurso exclusivo do plano Pro. Assine para desbloquear.'
                );
                if ($unchanged) {
                    unset($data['order_discount_tiers']);
                }
            }
        }

        $setting->update($data);

        // Produtos com custo pela ficha técnica usam energia, mão de obra e taxa de falha daqui
        // (e a margem, nos que seguem o preço sugerido)
        if ($setting->wasChanged(['electricity_rate_kwh', 'labor_hour_rate', 'default_failure_rate', 'default_markup'])) {
            app(ProductRecipe::class)->refreshCompany($setting->company_id);
        }

        return $setting;
    }

    /** Regras do cashback (Pro): % por compra, teto de uso por pedido e validade dos créditos. */
    public function updateCashback(Request $request)
    {
        $data = $request->validate([
            'cashback_enabled' => ['required', 'boolean'],
            'cashback_purchase_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'cashback_review_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'cashback_referral_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'cashback_max_redeem_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'cashback_validity_months' => ['required', 'integer', 'min:1', 'max:36'],
        ]);

        // Desligar sempre pode (o saldo dos clientes fica guardado); ligar e mudar as regras é do Pro
        if ($data['cashback_enabled']) {
            abort_unless($this->isPro($request), 403, '"Cashback" é um recurso exclusivo do plano Pro. Assine para desbloquear.');
        }

        $setting = $request->user()->company->setting;
        $setting->update($data);

        return $setting;
    }
}
