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
    ) {}

    /**
     * @return array{qualifier: ?string, statement: ?string, indirect_epc_uris: list<string>}
     */
    public function toArray(): array
    {
        return [
            'qualifier' => $this->qualifier,
            'statement' => $this->statement,
            'indirect_epc_uris' => array_values($this->indirectEpcUris),
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

        return new self(
            qualifier: filled($data['qualifier'] ?? null) ? (string) $data['qualifier'] : null,
            statement: filled($data['statement'] ?? null) ? (string) $data['statement'] : null,
            indirectEpcUris: array_values(array_filter(array_map(
                static fn (mixed $uri): ?string => is_string($uri) && trim($uri) !== '' ? trim($uri) : null,
                $uris,
            ))),
        );
    }
}
