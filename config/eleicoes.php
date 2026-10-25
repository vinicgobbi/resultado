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
    | TSE election codes for the presidential race of each round. They can be
    | found in /oficial/comum/config/ele-c.json ("cd" and "cdt2").
    |
    */

    'turnos' => [
        1 => (int) env('TSE_ELEICAO_1T', 6257),
        2 => (int) env('TSE_ELEICAO_2T', 6258),
    ],

    'turno_ativo' => (int) env('TSE_TURNO_ATIVO', 2),

    /*
    | Code of the presidential office in the TSE files.
    */

    'cargo' => '0001',

    /*
    | Maximum number of simultaneous requests sent to the TSE.
    */

    'concorrencia' => (int) env('TSE_CONCORRENCIA', 30),

];
