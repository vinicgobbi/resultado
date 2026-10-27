<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Divulgação de Resultados do TSE
    |--------------------------------------------------------------------------
    |
    | Base URL and environment ("oficial" or "simulado") of the public JSON
    | files published by the TSE. The elections themselves (cycle, codes,
    | dates and offices) are discovered from its index: tse:descobrir.
    |
    */

    'base_url' => env('TSE_BASE_URL', 'https://resultados.tse.jus.br'),

    'ambiente' => env('TSE_AMBIENTE', 'oficial'),

    /*
    | Maximum number of simultaneous requests sent to the TSE. Above ~10 the
    | TSE starts answering "429 Too Many Requests".
    */

    'concorrencia' => (int) env('TSE_CONCORRENCIA', 6),

    /*
    | Seconds without any request to the TSE after it answers 429.
    */

    'pausa_429' => (int) env('TSE_PAUSA_429', 300),

];
