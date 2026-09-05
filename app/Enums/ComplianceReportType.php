<?php

namespace App\Enums;

enum ComplianceReportType: string
{
    case TransactionReport = 'transaction_report';
    case DscsaComplianceReport = 'dscsa_compliance_report';
    case TiHistory = 'ti_history';
    case AuditPackage = 'audit_package';

    public function label(): string
    {
        return match ($this) {
            self::TransactionReport => 'Transaction Report (lot-based TI)',
            self::DscsaComplianceReport => 'DSCSA Compliance Report (serialized)',
            self::TiHistory => 'TI history (CSV)',
            self::AuditPackage => 'Audit package (ZIP)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TransactionReport => 'One PDF page per lot with ownership / TI summary for a parsed inbound EPCIS document.',
            self::DscsaComplianceReport => 'Serialized unit listing by lot with DSCSA legal language. Queued as a PDF export with email and in-app notification when ready.',
            self::TiHistory => 'CSV of GTIN, serial, lot, expiry, and party context for the selected inbound document.',
            self::AuditPackage => 'ZIP with transaction PDF, serialized compliance PDF, TI history CSV, and document summary JSON.',
        };
    }

    public function downloadLabel(): string
    {
        return match ($this) {
            self::AuditPackage => 'Download ZIP',
            self::TiHistory => 'Download CSV',
            self::DscsaComplianceReport => 'Queue PDF export',
            default => 'Download PDF',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::AuditPackage => 'application/zip',
            self::TiHistory => 'text/csv; charset=UTF-8',
            default => 'application/pdf',
        };
    }
}
