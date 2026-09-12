# ATP Pulse / OCI evidence

## Manual partner-supplied evidence

When recording ATP verification on a trading partner, operators may select **NABP Pulse (partner-supplied evidence)** or **OCI / directory (partner-supplied evidence)** as the source. Those values mean the buyer kept a screenshot, profile URL, or similar artifact the partner provided — the same diligence pattern as a partner-supplied document.

**Honesty:**

- Manual evidence ≠ a live NABP Pulse or OCI API integration. TracePharma does not sync Pulse or OCI directories.
- TracePharma is **not** Pulse-listed. Selecting these sources does not claim TracePharma (or the tenant) appears in NABP Pulse.
- Prefer attaching an evidence link or note when using these sources so a later review can see what was checked and when.

Filament **Record ATP verification** loads sources from `AtpVerificationSource::options()`, which includes these cases automatically.

## Live OCI wallet verify / present (VRS ATP seam)

TracePharma can call an **external** OCI-compatible wallet HTTP API (Generate VP / Verify VP) when configured:

- Tenant settings (`atp.oci_*`) are primary; `config/atp_oci.php` / env is the platform fallback when tenant fields are blank.
- Inbound VRS responder: `atp.oci_mode` = `off` | `warn` | `require`. Warn/require call `OciWalletClient::verify` on `ATP-Authorization` / `X-ATP-Credential` and persist `atp_credentials.verification_status`. Require rejects the VRS request on missing/invalid/expired/error **before** product verify.
- Outbound VRS: when `atp.oci_present_outbound` is on and the wallet is configured, `HttpVrsClient` best-effort attaches `ATP-Authorization` from `present()`.

**Honesty (live path):**

- Live verify ≠ manual `AtpVerificationSource::OciPartnerEvidence`.
- Live verify ≠ FDA WDD / 3PL license rows. Licenses and manual OCI evidence are **not** substitutes for VP verify.
- `AtpVerificationSource::OciLiveVerified` labels wallet-verified outcomes; it is distinct from partner-supplied OCI evidence.
- TracePharma does **not** mint keys or host a wallet — only an HTTP adapter (`OciWalletClient`).
