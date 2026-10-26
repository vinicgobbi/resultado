<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Divulgação de Resultados do TSE
    |--------------------------------------------------------------------------
    |
    | Base URL, environment ("oficial" or "simulado") and election cycle used
    | to build the public JSON file URLs published by the TSE.
    |
    */

    'base_url' => env('TSE_BASE_URL', 'https://resultados.tse.jus.br'),

    'ambiente' => env('TSE_AMBIENTE', 'oficial'),

    'ciclo' => env('TSE_CICLO', 'ele2026'),

    /*
    |--------------------------------------------------------------------------
    | Election codes per round
    |--------------------------------------------------------------------------
    |
    | TSE election codes of each round: the federal election (president) and
    | the state election (governor, senator and deputies). They can be found
    | in /oficial/comum/config/ele-c.json ("cd" and "cdt2").
    |
    */

    'turnos' => [
        1 => [
            'federal' => (int) env('TSE_ELEICAO_1T', 6257),
            'estadual' => (int) env('TSE_ELEICAO_ESTADUAL_1T', 6259),
        ],
        2 => [
            'federal' => (int) env('TSE_ELEICAO_2T', 6258),
            'estadual' => (int) env('TSE_ELEICAO_ESTADUAL_2T', 6260),
        ],
    ],

    'turno_ativo' => (int) env('TSE_TURNO_ATIVO', 2),

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
