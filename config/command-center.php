<?php

declare(strict_types=1);
use Bityukov\CommandCenter\Sources\ConfigSource;

return [
    /*
     | Auth guard used when a queued run reloads the actor for authorization,
     | and when history resolves a stored user id to a name.
     |
     | Null keeps the previous behaviour: Auth::getProvider() (the application
     | default guard). Set this when Command Center lives on a Filament panel
     | whose authGuard is not the default — for example 'central' or 'admin'.
     | The Filament pages also pass the current panel's auth guard at dispatch,
     | so this is mainly a fallback for non-panel callers.
     */
    'auth_guard' => 'admin',

    /*
     | Absolute path to the PHP binary used to run Artisan commands.
     | Null means auto-detect via Symfony's PhpExecutableFinder.
     */
    'php_binary' => env('COMMAND_CENTER_PHP_BINARY'),

    /*
     | Working directory for spawned processes. Null means the app base path.
     */
    'working_directory' => null,

    /*
     | Default timeout in seconds applied to commands that do not set their own.
     |
     | This defaults to the same value as max_sync_timeout, because a command
     | that does not specify a timeout may still run synchronously, and a
     | synchronous run cannot outlive the HTTP request that started it. Raise it
     | only for commands you also queue.
     */
    'default_timeout' => 30,

    /*
     | Commands running synchronously may not exceed this timeout, because the
     | web server would terminate the request and orphan the process.
     */
    'max_sync_timeout' => 30,

    /*
     | Shell commands are disabled by default. Enabling this does NOT allow
     | arbitrary commands: shell definitions remain allow-listed and are
     | executed as argument vectors, never as a shell string.
     */
    'shell' => [
        'enabled' => false,
    ],

    /*
     | Command sources, resolved from the container in order. Later sources
     | override earlier ones when two define the same command key.
     |
     | DatabaseSource is opt-in and deliberately not enabled here: anyone who
     | can write its table can run anything the PHP process can. Enable it only
     | with the editor guarded by a strong ability.
     */
    'sources' => [
        ConfigSource::class,
        // \Bityukov\CommandCenter\Sources\DatabaseSource::class,
    ],

    /*
     | Live output.
     |
     | Output is streamed into the cache while a command runs and copied onto
     | the run record when it finishes. The cap bounds a runaway command's log;
     | the head and tail are kept and the middle is dropped.
     */
    'output' => [
        'max_bytes' => 262144,
        'ttl_minutes' => 60,
        'poll_ms' => 750,
    ],

    /*
     | An optional limit applied to every command, on top of any per-command
     | rate limit. Null disables it.
     */
    'rate_limit' => [
        'global' => null,
    ],

    /*
     | Run history.
     |
     | The cache driver needs no migration, which keeps installation to a single
     | composer require. It is capped and TTL-bounded, so treat it as a recent
     | activity log rather than a permanent audit trail — a cache flush clears
     | it. Plan 4 adds a durable database driver.
     */
    'history' => [
        /*
         | 'cache' needs no migration and is capped and TTL-bounded, which makes
         | it a recent-activity log rather than an audit trail — a cache flush
         | clears it. 'database' is durable and needs the published migration.
         */
        'driver' => env('COMMAND_CENTER_HISTORY_DRIVER', 'cache'),
        'max' => 100,
        'ttl_hours' => 168,
        'store' => null,
    ],

    /*
     | Gate abilities the package checks for its own destructive actions.
     */
    'abilities' => [
        /*
         | Who sees the module at all: the command catalogue, a run, and the
         | history. Define this gate in your application — an undefined gate
         | denies, so the pages stay hidden until you say who may reach them.
         |
         | Set it to null to make the module visible to everyone who can open
         | the panel. That is a decision, not a default: each command's own
         | 'ability' is then the only thing standing between a panel user and
         | running it.
         */
        'access' => 'command-center:access',
        'prune_history' => 'command-center:prune-history',
        /*
         | Whoever holds this can define what the panel is able to execute.
         | Treat it as deploy access, not as an editor role.
         */
        'manage_commands' => 'command-center:manage-commands',
    ],

    /*
     | Command definitions, keyed by a unique slug.
     |
     | A starter set for a typical Laravel app ships here so a fresh install has
     | something to run. It is still an allow-list: delete what you do not want,
     | add what you do, and give anything dangerous its own 'ability'. Nothing
     | outside this array can ever be executed.
     |
     | Remember that the module is invisible until you define the access gate,
     | so these are not reachable by anyone until you say who may reach them.
     |
     | Run `php artisan command-center:check` after editing. It validates every
     | definition and fails on anything misconfigured.
     |
     | Keys: run, label, group, help, timeout, queue, ability, confirm,
     | concurrency, rate_limit, progress, variables, flags, type
     | ('artisan' by default, 'shell' if shell execution is enabled).
     */
    'commands' => [
        'cache-clear' => [
            'run' => 'cache:clear',
            'label' => 'Clear application cache',
            'group' => 'Cache',
            'help' => 'Flushes the application cache store.',
            'timeout' => 30,
        ],
        'config-clear' => [
            'run' => 'config:clear',
            'label' => 'Clear config cache',
            'group' => 'Cache',
            'timeout' => 30,
        ],
        'view-clear' => [
            'run' => 'view:clear',
            'label' => 'Clear compiled views',
            'group' => 'Cache',
            'timeout' => 30,
        ],
        'cache-forget' => [
            'run' => 'cache:forget {key}',
            'label' => 'Forget a cache key',
            'group' => 'Cache',
            'timeout' => 30,
            'variables' => [
                'key' => [
                    'label' => 'Cache key',
                    'type' => 'text',
                    'required' => true,
                ],
            ],
        ],
        'optimize' => [
            'run' => 'optimize',
            'label' => 'Optimize',
            'group' => 'Optimisation',
            'help' => 'Caches config, routes, views and events.',
            'timeout' => 30,
        ],
        'optimize-clear' => [
            'run' => 'optimize:clear',
            'label' => 'Clear all caches',
            'group' => 'Optimisation',
            'timeout' => 30,
        ],
        'queue-restart' => [
            'run' => 'queue:restart',
            'label' => 'Restart queue workers',
            'group' => 'Queue',
            'help' => 'Gracefully restarts workers, usually after a deploy.',
            'timeout' => 30,
        ],
        'queue-failed' => [
            'run' => 'queue:failed',
            'label' => 'List failed jobs',
            'group' => 'Queue',
            'timeout' => 30,
        ],
        'queue-retry' => [
            'run' => 'queue:retry {id}',
            'label' => 'Retry failed jobs',
            'group' => 'Queue',
            'help' => 'Retry one failed job by id, or "all" for every one of them.',
            'timeout' => 30,
            'variables' => [
                'id' => [
                    'label' => 'Job id',
                    'type' => 'text',
                    'default' => 'all',
                    'required' => true,
                ],
            ],
        ],
        'migrate-status' => [
            'run' => 'migrate:status',
            'label' => 'Migration status',
            'group' => 'Database',
            'timeout' => 30,
        ],
        'storage-link' => [
            'run' => 'storage:link',
            'label' => 'Link storage directory',
            'group' => 'Maintenance',
            'timeout' => 30,
        ],
        'about' => [
            'run' => 'about',
            'label' => 'Application overview',
            'group' => 'Diagnostics',
            'timeout' => 30,
        ],

        /*
         | TracePharma application commands, grouped by ops area.
         */

        // Connections — approval queue and lifecycle
        'connections-list' => [
            'run' => 'connections:list --tenant={tenant} --direction={direction} --status={status}',
            'label' => 'List connection requests',
            'group' => 'Connections',
            'help' => 'Central approval queue view with optional filters.',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']],
            ],
        ],
        'connections-review' => [
            'run' => 'connections:review {request} --note={note} --admin={admin}',
            'label' => 'Review a connection request',
            'group' => 'Connections',
            'help' => 'Approve or reject a pending request. A note is required when rejecting.',
            'variables' => [
                'request' => ['label' => 'Request id', 'type' => 'text', 'required' => true],
                'note' => ['label' => 'Review note', 'type' => 'text'],
                'admin' => ['label' => 'Admin id (reviewer)', 'type' => 'text'],
            ],
            'flags' => [
                '--approve' => ['label' => 'Approve'],
                '--reject' => ['label' => 'Reject'],
            ],
        ],
        'connections-suspend' => [
            'run' => 'connections:suspend {tenant} {connection} --direction={direction} --reason={reason}',
            'label' => 'Suspend a connection',
            'group' => 'Connections',
            'help' => 'Disables the connection immediately (audited).',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'connection' => ['label' => 'Connection id', 'type' => 'text', 'required' => true],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'default' => 'inbound', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
                'reason' => ['label' => 'Reason', 'type' => 'text'],
            ],
        ],
        'connections-resume' => [
            'run' => 'connections:resume {tenant} {connection} --direction={direction} --reason={reason}',
            'label' => 'Resume a connection',
            'group' => 'Connections',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'connection' => ['label' => 'Connection id', 'type' => 'text', 'required' => true],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'default' => 'inbound', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
                'reason' => ['label' => 'Reason', 'type' => 'text'],
            ],
        ],
        'connections-rotate-token' => [
            'run' => 'connections:rotate-token {tenant} {connection} --direction={direction} --set-token={set_token}',
            'label' => 'Rotate connection token',
            'group' => 'Connections',
            'help' => 'Inbound tokens are regenerated (instant cutover). Outbound tokens are partner-issued: paste via Set token.',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'connection' => ['label' => 'Connection id', 'type' => 'text', 'required' => true],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'default' => 'inbound', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
                'set_token' => ['label' => 'Set token (outbound only)', 'type' => 'text', 'redact' => true],
            ],
            'flags' => [
                '--show' => ['label' => 'Show generated token', 'help' => 'Print the generated inbound token in the run output.'],
            ],
        ],
        'connections-go-live-report' => [
            'run' => 'connections:go-live-report {tenant} {connection} --direction={direction} --output={output}',
            'label' => 'Go-live evidence pack',
            'group' => 'Connections',
            'help' => 'Renders the Markdown go-live evidence pack (checklist, sign-off, traffic).',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'connection' => ['label' => 'Connection id', 'type' => 'text', 'required' => true],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'default' => 'outbound', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
                'output' => ['label' => 'Output path', 'type' => 'text', 'help' => 'Write to this path instead of stdout.'],
            ],
        ],
        'connections-credential-expiry-report' => [
            'run' => 'connections:credential-expiry-report --days={days} --tenant={tenant}',
            'label' => 'Credential expiry report',
            'group' => 'Connections',
            'help' => 'Alerts on expired/expiring connection credentials and AS2 certificates.',
            'variables' => [
                'days' => ['label' => 'Days ahead', 'type' => 'text', 'default' => '30'],
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run', 'help' => 'Report matches without notifying.'],
            ],
        ],
        'connections-health-sweep' => [
            'run' => 'connections:health-sweep',
            'label' => 'Connection health sweep',
            'group' => 'Connections',
            'help' => 'Alerts on connection failure streaks and optionally auto-pauses runaway connections.',
            'queue' => true,
            'timeout' => 300,
        ],

        // Hub — provider switches, GLN routes, platform tokens
        'hub-providers' => [
            'run' => 'hub:providers {environment}',
            'label' => 'List hub providers',
            'group' => 'Hub',
            'variables' => [
                'environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['demo' => 'Demo', 'stage' => 'Stage', 'prod' => 'Prod'], 'help' => 'Leave empty for all.'],
            ],
        ],
        'hub-routes' => [
            'run' => 'hub:routes --tenant={tenant} --provider={provider}',
            'label' => 'List hub GLN routes',
            'group' => 'Hub',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'provider' => ['label' => 'Provider', 'type' => 'select', 'options' => ['tracepharma' => 'TracePharma', 'systech' => 'Systech', 'unitrace' => 'UniTrace']],
            ],
        ],
        'hub-register-route' => [
            'run' => 'hub:register-route {tenant} {provider} {gln} --connection={connection}',
            'label' => 'Register hub route',
            'group' => 'Hub',
            'help' => 'Claims a receiver GLN for a tenant on a hub provider.',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'provider' => ['label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => ['tracepharma' => 'TracePharma', 'systech' => 'Systech', 'unitrace' => 'UniTrace']],
                'gln' => ['label' => 'Receiver GLN', 'type' => 'text', 'required' => true, 'rules' => ['digits:13']],
                'connection' => ['label' => 'Default inbound connection id', 'type' => 'text'],
            ],
            'flags' => [
                '--inactive' => ['label' => 'Register as inactive'],
                '--allow-orphan' => ['label' => 'Allow orphan GLN', 'help' => 'Allow GLNs that are not the tenant company/site GLN.'],
            ],
        ],
        'hub-unregister-route' => [
            'run' => 'hub:unregister-route {tenant} {provider} {gln}',
            'label' => 'Unregister hub route',
            'group' => 'Hub',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'provider' => ['label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => ['tracepharma' => 'TracePharma', 'systech' => 'Systech', 'unitrace' => 'UniTrace']],
                'gln' => ['label' => 'Receiver GLN', 'type' => 'text', 'required' => true, 'rules' => ['digits:13']],
            ],
        ],
        'hub-enable-provider' => [
            'run' => 'hub:enable-provider {environment} {provider}',
            'label' => 'Enable hub provider',
            'group' => 'Hub',
            'variables' => [
                'environment' => ['label' => 'Environment', 'type' => 'select', 'required' => true, 'options' => ['demo' => 'Demo', 'stage' => 'Stage', 'prod' => 'Prod']],
                'provider' => ['label' => 'Provider', 'type' => 'text', 'required' => true],
            ],
        ],
        'hub-disable-provider' => [
            'run' => 'hub:disable-provider {environment} {provider}',
            'label' => 'Disable hub provider',
            'group' => 'Hub',
            'help' => 'The hub stops accepting documents for this provider.',
            'confirm' => true,
            'variables' => [
                'environment' => ['label' => 'Environment', 'type' => 'select', 'required' => true, 'options' => ['demo' => 'Demo', 'stage' => 'Stage', 'prod' => 'Prod']],
                'provider' => ['label' => 'Provider', 'type' => 'text', 'required' => true],
            ],
        ],
        'hub-rotate-token' => [
            'run' => 'hub:rotate-token {environment}',
            'label' => 'Rotate hub token',
            'group' => 'Hub',
            'help' => 'Previous token stays valid for the 24h grace window.',
            'variables' => [
                'environment' => ['label' => 'Environment', 'type' => 'select', 'required' => true, 'options' => ['demo' => 'Demo', 'stage' => 'Stage', 'prod' => 'Prod']],
            ],
            'flags' => [
                '--show' => ['label' => 'Show new token', 'help' => 'Print the new token in the run output.'],
            ],
        ],

        // Tenants — lifecycle and entitlement
        'tenants-list' => [
            'run' => 'tenants:list',
            'label' => 'List tenants',
            'group' => 'Tenants',
        ],
        'tenant-entitle' => [
            'run' => 'tenant:entitle {tenant} --inbound-env={inbound_env} --add-provider={add_provider} --remove-provider={remove_provider}',
            'label' => 'Manage tenant hub entitlement',
            'group' => 'Tenants',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'inbound_env' => ['label' => 'Inbound environment', 'type' => 'select', 'options' => ['demo' => 'Demo', 'stage' => 'Stage', 'prod' => 'Prod']],
                'add_provider' => ['label' => 'Add provider(s)', 'type' => 'text', 'help' => 'Comma-separated, e.g. tracepharma,systech'],
                'remove_provider' => ['label' => 'Remove provider(s)', 'type' => 'text', 'help' => 'Comma-separated'],
            ],
        ],
        'tenant-suspend' => [
            'run' => 'tenant:suspend {tenant} --reason={reason}',
            'label' => 'Suspend tenant',
            'group' => 'Tenants',
            'help' => 'Cascades to the pair sibling. Reason is required and audited.',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'reason' => ['label' => 'Reason', 'type' => 'text', 'required' => true],
            ],
        ],
        'tenant-activate' => [
            'run' => 'tenant:activate {tenant}',
            'label' => 'Activate tenant',
            'group' => 'Tenants',
            'help' => 'Reactivates a suspended tenant (cascades to the pair sibling).',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
            ],
        ],
        'tenant-provision' => [
            'run' => 'tracepharma:provision-tenant {name} {slug} --profile={profile} --environment={environment} --owner-name={owner_name} --owner-email={owner_email} --owner-password={owner_password}',
            'label' => 'Provision tenant',
            'group' => 'Tenants',
            'help' => 'Creates isolated stage and/or prod tenant hosts from a slug.',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'name' => ['label' => 'Display name', 'type' => 'text', 'required' => true],
                'slug' => ['label' => 'Tenant slug', 'type' => 'text', 'required' => true],
                'profile' => ['label' => 'Profile', 'type' => 'text', 'default' => 'pharmacy'],
                'environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['stage' => 'Stage', 'prod' => 'Prod'], 'help' => 'Omit to provision the pair.'],
                'owner_name' => ['label' => 'Owner name', 'type' => 'text'],
                'owner_email' => ['label' => 'Owner email', 'type' => 'text'],
                'owner_password' => ['label' => 'Owner password', 'type' => 'text', 'redact' => true],
            ],
            'flags' => [
                '--pair' => ['label' => 'Provision both stage and prod'],
            ],
        ],
        'tenant-health-alert' => [
            'run' => 'tracepharma:tenant-health-alert --throttle={throttle}',
            'label' => 'Tenant health alert',
            'group' => 'Tenants',
            'help' => 'Alerts platform admins when tenant integrity issues are detected.',
            'queue' => true,
            'timeout' => 300,
            'variables' => [
                'throttle' => ['label' => 'Throttle (hours)', 'type' => 'text', 'default' => '24'],
            ],
            'flags' => [
                '--force' => ['label' => 'Force', 'help' => 'Send even if recently alerted.'],
            ],
        ],
        'tenant-sync-pair-sibling' => [
            'run' => 'tracepharma:sync-pair-sibling',
            'label' => 'Sync pair sibling hosts',
            'group' => 'Tenants',
            'help' => 'Copies away-environment tenant hosts into the sibling central database.',
            'queue' => true,
            'timeout' => 300,
        ],

        // EPCIS — inbound polling, ingest, backfills, retention
        'epcis-poll-sftp' => [
            'run' => 'epcis:poll-sftp',
            'label' => 'Poll tenant SFTP inbounds',
            'group' => 'EPCIS',
            'help' => 'Dispatches SFTP polling jobs for active inbound connections.',
        ],
        'epcis-poll-platform-sftp' => [
            'run' => 'epcis:poll-platform-sftp',
            'label' => 'Poll platform SFTP drop',
            'group' => 'EPCIS',
            'help' => 'Polls the platform-owned SFTP drop per environment and hub-routes inbound files.',
            'flags' => [
                '--sync' => ['label' => 'Run inline', 'help' => 'Run the polls inline instead of dispatching jobs.'],
            ],
        ],
        'epcis-fail-stale-jobs' => [
            'run' => 'epcis:fail-stale-jobs --tenant={tenant}',
            'label' => 'Fail stale EPCIS jobs',
            'group' => 'EPCIS',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'epcis-emit-pending-mdn-signals' => [
            'run' => 'epcis:emit-pending-mdn-signals --tenant={tenant}',
            'label' => 'Emit pending MDN signals',
            'group' => 'EPCIS',
            'help' => 'Records MISSING_MDN / LATE_MDN signals for pending AS2 MDNs past SLA.',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'epcis-export-scenario-evidence' => [
            'run' => 'epcis:export-scenario-evidence --tenant={tenant} --output={output} --format={format}',
            'label' => 'Export scenario evidence',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'output' => ['label' => 'Output directory', 'type' => 'text'],
                'format' => ['label' => 'Format', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'All', 'md' => 'Markdown', 'junit' => 'JUnit']],
            ],
        ],
        'epcis-ingest' => [
            'run' => 'tracepharma:ingest-epcis {path} --tenant={tenant} --direction={direction}',
            'label' => 'Ingest EPCIS document',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 300,
            'variables' => [
                'path' => ['label' => 'Absolute XML path', 'type' => 'text', 'required' => true],
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'direction' => ['label' => 'Direction', 'type' => 'select', 'default' => 'inbound', 'options' => ['inbound' => 'Inbound', 'outbound' => 'Outbound']],
            ],
            'flags' => [
                '--sync' => ['label' => 'Process inline'],
            ],
        ],
        'epcis-evaluate-exceptions' => [
            'run' => 'tracepharma:evaluate-epcis-exceptions --tenant={tenant}',
            'label' => 'Evaluate EPCIS exceptions',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run', 'help' => 'Report matches without recording exceptions.'],
            ],
        ],
        'epcis-retention-report' => [
            'run' => 'tracepharma:epcis-retention-report --tenant={tenant}',
            'label' => 'EPCIS retention report',
            'group' => 'EPCIS',
            'help' => 'Reports documents past cutoff. No deletes.',
            'queue' => true,
            'timeout' => 300,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--check-pedigree-payloads' => ['label' => 'Check pedigree payloads'],
            ],
        ],
        'epcis-sync-document-epcs' => [
            'run' => 'tracepharma:epcis-sync-document-epcs --tenant={tenant} --document={document}',
            'label' => 'Sync document EPCs',
            'group' => 'EPCIS',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'document' => ['label' => 'Document id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'epcis-archive-events' => [
            'run' => 'tracepharma:epcis-archive-events --tenant={tenant}',
            'label' => 'Archive aged EPCIS events',
            'group' => 'EPCIS',
            'help' => 'Moves events older than retention_years into archive tables.',
            'queue' => true,
            'timeout' => 600,
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'epcis-prune-superseded-generations' => [
            'run' => 'tracepharma:epcis-prune-superseded-generations --tenant={tenant} --document={document}',
            'label' => 'Prune superseded generations',
            'group' => 'EPCIS',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'document' => ['label' => 'Document id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'epcis-backfill-vocabulary' => [
            'run' => 'tracepharma:epcis-backfill-vocabulary --tenant={tenant} --document={document} --limit={limit}',
            'label' => 'Backfill EPCIS vocabulary',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'document' => ['label' => 'Document id', 'type' => 'text'],
                'limit' => ['label' => 'Max documents', 'type' => 'text', 'default' => '500'],
            ],
            'flags' => [
                '--force' => ['label' => 'Force', 'help' => 'Re-upsert even when vocabulary rows already exist.'],
            ],
        ],
        'epcis-backfill-pedigree-fragments' => [
            'run' => 'tracepharma:epcis-backfill-pedigree-fragments --tenant={tenant} --document={document} --limit={limit}',
            'label' => 'Backfill pedigree fragments',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'document' => ['label' => 'Document id', 'type' => 'text'],
                'limit' => ['label' => 'Max documents', 'type' => 'text', 'default' => '500'],
            ],
            'flags' => [
                '--force' => ['label' => 'Force', 'help' => 'Re-extract even when fragments already exist.'],
            ],
        ],
        'epcis-reprocess-backfill' => [
            'run' => 'tracepharma:epcis-reprocess-backfill --tenant={tenant} --document={document} --limit={limit}',
            'label' => 'Reprocess documents (fidelity backfill)',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'document' => ['label' => 'Document id', 'type' => 'text'],
                'limit' => ['label' => 'Max documents', 'type' => 'text', 'default' => '500'],
            ],
            'flags' => [
                '--sync' => ['label' => 'Run inline'],
                '--force' => ['label' => 'Force', 'help' => 'Reprocess even when an open receiving session exists.'],
            ],
        ],
        'epcis-rebuild-outbound-shipping' => [
            'run' => 'tracepharma:rebuild-outbound-shipping-epcis {tenant} {session}',
            'label' => 'Rebuild outbound shipping EPCIS',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 300,
            'variables' => [
                'tenant' => ['label' => 'Tenant UUID or domain', 'type' => 'text', 'required' => true],
                'session' => ['label' => 'Shipping session id', 'type' => 'text', 'required' => true],
            ],
        ],
        'epcis-repair-missing-receiving' => [
            'run' => 'tracepharma:repair-missing-receiving-epcis --tenant={tenant} --session={session}',
            'label' => 'Repair missing receiving EPCIS',
            'group' => 'EPCIS',
            'queue' => true,
            'timeout' => 300,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'session' => ['label' => 'Receiving session id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'epcis-backfill-inbound-shipments' => [
            'run' => 'tracepharma:backfill-inbound-shipments --tenant={tenant}',
            'label' => 'Backfill inbound shipments',
            'group' => 'EPCIS',
            'help' => 'Attaches inbound EPCIS documents with ASN to inbound_shipments.',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'epcis-purge-test-documents' => [
            'run' => 'tracepharma:purge-test-epcis --tenants={tenants}',
            'label' => 'Purge test EPCIS',
            'group' => 'EPCIS',
            'help' => 'Hard-deletes test-generated EPCIS documents. Defaults to the demo2 tenant.',
            'confirm' => true,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
                '--force' => ['label' => 'Force', 'help' => 'Required to actually delete when not dry-running.'],
            ],
        ],

        // Compliance — ATP, exceptions, VRS
        'compliance-alert-license-expiry' => [
            'run' => 'compliance:alert-license-expiry --tenant={tenant}',
            'label' => 'ATP license expiry alerts',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'compliance-alert-center-digest' => [
            'run' => 'compliance:alert-center-digest --tenant={tenant}',
            'label' => 'Alert center digest',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
                '--force' => ['label' => 'Force', 'help' => 'Ignore the daily/weekly frequency gate.'],
            ],
        ],
        'compliance-exceptions-check-sla' => [
            'run' => 'exceptions:check-sla --tenant={tenant}',
            'label' => 'Exception SLA check',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'compliance-notify-aging-suppliers' => [
            'run' => 'exceptions:notify-aging-suppliers --tenant={tenant}',
            'label' => 'Notify aging suppliers',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
                '--force' => ['label' => 'Force', 'help' => 'Ignore cooldown since last partner email.'],
            ],
        ],
        'compliance-exception-digest' => [
            'run' => 'tracepharma:exception-digest --tenant={tenant}',
            'label' => 'EPCIS exception digest',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'compliance-vrs-readiness-log' => [
            'run' => 'vrs:export-readiness-log --tenant={tenant} --limit={limit} --output={output}',
            'label' => 'Export VRS readiness log',
            'group' => 'Compliance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'limit' => ['label' => 'Max rows per tenant', 'type' => 'text', 'default' => '100'],
                'output' => ['label' => 'Output JSON path', 'type' => 'text'],
            ],
        ],
        'compliance-clean-org-facility-atp' => [
            'run' => 'tracepharma:clean-org-facility-atp --tenants={tenants}',
            'label' => 'Clean org facility ATP',
            'group' => 'Compliance',
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'compliance-sync-tenant-atp-from-fda' => [
            'run' => 'tracepharma:sync-tenant-atp-from-fda --tenants={tenants}',
            'label' => 'Sync tenant ATP from FDA',
            'group' => 'Compliance',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all active tenants.'],
            ],
        ],

        // FDA Catalog — imports, dedupe, matching
        'fda-import-openfda-ndc' => [
            'run' => 'tracepharma:import-openfda-ndc --stage={stage} --path={path}',
            'label' => 'Import openFDA NDC directory',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 1800,
            'variables' => [
                'stage' => ['label' => 'Stage', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'All', 'partners' => 'Partners', 'products' => 'Products']],
                'path' => ['label' => 'Local json/zip path', 'type' => 'text'],
            ],
            'flags' => [
                '--fresh-download' => ['label' => 'Fresh download'],
            ],
        ],
        'fda-import-openfda-drugsfda' => [
            'run' => 'tracepharma:import-openfda-drugsfda --path={path}',
            'label' => 'Import Drugs@FDA packaging',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 1800,
            'variables' => [
                'path' => ['label' => 'Local json/zip path', 'type' => 'text'],
            ],
            'flags' => [
                '--fresh-download' => ['label' => 'Fresh download'],
            ],
        ],
        'fda-import-decrs' => [
            'run' => 'tracepharma:import-fda-decrs --path={path}',
            'label' => 'Import FDA DECRS',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 1800,
            'variables' => [
                'path' => ['label' => 'Local zip/drls_reg.txt path', 'type' => 'text'],
            ],
            'flags' => [
                '--fresh-download' => ['label' => 'Fresh download'],
            ],
        ],
        'fda-import-wdd-3pl' => [
            'run' => 'tracepharma:import-fda-wdd-3pl --path={path}',
            'label' => 'Import FDA WDD/3PL',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 1800,
            'variables' => [
                'path' => ['label' => 'Local TSV path', 'type' => 'text'],
            ],
            'flags' => [
                '--fresh-download' => ['label' => 'Fresh download'],
                '--report' => ['label' => 'Write unmatched CSV report'],
            ],
        ],
        'fda-import-mckesson-sold-ship-to' => [
            'run' => 'tracepharma:import-mckesson-sold-ship-to --path={path}',
            'label' => 'Import McKesson sold/ship-to',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'path' => ['label' => 'TSV path', 'type' => 'text', 'required' => true],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-backfill-ndc11' => [
            'run' => 'fda:backfill-ndc11 --chunk={chunk}',
            'label' => 'Backfill packaging NDC-11',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'chunk' => ['label' => 'Chunk size', 'type' => 'text', 'default' => '1000'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-dedupe-package-ndc' => [
            'run' => 'fda:dedupe-package-ndc --chunk={chunk}',
            'label' => 'Dedupe packaging rows',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 600,
            'confirm' => true,
            'variables' => [
                'chunk' => ['label' => 'Chunk size', 'type' => 'text', 'default' => '1000'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-dedupe-match-reviews' => [
            'run' => 'fda:dedupe-match-reviews --source={source}',
            'label' => 'Dedupe match reviews',
            'group' => 'FDA Catalog',
            'confirm' => true,
            'variables' => [
                'source' => ['label' => 'Import source', 'type' => 'text', 'help' => 'e.g. wdd, decrs'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-link-exact-match-reviews' => [
            'run' => 'fda:link-exact-match-reviews --source={source}',
            'label' => 'Auto-link exact match reviews',
            'group' => 'FDA Catalog',
            'variables' => [
                'source' => ['label' => 'Import source', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-resolve-stale-match-reviews' => [
            'run' => 'fda:resolve-stale-match-reviews',
            'label' => 'Resolve stale match reviews',
            'group' => 'FDA Catalog',
            'confirm' => true,
        ],
        'fda-resolve-open-wdd-3pl-unmatched' => [
            'run' => 'fda:resolve-open-wdd-3pl-unmatched --path={path}',
            'label' => 'Resolve open WDD/3PL unmatched',
            'group' => 'FDA Catalog',
            'confirm' => true,
            'variables' => [
                'path' => ['label' => 'wdd_3pl_facilities_report.txt path', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-resolve-stale-wdd-3pl-unmatched' => [
            'run' => 'fda:resolve-stale-wdd-3pl-unmatched --path={path}',
            'label' => 'Resolve stale WDD/3PL unmatched',
            'group' => 'FDA Catalog',
            'variables' => [
                'path' => ['label' => 'wdd_3pl_facilities_report.txt path', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'fda-recalc-establishment-registration' => [
            'run' => 'tracepharma:recalc-fda-establishment-registration',
            'label' => 'Recalc establishment registration',
            'group' => 'FDA Catalog',
        ],
        'fda-backfill-tenant-stamps' => [
            'run' => 'tracepharma:backfill-tenant-fda-stamps-from-catalog --tenants={tenants}',
            'label' => 'Backfill tenant FDA stamps',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'fda-ensure-major-wholesalers' => [
            'run' => 'catalog:ensure-major-wholesalers',
            'label' => 'Ensure major wholesalers',
            'group' => 'FDA Catalog',
            'help' => 'Ensures Top 6 major wholesaler FDA organizations exist (by GLN).',
        ],
        'fda-backfill-partner-places' => [
            'run' => 'tracepharma:backfill-partner-places --partner={partner} --limit={limit} --only-missing={only_missing}',
            'label' => 'Backfill partner Places data',
            'group' => 'FDA Catalog',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'partner' => ['label' => 'FDA organization id', 'type' => 'text'],
                'limit' => ['label' => 'Max organizations', 'type' => 'text'],
                'only_missing' => ['label' => 'Only missing', 'type' => 'select', 'default' => 'true', 'options' => ['true' => 'Yes', 'false' => 'No']],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
                '--sync' => ['label' => 'Run inline'],
            ],
        ],

        // Labels & SSCC
        'sscc-check-pool-levels' => [
            'run' => 'sscc:check-pool-levels --tenant={tenant}',
            'label' => 'Check SSCC pool levels',
            'group' => 'Labels & SSCC',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'sscc-fail-stale-client-print-jobs' => [
            'run' => 'sscc:fail-stale-client-print-jobs --tenant={tenant}',
            'label' => 'Fail stale client print jobs',
            'group' => 'Labels & SSCC',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'sscc-reconcile-l3-l4' => [
            'run' => 'sscc:reconcile-l3-l4 --tenant={tenant} --site={site} --batch={batch} --sscc={sscc}',
            'label' => 'Reconcile L3/L4 SSCCs',
            'group' => 'Labels & SSCC',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'site' => ['label' => 'Commission site id', 'type' => 'text'],
                'batch' => ['label' => 'Label batch id', 'type' => 'text'],
                'sscc' => ['label' => 'SSCC-18 / URN', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run', 'help' => 'Report mismatches without opening cases.'],
            ],
        ],

        // Search
        'search-scout-health' => [
            'run' => 'tracepharma:scout-health --tenant={tenant} --throttle={throttle}',
            'label' => 'Scout health check',
            'group' => 'Search',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
                'throttle' => ['label' => 'Throttle (hours)', 'type' => 'text', 'default' => '24'],
            ],
            'flags' => [
                '--alert' => ['label' => 'Alert on failure'],
                '--force' => ['label' => 'Force alert'],
            ],
        ],
        'search-scout-reindex' => [
            'run' => 'tracepharma:scout-reindex --tenant={tenant} --model={model}',
            'label' => 'Reindex tenant Scout indexes',
            'group' => 'Search',
            'queue' => true,
            'timeout' => 1800,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'required' => true],
                'model' => ['label' => 'Model', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'All', 'products' => 'Products', 'partners' => 'Partners', 'documents' => 'Documents', 'events' => 'Events']],
            ],
            'flags' => [
                '--flush' => ['label' => 'Flush first'],
            ],
        ],
        'search-scout-reindex-all' => [
            'run' => 'tracepharma:scout-reindex-all --model={model}',
            'label' => 'Reindex all tenants',
            'group' => 'Search',
            'queue' => true,
            'timeout' => 3600,
            'confirm' => true,
            'variables' => [
                'model' => ['label' => 'Model', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'All', 'products' => 'Products', 'partners' => 'Partners', 'documents' => 'Documents', 'events' => 'Events']],
            ],
            'flags' => [
                '--flush' => ['label' => 'Flush first'],
                '--sync-settings' => ['label' => 'Sync index settings first'],
            ],
        ],
        'search-scout-sync-index-settings' => [
            'run' => 'tracepharma:scout-sync-index-settings --tenant={tenant}',
            'label' => 'Sync Scout index settings',
            'group' => 'Search',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--all-tenants' => ['label' => 'All tenants'],
            ],
        ],

        // Demo & Seeding
        'demo-setup' => [
            'run' => 'tracepharma:setup-demo',
            'label' => 'Set up demo tenant',
            'group' => 'Demo & Seeding',
            'help' => 'Provisions the demo2 drug wholesaler tenant and default logins.',
            'queue' => true,
            'timeout' => 600,
            'confirm' => true,
        ],
        'demo-seed-choreography' => [
            'run' => 'tracepharma:seed-demo-choreography --tenant={tenant}',
            'label' => 'Seed demo choreography',
            'group' => 'Demo & Seeding',
            'help' => 'Seeds receive→ship demo data (custody, ship order, transfers, unpack/pack/return).',
            'queue' => true,
            'timeout' => 600,
            'variables' => [
                'tenant' => ['label' => 'Tenant id or demo domain', 'type' => 'text'],
            ],
            'flags' => [
                '--receive-only' => ['label' => 'Receive only'],
                '--transfer' => ['label' => 'Add transfer leg'],
                '--unpack' => ['label' => 'Add unpack'],
                '--pack' => ['label' => 'Add unpack+pack'],
                '--return' => ['label' => 'Add return'],
            ],
        ],
        'demo-seed-outbound-network-profiles' => [
            'run' => 'tracepharma:seed-outbound-network-profiles',
            'label' => 'Seed outbound network profiles',
            'group' => 'Demo & Seeding',
            'flags' => [
                '--force' => ['label' => 'Overwrite existing values'],
            ],
        ],
        'demo-seed-tenant-job-roles' => [
            'run' => 'tracepharma:seed-tenant-job-roles --tenants={tenants}',
            'label' => 'Seed tenant job roles',
            'group' => 'Demo & Seeding',
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'demo-seed-outbound-templates' => [
            'run' => 'tenants:seed-outbound-templates --tenants={tenants}',
            'label' => 'Seed outbound connection templates',
            'group' => 'Demo & Seeding',
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'demo-grant-dispense-check-ability' => [
            'run' => 'tracepharma:grant-dispense-check-ability --tenant={tenant}',
            'label' => 'Grant dispense-check ability',
            'group' => 'Demo & Seeding',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'demo-ensure-tenant-storage' => [
            'run' => 'tracepharma:ensure-tenant-storage --tenants={tenants}',
            'label' => 'Ensure tenant storage',
            'group' => 'Demo & Seeding',
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'demo-backfill-connection-approval-requests' => [
            'run' => 'tracepharma:backfill-connection-approval-requests --tenant={tenant}',
            'label' => 'Backfill connection approval requests',
            'group' => 'Demo & Seeding',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],

        // Maintenance
        'maintenance-activitylog-clean' => [
            'run' => 'activitylog:clean',
            'label' => 'Clean activity log',
            'group' => 'Maintenance',
            'confirm' => true,
        ],
        'maintenance-redact-inbound-secrets' => [
            'run' => 'activitylog:redact-inbound-connection-secrets --tenant={tenant}',
            'label' => 'Redact inbound connection secrets',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'maintenance-exports-fail-stale' => [
            'run' => 'exports:fail-stale --tenant={tenant}',
            'label' => 'Fail stale exports',
            'group' => 'Maintenance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'maintenance-exports-purge-expired' => [
            'run' => 'exports:purge-expired --tenant={tenant}',
            'label' => 'Purge expired exports',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
        ],
        'maintenance-decommission-never-shipped' => [
            'run' => 'disposition:decommission-never-shipped --tenant={tenant}',
            'label' => 'Decommission never-shipped EPCs',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'maintenance-doctor-aggregation-link-fk' => [
            'run' => 'tracepharma:doctor-aggregation-link-fk --tenant={tenant} --throttle={throttle}',
            'label' => 'Doctor aggregation link FK',
            'group' => 'Maintenance',
            'variables' => [
                'tenant' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
                'throttle' => ['label' => 'Throttle (hours)', 'type' => 'text', 'default' => '24'],
            ],
            'flags' => [
                '--fix' => ['label' => 'Fix', 'help' => 'Run tenant migrations for drifting tenants.'],
                '--alert' => ['label' => 'Alert on drift'],
                '--force' => ['label' => 'Force alert'],
            ],
        ],
        'maintenance-rederive-organization-sglns' => [
            'run' => 'tracepharma:rederive-organization-sglns --tenants={tenants}',
            'label' => 'Re-derive organization SGLNs',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
            'flags' => [
                '--dry-run' => ['label' => 'Dry run'],
            ],
        ],
        'maintenance-partners-deactivate-self' => [
            'run' => 'tracepharma:partners-deactivate-self --tenants={tenants}',
            'label' => 'Deactivate self partners',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'maintenance-sites-demote-test-leaks' => [
            'run' => 'tracepharma:sites-demote-test-leaks --tenants={tenants}',
            'label' => 'Demote leaked test sites',
            'group' => 'Maintenance',
            'confirm' => true,
            'variables' => [
                'tenants' => ['label' => 'Tenant id', 'type' => 'text', 'help' => 'Leave empty for all.'],
            ],
        ],
        'maintenance-command-center-prune' => [
            'run' => 'command-center:prune --days={days}',
            'label' => 'Prune command run history',
            'group' => 'Maintenance',
            'variables' => [
                'days' => ['label' => 'Older than (days)', 'type' => 'text', 'default' => '30'],
            ],
        ],
    ],
];
