<?php

declare(strict_types=1);

namespace App\Actions\Exports;

use App\Enums\DataExportType;
use App\Jobs\Exports\ProcessTrackTraceExportJob;
use App\Models\DataExport;
use App\Models\User;
use App\Services\Exports\TrackTraceExportQuery;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class QueueTrackTraceExport
{
    public function __construct(
        private readonly TrackTraceExportQuery $exportQuery,
    ) {}

    /**
     * @param  array{document_id?: int|null, rules?: list<array<string, mixed>>|null, notify_email?: string|null}  $payload
     */
    public function handle(User $user, array $payload): DataExport
    {
        $validated = $this->validatePayload($payload);
        $filters = $this->resolveFilters($validated);

        $export = DataExport::query()->create([
            'type' => DataExportType::TrackAndTrace,
            'requested_by_user_id' => (int) $user->getKey(),
            'filters' => $filters,
            'notify_email' => $validated['notify_email'] ?? null,
        ]);

        try {
            $this->exportQuery->assertDocumentReady($export, $user);
            $rowCount = $this->exportQuery->countForExport($export, $user);
            $this->exportQuery->assertExportableRowCount($rowCount);
        } catch (DomainException|InvalidArgumentException $exception) {
            $export->markFailed($exception->getMessage());

            throw ValidationException::withMessages([
                isset($filters['document_id']) ? 'document_id' : 'rules' => $exception->getMessage(),
            ]);
        }

        ProcessTrackTraceExportJob::dispatch(
            (string) tenant('id'),
            (string) $export->getKey(),
        )->onQueue((string) config('tracepharma.exports.queue', 'default'));

        return $export;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{document_id?: int, rules?: list<array<string, mixed>>, notify_email?: string|null}
     */
    private function validatePayload(array $payload): array
    {
        $validator = Validator::make($payload, [
            'document_id' => ['nullable', 'integer', 'min:1', 'required_without:rules'],
            'rules' => ['nullable', 'array', 'required_without:document_id'],
            'rules.*.field' => ['required_with:rules', 'string'],
            'rules.*.operator' => ['required_with:rules', 'string'],
            'rules.*.value' => ['nullable'],
            'rules.*.value_to' => ['nullable'],
            'rules.*.boolean' => ['nullable', 'in:and,or'],
            'notify_email' => ['nullable', 'email', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $hasDocument = filled($payload['document_id'] ?? null);
            $hasRules = is_array($payload['rules'] ?? null) && $payload['rules'] !== [];

            if ($hasDocument && $hasRules) {
                $validator->errors()->add('document_id', 'Provide either document_id or rules, not both.');
                $validator->errors()->add('rules', 'Provide either document_id or rules, not both.');
            }

            if (! $hasDocument && ! $hasRules) {
                $validator->errors()->add('document_id', 'Provide document_id or rules.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array{document_id?: int, rules?: list<array<string, mixed>>, notify_email?: string|null} */
        return $validator->validated();
    }

    /**
     * @param  array{document_id?: int, rules?: list<array<string, mixed>>, notify_email?: string|null}  $validated
     * @return array{document_id?: int, rules?: list<array<string, mixed>>}
     */
    private function resolveFilters(array $validated): array
    {
        if (isset($validated['document_id'])) {
            return ['document_id' => (int) $validated['document_id']];
        }

        if (! isset($validated['rules']) || ! is_array($validated['rules'])) {
            throw new InvalidArgumentException('Export rules are required when document_id is omitted.');
        }

        return ['rules' => $validated['rules']];
    }
}
