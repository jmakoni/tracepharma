<?php

namespace Tests\Feature\Tables;

use App\Filament\App\Resources\Exceptions\Pages\ListExceptions;
use App\Filament\App\Resources\Exceptions\Tables\ExceptionsTable;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Tables\ProductsTable;
use App\Filament\App\Resources\ReceivingSessions\Pages\ListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\Tables\ReceivingSessionsTable;
use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
use App\Filament\App\Resources\TradingPartners\Tables\TradingPartnersTable;
use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Filament\App\Resources\Users\Tables\UsersTable;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Zvizvi\FilamentColumnFilters\FilamentColumnFilters;
use Zvizvi\FilamentColumnFilters\Filters\DateColumnFilter;
use Zvizvi\FilamentColumnFilters\Filters\SearchColumnFilter;
use Zvizvi\FilamentColumnFilters\Filters\SelectColumnFilter;

class AppColumnFiltersTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string, 2: list<string>}>
     */
    public static function selectColumnCases(): array
    {
        return [
            'users' => [UsersTable::class, ListUsers::class, ['name', 'email', 'is_active', 'roles.name']],
            'exceptions' => [ExceptionsTable::class, ListExceptions::class, ['title', 'type.name', 'severity', 'status']],
            'trading_partners' => [TradingPartnersTable::class, ListTradingPartners::class, ['name', 'gln', 'partner_type', 'is_active']],
            'products' => [ProductsTable::class, ListProducts::class, ['name', 'ndc', 'is_active']],
            'receiving_sessions' => [ReceivingSessionsTable::class, ListReceivingSessions::class, ['session_kind', 'site.name', 'status']],
        ];
    }

    /**
     * @param  class-string  $tableClass
     * @param  class-string  $pageClass
     * @param  list<string>  $columnNames
     */
    #[Test]
    #[DataProvider('selectColumnCases')]
    public function app_list_column_filters_are_select_not_search(
        string $tableClass,
        string $pageClass,
        array $columnNames,
    ): void {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $table = $tableClass::configure(Table::make(new $pageClass));

        foreach ($columnNames as $columnName) {
            $column = $table->getColumn($columnName);
            $this->assertNotNull($column, $columnName);
            $filter = FilamentColumnFilters::getColumnFilter($column);
            $this->assertInstanceOf(SelectColumnFilter::class, $filter, $columnName);
            $this->assertNotInstanceOf(SearchColumnFilter::class, $filter, $columnName);
        }
    }

    #[Test]
    public function users_and_exceptions_date_columns_use_date_filters(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $users = UsersTable::configure(Table::make(new ListUsers));
        $exceptions = ExceptionsTable::configure(Table::make(new ListExceptions));

        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($users->getColumn('created_at')),
        );
        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($exceptions->getColumn('created_at')),
        );
        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($exceptions->getColumn('due_at')),
        );
    }
}
