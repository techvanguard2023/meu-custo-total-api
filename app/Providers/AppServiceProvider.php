<?php

namespace App\Providers;

use App\Models\Company;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

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
        // Limite de tentativas no login, cadastro e senha (força bruta e cadastro em massa). Por e-mail + IP e
        // por IP, pra uma rede compartilhada (loja com vários caixas) não travar todo mundo por causa de um.
        $tooMany = fn () => response()->json(['message' => 'Muitas tentativas. Aguarde um pouco e tente de novo.'], 429);
        $emailIp = fn (Request $r) => mb_strtolower((string) $r->input('email')).'|'.$r->ip();
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(8)->by('login:'.$emailIp($r))->response($tooMany),
            Limit::perMinute(40)->by('login-ip:'.$r->ip())->response($tooMany),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by('register:'.$r->ip())->response($tooMany));
        RateLimiter::for('password-forgot', fn (Request $r) => [
            Limit::perMinute(2)->by('forgot:'.$emailIp($r))->response($tooMany),
            Limit::perHour(20)->by('forgot-ip:'.$r->ip())->response($tooMany),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(10)->by('reset:'.$r->ip())->response($tooMany));

        // A entidade cobrada na Stripe é a Company (fixado em código para
        // não depender da env CASHIER_MODEL, que painéis corrompem fácil)
        Cashier::useCustomerModel(Company::class);

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
