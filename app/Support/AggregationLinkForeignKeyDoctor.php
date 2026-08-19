<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AggregationLinkForeignKeyDoctor
{
    public const LAST_AUDIT_CACHE_KEY = 'aggregation_link_fk_doctor:last_audit';

    /**
     * @return array{constraint_name: string, delete_rule: string}|null
     */
    public function establishedByEventForeignKey(): ?array
    {
        if (! Schema::hasTable('aggregation_links') || ! Schema::hasColumn('aggregation_links', 'established_by_event_id')) {
            return null;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT rc.CONSTRAINT_NAME AS constraint_name, rc.DELETE_RULE AS delete_rule
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                 ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = ?
               AND kcu.TABLE_NAME = ?
               AND kcu.COLUMN_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, 'aggregation_links', 'established_by_event_id'],
        );

        if ($row === null) {
            return null;
        }

        return [
            'constraint_name' => (string) $row->constraint_name,
            'delete_rule' => (string) $row->delete_rule,
        ];
    }

    public function hasCascadeDeleteOnEstablishedByEvent(): bool
    {
        $foreignKey = $this->establishedByEventForeignKey();

        return $foreignKey !== null && strtoupper($foreignKey['delete_rule']) === 'CASCADE';
    }

    /**
     * @return array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}|null
     */
    public function inspectTenant(Tenant $tenant): ?array
    {
        return $tenant->run(function () use ($tenant): ?array {
            $foreignKey = $this->establishedByEventForeignKey();

            if ($foreignKey === null || strtoupper($foreignKey['delete_rule']) !== 'CASCADE') {
                return null;
            }

            return [
                'tenant_id' => (string) $tenant->id,
                'tenant_name' => (string) $tenant->name,
                'constraint_name' => $foreignKey['constraint_name'],
                'delete_rule' => $foreignKey['delete_rule'],
            ];
        });
    }

    /**
     * @param  iterable<int, Tenant>  $tenants
     * @return list<array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}>
     */
    public function inspectTenants(iterable $tenants): array
    {
        $issues = [];

        foreach ($tenants as $tenant) {
            $issue = $this->inspectTenant($tenant);

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
