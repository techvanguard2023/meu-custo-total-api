<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponAutomation;
use App\Services\CouponAutomations;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cupom automático de reativação (Pro, só o dono): regras, prévia da mensagem e números. */
class CouponAutomationController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private CouponAutomations $automations) {}

    public function show(Request $request)
    {
        $this->requirePro($request, 'Cupons automáticos');

        return response()->json($this->payload($request->user()->company));
    }

    public function update(Request $request)
    {
        $this->requirePro($request, 'Cupons automáticos');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'inactive_days' => ['required', 'integer', 'min:15', 'max:730'],
            'discount_type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', Rule::when($request->input('discount_type') === Coupon::TYPE_PERCENT, ['max:100'])],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:90'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:200'],
            'allow_promotions' => ['required', 'boolean'],
        ], [
            'inactive_days.min' => 'Use pelo menos 15 dias sem comprar.',
            'value.max' => 'O desconto em % vai até 100.',
        ]);

        $company = $request->user()->company;
        $this->automations->settings($company)->fill($data)->save();

        return response()->json($this->payload($company));
    }

    private function payload(Company $company): array
    {
        $automation = $this->automations->settings($company);

        // Prévia com um cupom de mentira (não grava nada)
        $sample = new Coupon([
            'code' => 'MARIA-7K2Q',
            'type' => $automation->discount_type,
            'value' => $automation->value,
            'min_order' => $automation->min_order,
            'ends_on' => now($company->timezone ?: 'America/Sao_Paulo')->addDays($automation->validity_days)->toDateString(),
        ]);
        $sample->setRelation('customer', new \App\Models\Customer(['name' => 'Maria Silva']));

        return [
            'settings' => $automation->only(['enabled', 'inactive_days', 'discount_type', 'value', 'min_order', 'validity_days', 'daily_limit', 'allow_promotions']),
            'whatsapp_connected' => (bool) $company->whatsapp_session_name,
            // Quantos se encaixam hoje (antes do limite diário)
            'candidates' => $this->automations->reactivationCandidates($company, $automation)->count(),
            'preview' => $this->automations->message($company, $sample),
            'send_hours' => [CouponAutomations::SEND_FROM_HOUR, CouponAutomations::SEND_UNTIL_HOUR],
        ] + $this->automations->report($company);
    }
}
