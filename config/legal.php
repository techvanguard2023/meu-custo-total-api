<?php

return [
    // Versão vigente dos Termos de Uso e da Política de Privacidade (mesma data que aparece nas
    // páginas do frontend, src/lib/legal.ts). Mudou o texto de forma relevante? Atualize as duas:
    // quem aceitou a versão anterior vê a janela de aceite de novo no próximo acesso.
    'terms_version' => '2026-11-01',

    // Dias entre o pedido de exclusão da conta e a exclusão definitiva (dá pra desfazer nesse prazo)
    'deletion_grace_days' => 7,
];
