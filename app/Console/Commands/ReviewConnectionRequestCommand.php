<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Integrations\ReviewConnectionApprovalRequest;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use Illuminate\Console\Command;
use RuntimeException;

class ReviewConnectionRequestCommand extends Command
{
    protected $signature = 'connections:review
        {request : Central connection request id}
        {--approve : Approve the request}
        {--reject : Reject the request (requires --note)}
        {--note= : Review note (required for --reject)}
        {--admin= : Admin id recorded as reviewer (defaults to the first admin)}';

    protected $description = 'Approve or reject a pending connection request from the central queue.';

    public function handle(ReviewConnectionApprovalRequest $review): int
    {
        $request = ConnectionApprovalRequest::query()->find($this->argument('request'));

        if (! $request instanceof ConnectionApprovalRequest) {
            $this->error("Connection request [{$this->argument('request')}] not found.");

            return self::FAILURE;
        }

        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');

        if ($approve === $reject) {
            $this->error('Pass exactly one of --approve or --reject.');

            return self::FAILURE;
        }

        $admin = $this->resolveReviewer();

        if (! $admin instanceof Admin) {
            $this->error('No admin found to record as reviewer. Pass --admin=<id>.');

            return self::FAILURE;
        }

        try {
            if ($approve) {
                $review->approve($request, $admin);
                $this->info("Approved [{$request->connection_name}] for tenant [{$request->tenant_id}].");
            } else {
                $note = trim((string) ($this->option('note') ?? ''));

                if ($note === '') {
                    $this->error('--note is required when rejecting.');

                    return self::FAILURE;
                }

                $review->reject($request, $admin, $note);
                $this->info("Rejected [{$request->connection_name}] for tenant [{$request->tenant_id}].");
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveReviewer(): ?Admin
    {
        $adminId = $this->option('admin');

        if (is_string($adminId) && $adminId !== '') {
            return Admin::query()->find($adminId);
        }

        return Admin::query()->orderBy('id')->first();
    }
}
