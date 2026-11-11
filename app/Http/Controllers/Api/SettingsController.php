<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cashback;
use App\Services\CashbackImage;
use App\Services\CashbackRetroactive;
use App\Services\ProductRecipe;
use App\Support\Background;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ...self::FIXED_COSTS_RULES,
        ], self::FIXED_COSTS_MESSAGES);

        $setting = $request->user()->company->setting;

        // Funcionários e impostos (Essencial e Pro). Quem não tem o plano mantém o que já estava gravado e
        // ainda pode desligar (sem rateio e sem imposto %), mas não criar nem alterar.
        $fixed = $this->normalizeFixedCosts($data, $setting);
        foreach (array_keys($fixed) as $key) {
            unset($data[$key]);
        }
        if ($fixed !== []) {
            $current = collect(self::FIXED_COSTS_FIELDS)->mapWithKeys(fn ($k) => [$k => $this->fixedCostValue($setting, $k)])->all();
            $proposed = array_merge($current, $fixed);
            if ($proposed != $current && ! $this->isEssential($request)) {
                $turningOff = $proposed['fixed_costs_mode'] === \App\Models\Setting::FIXED_COSTS_NONE && (float) $proposed['tax_percent'] === 0.0;
                abort_unless($turningOff, 403, '"Funcionários e impostos" é um recurso dos planos Essencial e Pro. Assine para desbloquear.');
            }
            $this->validateFixedCosts($setting->replicate()->forceFill($proposed));
            $data = array_merge($data, $fixed);
        }

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
        // (e funcionários/impostos: hora da folha, imposto e rateio por dentro no preço sugerido)
        if ($setting->wasChanged(['electricity_rate_kwh', 'labor_hour_rate', 'default_failure_rate', 'default_markup', ...self::FIXED_COSTS_FIELDS])) {
            app(ProductRecipe::class)->refreshCompany($setting->company_id);
        }

        return $setting;
    }

    /** Campos de funcionários e impostos (Parâmetros de Precificação). */
    private const FIXED_COSTS_FIELDS = ['employees', 'fixed_expenses', 'fixed_costs_mode', 'estimated_monthly_revenue', 'orders_per_month', 'tax_percent', 'tax_fixed_monthly'];

    private const FIXED_COSTS_RULES = [
        'employees' => ['sometimes', 'nullable', 'array', 'max:50'],
        'employees.*.name' => ['required', 'string', 'max:80'],
        'employees.*.salary' => ['required', 'numeric', 'min:0', 'max:9999999'],
        'employees.*.charges_percent' => ['required', 'numeric', 'min:0', 'max:200'],
        'employees.*.hours_per_month' => ['required', 'numeric', 'min:0', 'max:744'],
        'fixed_expenses' => ['sometimes', 'nullable', 'array', 'max:30'],
        'fixed_expenses.*.name' => ['required', 'string', 'max:80'],
        'fixed_expenses.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
        'fixed_costs_mode' => ['sometimes', 'string', 'in:none,revenue,hour,order'],
        'estimated_monthly_revenue' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
        'orders_per_month' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
        'tax_fixed_monthly' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
    ];

    private const FIXED_COSTS_MESSAGES = [
        'employees.*.name.required' => 'Informe o nome ou a função de cada funcionário.',
        'employees.*.salary.required' => 'Informe o salário de cada funcionário.',
        'employees.*.hours_per_month.max' => 'Um mês tem no máximo 744 horas.',
        'tax_percent.max' => 'O imposto sobre a venda pode ser no máximo 50%.',
        'employees.max' => 'Cadastre no máximo 50 funcionários.',
        'fixed_expenses.*.name.required' => 'Informe o nome de cada despesa fixa.',
        'fixed_expenses.*.amount.required' => 'Informe o valor por mês de cada despesa fixa.',
        'fixed_expenses.max' => 'Cadastre no máximo 30 despesas fixas.',
    ];

    /** Valores enviados de funcionários/impostos, já limpos (só os que vieram na requisição). */
    private function normalizeFixedCosts(array $data, \App\Models\Setting $setting): array
    {
        $out = [];
        if (array_key_exists('employees', $data)) {
            $employees = collect($data['employees'] ?? [])->map(fn ($e) => [
                'name' => trim((string) $e['name']),
                'salary' => round((float) $e['salary'], 2),
                'charges_percent' => round((float) $e['charges_percent'], 2),
                'hours_per_month' => round((float) $e['hours_per_month'], 1),
            ])->values()->all();
            $out['employees'] = $employees === [] ? null : $employees;
        }
        if (array_key_exists('fixed_expenses', $data)) {
            $expenses = collect($data['fixed_expenses'] ?? [])->map(fn ($e) => [
                'name' => trim((string) $e['name']),
                'amount' => round((float) $e['amount'], 2),
            ])->values()->all();
            $out['fixed_expenses'] = $expenses === [] ? null : $expenses;
        }
        if (array_key_exists('fixed_costs_mode', $data)) {
            $out['fixed_costs_mode'] = $data['fixed_costs_mode'];
        }
        if (array_key_exists('estimated_monthly_revenue', $data)) {
            $out['estimated_monthly_revenue'] = $data['estimated_monthly_revenue'] === null ? null : round((float) $data['estimated_monthly_revenue'], 2);
        }
        if (array_key_exists('orders_per_month', $data)) {
            $out['orders_per_month'] = $data['orders_per_month'] === null ? null : (int) $data['orders_per_month'];
        }
        foreach (['tax_percent', 'tax_fixed_monthly'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = round((float) $data[$key], 2);
            }
        }

        return $out;
    }

    /** Valor gravado no mesmo formato de normalizeFixedCosts (pra comparar se algo mudou). */
    private function fixedCostValue(\App\Models\Setting $setting, string $key): mixed
    {
        return match ($key) {
            'employees' => $setting->employees ?: null,
            'fixed_expenses' => $setting->fixed_expenses ?: null,
            'fixed_costs_mode' => $setting->fixedCostsMode(),
            'estimated_monthly_revenue' => $setting->estimated_monthly_revenue === null ? null : (float) $setting->estimated_monthly_revenue,
            'orders_per_month' => $setting->orders_per_month,
            default => (float) $setting->{$key},
        };
    }

    /** Cada forma de rateio precisa do seu dado; e imposto + rateio por dentro não podem engolir o preço. */
    private function validateFixedCosts(\App\Models\Setting $proposed): void
    {
        $mode = $proposed->fixedCostsMode();
        $errors = [];
        if ($mode !== \App\Models\Setting::FIXED_COSTS_NONE && $proposed->fixedCostsMonthly() <= 0) {
            $errors['employees'] = 'Cadastre pelo menos um funcionário com salário, uma despesa fixa ou um imposto fixo mensal para incluir no preço.';
        }
        if ($mode === \App\Models\Setting::FIXED_COSTS_REVENUE && (float) $proposed->estimated_monthly_revenue <= 0) {
            $errors['estimated_monthly_revenue'] = 'Informe o faturamento mensal estimado.';
        }
        if ($mode === \App\Models\Setting::FIXED_COSTS_HOUR && $proposed->employeeHoursMonthly() <= 0) {
            $errors['employees'] = 'Informe as horas trabalhadas por mês dos funcionários.';
        }
        if ($mode === \App\Models\Setting::FIXED_COSTS_ORDER && (int) $proposed->orders_per_month <= 0) {
            $errors['orders_per_month'] = 'Informe quantos pedidos a loja faz por mês.';
        }
        if ($errors === [] && $proposed->taxRate() + $proposed->fixedCostsPercent() > \App\Models\Setting::MAX_INSIDE_PERCENT) {
            $errors['estimated_monthly_revenue'] = 'Imposto + custos fixos passam de '.\App\Models\Setting::MAX_INSIDE_PERCENT.'% do faturamento — confira o faturamento estimado.';
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * Prévia de funcionários e impostos, antes de salvar: faturamento e pedidos médios dos últimos 3 meses
     * (sugestões pros campos) e o preço de um produto de exemplo antes e depois dos valores informados.
     */
    public function fixedCostsPreview(Request $request)
    {
        $data = $request->validate(self::FIXED_COSTS_RULES, self::FIXED_COSTS_MESSAGES);
        $company = $request->user()->company;
        $setting = $company->setting;
        $proposed = $setting->replicate()->forceFill($this->normalizeFixedCosts($data, $setting));

        // Vendas aprovadas dos últimos 3 meses (ou desde o cadastro, se a loja é mais nova)
        $since = now()->subMonths(3);
        $months = max(1, min(3, (int) ceil($company->created_at->diffInDays(now()) / 30)));
        $sales = \App\Models\Quote::where('company_id', $company->id)
            ->where('status', \App\Models\Quote::STATUS_APPROVED)
            ->whereNull('cancelled_at')
            ->whereRaw('COALESCE(approved_at, created_at) >= ?', [$since])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(final_price), 0) as revenue')
            ->first();

        return response()->json([
            'months' => $months,
            'suggested_revenue' => round((float) $sales->revenue / $months, 2),
            'suggested_orders' => (int) round($sales->orders / $months),
            'sample' => $this->samplePrice($company->id, $setting, $proposed),
        ]);
    }

    /** Um produto cujo preço é calculado (ficha técnica ou custo + margem), com o preço atual e o novo. */
    private function samplePrice(int $companyId, \App\Models\Setting $current, \App\Models\Setting $proposed): ?array
    {
        $product = \App\Models\Product::where('company_id', $companyId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auto_cost', true)->where('auto_price', true))
                ->orWhere(fn ($q) => $q->whereNull('sale_price')->where('cost', '>', 0)))
            ->orderByDesc('auto_price')
            ->orderByDesc('updated_at')
            ->first();
        if (! $product) {
            return null;
        }

        $recipe = app(ProductRecipe::class);
        $priceWith = function (\App\Models\Setting $setting) use ($product, $recipe) {
            if ($product->auto_cost) {
                $unit = $recipe->unitCost($product, null, $setting);

                return $product->auto_price ? (float) $unit['suggested_price'] : $setting->autoPrice($unit['total']);
            }

            return $setting->autoPrice($product->cost);
        };

        return [
            'name' => $product->name,
            'before' => $priceWith($current),
            'after' => $priceWith($proposed),
        ];
    }

    /**
     * Agenda (Pro): regras de entrega — dias em que a loja trabalha, antecedência mínima, limite de pedidos por dia,
     * datas bloqueadas, formas oferecidas e se o cliente escolhe a data no catálogo e no bot.
     */
    public function updateDelivery(Request $request)
    {
        $this->requirePro($request, 'Agenda');
        $data = $request->validate([
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'min_days' => ['required', 'integer', 'min:0', 'max:60'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'blocked_dates' => ['sometimes', 'array', 'max:120'],
            'blocked_dates.*' => ['date_format:Y-m-d', 'distinct'],
            'methods' => ['required', 'array', 'min:1'],
            'methods.*' => [\Illuminate\Validation\Rule::in(\App\Support\Delivery::METHODS), 'distinct'],
            'customer_chooses' => ['required', 'boolean'],
        ], [
            'weekdays.required' => 'Marque pelo menos um dia em que a loja trabalha.',
            'weekdays.min' => 'Marque pelo menos um dia em que a loja trabalha.',
            'methods.required' => 'Escolha pelo menos uma forma de entrega.',
            'methods.min' => 'Escolha pelo menos uma forma de entrega.',
        ]);

        $today = now(\App\Support\Delivery::TZ)->toDateString();
        $rules = [
            'weekdays' => collect($data['weekdays'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
            'min_days' => (int) $data['min_days'],
            'daily_limit' => $data['daily_limit'] ? (int) $data['daily_limit'] : null,
            // Datas passadas não servem pra nada: saem da lista
            'blocked_dates' => collect($data['blocked_dates'] ?? [])->filter(fn ($d) => $d >= $today)->unique()->sort()->values()->all(),
            'methods' => array_values(array_intersect(\App\Support\Delivery::METHODS, $data['methods'])),
            'customer_chooses' => (bool) $data['customer_chooses'],
        ];
        $setting = $request->user()->company->setting;
        $setting->update(['delivery_rules' => $rules]);
        // O catálogo público guarda a página por alguns minutos: a pergunta da data entra ou sai na hora
        \App\Services\CatalogCache::forget($setting->company_id);

        return $setting->fresh();
    }

    /**
     * Nome da loja (errou no cadastro, mudou de nome). O endereço do catálogo, o QR das placas e a sessão do
     * WhatsApp usam o slug, que fica como está — nenhum link já divulgado quebra.
     */
    public function updateName(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255', 'regex:/\p{L}/u'],
        ], [
            'name.required' => 'Informe o nome da loja.',
            'name.min' => 'O nome da loja deve ter pelo menos 3 caracteres.',
            'name.max' => 'O nome da loja pode ter no máximo 255 caracteres.',
            'name.regex' => 'Informe um nome de loja válido.',
        ]);

        $company = $request->user()->company;
        $company->update(['name' => trim($data['name'])]);

        // Nome nas faturas e recibos da Stripe (sem travar a tela se a Stripe não responder)
        if ($company->stripe_id) {
            $companyId = $company->id;
            Background::run(static function () use ($companyId) {
                try {
                    $company = Company::find($companyId);
                    $company?->updateStripeCustomer(['name' => $company->name]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Nome da loja {$companyId} não atualizado na Stripe: ".$e->getMessage());
                }
            });
        }

        return response()->json(['name' => $company->name]);
    }

    /** Nicho da empresa: só muda nomes nas telas e as categorias padrão sugeridas — nada do que já foi cadastrado. */
    public function updateSegment(Request $request)
    {
        $data = $request->validate([
            'segment' => ['required', Rule::in(Company::SEGMENTS)],
        ]);

        $company = $request->user()->company;
        $company->update($data);

        return response()->json(['segment' => $company->segment]);
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

    /** Prévia do cashback retroativo: quem recebe e quanto, sem gravar nada. */
    public function cashbackRetroactivePreview(Request $request, CashbackRetroactive $retroactive)
    {
        $company = $request->user()->company;
        abort_unless(Cashback::isActive($company), 422, 'Ligue o cashback (plano Pro) antes de creditar os clientes atuais.');

        $data = $request->validate(['months' => ['required', 'integer', 'min:1', 'max:24']]);

        return response()->json([
            ...$retroactive->preview($company, (int) $data['months']),
            'queue' => $retroactive->queueStatus($company),
        ]);
    }

    /** Credita o cashback retroativo e, se pedido, enfileira os avisos pelo WhatsApp (um por minuto). */
    public function cashbackRetroactiveApply(Request $request, CashbackRetroactive $retroactive)
    {
        $company = $request->user()->company;
        abort_unless(Cashback::isActive($company), 422, 'Ligue o cashback (plano Pro) antes de creditar os clientes atuais.');

        $data = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $result = $retroactive->apply($company, (int) $data['months'], (bool) ($data['notify'] ?? false));
        abort_if($result['customers_count'] === 0, 422, 'Nenhuma compra desse período ainda sem cashback.');

        return response()->json([...$result, 'queue' => $retroactive->queueStatus($company)]);
    }

    /** Andamento da fila de avisos de cashback. */
    public function cashbackNotificationsStatus(Request $request, CashbackRetroactive $retroactive)
    {
        return response()->json($retroactive->queueStatus($request->user()->company));
    }

    /** Prévia da imagem que vai junto do aviso de cashback (com valores de exemplo). */
    public function cashbackImagePreview(Request $request, CashbackImage $image)
    {
        $company = $request->user()->company;
        $jpeg = $image->render($company, 'Maria', 24.5, now()->addMonths(max(1, (int) $company->setting->cashback_validity_months)));
        abort_unless($jpeg, 503, 'O servidor não conseguiu gerar a imagem.');

        return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }
}
