<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mantém a tabela de visitas do catálogo enxuta (só roda se o scheduler estiver ativo no servidor)
Schedule::command('catalog:prune-visits')->weeklyOn(1, '03:00');
