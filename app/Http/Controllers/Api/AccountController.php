<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletion;
use App\Services\AccountExport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Direitos do titular (LGPD): aceite dos termos, exportação dos dados e exclusão da conta. */
class AccountController extends Controller
{
    /** Aceite da versão vigente dos Termos de Uso e da Política de Privacidade (quem já tinha conta). */
    public function acceptTerms(Request $request)
    {
        $request->validate(['accept_terms' => ['accepted']]);

        $request->user()->acceptTerms($request->ip());

        return response()->json(['user' => $request->user()->fresh()->load('company')]);
    }

    /** Baixa todos os dados da empresa num ZIP (um CSV por tipo de dado). */
    public function export(Request $request, AccountExport $export)
    {
        $company = $request->user()->company;
        $path = $export->build($company);
        $filename = 'meu-custo-total-'.Str::slug($company->name).'-'.now()->format('Y-m-d').'.zip';

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Agenda a exclusão da conta (pede a senha). Até a data, dá pra desfazer entrando de novo. */
    public function requestDeletion(Request $request, AccountDeletion $deletion)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ], [
            'password.current_password' => 'Senha incorreta.',
        ]);

        $company = $request->user()->company;
        abort_if($company->isPendingDeletion(), 422, 'A exclusão desta conta já está agendada.');

        $deletion->schedule($company);

        return response()->json(['user' => $request->user()->fresh()->load('company')]);
    }

    /** Desiste da exclusão: a conta, o catálogo e o bot voltam a funcionar. */
    public function cancelDeletion(Request $request, AccountDeletion $deletion)
    {
        $company = $request->user()->company;
        abort_unless($company->isPendingDeletion(), 422, 'Não há exclusão agendada para esta conta.');

        $deletion->cancel($company);

        return response()->json(['user' => $request->user()->fresh()->load('company')]);
    }
}
