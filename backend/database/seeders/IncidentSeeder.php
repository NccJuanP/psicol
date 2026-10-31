<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Enums\Priority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bulk insert in batches of 1,000 rows (never factory()->create() row by row), with data that respects
 * the domain rules: whoever is past "open" has an assignee, resolved/closed carry their timestamps,
 * ~20 % of active cases are overdue, and every incident gets its "created" activity.
 */
class IncidentSeeder extends Seeder
{
    private const BATCH = 1000;

    private const STATUS_WEIGHTS = ['open' => 25, 'in_progress' => 25, 'on_hold' => 10, 'resolved' => 15, 'closed' => 20, 'cancelled' => 5];

    private const PRIORITY_WEIGHTS = ['low' => 30, 'medium' => 40, 'high' => 20, 'critical' => 10];

    private const SUBJECTS = [
        'La impresora', 'El portátil', 'El correo corporativo', 'La VPN', 'El monitor', 'El teléfono IP',
        'El servidor de archivos', 'El sistema de nómina', 'El acceso a SAP', 'La red wifi', 'El proyector',
        'La licencia de Office', 'La cuenta de usuario', 'El escáner', 'La base de datos de clientes',
    ];

    private const PROBLEMS = [
        'no enciende', 'no responde', 'muestra un error de conexión', 'funciona muy lento', 'no sincroniza',
        'se reinicia sola', 'no imprime', 'pierde la conexión', 'rechaza la contraseña', 'se bloquea al abrir',
    ];

    private const REQUESTS = [
        'Instalación de software en', 'Acceso a carpeta compartida para', 'Cambio de contraseña de',
        'Configuración de equipo nuevo para', 'Reemplazo de periférico en', 'Alta de usuario en',
    ];

    private const PLACES = [
        'contabilidad', 'el piso 3', 'recepción', 'la sede norte', 'bodega', 'talento humano',
        'la sala de juntas', 'gerencia', 'mercadeo', 'el área jurídica',
    ];

    private const DETAILS = [
        'El problema empezó esta mañana y afecta a varias personas del área.',
        'Ya se intentó reiniciar el equipo sin resultado.',
        'Es urgente porque bloquea el cierre del mes.',
        'Ocurre de forma intermitente, sobre todo en la tarde.',
        'Adjunto el mensaje de error que aparece en pantalla.',
        'El usuario confirma que ayer funcionaba con normalidad.',
        'Se necesita para el ingreso de personal nuevo la próxima semana.',
    ];

    public function run(?int $count = null): void
    {
        $count ??= (int) config('incidents.seed_count');
        $requesters = User::role(Role::Requester->value)->pluck('id')->all();
        $handlers = User::query()->assignable()->pluck('id')->all();

        if (! $requesters || ! $handlers) {
            throw new RuntimeException('IncidentSeeder needs users: run UserSeeder first.');
        }

        $now = CarbonImmutable::now('UTC');
        $lastIdBefore = (int) DB::table('incidents')->max('id');

        for ($done = 0; $done < $count; $done += self::BATCH) {
            $rows = [];

            for ($i = 0, $size = min(self::BATCH, $count - $done); $i < $size; $i++) {
                $rows[] = $this->row($now, $requesters, $handlers);
            }

            DB::table('incidents')->insert($rows);
        }

        // One set-based statement instead of 100,000 single inserts.
        DB::statement(
            "INSERT INTO incident_activities (incident_id, user_id, action, changes, created_at)
             SELECT id, created_by, 'created', '{}', created_at FROM incidents WHERE id > ?",
            [$lastIdBefore],
        );
    }

    /**
     * @param  list<int>  $requesters
     * @param  list<int>  $handlers
     * @return array<string, mixed>
     */
    private function row(CarbonImmutable $now, array $requesters, array $handlers): array
    {
        $status = IncidentStatus::from(self::pick(self::STATUS_WEIGHTS));
        $priority = Priority::fromCode(self::pick(self::PRIORITY_WEIGHTS));
        $sla = $priority->slaDays();
        $resolvedAt = $closedAt = null;

        if ($status->isActive()) {
            // Active cases are recent; one in five is already past its due date.
            $dueAt = mt_rand(1, 100) <= 20
                ? $now->subMinutes(mt_rand(60, 30 * 24 * 60))
                : $now->addMinutes(mt_rand(60, $sla * 24 * 60));
            $createdAt = $dueAt->subDays($sla);
        } else {
            $createdAt = $now->subMinutes(mt_rand(3 * 24 * 60, 365 * 24 * 60));
            $dueAt = $createdAt->addDays($sla);
        }

        if ($status === IncidentStatus::Resolved || $status === IncidentStatus::Closed) {
            $resolvedAt = min($now->subHour(), $createdAt->addMinutes(mt_rand(30, 2 * $sla * 24 * 60)));
        }

        if ($status === IncidentStatus::Closed) {
            $closedAt = min($now, $resolvedAt->addMinutes(mt_rand(60, 3 * 24 * 60)));
        }

        $needsAssignee = ! in_array($status, [IncidentStatus::Open, IncidentStatus::Cancelled], true);
        $isRequest = mt_rand(1, 100) <= 20;
        $place = self::PLACES[array_rand(self::PLACES)];
        $subject = self::SUBJECTS[array_rand(self::SUBJECTS)];

        return [
            'type' => $isRequest ? 'request' : 'incident',
            'title' => $isRequest
                ? self::REQUESTS[array_rand(self::REQUESTS)].' '.$place
                : $subject.' '.self::PROBLEMS[array_rand(self::PROBLEMS)].' en '.$place,
            'description' => self::DETAILS[array_rand(self::DETAILS)].' '.self::DETAILS[array_rand(self::DETAILS)],
            'priority' => $priority->value,
            'status' => $status->value,
            // Mostly requesters report; agents and admins also open cases.
            'created_by' => mt_rand(1, 100) <= 80 ? $requesters[array_rand($requesters)] : $handlers[array_rand($handlers)],
            'assigned_to' => $needsAssignee || mt_rand(0, 1) ? $handlers[array_rand($handlers)] : null,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'resolved_at' => $resolvedAt?->format('Y-m-d H:i:s'),
            'closed_at' => $closedAt?->format('Y-m-d H:i:s'),
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => ($closedAt ?? $resolvedAt ?? $createdAt)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param  array<string, int>  $weights */
    private static function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
