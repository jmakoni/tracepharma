<?php

namespace App\Support\Marketing;

class PrivacyPolicy
{
    public static function effectiveDate(): string
    {
        return TermsOfService::effectiveDate();
    }

    public static function version(): string
    {
        return TermsOfService::version();
    }

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     paragraphs: list<string>,
     *     bullets?: list<string>
     * }>
     */
    public static function sections(): array
    {
        $company = TermsOfService::legalEntityName();
        $product = TermsOfService::productName();
        $privacyEmail = TermsOfService::privacyContactEmail();
        $legalEmail = TermsOfService::legalContactEmail();
        $supportEmail = TermsOfService::supportContactEmail();
        $privacyUrl = route('marketing.privacy', absolute: true);
        $tosUrl = route('marketing.tos', absolute: true);

        return [
            [
                'id' => 'introduction',
                'title' => '1. Introduction',
                'paragraphs' => [
                    $company.' ("we", "us", or "our") respects your privacy. This Privacy Policy explains how we collect, use, disclose, and protect personal information when you visit our marketing websites, request a demo, create an account, or use the '.$product.' cloud platform and related services (collectively, the "Service").',
                    'This Privacy Policy supplements our Terms of Service at '.$tosUrl.'. If you do not agree with this Privacy Policy, please do not use the Service.',
                    'We may update this Privacy Policy from time to time. Material changes will be posted on this page with an updated effective date.',
                ],
            ],
            [
                'id' => 'who-we-are',
                'title' => '2. Who we are and how to contact us',
                'paragraphs' => [
                    'The Service is operated by '.$company.'. '.$product.' is a trade name of '.$company.'.',
                    'For privacy-specific requests (access, correction, deletion, or marketing opt-out), contact us at '.$privacyEmail.'.',
                    'For legal notices, contact '.$legalEmail.'. For product support, contact '.$supportEmail.'.',
                ],
            ],
            [
                'id' => 'scope',
                'title' => '3. Scope of this policy',
                'paragraphs' => [
                    'This Privacy Policy covers personal information we collect about identifiable individuals—such as account holders, demo requestors, and marketing-site visitors.',
                    'When customers use the '.$product.' platform to process supply-chain traceability data (including EPCIS events, serial numbers, lot data, trading-partner records, and shipment information), that information is generally Customer Data processed on behalf of the customer organization. Our handling of Customer Data in the Service is also governed by our Terms of Service and any data processing terms in your Order Form. This Privacy Policy focuses on personal information about users and website visitors, not on de-identified or purely operational product data except where noted below.',
                ],
            ],
            [
                'id' => 'what-we-collect',
                'title' => '4. Personal information we collect',
                'paragraphs' => [
                    'Depending on how you interact with us, we may collect:',
                ],
                'bullets' => [
                    'Identifiers and contact details: name, business email address, phone number, company name, job title, and mailing address.',
                    'Account and authentication data: username, role assignments, login timestamps, password hashes, and multi-factor authentication metadata.',
                    'Demo and sales inquiries: information you submit through demo request forms, contact pages, or email correspondence.',
                    'Usage and device data: IP address, browser type, operating system, pages viewed, referring URL, session duration, and similar analytics from our websites and authenticated application.',
                    'Support and communications: content of support tickets, exception escalations, and email or in-app messages you send to us.',
                    'Integration configuration metadata: connection endpoints, certificate identifiers, and webhook URLs that may contain business contact information.',
                ],
            ],
            [
                'id' => 'how-we-use',
                'title' => '5. How we use personal information',
                'paragraphs' => [
                    'We use personal information to:',
                ],
                'bullets' => [
                    'Provide, operate, secure, and improve the Service.',
                    'Create and administer tenant workspaces and user accounts.',
                    'Respond to demo requests, sales inquiries, and support questions.',
                    'Send service-related notices (security alerts, product changes, billing, and account administration).',
                    'Monitor platform performance, troubleshoot errors, and prevent fraud or abuse.',
                    'Comply with law, enforce our Terms of Service, and protect the rights and safety of '.$company.', our customers, and others.',
                ],
            ],
            [
                'id' => 'customer-data',
                'title' => '6. Customer Data and EPCIS processing',
                'paragraphs' => [
                    'Customers may upload or transmit traceability and supply-chain data through the Service. In those cases, '.$company.' typically acts as a service provider processing data on behalf of the customer. The customer organization controls what data is submitted and is responsible for providing any required notices and obtaining any required consents from its personnel and trading partners.',
                    'We process Customer Data and EPCIS payloads only to deliver the Service, provide support, maintain backups, and as otherwise instructed by the customer or required by applicable law.',
                    'Personal information contained within Customer Data (for example, operator names in audit logs) is handled under the customer\'s instructions and our contractual obligations, in addition to the safeguards described in this Privacy Policy.',
                ],
            ],
            [
                'id' => 'disclosures',
                'title' => '7. How we share personal information',
                'paragraphs' => [
                    'We do not sell personal information. We may share personal information with:',
                ],
                'bullets' => [
                    'Service providers and subprocessors that help us host infrastructure, send email, provide analytics, deliver customer support, or perform security monitoring—under contracts requiring appropriate data protection.',
                    'Professional advisors (lawyers, accountants, insurers) under confidentiality obligations.',
                    'Trading partners or integration endpoints only when you or your organization configure outbound connectivity; we do not independently disclose your personal information to supply-chain partners except as directed through the Service.',
                    'Authorities or other parties when required by law, court order, or to protect rights, safety, and security.',
                    'A successor entity in connection with a merger, acquisition, or sale of assets, subject to this Privacy Policy or a successor policy with notice where required.',
                ],
            ],
            [
                'id' => 'rights',
                'title' => '8. Your privacy rights',
                'paragraphs' => [
                    'Depending on where you live, you may have rights to access, correct, delete, or obtain a copy of personal information we hold about you; to restrict or object to certain processing; and to withdraw consent where processing is consent-based.',
                    'To exercise these rights, email '.$privacyEmail.' with the subject line "Privacy rights request". We may need to verify your identity before responding. If your information is held on behalf of an employer-customer, we may direct you to contact your organization\'s administrator.',
                    'We will not discriminate against you for exercising privacy rights afforded by applicable law.',
                ],
            ],
            [
                'id' => 'transfers',
                'title' => '9. Data storage and transfers',
                'paragraphs' => [
                    'We primarily serve US pharmaceutical supply-chain customers. Personal information may be stored and processed in the United States and in other countries where we or our service providers operate.',
                    'When we transfer personal information internationally, we implement appropriate safeguards as required by applicable law.',
                ],
            ],
            [
                'id' => 'legal-bases',
                'title' => '10. Legal bases for processing',
                'paragraphs' => [
                    'Where applicable privacy laws require a legal basis, we rely on one or more of the following: performance of a contract with you or your organization; legitimate interests in operating and improving the Service (balanced against your rights); compliance with legal obligations; and consent where required (for example, certain marketing communications).',
                ],
            ],
            [
                'id' => 'security',
                'title' => '11. Security',
                'paragraphs' => [
                    'We implement administrative, technical, and organizational measures designed to protect personal information, including access controls, encryption in transit, tenant isolation, and monitoring. No method of transmission or storage is completely secure; we cannot guarantee absolute security.',
                    'Voluntary feedback, comments, or ideas you submit through public contact channels may not be treated as confidential unless we agree otherwise in writing.',
                ],
            ],
            [
                'id' => 'retention',
                'title' => '12. How long we keep information',
                'paragraphs' => [
                    'We retain personal information for as long as needed to provide the Service, maintain business records, comply with legal obligations, resolve disputes, and enforce agreements.',
                    'Marketing and demo inquiry records are generally retained for a limited period unless you become a customer or request deletion sooner. Account data is retained while your tenant is active and for a reasonable period afterward, consistent with our Terms of Service and backup schedules.',
                    'Contact '.$privacyEmail.' for questions about retention.',
                ],
            ],
            [
                'id' => 'cookies',
                'title' => '13. Cookies and similar technologies',
                'paragraphs' => [
                    'Our websites and authenticated application may use cookies, local storage, and similar technologies for session management, security, preferences, and analytics.',
                    'Strictly necessary cookies support login sessions and platform security. Where optional analytics or marketing cookies are used, we will obtain consent when required by law.',
                    'You can control cookies through your browser settings. Disabling certain cookies may affect Service functionality.',
                ],
            ],
            [
                'id' => 'marketing',
                'title' => '14. Marketing communications',
                'paragraphs' => [
                    'With your consent where required, we may send information about '.$product.' features, events, or offers by email. You may opt out at any time by using the unsubscribe link in a message or emailing '.$privacyEmail.' with the subject line "Unsubscribe from marketing".',
                    'We will continue to send non-marketing service and security communications related to your account even if you opt out of marketing.',
                ],
            ],
            [
                'id' => 'links',
                'title' => '15. Links to other websites',
                'paragraphs' => [
                    'The Service may link to third-party websites (for example, FDA references, partner documentation, or integration vendors). We are not responsible for the privacy practices of those sites. Review their privacy policies before providing personal information.',
                ],
            ],
            [
                'id' => 'children',
                'title' => '16. Children\'s privacy',
                'paragraphs' => [
                    'The Service is intended for business users and is not directed to individuals under 18. We do not knowingly collect personal information from children. If you believe a child has provided information to us, contact '.$privacyEmail.' and we will take appropriate steps to delete it.',
                ],
            ],
            [
                'id' => 'california',
                'title' => '17. California privacy rights',
                'paragraphs' => [
                    'If you are a California resident, you may have additional rights under the California Consumer Privacy Act, as amended by the California Privacy Rights Act ("CPRA"), including the right to know categories of personal information collected, request access to or deletion of personal information, and correct inaccurate personal information.',
                    'We do not sell or share personal information for cross-context behavioral advertising as defined under CPRA.',
                    'To submit a verifiable request, email '.$privacyEmail.' with the subject line "California privacy request". We will respond within the timeframes required by California law. You may designate an authorized agent to submit requests on your behalf where permitted by law.',
                ],
            ],
            [
                'id' => 'virginia',
                'title' => '18. Virginia privacy rights',
                'paragraphs' => [
                    'If you are a Virginia resident, you may have rights under the Virginia Consumer Data Protection Act ("VCDPA"), including rights to access, correct, delete, and obtain a copy of personal information we process about you.',
                    'Submit requests to '.$privacyEmail.' with the subject line "Virginia privacy request". We will authenticate your request and respond as required by Virginia law.',
                ],
            ],
            [
                'id' => 'changes',
                'title' => '19. Changes to this Privacy Policy',
                'paragraphs' => [
                    'We may revise this Privacy Policy by posting an updated version at '.$privacyUrl.'. If we make material changes, we will provide additional notice through the Service or by email where appropriate.',
                    'Your continued use of the Service after the effective date of an updated Privacy Policy constitutes acceptance, unless otherwise required by law.',
                ],
            ],
            [
                'id' => 'contact',
                'title' => '20. Questions',
                'paragraphs' => [
                    'Questions about this Privacy Policy may be directed to '.$privacyEmail.'.',
                    'For Terms of Service inquiries, contact '.$legalEmail.'. For product support, contact '.$supportEmail.'.',
                ],
            ],
        ];
    }
}
