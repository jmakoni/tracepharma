<?php

namespace App\Exceptions;

use App\Models\Epcis\EpcisDocument;
use DomainException;

final class DuplicateEpcisUploadException extends DomainException
{
    public function __construct(
        public readonly EpcisDocument $existing,
        string $message = 'An EPCIS document with this file hash already exists.',
    ) {
        parent::__construct($message);
    }
}
