<?php

use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Services\DueDatePolicy;
use Carbon\CarbonImmutable;

it('computes the due date from the priority SLA', function (Priority $priority, int $days) {
    $from = CarbonImmutable::parse('2026-10-31T15:00:00Z');

    expect(DueDatePolicy::dueAt($priority, $from)->toIso8601ZuluString())
        ->toBe($from->addDays($days)->toIso8601ZuluString());
})->with([
    'critical' => [Priority::Critical, 1],
    'high' => [Priority::High, 3],
    'medium' => [Priority::Medium, 5],
    'low' => [Priority::Low, 10],
]);

it('reads the SLA from config', function () {
    config(['incidents.sla_days.low' => 20]);

    expect(Priority::Low->slaDays())->toBe(20);
});
