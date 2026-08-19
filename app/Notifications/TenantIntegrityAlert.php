<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Platform-level alert raised when the tenant integrity audit finds detached
 * tenants (central record points at a missing database) or tenants without
 * domains. Sent by tracepharma:tenant-health-alert.
 */
class TenantIntegrityAlert extends Notification
{
    use Queueable;

    /**
     * @param  array{healthy: bool, detached_tenants: list<array{id: string, name: string, db_name: string, domains: list<string>}>, tenants_without_domains: list<array{id: string, name: string}>, orphan_databases: list<string>}  $report
     */
    public function __construct(private readonly array $report) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $env = (string) config('app.env');
        $detached = $this->report['detached_tenants'];
        $withoutDomains = $this->report['tenants_without_domains'];

        $mail = (new MailMessage)
            ->error()
            ->subject("[TracePharma:{$env}] Tenant integrity issues detected")
            ->line('The scheduled tenant integrity audit found issues that need attention.');

        if ($detached !== []) {
            $mail->line('Detached tenants (central record points at a missing database):');

            foreach ($detached as $entry) {
                $mail->line(sprintf('• %s (%s) — db=%s domains=[%s]', $entry['name'], $entry['id'], $entry['db_name'], implode(', ', $entry['domains'])));
            }
        }

        if ($withoutDomains !== []) {
            $mail->line('Tenants without domains:');

            foreach ($withoutDomains as $entry) {
                $mail->line(sprintf('• %s (%s)', $entry['name'], $entry['id']));
            }
        }

        return $mail
            ->line('Run `php artisan tracepharma:tenant-health-alert` after repairing domain or database records.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'detached_tenants' => $this->report['detached_tenants'],
            'tenants_without_domains' => $this->report['tenants_without_domains'],
        ];
    }
}
