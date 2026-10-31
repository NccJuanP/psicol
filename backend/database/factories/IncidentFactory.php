<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\IncidentType;
use App\Domain\Incidents\Enums\Priority;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Writes rows directly (bypasses the aggregate) to set up test states; no activities are created.
 *
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => IncidentType::Incident,
            'title' => 'Falla en '.fake()->words(3, true),
            'description' => fake()->paragraph(),
            'priority' => Priority::Medium,
            'status' => IncidentStatus::Open,
            'created_by' => User::factory(),
            'assigned_to' => null,
            'due_at' => now()->addDays(5),
        ];
    }

    public function status(IncidentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assigned_to' => $user->id]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(['created_by' => $user->id]);
    }
}
