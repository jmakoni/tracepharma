# ATP Pulse / OCI evidence (manual)

When recording ATP verification on a trading partner, operators may select **NABP Pulse (partner-supplied evidence)** or **OCI / directory (partner-supplied evidence)** as the source. Those values mean the buyer kept a screenshot, profile URL, or similar artifact the partner provided — the same diligence pattern as a partner-supplied document.

**Honesty:**

- Manual evidence ≠ a live NABP Pulse or OCI API integration. TracePharma does not sync Pulse or OCI directories.
- TracePharma is **not** Pulse-listed. Selecting these sources does not claim TracePharma (or the tenant) appears in NABP Pulse.
- Prefer attaching an evidence link or note when using these sources so a later review can see what was checked and when.

Filament **Record ATP verification** loads sources from `AtpVerificationSource::options()`, which includes these cases automatically.
