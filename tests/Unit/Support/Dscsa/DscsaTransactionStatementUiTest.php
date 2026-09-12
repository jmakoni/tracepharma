<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Dscsa;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use App\Support\Dscsa\DscsaTransactionStatementUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DscsaTransactionStatementUiTest extends TestCase
{
    #[Test]
    public function null_document_does_not_apply(): void
    {
        $this->assertFalse(DscsaTransactionStatementUi::applies(null));
    }

    #[Test]
    #[DataProvider('matrix')]
    public function applies_matrix(
        array $attributes,
        bool $expected,
    ): void {
        $document = new EpcisDocument($attributes);

        $this->assertSame(
            $expected,
            DscsaTransactionStatementUi::applies($document),
            'attributes='.json_encode($attributes),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function matrix(): array
    {
        return [
            'inbound partner null kind' => [
                ['direction' => 'inbound', 'authored_kind' => null],
                true,
            ],
            'outbound shipping authored' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => EpcisAuthoredKind::Shipping,
                ],
                true,
            ],
            'outbound transferring authored' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => EpcisAuthoredKind::Transferring,
                ],
                false,
            ],
            'outbound receiving authored' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => EpcisAuthoredKind::Receiving,
                ],
                false,
            ],
            'outbound commissioning authored' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => EpcisAuthoredKind::Commissioning,
                ],
                false,
            ],
            'outbound null kind partner-like' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => null,
                    'notes' => 'Partner ASN',
                    'original_filename' => 'partner-asn.xml',
                ],
                true,
            ],
            'inferred transferring from notes when kind null' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => null,
                    'notes' => 'Generated transferring EPCIS (intracompany custody)',
                    'original_filename' => 'transfer-12.xml',
                ],
                false,
            ],
            'inferred receiving from filename when kind null' => [
                [
                    'direction' => 'outbound',
                    'authored_kind' => null,
                    'notes' => '',
                    'original_filename' => 'receiving-99.xml',
                ],
                false,
            ],
            'blank direction' => [
                ['direction' => null, 'authored_kind' => null],
                false,
            ],
        ];
    }
}
