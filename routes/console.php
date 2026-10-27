<?php

use Illuminate\Support\Facades\Schedule;

// New regular elections appear in the TSE index.
Schedule::command('tse:descobrir')
    ->hourly()
    ->withoutOverlapping();

// Elections being counted: only on the election day (after the polls close)
// and the following days until every result is final.
Schedule::command('tse:sincronizar')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->runInBackground();

// Past elections not imported yet, while the TSE still publishes them. A
// large one takes long, and is resumed after a 429.
Schedule::command('tse:importar')
    ->hourly()
    ->withoutOverlapping(360)
    ->runInBackground();

// Results the TSE did not finalize after the count may still be
// retotalized: only what it updated is downloaded.
Schedule::command('tse:importar --revisar')
    ->dailyAt('04:30')
    ->withoutOverlapping(360)
    ->runInBackground();

// Photos of past elections, like their results: only the majority offices
// (a few thousand, shown in big); deputies and councillors are hundreds of
// thousands, downloaded from the dashboard or when first shown. Stops on a
// 429 and while an election is counted, and resumes on the next run.
Schedule::command('tse:fotos --encerradas --sem-espera --cargo=presidente --cargo=governador --cargo=senador --cargo=prefeito')
    ->hourly()
    ->withoutOverlapping(120)
    ->runInBackground();

// Push notifications are queued by the sync; a short-lived worker started by
// the scheduler sends them, so no permanent worker process is required.
Schedule::command('queue:work --stop-when-empty --max-time=60')
    ->everyFifteenSeconds()
    ->withoutOverlapping()
    ->runInBackground();

// Tasks started from the admin dashboard run on their own queue, so a long
// one (all the photos, a whole election) never holds up the notifications.
Schedule::command('queue:work --queue=admin --stop-when-empty --timeout=3600')
    ->everyFifteenSeconds()
    ->withoutOverlapping(70)
    ->runInBackground();
