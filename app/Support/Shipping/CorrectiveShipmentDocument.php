<?php

namespace App\Support\Shipping;

use App\Actions\Shipping\GenerateShippingEpcisEvents;
use App\Services\Custody\EpcCustodyGate;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * How an authored shipping document says it corrects an earlier one, and how
 * readers tell a correction apart from the shipment it amends.
 *
 * A corrective shipment is a normal Shipping authored document — trading partners
 * receive the same event shape — so the only thing marking it is the reference to
 * what it corrects: {@see COLUMN} where the column exists, and the notes prose
 * every corrective document has carried since before it did.
 *
 * The distinction matters because a correction authors fresh shipping evidence for
 * stock that already left. Left unmarked, that evidence would authorize the next
 * correction, and the next, each one drifting further from the shipment the
 * operator meant to amend ({@see EpcCustodyGate::hasPriorTenantShipEvidence()}).
 */
final class CorrectiveShipmentDocument
{
    public const COLUMN = 'corrects_epcis_document_id';

    /**
     * Sentence {@see GenerateShippingEpcisEvents} writes into the notes of every
     * corrective document, including those authored before {@see COLUMN} existed.
     */
    public const NOTE_MARKER = 'Corrective shipment.';

    public static function columnExists(): bool
    {
        return Schema::hasColumn('epcis_documents', self::COLUMN);
    }

    /**
     * Narrow a query over `epcis_documents` to documents that are not themselves
     * corrections.
     *
     * NULL notes have to be spelled out: `notes NOT LIKE ...` is NULL for a NULL
     * column, which would drop every document that never carried a note.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $documents
     */
    public static function applyIsNotCorrection(
        EloquentBuilder|QueryBuilder $documents,
        string $alias = 'doc',
    ): void {
        if (self::columnExists()) {
            $documents->whereNull($alias.'.'.self::COLUMN);
        }

        $documents->where(function ($notes) use ($alias): void {
            $notes->whereNull($alias.'.notes')
                ->orWhere($alias.'.notes', 'not like', '%'.self::NOTE_MARKER.'%');
        });
    }
}
