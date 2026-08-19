<?php

namespace App\Support\Epcis;

use App\Actions\Epcis\PurgeTestEpcisDocuments;
use App\Services\Integrations\InboundEpcisReceiver;

/**
 * Pure predicates identifying EPCIS documents and inbound connections that were
 * created by automated tests and leaked into a tenant database (never real
 * partner traffic). Used by {@see PurgeTestEpcisDocuments}
 * to decide what is safe to hard-delete.
 */
final class TestEpcisArtifactMatcher
{
    /**
     * Exact (case-insensitive) filenames only ever produced by test fixtures.
     */
    private const EXACT_TEST_FILENAMES = [
        'webhook-test.xml',
    ];

    /**
     * Exact (case-insensitive) inbound connection names only ever created by
     * automated tests (verified against test suite usage).
     */
    private const EXACT_TEST_CONNECTION_NAMES = [
        'webhook test',
        'cardinal https',
    ];

    /**
     * Case-insensitive substrings that only appear in test-created connection names.
     */
    private const TEST_CONNECTION_NAME_SUBSTRINGS = [
        'hub test',
        'webhook test',
    ];

    /**
     * Whether an EPCIS document's original filename is a known test-generated artifact.
     *
     * Matches:
     * - exact `webhook-test.xml`
     * - `inbound-` followed by exactly 14 digits + `.xml` (the fallback name
     *   {@see InboundEpcisReceiver} synthesizes
     *   when a webhook posts without a filename)
     * - filenames containing `-resource-test-` (Filament resource test fixtures)
     *
     * Deliberately does NOT match real partner filenames (e.g. `ou_xttrium_*`
     * Systech exports) or ad hoc manually-uploaded demo files.
     */
    public function isTestDocumentFilename(string $filename): bool
    {
        $name = trim($filename);

        if ($name === '') {
            return false;
        }

        $lower = strtolower($name);

        if (in_array($lower, self::EXACT_TEST_FILENAMES, true)) {
            return true;
        }

        if (str_contains($lower, '-resource-test-')) {
            return true;
        }

        return preg_match('/^inbound-\d{14}\.xml$/i', $name) === 1;
    }

    /**
     * Whether an inbound connection name is a known test-generated artifact.
     *
     * Matches exact `Webhook Test` / `Cardinal HTTPS`, and any name containing
     * `Hub Test` or `Webhook Test` (covers `Systech Hub Test`, `Webhook Test 2`,
     * etc. created across the integration test suite).
     */
    public function isTestInboundConnectionName(string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        $lower = strtolower($trimmed);

        if (in_array($lower, self::EXACT_TEST_CONNECTION_NAMES, true)) {
            return true;
        }

        foreach (self::TEST_CONNECTION_NAME_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
