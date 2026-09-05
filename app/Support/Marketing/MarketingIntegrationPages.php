<?php

namespace App\Support\Marketing;

class MarketingIntegrationPages
{
    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     name: string,
     *     category: string,
     *     pulse_listed: bool,
     *     preset: ?string,
     *     transports: list<string>,
     *     meta_description: string,
     *     hero_description: string,
     *     summary: string,
     *     inbound: list<string>,
     *     outbound: list<string>,
     *     cutover: list<string>,
     *     best_for: list<string>,
     *     compare_route: ?string,
     *     faq: list<array{question: string, answer: string}>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'tracelink' => [
                'slug' => 'tracelink',
                'name' => 'TraceLink',
                'category' => 'Serialization platform',
                'pulse_listed' => true,
                'preset' => 'tracelink',
                'transports' => ['AS2', 'SFTP'],
                'meta_description' => 'Connect TracePharma to TraceLink Opus partners via AS2 or SFTP. Receive EPCIS, ship outbound, and monitor ACK health without Opus network fees.',
                'hero_description' => 'TracePharma interoperates with TraceLink-connected trading partners using tenant-scoped AS2 or SFTP presets—ideal when manufacturers or wholesalers already standardize on Opus but you want direct L4 operator UX.',
                'summary' => 'TraceLink Opus is the incumbent network for many global manufacturers and mega-wholesalers. TracePharma does not replace Opus on the partner side. You receive and send standards-based EPCIS to TraceLink endpoints your partners configure—or through an Axway gateway when required.',
                'inbound' => [
                    'AS2 receive with signed MDN for EPCIS 1.2 XML from TraceLink-connected suppliers.',
                    'SFTP polling when partners drop shipment files to your tenant inbox.',
                    'Partner adapter normalizes TraceLink envelopes into your L4 receiving workflow.',
                ],
                'outbound' => [
                    'Generate DSCSA-compliant outbound EPCIS for downstream customers on TraceLink.',
                    'Monitor customer ACK health and surface failed deliveries in exceptions.',
                    'REST API and outbound webhooks for WMS/ERP automation alongside EPCIS transport.',
                ],
                'cutover' => [
                    'Identify which partners send via AS2 vs SFTP on TraceLink.',
                    'Create inbound connections with the TraceLink serialization provider preset in onboarding.',
                    'Exchange AS2 IDs and certificates—or SFTP credentials—with each partner.',
                    'Upload a sample TraceLink shipment on Receiving before production cutover.',
                ],
                'best_for' => [
                    'Regional wholesalers receiving from TraceLink manufacturers.',
                    'Mid-market manufacturers shipping to TraceLink wholesalers.',
                    'Teams leaving Opus network economics but keeping known partner transport paths.',
                ],
                'compare_route' => 'marketing.compare.tracelink',
                'faq' => [
                    [
                        'question' => 'Does TracePharma require TraceLink Opus network enrollment?',
                        'answer' => 'No. TracePharma connects directly to partners you already know via AS2 or SFTP per tenant. You do not need Opus network transaction fees to run your L4 hub on TracePharma.',
                    ],
                    [
                        'question' => 'Can TracePharma receive from an Axway gateway in front of TraceLink?',
                        'answer' => 'Yes. Many deployments use Axway as middleware. TracePharma supports AS2 natively and HTTPS webhook patterns when a gateway forwards EPCIS to your tenant endpoint.',
                    ],
                ],
            ],
            'lspedia' => [
                'slug' => 'lspedia',
                'name' => 'LSPedia',
                'category' => 'Serialization platform',
                'pulse_listed' => true,
                'preset' => 'lspedia',
                'transports' => ['HTTPS'],
                'meta_description' => 'Interoperate TracePharma with LSPedia OneScan partners via HTTPS EPCIS webhooks. Direct L4 connectivity without mandatory Exchange network enrollment.',
                'hero_description' => 'Receive EPCIS from LSPedia-connected wholesalers and manufacturers through HTTPS inbound presets—while running operator-first receiving, exceptions, and compliance on TracePharma.',
                'summary' => 'LSPedia OneScan and Exchange serve many US trading partners. TracePharma interoperates via HTTPS webhook when partners push EPCIS to your tenant. Use it alongside—or instead of—a OneScan module for US L4 workflows you actually operate.',
                'inbound' => [
                    'HTTPS inbound webhook with tenant-scoped token authentication.',
                    'EPCIS 1.2 XML and opt-in 2.0 JSON-LD capture into unified receiving.',
                    '3T matching and exception surfacing before inventory acceptance.',
                ],
                'outbound' => [
                    'Outbound EPCIS generation for pharmacies, wholesalers, and 3PL customers.',
                    'Partner-specific routing and ACK monitoring per connection.',
                    'EPCIS 1.2 GA event repository; 2.0 JSON-LD opt-in for investigation (not full CBV 2.0 subscriptions).',
                ],
                'cutover' => [
                    'Confirm whether partners push via LSPedia Exchange or direct HTTPS.',
                    'Create an inbound connection with the LSPedia serialization provider preset.',
                    'Share your webhook URL and inbound token from Integrations.',
                    'Run a test file from Onboarding or Receiving before go-live.',
                ],
                'best_for' => [
                    'Wholesalers and 3PLs receiving from LSPedia manufacturers.',
                    'Buyers evaluating TracePharma vs OneScan who want to keep partner HTTPS paths.',
                    'Multi-profile tenants needing US L4 depth without global module sprawl.',
                ],
                'compare_route' => 'marketing.compare.lspedia',
                'faq' => [
                    [
                        'question' => 'Do I need LSPedia Exchange to use TracePharma?',
                        'answer' => 'No. TracePharma connects known partners directly. Exchange is optional on the partner side; your cutover focuses on re-pointing EPCIS delivery to TracePharma endpoints.',
                    ],
                    [
                        'question' => 'Is this the same as running LSPedia OneScan?',
                        'answer' => 'No. TracePharma is your L4 hub. LSPedia interoperability means we ingest and send EPCIS with partners already on OneScan—not that you must license OneScan modules.',
                    ],
                ],
            ],
            'infinitrak' => [
                'slug' => 'infinitrak',
                'name' => 'InfiniTrak',
                'category' => 'Pharmacy platform',
                'pulse_listed' => true,
                'preset' => 'infinitrak',
                'transports' => ['HTTPS'],
                'meta_description' => 'TracePharma + InfiniTrak via HTTPS: EPCIS receiving, VRS, and exceptions when you outgrow verify-only or run multi-site dispenser ops.',
                'hero_description' => 'Pharmacies and buying groups often start on InfiniTrak for turnkey onboarding. TracePharma connects via HTTPS preset when you need wholesaler-grade EPCIS depth, investigation tools, and structured exceptions.',
                'summary' => 'InfiniTrak excels at dispenser cutover and PMS ecosystem partnerships. TracePharma interoperates when inbound EPCIS arrives via HTTPS webhook. Migrate receiving and verification to a full L4 workspace while keeping wholesaler relationships.',
                'inbound' => [
                    'HTTPS webhook preset for InfiniTrak-as-provider inbound scenarios.',
                    'EPCIS receiving with scan-first shipment matching at the pharmacy or DC.',
                    'VRS verification workstation and POST /api/v1/dispense-check on the same tenant.',
                ],
                'outbound' => [
                    'Not typically required for independent pharmacies; buying groups may ship internal transfers.',
                    'Compliance exports including FDA 3911 and verification audit trails.',
                    'Exception workflows with supplier correction loops for wholesaler EPCIS gaps.',
                ],
                'cutover' => [
                    'Document wholesaler EPCIS delivery path (InfiniTrak-assisted vs direct).',
                    'Provision TracePharma tenant with Pharmacy or Buying Group profile.',
                    'Create InfiniTrak preset inbound connection and share webhook credentials.',
                    'Validate sample wholesaler shipment before disabling legacy verify-only paths.',
                ],
                'best_for' => [
                    'Independents outgrowing verify-only who keep InfiniTrak wholesaler relationships.',
                    'Buying groups needing member health dashboards plus EPCIS investigation.',
                    'Pharmacies requiring dispense-check gating with full receiving audit trails.',
                ],
                'compare_route' => 'marketing.compare.infinitrak',
                'faq' => [
                    [
                        'question' => 'Can I use TracePharma alongside InfiniTrak?',
                        'answer' => 'Parallel pilots are common during evaluation. Production cutover usually consolidates receiving and verification on one L4 hub to avoid duplicate audit trails.',
                    ],
                    [
                        'question' => 'Will my wholesaler still send EPCIS?',
                        'answer' => 'Yes, after re-pointing delivery to your TracePharma inbound endpoint. Wholesaler SFTP/AS2 presets (Cardinal, McKesson, Cencora) are separate from the InfiniTrak platform preset.',
                    ],
                ],
            ],
            'advasur' => [
                'slug' => 'advasur',
                'name' => 'Advasur',
                'category' => 'Pharmacy platform',
                'pulse_listed' => true,
                'preset' => 'advasur',
                'transports' => ['SFTP'],
                'meta_description' => 'Connect TracePharma to Advasur 360 partners via SFTP EPCIS polling. Pharmacy and light-wholesaler cutover with structured receiving and exceptions.',
                'hero_description' => 'Advasur 360 focuses on guided pharmacy and light-wholesaler onboarding. TracePharma interoperates via SFTP preset when partners drop EPCIS shipment files to your tenant inbox.',
                'summary' => 'Advasur is a Pulse-listed pharmacy compliance platform with partner onboarding services. TracePharma receives Advasur-path EPCIS through SFTP polling. Scale beyond dispenser-only scope with EPCIS event-store depth (1.2 GA; 2.0 capture + query-as-2.0), wholesaler workflows, and multi-site reporting.',
                'inbound' => [
                    'SFTP polling preset with tenant-scoped inbound paths.',
                    'EPCIS 1.2 XML parsing into receiving and 3T matching.',
                    'Exception surfacing when serials or transaction data are missing.',
                ],
                'outbound' => [
                    'Outbound EPCIS for light-wholesaler or secondary DC profiles when required.',
                    'VRS verification and FDA 3911 from the same L4 workspace.',
                    'ACK monitoring for downstream pharmacy customers on distributor profiles.',
                ],
                'cutover' => [
                    'Request Advasur SFTP onboarding details and file schedule from your partner.',
                    'Create inbound connection with Advasur serialization provider preset.',
                    'Configure SFTP host, credentials, and inbound path on Integrations.',
                    'Process a sample Advasur-format file on Receiving before production.',
                ],
                'best_for' => [
                    'Pharmacies migrating from Advasur 360 to full L4 receiving.',
                    'Light wholesalers evaluating structured exceptions vs onboarding-only tooling.',
                    'Teams listed on Pulse who need Advasur-path SFTP without staying on 360 alone.',
                ],
                'compare_route' => 'marketing.compare.advasur',
                'faq' => [
                    [
                        'question' => 'Is TracePharma an Advasur reseller or partner?',
                        'answer' => 'No. TracePharma interoperates at the EPCIS transport layer. You run your L4 hub on TracePharma while partners continue sending standards-based shipment files.',
                    ],
                ],
            ],
            'gateway-checker' => [
                'slug' => 'gateway-checker',
                'name' => 'Gateway Checker',
                'category' => 'Regional connectivity',
                'pulse_listed' => true,
                'preset' => 'gateway_checker',
                'transports' => ['HTTPS'],
                'meta_description' => 'TracePharma interoperates with Gateway Checker via HTTPS EPCIS webhooks—built for regional wholesalers and specialty distributors.',
                'hero_description' => 'Gateway Checker serves regional drug wholesalers and specialty distributors. TracePharma connects through HTTPS preset when partners push EPCIS to your tenant—same receive-to-ship L4 engine as national wholesaler presets.',
                'summary' => 'Gateway Checker is a Pulse-listed US DSCSA vendor focused on regional distribution. TracePharma ingests HTTPS EPCIS from Gateway Checker-connected partners. Run ACK monitoring, exceptions, and outbound ship in one workspace.',
                'inbound' => [
                    'HTTPS inbound webhook with token-authenticated EPCIS capture.',
                    'Scan-first receiving and expected-shipment matching for DC operators.',
                    'Integration with Cardinal, McKesson, and manufacturer feeds on the same tenant.',
                ],
                'outbound' => [
                    'Outbound EPCIS to pharmacy and institutional customers.',
                    'SSCC label generation and consolidated shipment documents.',
                    'Partner ACK health dashboards for compliance reporting.',
                ],
                'cutover' => [
                    'Confirm Gateway Checker delivery uses HTTPS push vs manual upload.',
                    'Create Gateway Checker preset on inbound Integrations.',
                    'Share webhook URL and token with partner onboarding contact.',
                    'Validate sample shipment before decommissioning legacy portal-only storage.',
                ],
                'best_for' => [
                    'Regional wholesalers receiving via Gateway Checker today.',
                    'Specialty distributors needing L4 depth beyond document archive portals.',
                    'Teams comparing Gateway Checker vs operator-first L4 UX.',
                ],
                'compare_route' => 'marketing.compare.gateway-checker',
                'faq' => [
                    [
                        'question' => 'Does TracePharma replace Gateway Checker?',
                        'answer' => 'TracePharma can be your primary L4 hub. Interoperability means partners can push EPCIS to TracePharma using the same HTTPS patterns Gateway Checker supports—often during a planned migration.',
                    ],
                ],
            ],
            'unitrace' => [
                'slug' => 'unitrace',
                'name' => 'UniTrace (Systech)',
                'category' => 'Serialization platform',
                'pulse_listed' => true,
                'preset' => 'unitrace',
                'transports' => ['HTTPS'],
                'meta_description' => 'Interoperate TracePharma with Systech UniTrace partners via HTTPS. Manufacturer and wholesaler EPCIS for US DSCSA L4 workflows.',
                'hero_description' => 'Systech UniTrace powers many manufacturer and brand-protection programs. TracePharma receives UniTrace-path EPCIS over HTTPS while you run US-focused L4 receiving, outbound ship, and exceptions.',
                'summary' => 'Systech is a Pulse-listed global serialization vendor. The TracePharma UniTrace preset supports HTTPS inbound when plant or corporate systems push EPCIS. Keep Guardian at L3—TracePharma runs your L4 corporate hub.',
                'inbound' => [
                    'HTTPS webhook preset with UniTrace-specific settings hints (program ID, facility ID).',
                    'Commissioning forward when manufacturers configure an L3 endpoint in Organization settings.',
                    'EPCIS 1.2 GA capture; opt-in 2.0 JSON-LD and event-store trace search for investigation.',
                ],
                'outbound' => [
                    'Outbound EPCIS to wholesalers and downstream distributors.',
                    'Customer ACK monitoring and saleable return workflows.',
                    'Compliance export API for audit packages.',
                ],
                'cutover' => [
                    'Map UniTrace HTTPS endpoints and credentials with your serialization IT contact.',
                    'Create UniTrace serialization provider preset in Integrations.',
                    'Configure L3 commissioning forward alongside outbound go-live (manufacturers).',
                    'Test commissioning forward to your plant or corporate L3 endpoint.',
                ],
                'best_for' => [
                    'Manufacturers on UniTrace shipping to US wholesalers.',
                    'Wholesalers receiving UniTrace manufacturer EPCIS.',
                    'US-only programs that do not need Systech global hub modules on L4.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Do I need Systech Guardian at L3 to use TracePharma?',
                        'answer' => 'No. TracePharma operates at L4. UniTrace interoperability is for EPCIS handoff between corporate/plant systems and your TracePharma hub—not replacing line-level serialization software.',
                    ],
                ],
            ],
            'axway' => [
                'slug' => 'axway',
                'name' => 'Axway',
                'category' => 'Middleware',
                'pulse_listed' => true,
                'preset' => 'axway',
                'transports' => ['AS2', 'HTTPS'],
                'meta_description' => 'Connect TracePharma through Axway B2B gateways—AS2 receive or HTTPS webhook forwarding from TraceLink, wholesalers, and enterprise EDI paths.',
                'hero_description' => 'Axway is middleware, not an L4 competitor. TracePharma interoperates when your partners or IT team routes EPCIS through an Axway gateway to your tenant AS2 endpoint or HTTPS webhook.',
                'summary' => 'Axway is a Pulse-listed integration platform used by manufacturers and wholesalers for AS2 and managed file transfer. TracePharma supports native AS2 and Axway-forwarded HTTPS patterns. Enterprise EDI teams keep their gateway while you run operator L4 workflows.',
                'inbound' => [
                    'Native AS2 receive with partner certificates registered per connection.',
                    'HTTPS webhook when Axway transforms and forwards EPCIS to TracePharma.',
                    'Axway preset documents gateway URL and partner cert exchange checklist.',
                ],
                'outbound' => [
                    'Outbound EPCIS returned via AS2 or partner-configured gateway paths.',
                    'MDN and ACK tracking surfaced in integration health views.',
                    'Same outbound generation engine as direct partner connections.',
                ],
                'cutover' => [
                    'Work with EDI team to map Axway partner profiles to TracePharma endpoints.',
                    'Exchange AS2 IDs, certificates, or webhook tokens per connection.',
                    'Run test messages through Axway staging before production promotion.',
                    'Monitor integration health for certificate expiry alerts.',
                ],
                'best_for' => [
                    'Enterprises with existing Axway B2B infrastructure.',
                    'TraceLink paths that require Axway gateway in the middle.',
                    'IT teams that will not expose L4 directly but will forward EPCIS.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Is Axway a TracePharma competitor?',
                        'answer' => 'No. Axway moves messages; TracePharma is your L4 DSCSA application. You typically run both—Axway for transport, TracePharma for receiving, exceptions, VRS, and compliance.',
                    ],
                ],
            ],
            'rfxcel' => [
                'slug' => 'rfxcel',
                'name' => 'rfXcel (Antares Vision)',
                'category' => 'Serialization platform',
                'pulse_listed' => true,
                'preset' => 'rfxcel',
                'transports' => ['SFTP'],
                'meta_description' => 'TracePharma interoperates with Antares rfXcel via SFTP EPCIS polling—manufacturer L4 handoff without replacing DIAMIND plant systems.',
                'hero_description' => 'rfXcel powers many Antares Vision global serialization programs. TracePharma receives rfXcel-path EPCIS over SFTP while you run US-focused corporate L4 receiving, outbound ship, and compliance.',
                'summary' => 'Antares rfXcel is a Pulse-listed enterprise L4 engine within the DIAMIND ecosystem. TracePharma does not replace line-level inspection or serialization hardware. Interoperate when corporate systems drop EPCIS shipment files to your tenant SFTP inbox.',
                'inbound' => [
                    'SFTP polling preset for rfXcel EPCIS 1.2 XML drops.',
                    'Commissioning forward when paired with an Organization settings L3 endpoint.',
                    'Exception surfacing for missing serials or 3T gaps.',
                ],
                'outbound' => [
                    'Outbound EPCIS to US wholesalers and distributors.',
                    'Customer ACK monitoring and saleable return workflows.',
                    'Compliance export API for audit packages.',
                ],
                'cutover' => [
                    'Coordinate SFTP credentials and file schedule with rfXcel PS or serialization IT.',
                    'Create inbound connection with rfXcel serialization provider preset.',
                    'Validate sample commissioning and shipping files on Receiving.',
                    'Configure L3 commissioning forward before production outbound go-live.',
                ],
                'best_for' => [
                    'US manufacturers on rfXcel shipping to domestic wholesalers.',
                    'Multi-site programs needing a lighter US L4 SaaS hub at corporate edge.',
                    'Teams migrating partner delivery from rfXcel SFTP to TracePharma inbox.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Does TracePharma replace Antares DIAMIND at the packaging line?',
                        'answer' => 'No. TracePharma operates at corporate L4. rfXcel interoperability is for EPCIS handoff between plant/corporate serialization and your TracePharma hub.',
                    ],
                ],
            ],
            'tracktracerx' => [
                'slug' => 'tracktracerx',
                'name' => 'TrackTraceRx',
                'category' => 'Pharmacy platform',
                'pulse_listed' => true,
                'preset' => 'tracktracerx',
                'transports' => ['HTTPS'],
                'meta_description' => 'TracePharma interoperates with TrackTraceRx pharmacy networks via HTTPS EPCIS webhooks when you need full L4 depth beyond partner onboarding.',
                'hero_description' => 'TrackTraceRx targets rapid pharmacy network deployment with partner location scale. TracePharma connects via HTTPS preset when dispensers or buying groups need event-store investigation (EPCIS 1.2 GA; 2.0 capture + query-as-2.0) and structured exceptions.',
                'summary' => 'TrackTraceRx is a Pulse-listed pharmacy DSCSA vendor emphasizing network location directory and onboarding economics. TracePharma interoperates at the HTTPS EPCIS layer. Add wholesaler-grade receiving, exceptions, and multi-profile reporting.',
                'inbound' => [
                    'HTTPS webhook preset with tenant-scoped token authentication.',
                    'EPCIS receiving with scan-first shipment matching.',
                    'VRS verification and POST /api/v1/dispense-check on pharmacy profiles.',
                ],
                'outbound' => [
                    'Compliance exports and FDA 3911 for failed verifications.',
                    'Buying group member health dashboards when applicable.',
                    'Exception workflows with supplier accountability.',
                ],
                'cutover' => [
                    'Document TrackTraceRx HTTPS delivery paths from wholesalers.',
                    'Provision Pharmacy or Buying Group tenant profile.',
                    'Create TrackTraceRx preset inbound connection and share webhook URL.',
                    'Run test receive before decommissioning legacy verify-only storage.',
                ],
                'best_for' => [
                    'Pharmacy networks outgrowing TrackTraceRx verify-only scope.',
                    'Buying groups needing EPCIS investigation across members.',
                    'Parallel evaluation while keeping HTTPS partner paths.',
                ],
                'compare_route' => 'marketing.compare.tracktracerx',
                'faq' => [
                    [
                        'question' => 'Is TrackTraceRx the same category as TracePharma?',
                        'answer' => 'Both serve pharmacy DSCSA. TrackTraceRx emphasizes network onboarding scale; TracePharma emphasizes full L4 EPCIS receiving, exceptions, and EPCIS 1.2 GA with 2.0 capture/query/subscriptions for teams that outgrow connectivity-only workflows.',
                    ],
                ],
            ],
            'optel' => [
                'slug' => 'optel',
                'name' => 'OPTEL',
                'category' => 'Serialization platform',
                'pulse_listed' => true,
                'preset' => null,
                'transports' => ['AS2', 'SFTP', 'HTTPS'],
                'meta_description' => 'TracePharma interoperates with OPTEL VerifyBrand trading partners via standard EPCIS transports—US L4 hub without VerifyBrand middleware.',
                'hero_description' => 'OPTEL VerifyBrand serves global MAHs with fixed-cost unlimited serials and optional L1–L5 stack. TracePharma is the US L4 hub for outbound EPCIS, ACK monitoring, and scan-first receiving—not a VerifyBrand reseller.',
                'summary' => 'OPTEL is a Pulse-listed global serialization vendor. TracePharma connects to US trading partners via standard EPCIS transports (AS2, SFTP, HTTPS)—using custom transport presets, not an OPTEL-named connector. Plant-floor serialization may remain on VerifyBrand while L4 partner workflows run on TracePharma.',
                'inbound' => [
                    'AS2, SFTP, or HTTPS EPCIS from partners formerly on VerifyBrand paths.',
                    'Use custom_as2, custom_sftp, or custom_https presets—no OPTEL-named preset in tenant onboarding.',
                    'Unified receiving with scan-first matching and structured exceptions.',
                ],
                'outbound' => [
                    'Outbound EPCIS to US wholesalers and pharmacies with ACK health monitoring.',
                    'L3 commissioning forward for manufacturers keeping VerifyBrand at plant edge.',
                    'Compliance exports and exception investigation at partner edge.',
                ],
                'cutover' => [
                    'Document partner EPCIS delivery paths independent of VerifyBrand middleware.',
                    'Configure standard transport presets pointing to TracePharma tenant endpoints.',
                    'Test receiving on sample shipments before decommissioning legacy L4 storage.',
                    'Review the compare page when evaluating TraceLink → OPTEL → TracePharma paths.',
                ],
                'best_for' => [
                    'US-only mid-market manufacturers evaluating VerifyBrand economics.',
                    'Teams leaving VerifyBrand who need self-serve L4 SaaS cutover without global L1–L5 modules.',
                    'Distributors receiving EPCIS from OPTEL-connected partners at the transport layer.',
                ],
                'compare_route' => 'marketing.compare.optel',
                'faq' => [
                    [
                        'question' => 'Does TracePharma replace OPTEL plant-floor serialization?',
                        'answer' => 'No. OPTEL can span L1–L5 including line equipment. TracePharma operates at US L4—corporate EPCIS hub, partner connectivity, and operator receiving/ship workflows.',
                    ],
                    [
                        'question' => 'Is there an OPTEL preset in TracePharma?',
                        'answer' => 'No. Use standard AS2, SFTP, or HTTPS transport presets (custom_as2, custom_sftp, custom_https) configured for your partner endpoints.',
                    ],
                ],
            ],
            'sap' => [
                'slug' => 'sap',
                'name' => 'SAP ATTP',
                'category' => 'ERP serialization',
                'pulse_listed' => true,
                'preset' => 'sap_ich',
                'transports' => ['HTTPS'],
                'meta_description' => 'Connect TracePharma to SAP Advanced Track and Trace via SAP ICH HTTPS webhook—corporate ERP serialization plus partner-edge L4 workflows.',
                'hero_description' => 'SAP ATTP is the corporate serialization repository for SAP-centric manufacturers. TracePharma interoperates via SAP ICH HTTPS when EPCIS flows from Integration Suite to your tenant—without replacing ATTP in the ERP core.',
                'summary' => 'SAP is a Pulse-listed enterprise stack vendor. TracePharma rarely displaces ATTP entirely. It handles partner-edge L4 workflows—receive, ship, exceptions, ACK monitoring—while SAP remains the corporate serial number repository.',
                'inbound' => [
                    'HTTPS webhook preset (sap_ich) for SAP Integration Suite / ICH delivery.',
                    'EPCIS capture into unified receiving and 3T matching.',
                    'Commissioning forward when manufacturers configure an L3 endpoint alongside SAP-to-L4 bridging.',
                ],
                'outbound' => [
                    'Outbound EPCIS to wholesalers not routed through SAP directly.',
                    'ACK monitoring and exception investigation at partner edge.',
                    'REST API for WMS and automation alongside SAP flows.',
                ],
                'cutover' => [
                    'Map SAP ICH or Integration Suite endpoints with your SAP SI partner.',
                    'Create sap_ich preset inbound connection in TracePharma.',
                    'Define which partners stay on SAP vs partner-edge TracePharma paths.',
                    'Test sample ICH delivery before production promotion.',
                ],
                'best_for' => [
                    'SAP S/4HANA manufacturers adding lighter partner-edge L4 UX.',
                    'Distributors receiving SAP-origin EPCIS via ICH HTTPS.',
                    'Teams where ATTP remains system of record but operators need scan-first L4.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Does TracePharma replace SAP ATTP?',
                        'answer' => 'Usually no. ATTP remains the ERP serialization repository. TracePharma handles partner-facing L4 operations—receiving, outbound to specific partners, exceptions, and compliance exports—alongside SAP.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $slug): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$slug])) {
            throw new \InvalidArgumentException("Unknown marketing integration page: {$slug}");
        }

        return $definitions[$slug];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(self::definitions());
    }
}
