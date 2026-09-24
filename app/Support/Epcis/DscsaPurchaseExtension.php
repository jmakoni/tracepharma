<?php

declare(strict_types=1);

namespace App\Support\Epcis;

final readonly class DscsaPurchaseExtension
{
    /**
     * @param  list<string>  $indirectEpcUris
     */
    public function __construct(
        public ?string $qualifier,
        public ?string $statement,
        public array $indirectEpcUris = [],
        public ?bool $booleanAffirmed = null,
    ) {}

    /**
     * @return array{qualifier: ?string, statement: ?string, indirect_epc_uris: list<string>, boolean_affirmed: ?bool}
     */
    public function toArray(): array
    {
        return [
            'qualifier' => $this->qualifier,
            'statement' => $this->statement,
            'indirect_epc_uris' => array_values($this->indirectEpcUris),
            'boolean_affirmed' => $this->booleanAffirmed,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $uris = $data['indirect_epc_uris'] ?? [];
        if (! is_array($uris)) {
            $uris = [];
        }

        $boolean = $data['boolean_affirmed'] ?? null;

        return new self(
            qualifier: filled($data['qualifier'] ?? null) ? (string) $data['qualifier'] : null,
            statement: filled($data['statement'] ?? null) ? (string) $data['statement'] : null,
            indirectEpcUris: array_values(array_filter(array_map(
                static fn (mixed $uri): ?string => is_string($uri) && trim($uri) !== '' ? trim($uri) : null,
                $uris,
            ))),
            booleanAffirmed: is_bool($boolean) ? $boolean : null,
        );
    }
}
