<?php

namespace App\Support\Mail;

use InvalidArgumentException;

class MailTemplateCatalog
{
    public const OnboardingAcknowledgment = 'customer_onboarding.acknowledgment';

    public const OnboardingReceived = 'customer_onboarding.received';

    public const DemoAcknowledgment = 'demo_request.acknowledgment';

    public const DemoReceived = 'demo_request.received';

    public const TenantProvisionedOwner = 'tenant.provisioned.owner';

    public const TenantProvisionedReceived = 'tenant.provisioned.received';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, MailTemplateDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::OnboardingAcknowledgment => new MailTemplateDefinition(
                key: self::OnboardingAcknowledgment,
                label: 'Customer onboarding — applicant acknowledgment',
                variables: ['first_name', 'company_display_name', 'contact_email'],
                recipients: ['applicant'],
                defaultSubject: 'We received your TracePharma application',
                defaultGreeting: 'Hi {{ first_name }},',
                defaultBody: implode("\n", [
                    'Thank you for applying to TracePharma. We received your submission for **{{ company_display_name }}** and will follow up at **{{ contact_email }}** after our team reviews your request.',
                    '**What happens next**',
                    '1. **Application review** — We verify your organization type, GLN if provided, and contracting readiness.',
                    '2. **Tenant provisioning** — After approval, we create your tenant host with the right profile and navigation.',
                    '3. **First login** — Your owner account signs in and completes in-app DSCSA setup.',
                    'If you need to add context before we connect, reply to this email.',
                ]),
                defaultSalutation: '— The TracePharma team',
                fixtures: [
                    'first_name' => 'Alex',
                    'company_display_name' => 'Example Pharmacy',
                    'contact_email' => 'alex@example-pharmacy.test',
                ],
            ),
            self::OnboardingReceived => new MailTemplateDefinition(
                key: self::OnboardingReceived,
                label: 'Customer onboarding — ops inbox',
                variables: [
                    'legal_company_name',
                    'company_display_name',
                    'contact_name',
                    'contact_email',
                    'contact_phone',
                    'contact_role',
                    'organization_type',
                    'gln',
                    'message',
                    'terms_version',
                    'privacy_version',
                ],
                recipients: ['ops_inbox'],
                defaultSubject: 'New TracePharma customer application — {{ company_display_name }}',
                defaultGreeting: 'New get-started application',
                defaultBody: implode("\n", [
                    'Legal company: {{ legal_company_name }}',
                    'Display name: {{ company_display_name }}',
                    'Contact: {{ contact_name }}',
                    'Email: {{ contact_email }}',
                    'Phone: {{ contact_phone }}',
                    'Role: {{ contact_role }}',
                    'Organization type: {{ organization_type }}',
                    'GLN: {{ gln }}',
                    'Message: {{ message }}',
                    'Terms version: {{ terms_version }}',
                    'Privacy version: {{ privacy_version }}',
                ]),
                defaultSalutation: null,
                fixtures: [
                    'legal_company_name' => 'Example Pharmacy LLC',
                    'company_display_name' => 'Example Pharmacy',
                    'contact_name' => 'Alex Rivera',
                    'contact_email' => 'alex@example-pharmacy.test',
                    'contact_phone' => '555-0100',
                    'contact_role' => 'Owner',
                    'organization_type' => 'Independent pharmacy',
                    'gln' => '0614141000005',
                    'message' => 'Ready to start DSCSA receiving.',
                    'terms_version' => '2026-01',
                    'privacy_version' => '2026-01',
                ],
            ),
            self::DemoAcknowledgment => new MailTemplateDefinition(
                key: self::DemoAcknowledgment,
                label: 'Demo request — requester acknowledgment',
                variables: ['first_name', 'company', 'email', 'solution_label', 'solution_url'],
                recipients: ['applicant'],
                defaultSubject: 'We received your TracePharma demo request',
                defaultGreeting: 'Hi {{ first_name }},',
                defaultBody: implode("\n", [
                    'Thank you for requesting a TracePharma demo. We received your submission for **{{ company }}** and will follow up at **{{ email }}** to schedule a walkthrough.',
                    '**What happens next**',
                    '1. **Demo scheduling** — We confirm your operating profile and book a 30–45 minute walkthrough.',
                    '2. **Tenant provisioning** — After the demo, your workspace is tuned to your profile with the right navigation and feature gates.',
                    '3. **In-app onboarding** — Set your organization GLN, add trading partners, configure inbound channels, and run a test receive.',
                    '4. **Scoping & proposal** — We document partner count, sites, and modules, then send packaging options.',
                    'If you need to add context before we connect, reply to this email.',
                ]),
                defaultSalutation: '— The TracePharma team',
                defaultActionLabel: 'Explore {{ solution_label }} workflows',
                defaultActionUrl: '{{ solution_url }}',
                fixtures: [
                    'first_name' => 'Alex',
                    'company' => 'Example Pharmacy',
                    'email' => 'alex@example-pharmacy.test',
                    'solution_label' => 'Pharmacies',
                    'solution_url' => 'https://tracepharma.io/solutions/pharmacies',
                ],
            ),
            self::DemoReceived => new MailTemplateDefinition(
                key: self::DemoReceived,
                label: 'Demo request — ops inbox',
                variables: ['name', 'email', 'company', 'phone', 'role', 'organization_type', 'message'],
                recipients: ['ops_inbox'],
                defaultSubject: 'New TracePharma demo request — {{ company }}',
                defaultGreeting: 'New demo request',
                defaultBody: implode("\n", [
                    'Name: {{ name }}',
                    'Email: {{ email }}',
                    'Company: {{ company }}',
                    'Phone: {{ phone }}',
                    'Role: {{ role }}',
                    'Organization type: {{ organization_type }}',
                    'Message: {{ message }}',
                ]),
                defaultSalutation: null,
                fixtures: [
                    'name' => 'Alex Rivera',
                    'email' => 'alex@example-pharmacy.test',
                    'company' => 'Example Pharmacy',
                    'phone' => '555-0100',
                    'role' => 'Owner',
                    'organization_type' => 'independent_pharmacy',
                    'message' => 'Interested in a receiving walkthrough.',
                ],
            ),
            self::TenantProvisionedOwner => new MailTemplateDefinition(
                key: self::TenantProvisionedOwner,
                label: 'Tenant provisioned — owner welcome',
                variables: [
                    'first_name',
                    'tenant_name',
                    'owner_email',
                    'prod_host',
                    'stage_host',
                    'prod_url',
                    'stage_url',
                ],
                recipients: ['applicant'],
                defaultSubject: 'Your TracePharma workspace is ready',
                defaultGreeting: 'Hi {{ first_name }},',
                defaultBody: implode("\n", [
                    'Your **{{ tenant_name }}** workspace is ready. Sign in with **{{ owner_email }}** and the password your administrator set.',
                    '**Production:** {{ prod_url }}',
                    '**Stage:** {{ stage_url }}',
                    'Use production for live DSCSA work. Stage is the paired sandbox.',
                    'If you did not expect this email, contact your TracePharma administrator.',
                ]),
                defaultSalutation: '— The TracePharma team',
                defaultActionLabel: 'Open your workspace',
                defaultActionUrl: '{{ prod_url }}',
                fixtures: [
                    'first_name' => 'Pat',
                    'tenant_name' => 'Example Pharmacy',
                    'owner_email' => 'pat@example-pharmacy.test',
                    'prod_host' => 'example.prod.tracepharma.io',
                    'stage_host' => 'example.stage.tracepharma.io',
                    'prod_url' => 'https://example.prod.tracepharma.io',
                    'stage_url' => 'https://example.stage.tracepharma.io',
                ],
            ),
            self::TenantProvisionedReceived => new MailTemplateDefinition(
                key: self::TenantProvisionedReceived,
                label: 'Tenant provisioned — ops inbox',
                variables: [
                    'tenant_name',
                    'slug',
                    'profile',
                    'owner_name',
                    'owner_email',
                    'prod_host',
                    'stage_host',
                ],
                recipients: ['ops_inbox'],
                defaultSubject: 'Tenant provisioned — {{ tenant_name }}',
                defaultGreeting: 'A tenant pair was created',
                defaultBody: implode("\n", [
                    'Tenant: {{ tenant_name }}',
                    'Slug: {{ slug }}',
                    'Profile: {{ profile }}',
                    'Owner: {{ owner_name }}',
                    'Owner email: {{ owner_email }}',
                    'Production: {{ prod_host }}',
                    'Stage: {{ stage_host }}',
                ]),
                defaultSalutation: null,
                fixtures: [
                    'tenant_name' => 'Example Pharmacy',
                    'slug' => 'example',
                    'profile' => 'Pharmacy',
                    'owner_name' => 'Pat Owner',
                    'owner_email' => 'pat@example-pharmacy.test',
                    'prod_host' => 'example.prod.tracepharma.io',
                    'stage_host' => 'example.stage.tracepharma.io',
                ],
            ),
        ];
    }

    public static function get(string $key): MailTemplateDefinition
    {
        $definition = self::definitions()[$key] ?? null;

        if (! $definition instanceof MailTemplateDefinition) {
            throw new InvalidArgumentException('Unknown mail template key: '.$key);
        }

        return $definition;
    }
}
