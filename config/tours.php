<?php

/**
 * Tours do app (react-joyride). Cada tour tem:
 *  - plan: plano mínimo da feature (free | essential | pro). Quem não tem vê a prévia "Ver planos", uma vez.
 *  - version: suba quando o tour mudar — aí quem tem o plano vê de novo.
 * Os ids batem com os tours montados no frontend (src/lib/tours.ts).
 */
return [
    'tours' => [
        'caixa' => ['title' => 'Caixa (venda rápida)', 'plan' => 'essential', 'version' => 1],
        'agenda' => ['title' => 'Agenda de entregas', 'plan' => 'pro', 'version' => 1],
        'sino-entregas' => ['title' => 'Avisos de entrega', 'plan' => 'pro', 'version' => 1],
        'cashback' => ['title' => 'Cashback', 'plan' => 'pro', 'version' => 1],
        'pagamento-online' => ['title' => 'Pagamento online', 'plan' => 'pro', 'version' => 1],
    ],
];
