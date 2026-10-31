<?php

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'user_id', 'action', 'changes', 'note'])]
class IncidentActivity extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
