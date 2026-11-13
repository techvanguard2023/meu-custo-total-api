<?php

namespace App\Providers;

use App\Models\Company;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Alexa\AlexaRequestVerifier::class, fn () => new \App\Services\Alexa\AlexaRequestVerifier(config('services.alexa.skill_id')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->watchLazyLoading();
        $this->queueHeartbeat();

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

    /**
     * Fora de produção, relação carregada "sob demanda" dentro de um laço (o N+1) é denunciada: nos testes
     * quebra na hora; no ambiente local vai pro log do container (docker logs) com o model e a relação.
     * Corrija com with()/load() ou setRelation() — em produção nada muda.
     */
    private function watchLazyLoading(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            if ($this->app->runningUnitTests()) {
                throw new LazyLoadingViolationException($model, $relation);
            }

            $where = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))
                ->first(fn ($f) => isset($f['file']) && str_starts_with($f['file'], app_path()));
            Log::channel('stderr')->warning(sprintf(
                'N+1: %s::$%s carregado sob demanda%s',
                $model::class,
                $relation,
                $where ? ' em '.str_replace(base_path().'/', '', $where['file']).':'.$where['line'] : '',
            ));
        });
    }

    /**
     * Sinal de vida dos workers da fila (Admin → Sistema): a cada volta do worker, no máximo uma vez por
     * minuto, grava a hora no cache. Sem sinal há alguns minutos = worker parado.
     */
    private function queueHeartbeat(): void
    {
        Event::listen(Looping::class, function (Looping $event) {
            static $last = [];
            if (($last[$event->connectionName] ?? 0) > time() - 60) {
                return;
            }
            $last[$event->connectionName] = time();

            try {
                Cache::put("queue-worker:{$event->connectionName}", now()->toIso8601String(), 600);
            } catch (\Throwable) {
                // Cache fora do ar: o worker segue trabalhando
            }
        });
    }
}
