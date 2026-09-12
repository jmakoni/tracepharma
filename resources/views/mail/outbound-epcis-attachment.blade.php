<x-mail::message>
# EPCIS / TI attached

{{ $partnerOrTenantLabel }} has sent electronic transaction information (EPCIS) as an attachment to this email.

@if (filled($asnNumber))
**ASN:** {{ $asnNumber }}
@endif

@if (filled($customerPo))
**PO:** {{ $customerPo }}
@endif

Download and retain the attached file for DSCSA recordkeeping. Prefer a B2B connection (HTTPS, SFTP, or AS2) when available for automated exchange.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
