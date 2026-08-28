<?php

declare(strict_types=1);

use App\Support\Config\SafetyGate;

return [
    'central_domain' => env('CENTRAL_DOMAIN', 'localhost'),
    'admin_domain' => env('ADMIN_DOMAIN', 'admin2.localhost'),
    'marketing_domain' => env('MARKETING_DOMAIN', env('CENTRAL_DOMAIN', 'localhost')),
    'platform_base_domain' => env('PLATFORM_BASE_DOMAIN', 'tracepharma.io'),
    'tenant_environment' => env('TENANT_ENVIRONMENT', 'prod'),
    'pair_sibling_database' => env('PAIR_SIBLING_DB_DATABASE'),
    'stage_provisioning' => [
        'enabled' => (bool) env('STAGE_PROVISION_ENABLED', false),
        'ssh_host' => env('STAGE_SSH_HOST', '127.0.0.1'),
        'ssh_user' => env('STAGE_SSH_USER', 'www-data'),
        'deploy_path' => env('STAGE_DEPLOY_PATH', '/var/www/html/tracepharma-stage'),
    ],
    'app_version' => env('APP_VERSION', '1.2.0'),
    'demo_domains' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('DEMO_DOMAINS', 'demo2.internal.vatengi.com,demo2.localhost'))
    ))),

    'impersonation' => [
        // One-time impersonation token TTL (seconds) before redemption on tenant host.
        'token_ttl' => (int) env('TRACEPHARMA_IMPERSONATION_TOKEN_TTL', 120),
    ],

    /*
    | App-panel Terms + Privacy acceptance for owners / organization-settings users.
    | Grace starts on first notice (legal_notice_started_at), then the App hard-blocks.
    */
    'legal_acceptance' => [
        'grace_days' => max(0, (int) env('LEGAL_ACCEPTANCE_GRACE_DAYS', 14)),
    ],

    /*
    | Ops mailbox for platform-level operational alerts. Leave null to rely on
    | admin users + logs only once alert notifications are wired.
    */
    'ops_alert_email' => env('OPS_ALERT_EMAIL', 'ops@tracepharma.io'),

    /*
    | Platform support mailbox for Critical-tier exception alerts, tenant
    | escalations, and integration failures.
    */
    'platform_support_email' => env('PLATFORM_SUPPORT_EMAIL', 'support@tracepharma.io'),

    'legal_contact_email' => env('LEGAL_CONTACT_EMAIL', 'legal@tracepharma.io'),

    'privacy_contact_email' => env('PRIVACY_CONTACT_EMAIL', 'privacy@tracepharma.io'),

    'marketing' => [
        'demo_notify_email' => env('MARKETING_DEMO_NOTIFY_EMAIL'),
        'onboarding_notify_email' => env('MARKETING_ONBOARDING_NOTIFY_EMAIL'),
    ],

    'onboarding_mail' => [
        'from_address' => env('MARKETING_ONBOARDING_FROM_EMAIL', 'onboarding@tracepharma.io'),
        'from_name' => env('MARKETING_ONBOARDING_FROM_NAME', 'TracePharma Onboarding'),
    ],

    'exception_mail' => [
        'from_address' => env('EXCEPTION_MAIL_FROM', 'dscsaexceptions@tracepharma.io'),
        'from_name' => env('EXCEPTION_MAIL_FROM_NAME', 'DSCSA Exceptions'),
    ],

    'epcis' => [
        'max_upload_kb' => (int) env('TRACEPHARMA_EPCIS_MAX_UPLOAD_KB', 20480), // raise with PHP-FPM upload_max_filesize for 50–100MB
        // Inbound uploads may still use EPCIS_INBOUND_DISK via TRACEPHARMA_EPCIS_PAYLOAD_DISK.
        // On S3, inbound keys are hub-style: inbound/{uuid}.xml
        'payload_disk' => env('TRACEPHARMA_EPCIS_PAYLOAD_DISK', env('EPCIS_INBOUND_DISK', env('FILESYSTEM_DISK', 'local'))),
        // Authored outbound/receiving/transfer XML — local only (does not inherit inbound S3 disk).
        'authored_payload_disk' => env('TRACEPHARMA_EPCIS_AUTHORED_PAYLOAD_DISK', 'local'),
        'inbound_url_ttl_minutes' => (int) env('EPCIS_INBOUND_URL_TTL', 15),
        'inbound_bucket' => env('EPCIS_INBOUND_BUCKET', env('AWS_BUCKET')),
        'retention_years' => (int) env('TRACEPHARMA_EPCIS_RETENTION_YEARS', 6),
        // Compliance kill-switches: fail closed, see SafetyGate.
        'enforce_ts_for_receiving' => SafetyGate::enabled('TRACEPHARMA_EPCIS_ENFORCE_TS_RECEIVING'),
        'enforce_atp_soft_gate' => SafetyGate::enabled('TRACEPHARMA_EPCIS_ENFORCE_ATP_SOFT'),
        // Outbound is a transfer of ownership, so the ATP gate blocks the send.
        'enforce_atp_outbound_gate' => SafetyGate::enabled('TRACEPHARMA_EPCIS_ENFORCE_ATP_OUTBOUND'),
        'require_validated_for_receiving' => SafetyGate::enabled('TRACEPHARMA_EPCIS_REQUIRE_VALIDATED_RECEIVING'),
        // Partitioning: redesign PK to include event_time before >50M rows; deferred.
        'partition_ready' => false,

        'validation' => [
            'default_profile' => env('TRACEPHARMA_EPCIS_VALIDATION_PROFILE', 'gs1us_r12'),
            'force_r13' => (bool) env('TRACEPHARMA_EPCIS_FORCE_R13', false),
            'hierarchy_depth_limit' => (int) env('TRACEPHARMA_EPCIS_HIERARCHY_DEPTH_LIMIT', 6),
            'future_event_skew_seconds' => (int) env('TRACEPHARMA_EPCIS_FUTURE_EVENT_SKEW', 300),
            'stale_event_days' => (int) env('TRACEPHARMA_EPCIS_STALE_EVENT_DAYS', 365),
            'max_findings_per_type' => (int) env('TRACEPHARMA_EPCIS_MAX_FINDINGS_PER_TYPE', 50),
            'severity_overrides' => [],
        ],

        /*
        | EPCIS 2.0 JSON-LD ingest/outbound is opt-in. Default off preserves
        | EPCIS 1.2/1.3 XML as the only accepted edge format.
        */
        'accept_20' => (bool) env('TRACEPHARMA_EPCIS_ACCEPT_20', false),
        // Platform default for new / unpinned outbound connections. Prefer 1.2 XML until
        // a real XML 2.0 writer ships; JSON-LD 2.0 remains opt-in via connection + accept_20.
        // Existing rows may still be pinned explicitly by tenant migration pin_outbound_epcis_version_1_2.
        'default_outbound_version' => env('TRACEPHARMA_EPCIS_DEFAULT_OUTBOUND_VERSION', '1.2'),
        'subscription_inline_event_threshold' => (int) env('TRACEPHARMA_EPCIS_SUBSCRIPTION_INLINE_EVENTS', 50),
        'subscription_download_ttl_minutes' => (int) env('TRACEPHARMA_EPCIS_SUBSCRIPTION_DOWNLOAD_TTL', 60),
    ],

    /*
    | Queue-backed EPCIS Jobs ledger for authored outbound transmit (Phase 1).
    | When disabled, ScheduleOutboundEpcisTransmission stays synchronous.
    */
    'epcis_jobs' => [
        'enabled' => (bool) env('TRACEPHARMA_EPCIS_JOBS_ENABLED', false),
        'queue' => env('TRACEPHARMA_EPCIS_JOBS_QUEUE', 'epcis'),
        'stale_queued_seconds' => (int) env('TRACEPHARMA_EPCIS_JOBS_STALE_QUEUED_SECONDS', 900),
    ],

    /*
    | Centralized Systech / UniTrace EPCIS hub inbound. Partners POST once to
    | /api/webhooks/epcis/hub/{provider} on demo/stage/prod hub hosts; routing uses
    | SBDH receiver GLN. Hosts/tokens/providers may be overridden via platform_settings.
    */
    'epcis_hub' => [
        'providers' => ['systech', 'unitrace'],
        'hub_token' => env('EPCIS_HUB_TOKEN'), // legacy fallback
        'demo' => [
            'host' => env('EPCIS_HUB_HOST_DEMO', 'admin2.internal.vatengi.com'),
            'hub_token' => env('EPCIS_HUB_TOKEN_DEMO'),
        ],
        'stage' => [
            'host' => env('EPCIS_HUB_HOST_STAGE', 'stage.tracepharma.io'),
            'hub_token' => env('EPCIS_HUB_TOKEN_STAGE'),
        ],
        'prod' => [
            'host' => env('EPCIS_HUB_HOST_PROD', 'prod.tracepharma.io'),
            'hub_token' => env('EPCIS_HUB_TOKEN_PROD'),
        ],
        // Map local/test hosts → environment (used by environmentForHost).
        'testing_hosts' => [
            'localhost' => 'demo',
            '127.0.0.1' => 'demo',
        ],
    ],

    /*
    | Exception case management (investigation layer on top of epcis_exceptions signals).
    | Auto-promote runs outside the ingest transaction via PromoteEpcisExceptionToCaseJob.
    */
    'exceptions' => [
        'auto_promote_types' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRACEPHARMA_EXCEPTIONS_AUTO_PROMOTE_TYPES', ''))
        ))),
    ],

    /*
    | Regulatory compliance password gate for App-panel mutating Filament actions.
    | Disabled by default in PHPUnit via Tests\TestCase.
    |
    | The password prompt is rate limited per user and action: after max_attempts
    | rejected passwords that action locks for lockout_seconds.
    */
    'regulatory_compliance' => [
        'password_gate' => SafetyGate::enabled('TRACEPHARMA_REGULATORY_PASSWORD_GATE'),
        'max_attempts' => (int) env('TRACEPHARMA_REGULATORY_MAX_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('TRACEPHARMA_REGULATORY_LOCKOUT_SECONDS', 900),
    ],

    /*
    | Supplier-facing exception portal links. Links are temporary signed URLs so a
    | forwarded email stops working on its own; they can also be revoked or rotated
    | per partner (portal_share_uuid) by owners and master-data administrators.
    */
    'supplier_portal' => [
        'link_ttl_days' => (int) env('TRACEPHARMA_SUPPLIER_PORTAL_LINK_TTL_DAYS', 30),
    ],

    /*
    | Aging supplier exception collaboration: push email + portal status when
    | open partner-linked cases age. No inbound email-reply parser.
    */
    'supplier_exception_notify' => [
        'aging_days' => (int) env('TRACEPHARMA_SUPPLIER_EXCEPTION_AGING_DAYS', 3),
        'cooldown_hours' => (int) env('TRACEPHARMA_SUPPLIER_EXCEPTION_NOTIFY_COOLDOWN_HOURS', 72),
    ],

    /*
    | AS2 outbound MDN SLAs for catalog signals (MISSING_MDN / LATE_MDN).
    | Pending transmission_mdns past missing (but before late) → MISSING_MDN;
    | past late → LATE_MDN only. De-duped per document + exception_type.
    */
    'as2_mdn' => [
        'missing_after_hours' => (int) env('TRACEPHARMA_AS2_MDN_MISSING_HOURS', 24),
        'late_after_hours' => (int) env('TRACEPHARMA_AS2_MDN_LATE_HOURS', 72),
    ],

    /*
    | Buyer-facing outbound ASN/EPCIS portal. Separate uuid from the supplier
    | exception portal. Listed documents follow epcis.retention_years.
    */
    'customer_portal' => [
        'link_ttl_days' => (int) env('TRACEPHARMA_CUSTOMER_PORTAL_LINK_TTL_DAYS', 30),
    ],

    /*
    | Partner acknowledgment links embedded in recall broadcast emails. Temporary
    | signed URLs tied to each tracing_request_notifications.ack_share_uuid row.
    */
    'recall_broadcast_ack' => [
        'link_ttl_days' => (int) env('TRACEPHARMA_RECALL_BROADCAST_ACK_LINK_TTL_DAYS', 90),
    ],

    /*
    | Asset Tracking initial payload caps — full history remains in Filament tables.
    */
    'tracing' => [
        'initial_direct_events_limit' => (int) env('TRACEPHARMA_TRACING_INITIAL_DIRECT_EVENTS_LIMIT', 100),
        'initial_ancestor_depth_limit' => (int) env('TRACEPHARMA_TRACING_INITIAL_ANCESTOR_DEPTH_LIMIT', 3),
        'initial_ancestor_events_limit' => (int) env('TRACEPHARMA_TRACING_INITIAL_ANCESTOR_EVENTS_LIMIT', 50),
        'initial_timeline_events_limit' => (int) env('TRACEPHARMA_TRACING_INITIAL_TIMELINE_EVENTS_LIMIT', 100),
    ],
];
