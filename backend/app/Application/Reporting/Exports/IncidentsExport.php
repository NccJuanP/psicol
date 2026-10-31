<?php

namespace App\Application\Reporting\Exports;

use App\Application\Incidents\Queries\IncidentFilters;
use App\Application\Incidents\Queries\ListIncidentsQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Incidents\Models\Incident;
use Carbon\CarbonImmutable;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** D-17: streams the listing's query in id chunks of 1,000, so memory stays flat at any size. */
final class IncidentsExport
{
    private const HEADER = [
        'Código', 'Tipo', 'Título', 'Prioridad', 'Estado', 'Vencido', 'Solicitante', 'Responsable',
        'Vence (hora Colombia)', 'Creado (hora Colombia)', 'Actualizado (hora Colombia)',
    ];

    private const CONTENT_TYPES = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(private readonly ListIncidentsQuery $query) {}

    public function download(User $user, IncidentFilters $filters, string $format): StreamedResponse
    {
        $zone = config('incidents.timezone');
        $incidents = $this->query->builder($user, $filters)->reorder()->lazyByIdDesc(1000);
        $local = fn (?CarbonImmutable $date) => $date?->setTimezone($zone)->format('Y-m-d H:i');

        return response()->streamDownload(function () use ($incidents, $format, $local) {
            $writer = SimpleExcelWriter::create('php://output', $format)->addHeader(self::HEADER);

            /** @var Incident $incident */
            foreach ($incidents as $incident) {
                $writer->addRow([
                    $incident->code,
                    $incident->type->label(),
                    $incident->title,
                    $incident->priority->label(),
                    $incident->status->label(),
                    $incident->is_overdue ? 'Sí' : 'No',
                    $incident->creator->name,
                    $incident->assignee?->name ?? '',
                    $local($incident->due_at->toImmutable()),
                    $local($incident->created_at->toImmutable()),
                    $local($incident->updated_at->toImmutable()),
                ]);
            }

            $writer->close();
        }, 'incidentes_'.now($zone)->format('Ymd_Hi').".{$format}", ['Content-Type' => self::CONTENT_TYPES[$format]]);
    }
}
