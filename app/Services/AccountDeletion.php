<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Exclusão da conta (LGPD): o lojista pede, a conta fica fora do ar por alguns dias (dá pra desfazer)
 * e depois account:purge-deleted apaga a empresa com tudo o que é dela — no banco e nos arquivos.
 */
class AccountDeletion
{
    public function __construct(private WahaClient $waha, private VercelDomains $vercel) {}

    /** Agenda a exclusão. A assinatura é cancelada no fim do período pago, pra não cobrar de novo. */
    public function schedule(Company $company): void
    {
        $subscription = $company->subscription('default');
        $paused = false;

        if ($subscription && $subscription->valid() && ! $subscription->onGracePeriod()) {
            $subscription->cancel();
            $paused = true;
        }

        $company->forceFill([
            'deletion_scheduled_for' => now()->addDays((int) config('legal.deletion_grace_days', 7)),
            'deletion_paused_subscription' => $paused,
        ])->save();
    }

    /** Desfaz o pedido: tudo volta como estava, inclusive a assinatura que o pedido cancelou. */
    public function cancel(Company $company): void
    {
        $subscription = $company->subscription('default');

        if ($company->deletion_paused_subscription && $subscription?->onGracePeriod()) {
            $subscription->resume();
        }

        $company->forceFill([
            'deletion_scheduled_for' => null,
            'deletion_paused_subscription' => false,
        ])->save();
    }

    /**
     * Apaga a empresa de vez. As tabelas da empresa saem em cascata; aqui ficam o que a cascata não alcança
     * (assinatura, tokens, arquivos) e os serviços de fora (Stripe, WhatsApp, Vercel).
     * Os registros de cobrança continuam na Stripe — obrigação fiscal, citada na Política de Privacidade.
     */
    public function purge(Company $company): void
    {
        // Sem conseguir encerrar a assinatura, não apaga: a cobrança continuaria sem a conta existir.
        // O comando tenta de novo no dia seguinte.
        $subscription = $company->subscription('default');
        if ($subscription && ! $subscription->ended()) {
            $subscription->cancelNow();
        }

        if ($company->whatsapp_session_name && $this->waha->isConfigured()) {
            $this->attempt(fn () => $this->waha->logoutSession($company->whatsapp_session_name), $company, 'sessão do WhatsApp');
        }

        if ($this->vercel->isConfigured()) {
            foreach (array_filter([$company->custom_domain, $company->partner_domain]) as $domain) {
                $this->attempt(fn () => $this->vercel->remove($domain), $company, "domínio {$domain}");
            }
        }

        $files = $this->filesOf($company);
        $users = $company->users()->get(['id', 'email']);

        DB::transaction(function () use ($company, $users) {
            $subscriptionIds = DB::table('subscriptions')->where('company_id', $company->id)->pluck('id');
            DB::table('subscription_items')->whereIn('subscription_id', $subscriptionIds)->delete();
            DB::table('subscriptions')->whereIn('id', $subscriptionIds)->delete();

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $users->pluck('id'))
                ->delete();
            DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email'))->delete();
            DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->delete();

            $company->delete();
        });

        Storage::disk('public')->delete($files);
    }

    /** Fotos, logo, banners e imagens geradas que pertencem à empresa. */
    private function filesOf(Company $company): array
    {
        $productIds = DB::table('products')->where('company_id', $company->id)->pluck('id');
        $collectionBanners = DB::table('product_collections')->where('company_id', $company->id)->whereNotNull('banner_path')->pluck('banner_path');

        return collect([$company->logo_path])
            ->merge(DB::table('products')->where('company_id', $company->id)->whereNotNull('image_path')->pluck('image_path'))
            ->merge(DB::table('product_images')->whereIn('product_id', $productIds)->pluck('path'))
            ->merge(DB::table('catalog_banners')->where('company_id', $company->id)->pluck('image_path'))
            ->merge($collectionBanners)
            ->merge($collectionBanners->map(fn ($path) => ShareImageGenerator::pathFor($path)))
            ->merge(DB::table('display_visits')
                ->join('displays', 'displays.id', '=', 'display_visits.display_id')
                ->where('displays.company_id', $company->id)
                ->whereNotNull('display_visits.photo_path')
                ->pluck('display_visits.photo_path'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Serviço de fora fora do ar não impede a exclusão — só fica registrado. */
    private function attempt(callable $action, Company $company, string $what): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::warning("Exclusão da empresa {$company->id}: não foi possível remover {$what}: {$e->getMessage()}");
        }
    }
}
