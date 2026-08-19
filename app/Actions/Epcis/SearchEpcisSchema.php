<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\EpcisQueryFieldRegistry;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Multi-rule search over curated EPCIS schema fields (AND/OR between rules).
 */
final class SearchEpcisSchema
{
    public function __construct(
        private readonly EpcisQueryFieldRegistry $registry = new EpcisQueryFieldRegistry,
    ) {}

    /**
     * @param  list<array{field: string, operator: string, value?: mixed, value_to?: mixed, boolean?: string}>  $rules
     * @return array{type: string, total: int, rows: Collection, ids: list<int>, truncated: bool}
     */
    public function handle(
        string $resultType,
        array $rules,
        int $limit = 1000,
        int $displayLimit = 100,
        ?User $actor = null,
    ): array
    {
        if (! in_array($resultType, ['epcs', 'documents'], true)) {
            throw new InvalidArgumentException('resultType must be epcs or documents.');
        }

        $limit = max(1, min($limit, 1000));
        $displayLimit = max(1, min($displayLimit, $limit));

        $rules = $this->normalizeRules($resultType, $rules);

        if ($rules === []) {
            throw new InvalidArgumentException('At least one complete search rule is required.');
        }

        if ($resultType === 'epcs' && ! $this->hasSelectiveRule($rules)) {
            throw new DomainException(
                'Add a product or shipment identifier (GTIN, lot, SSCC, ASN, or PO).',
            );
        }

        $query = $resultType === 'epcs'
            ? $this->buildEpcQuery($rules)
            : $this->buildDocumentQuery($rules);

        if ($actor instanceof User) {
            $query = $resultType === 'epcs'
                ? SiteAccess::constrainEpcsViaInboundDocuments($query, $actor)
                : SiteAccess::constrainInboundDocuments($query, $actor);
        }

        $idColumn = $resultType === 'epcs' ? 'epcs.id' : 'epcis_documents.id';

        $trueTotal = (clone $query)->reorder()->count($idColumn);

        $ids = (clone $query)
            ->select($idColumn)
            ->reorder()
            ->orderBy($idColumn)
            ->limit($limit)
            ->pluck($idColumn)
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $total = $trueTotal;
        $truncated = $trueTotal > $limit;
        $displayIds = $ids->take($displayLimit)->all();

        $rows = $resultType === 'epcs'
            ? $this->loadEpcs($displayIds)
            : $this->loadDocuments($displayIds);

        $this->logSearch($resultType, $rules, $total);

        return [
            'type' => $resultType,
            'total' => $total,
            'rows' => $rows,
            'ids' => $ids->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<array{field: string, operator: string, value?: mixed, value_to?: mixed, boolean?: string}>  $rules
     * @return list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>
     */
    private function normalizeRules(string $resultType, array $rules): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $fieldKey = trim((string) ($rule['field'] ?? ''));
            $operator = trim((string) ($rule['operator'] ?? ''));

            if ($fieldKey === '' || $operator === '') {
                continue;
            }

            $def = $this->registry->get($fieldKey);
            if ($def === null || ! in_array($resultType, $def['scopes'], true)) {
                throw new InvalidArgumentException("Field [{$fieldKey}] is not allowed for result type [{$resultType}].");
            }

            if (! in_array($operator, $def['operators'], true)) {
                throw new InvalidArgumentException("Operator [{$operator}] is not allowed for field [{$fieldKey}].");
            }

            if (! $this->ruleHasValue($operator, $rule)) {
                continue;
            }

            $value = match (true) {
                in_array($operator, ['is_true', 'is_false', 'is_empty', 'is_not_empty', 'is_today', 'is_yesterday', 'is_this_week', 'is_this_month'], true) => null,
                in_array($operator, ['is_any_of', 'is_not_any_of'], true) => $this->normalizeListValue($def, $rule['value'] ?? null),
                default => $this->normalizeValue($def, $rule['value'] ?? null),
            };

            $boolean = strtolower(trim((string) ($rule['boolean'] ?? 'and')));
            if (! in_array($boolean, ['and', 'or'], true)) {
                $boolean = 'and';
            }

            $entry = [
                'field' => $fieldKey,
                'operator' => $operator,
                'value' => $value,
                'boolean' => $boolean,
                'def' => $def,
            ];

            if (in_array($operator, ['between', 'not_between'], true)) {
                $entry['value_to'] = $this->normalizeValue($def, $rule['value_to'] ?? null);
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array{operator?: string, value?: mixed, value_to?: mixed}  $rule
     */
    private function ruleHasValue(string $operator, array $rule): bool
    {
        if (in_array($operator, [
            'is_empty',
            'is_not_empty',
            'is_today',
            'is_yesterday',
            'is_this_week',
            'is_this_month',
            'is_true',
            'is_false',
        ], true)) {
            return true;
        }

        if (in_array($operator, ['between', 'not_between'], true)) {
            return $this->isPresent($rule['value'] ?? null)
                && $this->isPresent($rule['value_to'] ?? null);
        }

        if (in_array($operator, ['is_any_of', 'is_not_any_of'], true)) {
            $value = $rule['value'] ?? null;

            if (is_array($value)) {
                return $value !== [];
            }

            return $this->isPresent($value);
        }

        return $this->isPresent($rule['value'] ?? null);
    }

    private function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    /**
     * @param  array{type: string, key: string}  $def
     * @return list<mixed>
     */
    private function normalizeListValue(array $def, mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! $this->isPresent($item)) {
                continue;
            }

            $normalized[] = $this->normalizeValue($def, $item);
        }

        return array_values($normalized);
    }

    /**
     * @param  array{type: string, key: string}  $def
     */
    private function normalizeValue(array $def, mixed $value): mixed
    {
        if ($def['type'] === 'bool') {
            return $this->normalizeBool($value);
        }

        if ($def['type'] === 'fk_partner' || $def['type'] === 'numeric') {
            return (int) $value;
        }

        if ($def['type'] === 'gln' || $def['key'] === 'epc.gtin14') {
            return preg_replace('/\D+/', '', (string) $value) ?? '';
        }

        if ($def['type'] === 'date') {
            return $this->normalizeDateCalendarDay($value) ?? '';
        }

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Filament non-native DatePickers dehydrate as Y-m-d H:i:s (often midnight or "now" time).
     * Calendar fields must compare by day, not exact timestamp.
     */
    private function normalizeDateCalendarDay(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->timezone((string) config('app.timezone'))->toDateString();
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        try {
            return Carbon::parse($trimmed, (string) config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, def: array}>  $rules
     */
    private function hasSelectiveRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->registry->isSelective($rule['field'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>  $rules
     * @return Builder<Epc>
     */
    private function buildEpcQuery(array $rules): Builder
    {
        $query = Epc::query()->select('epcs.*');

        $this->applyRulesWithBoolean(
            $query,
            $rules,
            function (Builder $nested, array $rule): void {
                $this->applyEpcRule($nested, $rule);
            },
        );

        return $query;
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>  $rules
     * @return Builder<EpcisDocument>
     */
    private function buildDocumentQuery(array $rules): Builder
    {
        $query = EpcisDocument::query()->select('epcis_documents.*');

        $this->applyRulesWithBoolean(
            $query,
            $rules,
            function (Builder $nested, array $rule): void {
                $this->applyDocumentRule($nested, $rule);
            },
        );

        return $query;
    }

    /**
     * Apply rules with AND/OR joins.
     *
     * Consecutive OR rows form a disjunction group; groups are ANDed:
     * GTIN AND lotA OR lotB → GTIN AND (lotA OR lotB).
     *
     * @param  Builder<Epc>|Builder<EpcisDocument>  $query
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>  $rules
     * @param  callable(Builder, array): void  $apply
     */
    private function applyRulesWithBoolean(Builder $query, array $rules, callable $apply): void
    {
        foreach ($this->groupRulesByBoolean($rules) as $group) {
            if (count($group) === 1) {
                $apply($query, $group[0]);

                continue;
            }

            $query->where(function (Builder $orGroup) use ($group, $apply): void {
                foreach ($group as $index => $rule) {
                    $method = $index === 0 ? 'where' : 'orWhere';

                    $orGroup->{$method}(function (Builder $nested) use ($apply, $rule): void {
                        $apply($nested, $rule);
                    });
                }
            });
        }
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>  $rules
     * @return list<list<array{field: string, operator: string, value: mixed, value_to?: mixed, boolean: string, def: array}>>
     */
    private function groupRulesByBoolean(array $rules): array
    {
        $groups = [];
        $current = [];

        foreach ($rules as $index => $rule) {
            $boolean = $index === 0 ? 'and' : ($rule['boolean'] ?? 'and');

            if ($index === 0 || $boolean === 'and') {
                if ($current !== []) {
                    $groups[] = $current;
                }
                $current = [$rule];

                continue;
            }

            $current[] = $rule;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param  Builder<Epc>  $query
     * @param  array{field: string, operator: string, value: mixed, value_to?: mixed, def: array}  $rule
     */
    private function applyEpcRule(Builder $query, array $rule): void
    {
        $def = $rule['def'];
        $table = $def['table'];

        if ($table === 'epcs') {
            $this->applyPredicate($query, 'epcs.'.$def['column'], $rule);

            return;
        }

        if ($table === 'epc_ilmd') {
            if ($def['key'] === 'epc.gtin14' && ! Schema::hasColumn('epc_ilmd', 'gtin14')) {
                $this->applyPredicate($query, 'epcs.gtin14', $rule);

                return;
            }

            $query->whereHas('ilmd', function (Builder $ilmd) use ($rule, $def): void {
                $this->applyPredicate($ilmd, 'epc_ilmd.'.$def['column'], $rule);
            });

            return;
        }

        if ($table === 'epcis_documents') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def): void {
                if (Schema::hasTable('document_epcs')) {
                    $exists->selectRaw('1')
                        ->from('document_epcs')
                        ->join('epcis_documents', 'epcis_documents.id', '=', 'document_epcs.document_id')
                        ->whereColumn('document_epcs.epc_id', 'epcs.id');

                    if (Schema::hasColumn('epcis_documents', 'ingest_generation')
                        && Schema::hasColumn('document_epcs', 'ingest_generation')) {
                        $exists->whereColumn(
                            'document_epcs.ingest_generation',
                            'epcis_documents.ingest_generation',
                        );
                    }
                } else {
                    $exists->selectRaw('1')
                        ->from('event_epcs')
                        ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                        ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
                        ->whereColumn('event_epcs.epc_id', 'epcs.id');

                    if (Schema::hasColumn('epcis_events', 'ingest_generation')
                        && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                        $exists->whereColumn(
                            'epcis_events.ingest_generation',
                            'epcis_documents.ingest_generation',
                        );
                    }
                }

                $this->applyDocumentShippingPredicate($exists, $rule, $def);
            });

            return;
        }

        if ($table === 'epcis_events') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def): void {
                $exists->selectRaw('1')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->whereColumn('event_epcs.epc_id', 'epcs.id');

                $this->scopeEventToActiveDocumentGeneration($exists);
                $this->applyPredicate($exists, 'epcis_events.'.$def['column'], $rule);
            });

            return;
        }

        if ($table === 'event_biz_transactions') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def): void {
                $exists->selectRaw('1')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->join('event_biz_transactions', 'event_biz_transactions.event_id', '=', 'epcis_events.id')
                    ->whereColumn('event_epcs.epc_id', 'epcs.id');

                $this->scopeEventToActiveDocumentGeneration($exists);
                $this->applyPredicate($exists, 'event_biz_transactions.'.$def['column'], $rule);
            });
        }
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @param  array{field: string, operator: string, value: mixed, value_to?: mixed, def: array}  $rule
     */
    private function applyDocumentRule(Builder $query, array $rule): void
    {
        $def = $rule['def'];
        $table = $def['table'];

        if ($table === 'epcis_documents') {
            $this->applyDocumentShippingPredicate($query, $rule, $def);

            return;
        }

        if ($table === 'epcs' || $table === 'epc_ilmd') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def, $table): void {
                $this->joinDocumentEpcsForDocumentQuery($exists);

                if ($table === 'epc_ilmd') {
                    if ($def['key'] === 'epc.gtin14' && ! Schema::hasColumn('epc_ilmd', 'gtin14')) {
                        $exists->join('epcs', 'epcs.id', '=', 'document_epcs.epc_id');
                        $this->applyPredicate($exists, 'epcs.gtin14', $rule);

                        return;
                    }

                    $exists->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'document_epcs.epc_id');
                    $this->applyPredicate($exists, 'epc_ilmd.'.$def['column'], $rule);

                    return;
                }

                $exists->join('epcs', 'epcs.id', '=', 'document_epcs.epc_id');
                $this->applyPredicate($exists, 'epcs.'.$def['column'], $rule);
            });

