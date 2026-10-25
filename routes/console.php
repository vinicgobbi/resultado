<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tse:sincronizar')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->runInBackground();
