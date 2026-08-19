<?php

namespace Tests\Feature\Epcis;

use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FindRecallSelectAllTest extends TestCase
{
    #[Test]
    public function select_all_checkbox_selects_displayed_rows_only_not_full_id_set(): void
    {
        $page = $this->pageWithPayload();

        $page->toggleSelectAllEpcs(true);

        $this->assertSame([101], $page->selectedEpcIds);
    }

    #[Test]
    public function select_all_matching_epcs_uses_full_id_set(): void
    {
        $page = $this->pageWithPayload();

        $page->selectAllMatchingEpcs();

        $this->assertSame([101, 102], $page->selectedEpcIds);
    }

    #[Test]
    public function select_all_matching_action_is_available_when_more_matches_exist_than_displayed_rows(): void
    {
        $page = $this->pageWithPayload();

        $this->assertTrue($page->canSelectAllMatchingEpcs());
    }

    #[Test]
    public function select_all_matching_action_is_hidden_when_every_match_is_displayed(): void
    {
        $page = new ListEpcisDocuments;
        $page->schemaSearchPayload = [
            'type' => 'epcs',
            'total' => 1,
            'truncated' => false,
            'ids' => [101],
            'rows' => [
                ['id' => 101, 'gtin14' => '00301162001162', 'serial_number' => 'A'],
            ],
        ];

        $this->assertFalse($page->canSelectAllMatchingEpcs());
    }

    #[Test]
    public function select_all_matching_action_is_hidden_when_results_are_truncated(): void
    {
        $page = new ListEpcisDocuments;
        $page->schemaSearchPayload = [
            'type' => 'epcs',
            'total' => 1500,
            'truncated' => true,
            'ids' => array_map(static fn (int $id): int => $id, range(1, 1000)),
            'rows' => [
                ['id' => 101, 'gtin14' => '00301162001162', 'serial_number' => 'A'],
            ],
        ];

        $this->assertFalse($page->canSelectAllMatchingEpcs());
        $this->assertTrue($page->isFindRecallResultTruncated());
    }

    private function pageWithPayload(): ListEpcisDocuments
    {
        $page = new ListEpcisDocuments;
        $page->schemaSearchPayload = [
            'type' => 'epcs',
            'total' => 2,
            'truncated' => false,
            'ids' => [101, 102],
            'rows' => [
                ['id' => 101, 'gtin14' => '00301162001162', 'serial_number' => 'A'],
            ],
        ];

        return $page;
    }
}
