<?php

namespace App\Providers;

use App\Listeners\SyncPlanFromStripe;
use App\Models\Company;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A entidade cobrada na Stripe é a Company (fixado em código para
        // não depender da env CASHIER_MODEL, que painéis corrompem fácil)
        Cashier::useCustomerModel(Company::class);

        Event::listen(WebhookHandled::class, SyncPlanFromStripe::class);

        // O link de redefinição aponta para a tela do frontend
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim(config('services.frontend_url'), '/');

            return $frontend.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // E-mail de redefinição em português
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token);

            return (new MailMessage)
                ->subject('Redefinição de senha — Meu Custo Total')
                ->greeting('Olá!')
                ->line('Recebemos uma solicitação para redefinir a senha da sua conta.')
                ->action('Redefinir Senha', $url)
                ->line('Este link expira em 60 minutos.')
                ->line('Se você não solicitou a redefinição, nenhuma ação é necessária.')
                ->salutation('Equipe Meu Custo Total');
        });
    }
}
