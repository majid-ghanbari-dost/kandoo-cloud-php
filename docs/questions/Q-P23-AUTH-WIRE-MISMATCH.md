# Q-P23-AUTH-WIRE-MISMATCH — executive decision required

## Blocker

C-80b `b2d8a0d267fd618bf16a1fc173c46544338d3558` sends `Authorization: Bearer <device-secret>` (its `src/Kandoo.Hub/HubCloudReplicationClient.cs`; paper `docs/openapi.yaml` DeviceBearer). Independently checked Hub CI https://github.com/majid-ghanbari-dost/Kandoo/actions/runs/44039697223 is green for Ubuntu and Windows. The actual PHP `src/Service/IngestService.php` instead requires **both** `X-Kandoo-Device-Id` and `X-Kandoo-Device-Secret`, looks up the device by ID, verifies its store and compares the SHA-256 of the secret; it does not consume Authorization. Thus a C-80b request alone will be rejected as 401. C-80b fixes the old missing-credential issue but not the two implementations' agreement. The existing frozen `docs/CLOUD-CONTRACT.md` records the older no-auth wire and an optional different global key; do not rewrite it. D-B mandates per-device, store-scoped authentication. No actual Hub replay was executed or claimed; green C# CI does not exercise this PHP endpoint.

## Options

1. **Safe default:** keep authentication unchanged and defer P-23 until the two teams agree a wire format through the required approved contract addendum; do not claim interoperability.
2. Hub sends the PHP Cloud's two existing per-device headers securely, and both sides pin that format in an approved addendum; retain store binding and missing/wrong-key tests. Requires C# change and contract approval.
3. Approve an addendum adopting DeviceBearer for PHP, then implement a secure store-scoped device lookup, unambiguous rotation/revocation behavior, constant-time secret validation and negative/cross-store tests before real replay. Preserve frozen document and existing clients according to the approved transition. Requires security and contract review; do not infer device identity from untrusted input without verification.

No bypass, global shared secret, fake replay, or production/customer data. P-23 and P-25 remain blocked until a compatible authorized protocol and real E2E proof exist.
