<?php

use Illuminate\Console\Scheduling\Schedule;

it('runs maintenance once across replicas without overlapping', function (string $command, string $expression, int $expiresAt) {
    $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => str_contains($event->command ?? '', $command));

    expect($event->expression)->toBe($expression);
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBe($expiresAt);
})->with([
    ['store:sweep', '0 * * * *', 10],
    ['catalog:prune-imports', '0 0 * * *', 60],
]);
