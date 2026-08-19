<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;
use Stringable;

/**
 * EPC Class / IDPAT URI used in quantityList / childQuantityList.
 */
final readonly class EpcClassUri implements Stringable
{
    private function __construct(
        private string $uri,
    ) {}

    /**
     * Accept Pure Identity SGTIN/SSCC, SGTIN IDPAT, or LGTIN class URIs used in TracePharma.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $uri): self
    {
        $uri = trim($uri);

        if ($uri === '') {
            throw new InvalidArgumentException('EPC class URI is required.');
        }

        if (preg_match('/^urn:epc:id:sgtin:/i', $uri) === 1) {
            return new self(SgtinUri::fromUrn($uri)->toString());
        }

        if (preg_match('/^urn:epc:id:sscc:/i', $uri) === 1) {
            return new self(SsccUri::fromUrn($uri)->toString());
        }

        // urn:epc:idpat:sgtin:{companyPrefix}.{indicatorItemRef}.*
        if (preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/i', $uri, $matches) === 1) {
            return new self('urn:epc:idpat:sgtin:'.$matches[1].'.'.$matches[2].'.*');
        }

        // urn:epc:class:lgtin:{companyPrefix}.{itemRef}.{lot}
        if (preg_match('/^urn:epc:class:lgtin:(\d+)\.([0-9A-Za-z]+)\.(.+)$/i', $uri, $matches) === 1) {
            return new self(
                'urn:epc:class:lgtin:'.$matches[1].'.'.$matches[2].'.'.$matches[3],
            );
        }

        throw new InvalidArgumentException('Invalid EPC class / IDPAT URI.');
    }

    public function toString(): string
    {
        return $this->uri;
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}
