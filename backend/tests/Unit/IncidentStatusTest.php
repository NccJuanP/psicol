<?php

use App\Domain\Incidents\Enums\IncidentStatus;

const ALLOWED_TRANSITIONS = [
    'open>in_progress', 'open>cancelled',
    'in_progress>on_hold', 'in_progress>resolved', 'in_progress>cancelled',
    'on_hold>in_progress', 'on_hold>cancelled',
    'resolved>closed', 'resolved>in_progress',
];

dataset('every status pair', function () {
    foreach (IncidentStatus::cases() as $from) {
        foreach (IncidentStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});

it('allows only the documented transitions', function (IncidentStatus $from, IncidentStatus $to) {
    expect($from->canTransitionTo($to))->toBe(in_array("{$from->value}>{$to->value}", ALLOWED_TRANSITIONS, true));
})->with('every status pair');

it('treats closed and cancelled as terminal and the first three as active', function () {
    expect(array_values(array_map(fn ($s) => $s->value, array_filter(IncidentStatus::cases(), fn ($s) => $s->isTerminal()))))
        ->toEqualCanonicalizing(['closed', 'cancelled'])
        ->and(array_map(fn ($s) => $s->value, IncidentStatus::active()))
        ->toBe(['open', 'in_progress', 'on_hold']);
});