            return;
        }

        if ($table === 'epcis_events') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def): void {
                $exists->selectRaw('1')
                    ->from('epcis_events')
                    ->whereColumn('epcis_events.document_id', 'epcis_documents.id');

                if (Schema::hasColumn('epcis_events', 'ingest_generation')
                    && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                    $exists->whereColumn(
                        'epcis_events.ingest_generation',
                        'epcis_documents.ingest_generation',
                    );
                }

                $this->applyPredicate($exists, 'epcis_events.'.$def['column'], $rule);
            });

            return;
        }

        if ($table === 'event_biz_transactions') {
            $query->whereExists(function (QueryBuilder $exists) use ($rule, $def): void {
                $exists->selectRaw('1')
                    ->from('epcis_events')
                    ->join('event_biz_transactions', 'event_biz_transactions.event_id', '=', 'epcis_events.id')
                    ->whereColumn('epcis_events.document_id', 'epcis_documents.id');

                if (Schema::hasColumn('epcis_events', 'ingest_generation')
                    && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                    $exists->whereColumn(
                        'epcis_events.ingest_generation',
                        'epcis_documents.ingest_generation',
                    );
                }

                $this->applyPredicate($exists, 'event_biz_transactions.'.$def['column'], $rule);
            });
        }
    }

    private function scopeEventToActiveDocumentGeneration(QueryBuilder $exists): void
    {
        if (! Schema::hasColumn('epcis_events', 'ingest_generation')
            || ! Schema::hasTable('epcis_documents')
            || ! Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            return;
        }

        $exists->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation');
    }

    /**
     * Seed a document↔EPC EXISTS subquery. Leaves `document_epcs.epc_id` available to join.
     */
    private function joinDocumentEpcsForDocumentQuery(QueryBuilder $exists): void
    {
        if (Schema::hasTable('document_epcs')) {
            $exists->selectRaw('1')
                ->from('document_epcs')
                ->whereColumn('document_epcs.document_id', 'epcis_documents.id');

            if (Schema::hasColumn('epcis_documents', 'ingest_generation')
                && Schema::hasColumn('document_epcs', 'ingest_generation')) {
                $exists->whereColumn(
                    'document_epcs.ingest_generation',
                    'epcis_documents.ingest_generation',
                );
            }

            return;
        }

        $exists->selectRaw('1')
            ->from('event_epcs as document_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'document_epcs.event_id')
            ->whereColumn('epcis_events.document_id', 'epcis_documents.id');

        if (Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $exists->whereColumn(
                'epcis_events.ingest_generation',
                'epcis_documents.ingest_generation',
            );
        }
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query
     * @param  array{operator: string, value: mixed, value_to?: mixed, def?: array{type?: string}}  $rule
     */
    /**
     * @param  array{field: string, operator: string, value: mixed, value_to?: mixed, def: array}  $rule
     * @param  array{key: string, column: string, type?: string}  $def
     */
    private function applyDocumentShippingPredicate(Builder|QueryBuilder $query, array $rule, array $def): void
    {
        if (($def['key'] ?? '') === 'doc.asn_or_po') {
            $query->where(function (Builder|QueryBuilder $group) use ($rule): void {
                $this->applyPredicate($group, 'epcis_documents.asn_number', $rule);
                $group->orWhere(function (Builder|QueryBuilder $or) use ($rule): void {
                    $this->applyPredicate($or, 'epcis_documents.customer_po', $rule);
                });
            });

            return;
        }

        $this->applyPredicate($query, 'epcis_documents.'.$def['column'], $rule);
    }

    private function applyPredicate(Builder|QueryBuilder $query, string $qualifiedColumn, array $rule): void
    {
        $operator = $rule['operator'];
        $value = $rule['value'] ?? null;
        $type = $rule['def']['type'] ?? 'string';
        $now = Carbon::now(config('app.timezone'));

        match ($operator) {
            'eq' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '=', (string) $value)
                : $query->where($qualifiedColumn, '=', $value),
            'neq' => $type === 'date'
                ? $query->where(function (Builder|QueryBuilder $inner) use ($qualifiedColumn, $value): void {
                    $inner->whereNull($qualifiedColumn)
                        ->orWhereDate($qualifiedColumn, '!=', (string) $value);
                })
                : $query->where($qualifiedColumn, '!=', $value),
            'starts_with' => $query->where($qualifiedColumn, 'like', $this->escapeLike((string) $value).'%'),
            'ends_with' => $query->where($qualifiedColumn, 'like', '%'.$this->escapeLike((string) $value)),
            'contains' => $query->where($qualifiedColumn, 'like', '%'.$this->escapeLike((string) $value).'%'),
            'not_contains' => $query->where(function (Builder|QueryBuilder $inner) use ($qualifiedColumn, $value): void {
                $pattern = '%'.$this->escapeLike((string) $value).'%';
                $inner->whereNull($qualifiedColumn)
                    ->orWhere($qualifiedColumn, 'not like', $pattern);
            }),
            'gt' => $query->where($qualifiedColumn, '>', $value),
            'gte' => $query->where($qualifiedColumn, '>=', $value),
            'lt' => $query->where($qualifiedColumn, '<', $value),
            'lte' => $query->where($qualifiedColumn, '<=', $value),
            'before' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '<', (string) $value)
                : $query->where($qualifiedColumn, '<', $value),
            'before_or_equal' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '<=', (string) $value)
                : $query->where($qualifiedColumn, '<=', $value),
            'after' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '>', (string) $value)
                : $query->where($qualifiedColumn, '>', $value),
            'after_or_equal' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '>=', (string) $value)
                : $query->where($qualifiedColumn, '>=', $value),
            'between' => $type === 'date'
                ? $query->whereDate($qualifiedColumn, '>=', (string) $value)
                    ->whereDate($qualifiedColumn, '<=', (string) ($rule['value_to'] ?? $value))
                : $query->whereBetween($qualifiedColumn, [$value, $rule['value_to']]),
            'not_between' => $type === 'date'
                ? $query->where(function (Builder|QueryBuilder $inner) use ($qualifiedColumn, $value, $rule): void {
                    $to = (string) ($rule['value_to'] ?? $value);
                    $inner->whereNull($qualifiedColumn)
                        ->orWhereDate($qualifiedColumn, '<', (string) $value)
                        ->orWhereDate($qualifiedColumn, '>', $to);
                })
                : $query->whereNotBetween($qualifiedColumn, [$value, $rule['value_to']]),
            'is_any_of' => $query->whereIn($qualifiedColumn, is_array($value) ? $value : [$value]),
            'is_not_any_of' => $query->whereNotIn($qualifiedColumn, is_array($value) ? $value : [$value]),
            'is_true' => $query->where($qualifiedColumn, '=', true),
            'is_false' => $query->where($qualifiedColumn, '=', false),
            'is_empty' => $this->applyIsEmpty($query, $qualifiedColumn, $type),
            'is_not_empty' => $this->applyIsNotEmpty($query, $qualifiedColumn, $type),
            'is_today' => $query->whereBetween($qualifiedColumn, [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ]),
            'is_yesterday' => $query->whereBetween($qualifiedColumn, [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ]),
            'is_this_week' => $query->whereBetween($qualifiedColumn, [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek(),
            ]),
            'is_this_month' => $query->whereBetween($qualifiedColumn, [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ]),
            default => throw new InvalidArgumentException("Unsupported operator [{$operator}]."),
        };
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query
     */
    private function applyIsEmpty(Builder|QueryBuilder $query, string $qualifiedColumn, string $type): void
    {
        if (in_array($type, ['string', 'gln'], true)) {
            $query->where(function (Builder|QueryBuilder $inner) use ($qualifiedColumn): void {
                $inner->whereNull($qualifiedColumn)
                    ->orWhere($qualifiedColumn, '=', '');
            });

            return;
        }

        $query->whereNull($qualifiedColumn);
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query
     */
    private function applyIsNotEmpty(Builder|QueryBuilder $query, string $qualifiedColumn, string $type): void
    {
        if (in_array($type, ['string', 'gln'], true)) {
            $query->where(function (Builder|QueryBuilder $inner) use ($qualifiedColumn): void {
                $inner->whereNotNull($qualifiedColumn)
                    ->where($qualifiedColumn, '!=', '');
            });

            return;
        }

        $query->whereNotNull($qualifiedColumn);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Epc>
     */
    private function loadEpcs(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Epc::query()
            ->with('ilmd')
            ->whereIn('epcs.id', $ids)
            ->orderBy('epcs.id')
            ->get();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, EpcisDocument>
     */
    private function loadDocuments(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return EpcisDocument::query()
            ->whereIn('epcis_documents.id', $ids)
            ->orderBy('epcis_documents.id')
            ->get();
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed, value_to?: mixed, def: array}>  $rules
     */
    private function logSearch(string $resultType, array $rules, int $hitCount): void
    {
        if (! function_exists('activity')) {
            return;
        }

        $fields = array_values(array_map(static fn (array $r): string => $r['field'], $rules));
        $operators = array_values(array_map(static fn (array $r): string => $r['operator'], $rules));
        $values = array_values(array_map(function (array $r): mixed {
            $value = $this->truncateForLog($r['value'] ?? null);
            if (in_array($r['operator'] ?? null, ['between', 'not_between'], true)) {
                return [
                    'from' => $value,
                    'to' => $this->truncateForLog($r['value_to'] ?? null),
                ];
            }

            return $value;
        }, $rules));

        activity()
            ->withProperties([
                'result_type' => $resultType,
                'fields' => $fields,
                'operators' => $operators,
                'values' => $values,
                'hit_count' => $hitCount,
            ])
            ->log('epcis_schema_search');
    }

    private function truncateForLog(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (strlen($value) <= 64) {
            return $value;
        }

        return substr($value, 0, 61).'...';
    }
}
