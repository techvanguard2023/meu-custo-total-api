<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\PaymentAccount;
use App\Services\PagBank;
use App\Services\PagBankAccounts;
use App\Services\PixCharges;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Integrações → PagBank (Pro, só o dono): status, "Conectar com PagBank" (Connect) ou colar o token da conta, e desconectar. A loja usa um
 * gateway por vez: conectar o PagBank troca o Mercado Pago (e o contrário). A volta do Connect (callback) é
 * pública e redireciona para o app com o resultado. A regra do Pix do bot é a mesma dos dois gateways
 * (PUT /payments/mercadopago/rules).
 */
class PagBankController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private PagBankAccounts $accounts) {}

    public function show(Request $request)
    {
        $company = $request->user()->company;
        $account = $this->accounts->account($company);
        $test = (bool) config('services.pagbank.test_access_token') && ! app()->isProduction();
        $current = PaymentAccount::where('company_id', $company->id)->value('provider');

        return response()->json([
            // Colar o token da conta não depende de nada da plataforma: sempre disponível. O botão do Connect, só
            // com a aplicação configurada.
            'available' => true,
            'connect_available' => PagBank::configured(),
            'pro' => $company->isPro(),
            'test_mode' => $test,
            'sandbox' => (bool) config('services.pagbank.sandbox'),
            'connected' => (bool) $account?->isActive(),
            // Escondido pela plataforma (admin → Sistema): o card só aparece para quem já está conectado
            'visible' => PlatformSetting::gatewayVisible('pagbank', $request->user()),
            // Outro gateway conectado (conectar o PagBank troca por ele)
            'other_gateway' => $current && $current !== PagBankAccounts::PROVIDER ? $current : null,
            'rules' => PixCharges::rules($company),
            'account' => $account ? [
                'connected_at' => $account->connected_at?->toIso8601String(),
                'live_mode' => $account->live_mode,
                'revoked' => ! $account->isActive(),
                'mode' => PagBankAccounts::byToken($account) ? 'token' : 'connect',
                'error' => $account->revoked_at
                    ? (PagBankAccounts::byToken($account) ? 'O PagBank recusou o token da loja (revogado ou trocado). Cole um token novo.' : 'O acesso foi revogado ou venceu. Conecte de novo.')
                    : null,
            ] : null,
        ]);
    }

    public function connect(Request $request)
    {
        abort_unless(PlatformSetting::gatewayVisible('pagbank', $request->user()), 403, 'O pagamento pelo PagBank não está disponível no momento.');
        $this->requirePro($request, 'Pagamento online');
        try {
            return response()->json(['url' => $this->accounts->connectUrl($request->user()->company, $request->user()->id)]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** POST /payments/pagbank/token {token}: conecta pelo token da própria conta do PagBank (sem o Connect). */
    public function connectWithToken(Request $request)
    {
        abort_unless(PlatformSetting::gatewayVisible('pagbank', $request->user()), 403, 'O pagamento pelo PagBank não está disponível no momento.');
        $this->requirePro($request, 'Pagamento online');
        $data = $request->validate(['token' => ['required', 'string', 'max:500']], ['token.required' => 'Cole o token da sua conta do PagBank.']);
        try {
            $this->accounts->connectWithToken($request->user()->company, $data['token']);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->show($request);
    }

    public function disconnect(Request $request)
    {
        $this->accounts->disconnect($request->user()->company);

        return response()->json(['connected' => false]);
    }

    /** GET /pagbank/callback?code=...&state=... (o navegador do lojista volta do PagBank). */
    public function callback(Request $request)
    {
        $target = rtrim((string) config('services.frontend_url'), '/').'/integrations';
        try {
            $this->accounts->finish($request->query('state'), $request->query('code'));

            return redirect()->away($target.'?pagbank=conectado#pagbank');
        } catch (RuntimeException $e) {
            return redirect()->away($target.'?'.http_build_query(['pagbank' => 'erro', 'motivo' => $e->getMessage()]).'#pagbank');
        }
    }
}
