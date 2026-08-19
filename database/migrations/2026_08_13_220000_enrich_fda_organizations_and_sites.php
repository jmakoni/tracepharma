<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_organizations', function (Blueprint $table): void {
            $this->addString($table, 'fda_organizations', 'name', after: 'original_name');
            $this->addString($table, 'fda_organizations', 'doing_business_as', after: 'canonical_name');
            if (! Schema::hasColumn('fda_organizations', 'partner_type')) {
                $table->string('partner_type', 32)->nullable()->after('doing_business_as')->index();
            }
            if (! Schema::hasColumn('fda_organizations', 'gln')) {
                $table->char('gln', 13)->nullable()->unique()->after('duns_number');
            }
            if (! Schema::hasColumn('fda_organizations', 'sgln')) {
                $table->string('sgln', 64)->nullable()->after('gln');
            }
            $this->addString($table, 'fda_organizations', 'telephone', 50);
            $this->addString($table, 'fda_organizations', 'email');
            $this->addString($table, 'fda_organizations', 'fax', 50);
            $this->addString($table, 'fda_organizations', 'website');
            if (! Schema::hasColumn('fda_organizations', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('fda_organizations', 'logo')) {
                $table->string('logo', 2048)->nullable();
            }
            $this->addString($table, 'fda_organizations', 'street_address');
            $this->addString($table, 'fda_organizations', 'street_address_2');
            $this->addString($table, 'fda_organizations', 'city', 100);
            $this->addString($table, 'fda_organizations', 'state_province', 64);
            $this->addString($table, 'fda_organizations', 'postal_code', 20);
            if (! Schema::hasColumn('fda_organizations', 'country_code')) {
                $table->char('country_code', 2)->nullable()->default('US');
            }
            if (! Schema::hasColumn('fda_organizations', 'full_address')) {
                $table->text('full_address')->nullable();
            }
            $this->addString($table, 'fda_organizations', 'timezone', 64);
            $this->addDecimal($table, 'fda_organizations', 'latitude', 10, 7);
            $this->addDecimal($table, 'fda_organizations', 'longitude', 10, 7);
            $this->addDecimal($table, 'fda_organizations', 'altitude', 8, 2);
            if (! Schema::hasColumn('fda_organizations', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        DB::table('fda_organizations')->whereNull('name')->update([
            'name' => DB::raw('original_name'),
        ]);

        Schema::table('fda_establishments', function (Blueprint $table): void {
            $this->addString($table, 'fda_establishments', 'name', after: 'firm_name');
            if (! Schema::hasColumn('fda_establishments', 'code')) {
                $table->string('code')->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_establishments', 'gln')) {
                $table->char('gln', 13)->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_establishments', 'sgln')) {
                $table->string('sgln', 64)->nullable();
            }
            $this->addString($table, 'fda_establishments', 'street_address_2');
            $this->addString($table, 'fda_establishments', 'timezone', 64);
            $this->addDecimal($table, 'fda_establishments', 'latitude', 10, 7);
            $this->addDecimal($table, 'fda_establishments', 'longitude', 10, 7);
            $this->addDecimal($table, 'fda_establishments', 'altitude', 8, 2);
            if (! Schema::hasColumn('fda_establishments', 'is_headquarters')) {
                $table->boolean('is_headquarters')->default(false);
            }
            if (! Schema::hasColumn('fda_establishments', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        DB::table('fda_establishments')->whereNull('name')->update([
            'name' => DB::raw('firm_name'),
        ]);

        Schema::table('fda_wdd_facilities', function (Blueprint $table): void {
            $this->addString($table, 'fda_wdd_facilities', 'name', after: 'facility_name');
            if (! Schema::hasColumn('fda_wdd_facilities', 'code')) {
                $table->string('code')->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_wdd_facilities', 'gln')) {
                $table->char('gln', 13)->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_wdd_facilities', 'sgln')) {
                $table->string('sgln', 64)->nullable();
            }
            $this->addString($table, 'fda_wdd_facilities', 'street_address_2');
            $this->addString($table, 'fda_wdd_facilities', 'timezone', 64);
            $this->addDecimal($table, 'fda_wdd_facilities', 'latitude', 10, 7);
            $this->addDecimal($table, 'fda_wdd_facilities', 'longitude', 10, 7);
            $this->addDecimal($table, 'fda_wdd_facilities', 'altitude', 8, 2);
            if (! Schema::hasColumn('fda_wdd_facilities', 'is_headquarters')) {
                $table->boolean('is_headquarters')->default(false);
            }
            if (! Schema::hasColumn('fda_wdd_facilities', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        DB::table('fda_wdd_facilities')->whereNull('name')->update([
            'name' => DB::raw('facility_name'),
        ]);

        Schema::table('fda_wdd_licenses', function (Blueprint $table): void {
            $this->addString($table, 'fda_wdd_licenses', 'contact_person');
            $this->addString($table, 'fda_wdd_licenses', 'contact_email');
        });
    }

    public function down(): void
    {
        $this->dropColumns('fda_wdd_licenses', ['contact_person', 'contact_email']);
        $this->dropColumns('fda_wdd_facilities', [
            'name', 'code', 'gln', 'sgln', 'street_address_2', 'timezone',
            'latitude', 'longitude', 'altitude', 'is_headquarters', 'is_active',
        ]);
        $this->dropColumns('fda_establishments', [
            'name', 'code', 'gln', 'sgln', 'street_address_2', 'timezone',
            'latitude', 'longitude', 'altitude', 'is_headquarters', 'is_active',
        ]);
        $this->dropColumns('fda_organizations', [
            'name', 'doing_business_as', 'partner_type', 'gln', 'sgln',
            'telephone', 'email', 'fax', 'website', 'description', 'logo',
            'street_address', 'street_address_2', 'city', 'state_province',
            'postal_code', 'country_code', 'full_address', 'timezone',
            'latitude', 'longitude', 'altitude', 'is_active',
        ]);
    }

    private function addString(Blueprint $table, string $tableName, string $column, int $length = 255, ?string $after = null): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        $definition = $table->string($column, $length)->nullable();

        if ($after !== null && Schema::hasColumn($tableName, $after)) {
            $definition->after($after);
        }
    }

    private function addDecimal(Blueprint $table, string $tableName, string $column, int $precision, int $scale): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        $table->decimal($column, $precision, $scale)->nullable();
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }
};
