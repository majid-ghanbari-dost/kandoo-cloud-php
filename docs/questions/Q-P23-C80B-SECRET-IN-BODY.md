# Q-P23-C80B-SECRET-IN-BODY — security blocker

## Finding

Hub C-80b `o15-C-80b` commit `b2d8a0d267fd618bf16a1fc173c46544338d3558` contains `src/Kandoo.Hub/ReplicationContracts.cs` `ReplicationBatch.DeviceSecret` with `[JsonPropertyName("device_secret")]` and `[JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]`. `src/Kandoo.Hub/HubCloudReplicationClient.cs` sets `batch.DeviceSecret` and then `HttpCloudReplicationTransport.SendAsync` serializes the *whole batch* using `JsonSerializer.Serialize(batch, HubJson.Options)` before setting `Authorization: Bearer ...`. Therefore a non-null device secret is present in the HTTP JSON body as well as the Authorization header. The PHP application does not itself log request bodies, but HTTP middleware, diagnostics, capture tooling, or other infrastructure may log bodies without applying Authorization-header redaction. Demo LAN HTTP also is unencrypted. Treat this as a security risk, not proof that any secret was actually leaked. Only source was inspected; no real request was sent in this session.

## Options

1. **Safe default / recommended:** C# agent marks `DeviceSecret` `[JsonIgnore]` unconditionally (or constructs a credential-free wire DTO), retains Bearer, and adds real transport tests asserting the key is present only in the Authorization header and never in JSON/log output; green Hub CI and a pinned revised tag. Then run P-23 against that version with synthetic credentials.
2. Security owner approves a specifically bounded synthetic-only replay on trusted loopback, while treating C-80b itself as unsafe for owner credentials; real owner/demo use still requires option 1. This exception does not eliminate the risk.
3. Stop and defer P-23/P-25 until a compatible secure transport is supplied. Do not disable auth, silently strip/change actual Hub traffic, or use owner/customer data.

Current default is option 1 plus pause. No schema change or Cloud bypass is implicated. Q-P23-AUTH-WIRE-MISMATCH is closed for wire-format agreement only; this is a separate security defect. Cross-stack CI/replay and Windows execution remain unverified.

## Executive acknowledgment

The executive accepted the security stop and assigned the fix and the OpenAPI deprecation line to C# task C-83. Await the executive announcement of green C-83 before closing this question with the fix commit and resuming P-23. No bypass or security shortcut is authorized.
