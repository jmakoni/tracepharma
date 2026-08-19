<?php

namespace App\Support\Marketing;

class TermsOfService
{
    public static function legalEntityName(): string
    {
        return 'Vatengi Systems LLC';
    }

    public static function productName(): string
    {
        return 'TracePharma';
    }

    public static function supportContactEmail(): string
    {
        return config('tracepharma.platform_support_email', 'support@tracepharma.io');
    }

    public static function legalContactEmail(): string
    {
        return config('tracepharma.legal_contact_email', 'legal@tracepharma.io');
    }

    public static function privacyContactEmail(): string
    {
        return config('tracepharma.privacy_contact_email', 'privacy@tracepharma.io');
    }

    public static function governingLawState(): string
    {
        return 'Illinois';
    }

    public static function effectiveDate(): string
    {
        return 'July 10, 2026';
    }

    public static function version(): string
    {
        return '1.0';
    }

    public static function copyrightNotice(): string
    {
        return '© '.date('Y').' '.self::legalEntityName().'. '.self::productName().' is a service of '.self::legalEntityName().'.';
    }

    public static function productAttribution(): string
    {
        return self::productName().' is a product of '.self::legalEntityName().'.';
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
        $company = self::legalEntityName();
        $product = self::productName();
        $legalEmail = self::legalContactEmail();
        $supportEmail = self::supportContactEmail();
        $governingState = self::governingLawState();

        return [
            [
                'id' => 'agreement',
                'title' => '1. Agreement',
                'paragraphs' => [
                    'These Terms of Service ("Terms") govern access to and use of the '.$product.' cloud platform, websites, APIs, and related services (collectively, the "Service") provided by '.$company.' ("we", "us", or "our").',
                    'By creating an account, accessing a tenant workspace, clicking to accept, or using the Service, you ("Customer", "you", or "your") agree to these Terms on behalf of yourself and the organization you represent. If you do not agree, do not use the Service.',
                    'If you enter into a separate written order form, master subscription agreement, or statement of work with us ("Order Form"), that Order Form controls where it conflicts with these Terms on commercial topics such as fees, term length, or service levels.',
                ],
            ],
            [
                'id' => 'definitions',
                'title' => '2. Definitions',
                'paragraphs' => [
                    '"Authorized User" means an individual permitted by Customer to access the Service under Customer\'s subscription.',
                    '"Customer Data" means information, files, and records submitted to or generated within the Service by or on behalf of Customer, including trading-partner master data, user accounts, configuration, and operational records.',
                    '"EPCIS Data" means Electronic Product Code Information Services event documents, acknowledgments, verification requests and responses, and related serialization or traceability payloads processed through the Service.',
                    '"Tenant" means the isolated workspace and database provisioned for Customer under multi-tenant architecture.',
                ],
            ],
            [
                'id' => 'service',
                'title' => '3. The Service',
                'paragraphs' => [
                    'The '.$product.' Service provides software for US pharmaceutical supply-chain traceability workflows, including EPCIS ingest and outbound exchange, receiving and shipping accountability, serial verification, exception investigation, and compliance reporting. Features available to a Tenant depend on the subscription package and tenant profile configured for Customer.',
                    'We may improve, modify, or discontinue features from time to time. We will use commercially reasonable efforts to avoid material degradation of core subscribed functionality during a paid term, except for maintenance, security updates, or changes required by law, standards bodies, or third-party networks.',
                    'The Service is a software platform. It does not replace your ERP, WMS, pharmacy management system, corporate serialization system, legal counsel, or quality/compliance function. Customer remains responsible for its own operational and regulatory decisions.',
                ],
            ],
            [
                'id' => 'accounts',
                'title' => '4. Accounts and security',
                'paragraphs' => [
                    'Customer is responsible for maintaining accurate account information, assigning roles appropriately, and ensuring Authorized Users comply with these Terms.',
                    'Customer must safeguard credentials, API keys, certificates, and integration secrets. Notify us promptly at '.$legalEmail.' if you suspect unauthorized access.',
                    'We may suspend or restrict access to protect the Service, other customers, or if we reasonably believe these Terms have been violated.',
                ],
            ],
            [
                'id' => 'customer-responsibilities',
                'title' => '5. Customer responsibilities',
                'paragraphs' => [
                    'Customer is solely responsible for:',
                ],
                'bullets' => [
                    'Determining whether use of the Service satisfies Customer\'s DSCSA, FDA, state-board, and contractual obligations.',
                    'Accuracy and legality of Customer Data, trading-partner authorizations, and outbound EPCIS or verification traffic.',
                    'Maintaining compatible integrations, network connectivity, certificates, and upstream/downstream systems of record.',
                    'Training personnel, validating workflows before production cutover, and retaining records required by applicable law.',
                    'Promptly investigating exceptions, quarantines, suspect product events, and verification failures surfaced by the Service.',
                ],
            ],
            [
                'id' => 'dscsa',
                'title' => '6. DSCSA and regulatory disclaimer',
                'paragraphs' => [
                    'The Service is designed to support interoperable traceability workflows commonly used under the US Drug Supply Chain Security Act (DSCSA) and related industry standards such as GS1 EPCIS. We do not warrant that use of the Service alone achieves legal or regulatory compliance.',
                    'Nothing in the Service or these Terms constitutes legal, regulatory, or professional compliance advice. Customer must consult qualified counsel and compliance professionals regarding its obligations, including transaction information, transaction history, transaction statements, verification, saleable returns, and FDA reporting.',
                    'Customer acknowledges that standards, FDA guidance, trading-partner requirements, and network certification programs (including VRS and future Pulse-related programs) evolve. Customer is responsible for monitoring changes that affect its operations.',
                ],
            ],
            [
                'id' => 'data',
                'title' => '7. Customer Data and EPCIS Data',
                'paragraphs' => [
                    'As between the parties, Customer retains all rights in Customer Data and EPCIS Data. Customer grants us a limited license to host, process, transmit, display, and back up Customer Data and EPCIS Data solely to provide, secure, and support the Service and as otherwise described in our Privacy Policy at '.route('marketing.privacy', absolute: true).'.',
                    'Customer represents that it has all rights and consents necessary to submit Customer Data and EPCIS Data to the Service, including data received from trading partners and systems integrators.',
                    'We implement administrative, technical, and organizational safeguards appropriate to a regulated B2B SaaS platform. No method of transmission or storage is completely secure; Customer accepts residual risk inherent in cloud services.',
                ],
            ],
            [
                'id' => 'acceptable-use',
                'title' => '8. Acceptable use',
                'paragraphs' => [
                    'Customer will not, and will not permit others to:',
                ],
                'bullets' => [
                    'Use the Service in violation of law or third-party rights.',
                    'Upload malware, attempt unauthorized access, probe or scan systems without permission, or interfere with Service integrity or performance.',
                    'Misrepresent product identity, serial numbers, locations, or transaction statements.',
                    'Reverse engineer the Service except to the limited extent prohibited restrictions are unenforceable under applicable law.',
                    'Resell, sublicense, or provide the Service to third parties except as expressly permitted in an Order Form.',
                ],
            ],
            [
                'id' => 'fees',
                'title' => '9. Fees and payment',
                'paragraphs' => [
                    'Fees, billing frequency, and included usage are set forth in the applicable Order Form or pricing agreement. Unless stated otherwise, fees are non-refundable and exclusive of taxes, which Customer is responsible for where applicable.',
                    'We may change list pricing for new subscriptions or renewals upon notice. Continued use after a renewal term at updated pricing constitutes acceptance unless Customer terminates in accordance with the Order Form.',
                ],
            ],
            [
                'id' => 'confidentiality',
                'title' => '10. Confidentiality',
                'paragraphs' => [
                    'Each party may receive non-public information from the other that is designated confidential or that reasonably should be understood as confidential ("Confidential Information"). The receiving party will use Confidential Information only to perform under these Terms and will protect it with at least reasonable care.',
                    'Confidential Information does not include information that is public through no fault of the receiving party, independently developed without use of Confidential Information, or rightfully received from a third party without restriction.',
                ],
            ],
            [
                'id' => 'ip',
                'title' => '11. Intellectual property',
                'paragraphs' => [
                    'We and our licensors own the Service, software, documentation, branding, and all related intellectual property rights. No rights are granted except as expressly stated in these Terms.',
                    'Customer may provide feedback or suggestions. We may use feedback without restriction and without obligation to Customer.',
                ],
            ],
            [
                'id' => 'third-parties',
                'title' => '12. Third-party services',
                'paragraphs' => [
                    'The Service may interoperate with third-party networks, certificate authorities, cloud infrastructure, communication providers, and customer systems. We are not responsible for third-party services, their availability, or their terms.',
                    'Outbound delivery of EPCIS, AS2, SFTP, HTTPS callbacks, and verification routing depends on Customer configuration and external endpoints operated by Customer or its trading partners. We do not guarantee partner acceptance, network certification status, or error-free transmission beyond commercially reasonable efforts described in an applicable Order Form.',
                ],
            ],
            [
                'id' => 'disclaimers',
                'title' => '13. Disclaimers',
                'paragraphs' => [
                    'THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE." TO THE MAXIMUM EXTENT PERMITTED BY LAW, WE DISCLAIM ALL WARRANTIES, WHETHER EXPRESS, IMPLIED, STATUTORY, OR OTHERWISE, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND NON-INFRINGEMENT.',
                    'WITHOUT LIMITING THE FOREGOING, WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, COMPLETE, OR THAT EPCIS PROCESSING, VERIFICATION RESULTS, OR COMPLIANCE OUTPUTS WILL MEET CUSTOMER\'S REGULATORY OR BUSINESS REQUIREMENTS.',
                ],
            ],
            [
                'id' => 'liability',
                'title' => '14. Limitation of liability',
                'paragraphs' => [
                    'TO THE MAXIMUM EXTENT PERMITTED BY LAW, NEITHER PARTY WILL BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, COVER, OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, LOST REVENUE, LOST DATA, OR BUSINESS INTERRUPTION, EVEN IF ADVISED OF THE POSSIBILITY.',
                    'EXCEPT FOR CUSTOMER\'S PAYMENT OBLIGATIONS OR A PARTY\'S BREACH OF SECTION 10 (CONFIDENTIALITY) OR SECTION 8 (ACCEPTABLE USE), EACH PARTY\'S AGGREGATE LIABILITY ARISING OUT OF OR RELATED TO THESE TERMS WILL NOT EXCEED THE AMOUNTS PAID OR PAYABLE BY CUSTOMER TO US FOR THE SERVICE IN THE TWELVE (12) MONTHS BEFORE THE EVENT GIVING RISE TO LIABILITY.',
                    'Some jurisdictions do not allow certain limitations; in those cases, the above limits apply to the fullest extent permitted by law.',
                ],
            ],
            [
                'id' => 'indemnification',
                'title' => '15. Indemnification',
                'paragraphs' => [
                    'Customer will defend, indemnify, and hold harmless '.$company.' and its affiliates, officers, directors, and employees from third-party claims arising out of Customer Data, EPCIS Data, Customer\'s use of the Service in violation of law or these Terms, or Customer\'s trading-partner disputes, except to the extent caused by our gross negligence or willful misconduct.',
                    'We will promptly notify Customer of any claim subject to indemnification and cooperate at Customer\'s expense. Customer may not settle a claim in a manner that admits fault by '.$company.' or imposes obligations on us without our prior written consent.',
                ],
            ],
            [
                'id' => 'term',
                'title' => '16. Term and termination',
                'paragraphs' => [
                    'These Terms remain effective while Customer uses the Service or until terminated. Subscription start and end dates are governed by the Order Form where applicable.',
                    'Either party may terminate for material breach if the breach is not cured within thirty (30) days after written notice. We may suspend or terminate immediately for non-payment, security risk, or violations of Section 8.',
                    'Upon termination, Customer\'s access will cease. We will make Customer Data available for export for a reasonable period upon request, subject to backup retention schedules and legal holds, after which we may delete Tenant data unless otherwise required by law or an Order Form.',
                ],
            ],
            [
                'id' => 'audit',
                'title' => '17. Audit and records',
                'paragraphs' => [
                    'The Service provides operational and compliance reports intended to support Customer\'s internal audits and traceability investigations. Customer remains responsible for record retention periods required by DSCSA, FDA, state law, and its quality agreements.',
                    'If an Order Form includes audit or validation support, those services are provided only as expressly stated therein and do not expand our warranties in Section 13.',
                ],
            ],
            [
                'id' => 'changes',
                'title' => '18. Changes to these Terms',
                'paragraphs' => [
                    'We may update these Terms from time to time. We will post the revised Terms on this page and update the effective date. Material changes will be notified through the Service, by email to account owners, or by other reasonable means.',
                    'Continued use after the effective date of revised Terms constitutes acceptance. If Customer does not agree, Customer must stop using the Service and may terminate in accordance with Section 16.',
                ],
            ],
            [
                'id' => 'general',
                'title' => '19. General',
                'paragraphs' => [
                    'These Terms are governed by the laws of the State of '.$governingState.', USA, excluding conflict-of-law rules. The parties consent to exclusive jurisdiction and venue in the state or federal courts located in '.$governingState.', except that either party may seek injunctive relief in any court of competent jurisdiction.',
                    'If any provision is held unenforceable, the remaining provisions remain in effect. Failure to enforce a provision is not a waiver. Customer may not assign these Terms without our consent; we may assign in connection with a merger, acquisition, or sale of assets.',
                    'Notices to '.$company.' should be sent to '.$legalEmail.'. Notices to Customer may be sent to account owner email addresses on file.',
                ],
            ],
            [
                'id' => 'contact',
                'title' => '20. Questions',
                'paragraphs' => [
                    'Questions about these Terms or formal legal notices may be directed to '.$legalEmail.'. For product support, contact '.$supportEmail.'. For commercial or demo inquiries, use the contact page on our website.',
                ],
            ],
        ];
    }
}
