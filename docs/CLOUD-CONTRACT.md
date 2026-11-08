# KANDOO CLOUD CONTRACT — extracted for the PHP production Cloud (v1)

Product: **فاکتور دیجیتال کندو (Kandoo Digital Invoice)**.
This document contains no legal or tax claims, and the Kandoo Cloud has **no tax-authority
integration of any kind**. The Cloud serves only a compact **public projection** of invoices
that the local Hub replicates to it.

| | |
|---|---|
| Document status | **FROZEN from creation (task P-01, PM ORDER 9, 3 Nov 2026). No edits after P-01 without explicit Execution-Manager instruction.** |
| Extracted by | PHP agent (CREATE-ONLY role) |
| Extraction source | Read-only study of `majid-ghanbari-dost/Kandoo` (C#/.NET) at commit `4780ed224db17159eaad4f0e17e0dac7ce88bf69` (2026-11-01) |
| Target implementation | New production Cloud: PHP 8.1+, MariaDB 10.6, shared host, no shell/Composer on the host (decision D2) |
| Companion files | `docs/DECISIONS.md` (D1–D5), `docs/schemas/*.schema.json` (JSON Schemas), `docs/golden/*.json` (golden request/response pairs, all values FAKE/synthetic) |

**How to read this document.** Every statement is one of:

- **[CONFIRMED]** — read directly from the frozen .NET Cloud code (or its official docs/vectors) and reproduced byte-compatibly by the PHP Cloud.
- **[PROPOSED v1.1]** — the extension shape ordered by the PM for the next protocol revision; not emitted by the frozen Hub yet.
- **[UNCONFIRMED n]** — the contract sources are silent, contradictory, or ambiguous; the PHP Cloud picks the most conservative compatible behaviour and item `Un` is listed in the register at §14. Nothing here is filled with silent assumptions.

Two source layers exist and they are **not identical**:

1. The **normative paper contract** of the C# repo: `docs/cloud-api-contract.md` + `docs/openapi.yaml` (22 operations, DeviceBearer auth, `/v1/public/invoices/{public_token}`), and `docs/sync-protocol.md` + `docs/sync-protocol-test-vectors.json` (34 vectors, canonical hashing).
2. The **frozen running implementation**: `src/Kandoo.Cloud` (ASP.NET Core, .NET 8, PostgreSQL) + its client, the Hub transport `src/Kandoo.Hub/HubCloudReplicationClient.cs`. It implements only a subset of the paper contract, with **no bearer authentication on the wire** and the public page at **`/i/{token}`**.

The PHP Cloud is built to be **wire-compatible with the frozen implementation and with what the frozen Hub actually sends**, while documenting every divergence from the paper contract. Where the two layers disagree, this document says so explicitly (see U1, U6).

---

## 1. System topology and scope

- The **Hub** (local .NET MAUI/console app) is the only ordinary domain-write authority and holds the **master copy** of all data (SQLite operation log with durable outbox).
- The **Cloud** receives a compact public projection of each invoice via ordered log replication and serves:
  - public invoice pages through **unguessable capability tokens**;
  - (deferred) customer OTP login and per-customer invoice lists;
  - (deferred) all SMS sending — **only the Cloud ever holds the SMS key (D3)**; the Hub never does.
- The Cloud keeps **only** the public/compact form (D5): the replicated operation log needed for the projection, the projected public invoice rows, and delivery/rate-limit bookkeeping. It never enriches, never computes master data, never stores data beyond the projection defined here.
- Hosting limits designed for (D2/D5): DB ≈ 200–300 MB, ≈ 2000 visits/day on plan E1, no shell/Composer on the host, config outside the web root, outbound HTTPS only to `api.iranpayamak.com` (deferred SMS window), cron every minute, shared outbound IP (never rely on IP allow-listing), nightly DB backup by our own cron.

**PHP pilot v1 scope (PM ORDER 9):** health, Hub→Cloud replication ingest, public projection derivation, public invoice read (JSON + HTML), per-client rate limiting. **Deferred (no code in this window):** OTP/SMS, customer pages, deployment guide, hosting tests (§12).

---

## 2. Endpoint surface

### 2.1 Implemented by the frozen .NET Cloud (and by PHP pilot v1)

| # | Method & path | Purpose | Auth on the frozen wire |
|---|---|---|---|
| E1 | `GET /health` | Liveness probe | none |
| E2 | `POST /v1/stores/{store_id}/operation-batches` | Hub→Cloud replication ingest (atomic, ordered, idempotent) | **none on the wire** (see §4, U1); writer fencing by `(store_id, writer_device_id, writer_epoch)` + hash chain |
| E3 | `GET /i/{token}` | Public invoice read by capability token; HTML page or JSON view | none (the token **is** the capability) |
| E4 | `GET /fonts/Vazirmatn-Regular.ttf`, `GET /fonts/Vazirmatn-Bold.ttf` | Local static font assets (no CDN) | none |
| E5 | `POST /v1/customer/otp/challenges` | Customer OTP issue (**deferred** in PHP pilot, §12) | none (phone + Idempotency-Key in body/header) |
| E6 | `POST /v1/customer/otp/challenges/{challenge_id}/verify` | Customer OTP verify (**deferred**) | none |
| E7 | `POST /v1/public/invoices/{token}/claim` | Claim invoice into customer account (**deferred**) | customer `Authorization: Bearer` |
| E8 | `GET /v1/customer/invoices` | Customer invoice list (**deferred**) | customer `Authorization: Bearer` |

PHP pilot v1 implements **E1–E4**. Deferred endpoints (E5–E8) answer with the generic unknown-route 404 in the PHP pilot (U12). Asset path note: the PHP Cloud serves fonts under `/assets/fonts/...` (web-root layout, §10.4) instead of `/fonts/...`; the public page references the local path accordingly (U15).

### 2.2 Normative-only endpoints (paper contract `openapi.yaml`, **not implemented** by the frozen Cloud, **not implemented** in PHP pilot v1)

`POST /v1/otp/challenges`, `POST /v1/otp/challenges/{challenge_id}/verify`, `POST /v1/stores`, `GET /v1/stores/{store_id}`, `POST /v1/stores/{store_id}/pairing-grants`, `POST /v1/pairing-grants/{pairing_id}/consume`, `GET /v1/stores/{store_id}/devices`, `POST /v1/stores/{store_id}/devices/{device_id}/revoke`, `POST /v1/stores/{store_id}/devices/{device_id}/token-rotation`, `GET /v1/stores/{store_id}/operations`, `GET /v1/stores/{store_id}/locks/current`, `POST /v1/stores/{store_id}/locks/{lock_id}/release`, `POST /v1/stores/{store_id}/restore-requests`, `GET .../restore-requests/{id}/manifest`, `GET .../restore-requests/{id}/entries`, `POST .../restore-requests/{id}/verification`, `POST .../restore-requests/{id}/activation`, `POST /v1/stores/{store_id}/invoices/{invoice_id}/delivery/sms`, `.../delivery/qr-link`, `.../delivery/share-link`, `GET /v1/public/invoices/{public_token}`.

The paper contract requires `DeviceBearer` auth for these and `Idempotency-Key` on all 15 mutations, cursor pagination on lists, and the error matrix of 51 codes (§13 lists the subset relevant to v1). They are recorded here so a future window can implement them; PHP pilot v1 must answer them as unknown routes (U6, U12).

---

## 3. Common wire rules [CONFIRMED]

- **Encoding:** all request/response bodies are UTF-8 JSON unless stated otherwise (`text/html; charset=utf-8` for the public page).
- **Field naming:** `snake_case` everywhere (explicit `JsonPropertyName` in the frozen DTOs). Property order in serialized responses is the DTO declaration order given in this document; JSON object key order is **not** semantically meaningful for parsing, but the golden pairs fix exact bytes, and the PHP Cloud reproduces them byte-for-byte.
- **Compact output:** no insignificant whitespace in bodies produced by the Cloud (`{"a":1,"b":2}` style).
- **UUIDs:** lowercase 8-4-4-4-12 ("D") format on the wire. The frozen stack parses leniently (case-insensitive; `D`, `B` braces, `N`, `P` forms) and re-serializes canonical lowercase `D`. The PHP Cloud accepts the same forms, normalizes to lowercase `D` internally, and rejects anything else as a malformed body/route (U13).
- **Integers:** all hash-bearing JSON uses signed 64-bit integers only — no floats, no exponent forms, no NaN/Infinity. Quantities are scaled thousandths (`quantity_millis`, `quantity_delta_millis`, `stock_millis`; `1000 = 1.000`); Rial amounts are unscaled integers (ASM-22). A number outside the integer profile → `400 VALIDATION_ERROR` (see §5.4 steps 8/10).
- **Timestamps:** RFC 3339 / ISO 8601 strings. The frozen Hub writes `hub_recorded_at` in round-trip "O" format UTC; the Cloud treats it as an opaque string. `issued_at` values are serialized by the Cloud as ISO 8601 with explicit `+00:00` offset (e.g. `2026-01-02T03:04:05+00:00`; fractional seconds only when non-zero — U9).
- **Error envelope** (every non-2xx JSON response of E2/E3 and deferred endpoints):

```json
{"code":"ERROR_CODE","message":"Human-readable sentence.","retry_hint":{"action":"ACTION", "...extra hint fields...":0}}
```

  `retry_hint.action` is always present and first; extra hint fields follow in the order documented per error (§5.6, §6). `retry_hint` values are advisory metadata for the Hub's retry logic.
- **No secrets in errors/logs:** tokens, phones, OTPs, proofs, keys and URLs containing tokens never appear in log lines or error bodies (§6.6).

---

## 4. Authentication and authorization

### 4.1 Paper contract (normative, unimplemented on the frozen wire)

`openapi.yaml` defines `DeviceBearer` (opaque per-device token, store-bound; only the active HUB credential authorizes replicated domain mutations) and `RestoreBearer`; servers store only hashed opaque tokens. `ingestOperationBatch` is specified with `DeviceBearer`.

### 4.2 Frozen wire reality [CONFIRMED]

The frozen Hub transport (`HttpCloudReplicationTransport`) sends **no `Authorization` header** — only `Content-Type: application/json` and `Idempotency-Key: {batch_id}`. The frozen Cloud ingest endpoint performs **no bearer check**. Protection on the wire is therefore:

1. **Store scope:** the unguessable `store_id` UUID in the path must exist (`404 STORE_NOT_FOUND` conceals missing *and* invisible stores).
2. **Writer fencing:** the batch's `(writer_device_id, writer_epoch)` must equal the store's active writer; anything else is `409 WRITER_FENCED` — evaluated **before** every other batch check, even for an empty batch (CLOUD-008).
3. **Integrity:** every entry must carry a valid `request_hash` and `entry_hash` over the canonical P-02 profile (§5.5) and chain via `previous_entry_hash`; corrupted content fails closed (`ENTRY_HASH_MISMATCH`, `LOG_CONFLICT`, `OPERATION_ID_CONFLICT`) and is never merged or overwritten.
4. **Idempotency:** `Idempotency-Key` must equal `batch_id`; a repeated key with different canonical content is `409 IDEMPOTENCY_CONFLICT`.

The public read `/i/{token}` is anonymous by design: the token itself is a 128-bit capability (D4/paper §2.1). Customer endpoints (deferred) use `Authorization: Bearer <opaque token, 32..256 chars>` validated against stored SHA-256 hashes.

### 4.3 PHP pilot v1 decision [UNCONFIRMED U1]

Because the frozen Hub sends no credential, requiring one would break replication. The PHP Cloud therefore supports an **optional** pre-shared replication key: config `replication_key` (default `null`). When set to a non-empty value, ingest requires header `X-Kandoo-Replication-Key` equal to it (compared with `hash_equals`, constant time); mismatch → `401` error envelope `{"code":"UNAUTHENTICATED","message":"A valid replication credential is required.","retry_hint":{"action":"REAUTHENTICATE"}}` (message/action chosen to match the paper matrix; the frozen Cloud has no such path). When `null`, behaviour is byte-identical to the frozen Cloud. Upgrading the frozen Hub to send `DeviceBearer` remains an open Owner/PM decision; the optional key is the conservative bridge for a shared-IP host. This header is a **transport secret**: it is configuration, never hardcoded, never logged.

---

## 5. Hub→Cloud replication — `POST /v1/stores/{store_id}/operation-batches`

Protocol identifier: `kandoo.sync.v0`, `protocol_version: "0"`. The Hub uploads **one store at a time, one active upload stream per store**, batches of at most 100 entries / 1 MiB, retrying from a durable outbox until acknowledged.

### 5.1 Request [CONFIRMED]

Headers:

| Header | Requirement |
|---|---|
| `Content-Type` | `application/json` (the frozen Cloud does not hard-reject other values; body must be JSON) |
| `Idempotency-Key` | **Required.** Must equal body `batch_id` exactly (ordinal). Missing/blank → `400 IDEMPOTENCY_KEY_REQUIRED`; different → `409 IDEMPOTENCY_CONFLICT`. |
| `X-Kandoo-Replication-Key` | PHP-only, only when config `replication_key` is set (§4.3, U1). |
| `Content-Length` | If declared and `> 1048576` bytes → `413 PAYLOAD_TOO_LARGE` **before the body is parsed**. |

Body — `OperationBatch` (schema: `docs/schemas/operation-batch.request.schema.json`):

| Field | Type | Rule |
|---|---|---|
| `batch_id` | string | Required, non-blank. Hub convention: `hub-{firstSeq}-{lastSeq}-{first16OfHeadHash}`. Bounded to 191 chars in PHP (MariaDB key limit, U8). |
| `store_id` | uuid | Required; must equal the route `store_id` → else `400 VALIDATION_ERROR`. |
| `writer_device_id` | uuid | Required; fencing identity. |
| `writer_epoch` | integer ≥ 1 | Required; fencing identity. |
| `entries` | array of `LogEntry` **or null** | `null`/absent vs `[]` are distinguished at parse level but both fail as `INCOMPLETE_BATCH` for an active, unthrottled writer. Wire order of object keys is irrelevant. |

`LogEntry` (16 fields; unknown extra fields are **ignored** by DTO parsing and excluded from hashes):

| Field | Type | Notes |
|---|---|---|
| `protocol_version` | string | Must be exactly `"0"`. |
| `store_id` | uuid | Must equal batch `store_id`. |
| `writer_device_id` | uuid | Must equal batch `writer_device_id`. |
| `writer_epoch` | integer | Must equal batch `writer_epoch`. |
| `operation_seq` | integer ≥ 1 | Strictly ascending, contiguous; begins at 1 for a new store. |
| `previous_entry_hash` | string(64) | Hex SHA-256 of previous entry; 64 zeros (`0000…0000`) at genesis. |
| `entry_hash` | string(64) | Hex SHA-256 over the canonical entry **excluding `entry_hash`** (§5.5). |
| `operation_id` | string | Non-blank; unique per store forever (strict operation identity). |
| `device_id` | uuid | Originating device (may differ from writer, e.g. a companion). |
| `operation_type` | string | Non-blank; e.g. `product.update`, `inventory.append`, `invoice.draft_create`, `invoice.item_add`, `invoice.issue`, `invoice.cancel`, `device.pair`. |
| `base_version` | integer or null | OCC base version where applicable. |
| `request_hash` | string(64) | Hex SHA-256 over the canonical request tuple (§5.5). |
| `payload` | object | Operation request payload (must be a JSON object). |
| `outcome` | object | Authoritative Hub outcome (must be a JSON object). Frozen Hub writes the stored operation response `{status, result_status, response}`. |
| `response_snapshot` | object | Authoritative response snapshot (must be a JSON object). Frozen Hub writes `{operation_seq, status, result_status, response}`. The public projection reads only this object (§7). |
| `hub_recorded_at` | string | Hub clock, opaque to the Cloud (never used for ordering). |

### 5.2 Successful response — `200` `IngestAck` [CONFIRMED]

Body (schema: `docs/schemas/ingest-ack.response.schema.json`), exact field order:

```json
{"batch_id":"…","status":"ACCEPTED|DUPLICATE","accepted_through_seq":42,"ingested_count":2,"duplicate_count":0,"head_entry_hash":"<64hex or null>","next_expected_seq":43,"cloud_cursor":"<32-hex store N>:42"}
```

- `status`: `ACCEPTED` when ≥1 new entry was appended; `DUPLICATE` when the batch was fully re-validated against stored entries with zero new effects.
- `accepted_through_seq`: store high watermark after the transaction.
- `ingested_count` / `duplicate_count`: counts within this batch (a committed retry returns `0` / full count — it never replays the first ACK's `ingested_count`, CLOUD-005).
- `head_entry_hash`: store head hash after the transaction (`null` only for a store that has never accepted an entry).
- `next_expected_seq`: `accepted_through_seq + 1`.
- `cloud_cursor`: `"{store_id:N}:{accepted_through_seq}"` (store UUID in 32-hex "N" form) when new entries were appended, else `null`. For downstream change feeds only; never an ordering authority.

**Hub-side acceptance rules (constrain the ACK)** [CONFIRMED from `HubCloudReplicationClient.Acknowledge`]: for a non-empty batch the Hub requires `status ∈ {ACCEPTED, DUPLICATE}`, non-null `accepted_through_seq`, non-null `head_entry_hash`, `accepted_through_seq == last entry's operation_seq`, and `head_entry_hash == last entry's entry_hash` (ordinal). Otherwise the Hub flags `INVALID_ACKNOWLEDGEMENT` / `INCOMPLETE_ACKNOWLEDGEMENT` (retry) / `ACKNOWLEDGEMENT_MISMATCH` (review) and does **not** clear its outbox. Only a matching ACK removes entries from the durable outbox.

Response headers on **every** E2 response (success and error): `RateLimit-Limit: 120`, `RateLimit-Remaining: 119`, `RateLimit-Reset: 60` (the frozen Cloud emits these as fixed advertised values). When the response is `429`, additionally `Retry-After: 2`.

### 5.3 Hub retry/backoff behaviour the Cloud must tolerate [CONFIRMED]

Up to 3 attempts per replicate call; delay = `Retry-After`/`retry_after_ms` when given, else exponential 1 s → 60 s cap. On `THROTTLED` the Hub shrinks subsequent batches to the advertised `max_batch_entries`/`max_batch_bytes`. On `WRITER_FENCED` the Hub switches that store to `FENCED_READ_ONLY` and stops writing. On `GAP_DETECTED` it re-uploads from `expected_seq`. Network/5xx are retryable; `ENTRY_HASH_MISMATCH`, `LOG_CONFLICT`, `OPERATION_ID_CONFLICT` stop automatic advancement for review. The Cloud may therefore receive the same batch many times and must stay idempotent.

### 5.4 Ingest algorithm — exact evaluation order [CONFIRMED]

One DB transaction; the store row is locked for the whole validation+commit (`SELECT … FOR UPDATE`). **No mutation happens before the commit point.** HTTP-boundary checks (1–3) run before store load:

1. Route `store_id` must parse as UUID → else `400 VALIDATION_ERROR` "store_id must be a UUID." (hint `FIX_REQUEST`).
2. Declared `Content-Length > 1048576` → `413 PAYLOAD_TOO_LARGE` "The request exceeds the batch byte limit." (hint `FIX_REQUEST`, `max_batch_entries:100`, `max_batch_bytes:1048576`).
3. Body must deserialize into `OperationBatch` (valid JSON, correct types, parseable UUIDs, integer profile) → else `400 VALIDATION_ERROR` "The request body must be a valid operation batch." (hint `FIX_REQUEST`).
4. Load store row → missing → `404 STORE_NOT_FOUND` "The store is not available in this scope." (hint action `NONE` only). Conceals missing and invisible stores alike.
5. **Writer fencing (before all shape checks, CLOUD-008):** `writer_epoch != active_writer_epoch` OR `writer_device_id != active_writer_device_id` → `409 WRITER_FENCED` "The writer is not the active Hub epoch; stop new writes and review unsynced entries." (hint `STOP_WRITER`, `accepted_through_seq`, `current_version` = active writer epoch).
6. `Idempotency-Key` blank/missing → `400 IDEMPOTENCY_KEY_REQUIRED` "Idempotency-Key is required." (`FIX_REQUEST`).
7. `batch_id` blank → `400 VALIDATION_ERROR` "batch_id is required." (`FIX_REQUEST`). Key ≠ `batch_id` (ordinal) → `409 IDEMPOTENCY_CONFLICT` "Idempotency-Key must equal batch_id." (`REVIEW`).
8. Body `store_id` ≠ route `store_id` → `400 VALIDATION_ERROR` "The batch store does not match the route store." (`FIX_REQUEST`).
9. **Backpressure (before completeness, CLOUD-006):** store `throttled` flag set → `429 THROTTLED` "Cloud ingest is throttled; retain the outbox and retry the unchanged batch later." (hint `RETRY_AFTER`, `retry_after_ms:2000`, `max_batch_entries:2`, `max_batch_bytes:65536`, `accepted_through_seq`) + header `Retry-After: 2`.
10. `entries` null/absent/empty → `400 INCOMPLETE_BATCH` "An active writer must supply a complete non-empty batch." (hint `RETRY_SAME`, `accepted_through_seq`).
11. `entries.length > 100` → `413 PAYLOAD_TOO_LARGE` "The batch exceeds the entry limit; split only at a valid batch boundary." (hint `FIX_REQUEST`, `max_batch_entries:100`, `max_batch_bytes:1048576`, `accepted_through_seq`).
12. **Batch fingerprint** = SHA-256 over canonical JSON of the DTO-round-tripped batch (only the 5 known batch fields; each entry reduced to its 16 known fields; §5.5). Integer-profile violation → `400 VALIDATION_ERROR` "The batch contains a value outside the integer JSON profile." (`FIX_REQUEST`). Known `(store_id, batch_id)` with a **different** fingerprint → `409 IDEMPOTENCY_CONFLICT` "The batch key was already used for different content." (`REVIEW`). Known key with equal fingerprint → continue; the batch is fully **re-validated** and finally acknowledged `DUPLICATE` with zero new ingests (it does not replay stored ACK counters, CLOUD-005).
13. **Per-entry validation, in array order** (first failure aborts the whole batch, no partial effects):
    a. Envelope: `store_id`/`writer_device_id`/`writer_epoch` equal the batch's; `protocol_version == "0"`; `operation_id` and `operation_type` non-blank; `operation_seq ≥ 1`; `payload`, `outcome`, `response_snapshot` are JSON objects → else `400 VALIDATION_ERROR` "An entry is outside the store, writer, or P-02 envelope contract." (`FIX_REQUEST`).
    b. Intra-batch order (index > 0): `operation_seq == previous entry's seq + 1` → else `400 SEQUENCE_ORDER_VIOLATION` "Entries must be strictly ascending and contiguous inside a batch." (hint `RESUME_FROM_SEQUENCE`, `expected_seq` = previous+1, `received_seq`, `accepted_through_seq`).
    c. `request_hash` must equal the recomputed canonical request hash (§5.5) → else `400 REQUEST_HASH_MISMATCH` "The request hash does not match the canonical P-02 request." (hint `REVIEW`, `accepted_through_seq`, `details:{expected_request_hash, supplied_request_hash}`).
    d. `entry_hash` must equal the recomputed canonical entry hash → else `400 ENTRY_HASH_MISMATCH` "The entry hash chain failed verification; the entry is retained for review, not applied." (hint `REVIEW`, `accepted_through_seq`, `received_seq`, `details:{supplied_entry_hash, recomputed_entry_hash, quarantine:"REVIEW"}`). Integer-profile violation inside an entry → `400 VALIDATION_ERROR` "The entry contains a value outside the P-02 integer JSON profile."
    e. Intra-batch chain (index > 0): `previous_entry_hash` == previous entry's **recomputed** hash → else the same `ENTRY_HASH_MISMATCH` shape (supplied = the entry's `previous_entry_hash`, recomputed = previous entry's recomputed hash).
14. **Gap check:** first entry with `operation_seq > accepted_through_seq` must be exactly `accepted_through_seq + 1` → else `409 GAP_DETECTED` "Upload the earliest missing operation sequence before retrying the batch." (hint `RESUME_FROM_SEQUENCE`, `expected_seq` = accepted+1, `received_seq`, `accepted_through_seq`). The Cloud never applies later entries to "catch up".
15. **Per-entry disposition, in array order:**
    - `operation_seq ≤ accepted_through_seq` (already-accepted prefix): the stored entry must exist → else `409 LOG_CONFLICT` "The historical entry cannot be hash-verified; no suffix was applied." (hint `REVIEW`, `accepted_through_seq`, `received_seq`); stored `entry_hash` must equal the recomputed hash → else `409 LOG_CONFLICT` "An existing operation sequence has different content; Cloud will not overwrite it." (same hint shape); otherwise count as duplicate.
    - New entries: `operation_id` must be unknown store-wide and unique within the batch → else `409 OPERATION_ID_CONFLICT` "The operation identity is already present with a different log position or content." (hint `REVIEW`, `accepted_through_seq`, `received_seq`).
16. **Head chain:** the first new entry's `previous_entry_hash` must equal the stored `head_entry_hash` (or 64 zeros when the store has no head) → else `400 ENTRY_HASH_MISMATCH` with `details.supplied_entry_hash` = **the first new entry's `entry_hash`** and `details.recomputed_entry_hash` = **the expected previous/head hash** (this argument order is the frozen behaviour and is reproduced exactly), `received_seq` = first new seq, plus `quarantine:"REVIEW"`.
17. **Commit point (single transaction):** insert the new suffix into `cloud_entries`; apply the public projection to each new entry (§7); advance `accepted_through_seq`, `entry_count += new`, `head_entry_hash` = last new entry's recomputed hash, `projection_version += new`; record `(store_id, batch_id, fingerprint)`; commit. Build `IngestAck` (§5.2).

Any unexpected server failure (e.g. a projection rule violation, §7.3) rolls the whole transaction back; the PHP Cloud answers `500` with envelope `{"code":"INTERNAL_ERROR","message":"An unexpected server failure occurred.","retry_hint":{"action":"RETRY_AFTER"}}` (U11: the frozen Cloud leaks a framework-dependent empty-body 500 here; the PHP Cloud keeps the envelope but equally applies **no** partial effects).

Rate limiting for E2: the paper contract advertises 120 batches/min per store; the frozen Cloud emits the headers but does not enforce. The PHP Cloud enforces a DB-backed fixed 1-minute window per `store_id`, default limit 120, configurable (`ingest_rate_limit_per_minute`; `0` disables). On exhaustion → `429` envelope `{"code":"RATE_LIMITED","message":"Too many ingest requests for this store.","retry_hint":{"action":"RETRY_AFTER","retry_after_ms":<ms until window end>}}` + `Retry-After` header (U5; the Hub treats 429 as retryable backpressure and retains its outbox).

### 5.5 Canonical JSON profile and hash inputs [CONFIRMED — verified against all fixture hashes]

Canonical serialization (used for **all** hashes; reproduces `Kandoo.Core.CanonicalJson` + `JavaScriptEncoder.UnsafeRelaxedJsonEscaping`):

- UTF-8; no insignificant whitespace (separators `,` and `:`).
- Object keys sorted **lexicographically by Unicode code point** (equivalently: unsigned UTF-8 byte order).
- Array order preserved (no sorting for replication entries; replayed-state collection sorting belongs to the deferred restore protocol only).
- Duplicate object keys are **not canonical** → hash-input error → the corresponding `VALIDATION_ERROR` (§5.4 steps 12/13d). PHP detects duplicates with its own strict parser (U7 note: detection depth).
- Numbers: signed 64-bit integers written in plain decimal (no `+`, no `.0`, no exponents). Any other numeric form in hash-bearing JSON is an integer-profile violation.
- Strings: raw UTF-8; minimal escaping — `"` → `\"`, `\` → `\\`, and control characters: `\b \f \n \r \t` short forms, other U+0000–U+001F as `\u00XX` with **uppercase** hex; U+007F as `\u007F`. Non-ASCII (including Persian text) is **never** `\u`-escaped. (U7: the exact .NET escaping of control characters/DEL cannot occur in Hub-generated fixtures and is unverified against a live serializer; the profile above is the conservative reconstruction.)
- `true`/`false`/`null` literals as-is.

Hash inputs (all SHA-256, lowercase 64-hex output):

```text
request_hash = SHA256(canonical_json({store_id, device_id, operation_id, operation_type, base_version, payload}))
               # object with exactly these six members; canonical sorting yields:
               # base_version, device_id, operation_id, operation_type, payload, store_id
entry_hash   = SHA256(canonical_json(log_entry_without_entry_hash))
               # the 16 known LogEntry fields, DTO-normalized (missing strings = ""), root key entry_hash omitted
batch_fingerprint = SHA256(canonical_json(OperationBatch_dto))
               # the 5 known batch fields; entries = list of 16-field DTO-normalized entries, or null
issued_content_sha256 = SHA256(canonical_json(issued_payload))   # verified by the projection, never recomputed into new data
```

DTO normalization detail [CONFIRMED]: the frozen Cloud hashes the **deserialized DTO re-serialization**, not the raw wire bytes — unknown extra fields drop out, absent string fields become `""`, UUIDs re-serialize as lowercase `D`, integers as plain decimals. The PHP Cloud performs the identical normalization before hashing. Wire key order is irrelevant.

Verification record: an independent reference implementation of this profile reproduced **every** hash of the official fixtures — `entry_41_hash = ca15b6e0b3d101f57f9855c6e43871e4d5db6875d87a19b0b8b0ee8baa9975a8`, `entry_42_hash = fa0daab5586810444f1ecfb1ebf8f3f7e061a42630d77806a4e8d9aa3a98e502`, `product_request_hash = 8b6fcb64f008a9d921bf44fe7a9346eb6a8898fa569904942be8a9f36b3eb8a6`, and the intentional CLOUD-007 corruption recomputes to `387ca0f3d13d21a4b4373898e1c0c58dd44f6d7adca1ec01556027e5ae0a97b8` while supplying `ca15…`.

### 5.6 Ingest error table (exact bodies) [CONFIRMED]

| Status | `code` | `retry_hint.action` | Extra hint fields (in order) | Trigger |
|---|---|---|---|---|
| 400 | `VALIDATION_ERROR` | `FIX_REQUEST` | — | route not UUID; unparsable body; blank `batch_id`; store mismatch; envelope violation; integer-profile violation |
| 400 | `IDEMPOTENCY_KEY_REQUIRED` | `FIX_REQUEST` | — | missing/blank key |
| 400 | `REQUEST_HASH_MISMATCH` | `REVIEW` | `accepted_through_seq`, `details{expected_request_hash, supplied_request_hash}` | request hash differs |
| 400 | `INCOMPLETE_BATCH` | `RETRY_SAME` | `accepted_through_seq` | active writer, null/empty entries |
| 400 | `SEQUENCE_ORDER_VIOLATION` | `RESUME_FROM_SEQUENCE` | `expected_seq`, `received_seq`, `accepted_through_seq` | intra-batch order break |
| 400 | `ENTRY_HASH_MISMATCH` | `REVIEW` | `accepted_through_seq`, `received_seq`, `details{supplied_entry_hash, recomputed_entry_hash, quarantine:"REVIEW"}` | entry/chain/head hash failure |
| 404 | `STORE_NOT_FOUND` | `NONE` | — | unknown/invisible store |
| 409 | `IDEMPOTENCY_CONFLICT` | `REVIEW` | — | key ≠ batch_id; or known key, different fingerprint |
| 409 | `WRITER_FENCED` | `STOP_WRITER` | `accepted_through_seq`, `current_version` | stale writer identity |
| 409 | `GAP_DETECTED` | `RESUME_FROM_SEQUENCE` | `expected_seq`, `received_seq`, `accepted_through_seq` | first new seq > accepted+1 |
| 409 | `LOG_CONFLICT` | `REVIEW` | `accepted_through_seq`, `received_seq` | same seq, different/ unverifiable content |
| 409 | `OPERATION_ID_CONFLICT` | `REVIEW` | `accepted_through_seq`, `received_seq` | reused operation identity |
| 413 | `PAYLOAD_TOO_LARGE` | `FIX_REQUEST` | boundary: `max_batch_entries`, `max_batch_bytes`; entry-count: + `accepted_through_seq` | declared bytes > 1 MiB; entries > 100 |
| 429 | `THROTTLED` | `RETRY_AFTER` | `retry_after_ms:2000`, `max_batch_entries:2`, `max_batch_bytes:65536`, `accepted_through_seq` | store backpressure flag (+ `Retry-After: 2` header) |
| 429 | `RATE_LIMITED` | `RETRY_AFTER` | `retry_after_ms` | PHP-only enforcement of the advertised 120/min (U5) |
| 500 | `INTERNAL_ERROR` | `RETRY_AFTER` | — | unexpected failure; transaction rolled back, no partial effects (U11) |

Exact `message` strings for every row are fixed by the golden pairs `docs/golden/g-002…g-016*.json` (byte-exact bodies).

### 5.7 Store provisioning [UNCONFIRMED U2]

The frozen Cloud has **no HTTP endpoint** to create a store row (`POST /v1/stores` is normative-only, unimplemented); rows exist in tests via an internal `Seed()`. Without a row, ingest answers `404 STORE_NOT_FOUND` forever. The paper contract allocates `store_id` server-side at registration (OTP + lawyer-approved agreement acceptance). For the pilot, the PHP Cloud provisions stores **out of band**: CLI `bin/store.php provision <store_id> <writer_device_id> <writer_epoch>` (admin-only, never web-exposed) plus SQL migrations. A production registration flow is a later window's decision.

---

## 6. Public invoice read — `GET /i/{token}`

### 6.1 Token rules [CONFIRMED]

- **Generation (Cloud-side, at projection commit):** 16 cryptographically random bytes → base64url without padding → exactly **22 characters**, alphabet `[A-Za-z0-9_-]` (128 bits of entropy, unguessable). Tokens are minted **once per projected invoice**; retries never mint extra tokens.
- **Validation:** length 22–128 and every character in `[A-Za-z0-9_-]`. Anything else (including empty, short, or containing other characters) is *malformed* → identical 404 (§6.3). No route-level syntax validation leaks validity.
- **Token hash index:** `public_token_hash = lowercase hex SHA-256(UTF-8 token)` with a **UNIQUE index**; lookups go through the hash, never a plaintext scan.
- **Constant-time comparison:** after fetching the row by hash, the Cloud re-hashes the **stored** raw token and compares the two 32-byte digests with a fixed-time primitive (`CryptographicOperations.FixedTimeEquals` / PHP `hash_equals`). Length mismatch or comparison failure → treated exactly like "unknown" → identical 404. Unknown and unauthorized capabilities deliberately collapse into one behaviour.
- **Lifecycle filter:** only rows with `lifecycle_status ∈ {ISSUED, REVOKED, SUPERSEDED}` are visible.
- The frozen Cloud stores the raw token in the DB **solely** so trusted server-side delivery orchestration (SMS, deferred) can build the link; it is never logged, never returned by any endpoint, never echoed in errors (§6.6). PHP mirrors this (U10).

### 6.2 Rate limiting [CONFIRMED semantics, PHP storage per PM order]

Fixed 1-minute window per **client address** (frozen: `RemoteIpAddress`, bucket key `"unknown"` when absent), default limit **60 requests/minute**, configurable (`PublicToken:RateLimitPerMinute` in the frozen Cloud; `public_rate_limit_per_minute` in PHP config). The check runs **before** token lookup, on every `/i/{token}` request. Allowed requests increment the window counter; **denied requests do not**. On denial:

- `429`, body `{"code":"PUBLIC_RATE_LIMITED","message":"Too many public invoice requests.","retry_hint":{"action":"RETRY_AFTER"}}`;
- header `Retry-After: <seconds until window end, minimum 1>`;
- the usual `/i/` headers (`Cache-Control: no-store`, `Vary: Accept`) are present on **all** responses including 429/404.

Frozen storage is process-local; PHP uses a **DB-backed** counter (`public_rate_buckets`), same window semantics (PM ORDER 9 P-03). Client key = `REMOTE_ADDR`; shared-host proxy handling (`X-Forwarded-For` trust) is U4 — the conservative default is `REMOTE_ADDR` only. The paper contract scopes the bucket to "public token + source IP"; the frozen implementation scopes by source IP only; PHP follows the frozen implementation (U4).

### 6.3 Identical 404 rule [CONFIRMED]

For **malformed**, **unknown**, **unauthorized** (any server-side capability rejection) and **invisible** tokens the response is deliberately indistinguishable:

- status `404`;
- body, byte-identical: `{"code":"PUBLIC_INVOICE_NOT_FOUND","message":"The requested invoice is not available.","retry_hint":{"action":"NONE"}}`;
- headers: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, `Vary: Accept`;
- the requested token appears **nowhere** in the body or headers.

### 6.4 Content negotiation [CONFIRMED]

Checked in order, after the invoice was found (and after the legal-copy gate §6.5):

1. Query `format=json` (case-insensitive value) → JSON view (§8).
2. Else if any `Accept` header value contains `application/json` **and** none contains `text/html` → JSON view.
3. Else → HTML page (§9), `Content-Type: text/html; charset=utf-8`.

### 6.5 Legal-copy gate (D14) [CONFIRMED frozen behaviour; PHP posture UNCONFIRMED U3]

The frozen Cloud serves public pages only in `Development`/`Testing` environments; in production it returns, **after** finding the invoice: `503` `{"code":"LEGAL_COPY_UNAVAILABLE","message":"Public invoice wording is awaiting lawyer approval.","retry_hint":{"action":"ENABLE_LEGAL_COPY"}}` — a hard release gate until lawyer-approved copy exists. The rendered copy is the placeholder constant (§8: `legal_copy` fields). PM ORDER 9 P-03 explicitly orders the PHP pilot to serve the public page, so the PHP Cloud models the gate as config flag `public_legal_copy_required` (default `false` = serve; `true` = reproduce the frozen 503 exactly, golden G-022). Introducing lawyer-approved copy is a later window; **no legal claims are made anywhere in this repo.**

### 6.6 Logging and privacy rules [CONFIRMED]

- Public capability tokens must never be emitted through request/routing diagnostics (the frozen Cloud disables ASP.NET hosting/routing logs for this reason). The PHP Cloud logs no request URI, no query string, and no token; error pages/messages never echo tokens.
- The Cloud logs no customer payload, phone, OTP, proof, or credential.
- `Cache-Control: no-store` on all `/i/` responses; capability URLs are not cacheable or shareable beyond the single invoice.

---

## 7. Public projection derivation (Cloud-side, inside the ingest transaction) [CONFIRMED]

The Cloud projects **only** the Hub's successful, fully described native ISSUE result. Rules (frozen `PublicInvoiceReplicationProjection.Apply`, applied per newly appended entry, in seq order):

### 7.1 Trigger

An entry qualifies only when **all** hold; otherwise it is simply replicated with **no** projection (legacy/low-detail records never create a public invoice with invented data):

- `operation_type == "invoice.issue"`;
- `response_snapshot` is an object with `status == "SUCCEEDED"` and `response.invoice` an object;
- `response.invoice.status == "ISSUED"`;
- `response.invoice.invoice_id` is a non-empty UUID and equals the entry `payload.invoice_id`;
- `response.invoice.display_number` is an integer > 0;
- `response.invoice` carries `issued_payload` (object), `issued_content_sha256` (string), `issued_at` (string), `items` (array);
- `SHA256(canonical_json(issued_payload)) == issued_content_sha256` (verification only — the Cloud never rewrites it);
- every item has non-blank `description`, `quantity_millis > 0`, `unit_price_rials ≥ 0`, `line_total_rials ≥ 0`; at least one item exists.

Frozen Hub shape of `issued_payload` (informational): canonical `{"display_number":N,"invoice_id":"<uuid>"}`; items carry `{invoice_item_id, line_number, description, quantity_millis, unit_price_rials, line_total_rials, product_id}`.

### 7.2 Derived row contents

| Column | Source rule |
|---|---|
| `public_token` / `public_token_hash` | Newly generated (§6.1), minted once. |
| `digital_invoice_id` | New server-side UUID (projection identity, distinct from `invoice_id`). |
| `invoice_id`, `store_id` | From the entry. |
| `store_name` | `payload.store_name` (trimmed, non-blank) of the **earliest replicated successful `invoice.draft_create`** whose `response.response.invoice.invoice_id` matches; fallback `"Store {store_id:D}"`. |
| `customer_phone` | `payload.customer_phone` of that same draft entry, normalized to `+<digits>` (international `+` syntax required); else NULL. Stored, not exposed by the public views in v1. |
| `display_number` | From the issued invoice record (Hub-allocated; the Cloud allocates none). |
| `lifecycle_status` | `'ISSUED'` at projection time. |
| `issued_at` | From the issued invoice record, stored UTC. |
| `issued_payload`, `issued_content_sha256` | Stored **as delivered** (never re-canonicalized into new claims). |
| `line_items` | JSON list of `{product_name ← item.description, quantity_millis, unit_price_rials, line_total_rials}` in delivered order. |
| `payable_rials` | `Σ line_total_rials` (checked 64-bit addition; overflow fails the transaction). This is a projection of **recorded** line totals — the Cloud invents no prices. |
| v1.1 columns (§11) | Recorded as delivered when present; never computed. |

Uniqueness: one projection per `(store_id, invoice_id)`; a repeat projection attempt is a no-op (`ON CONFLICT DO NOTHING` / `INSERT IGNORE`) — first write wins, no token is re-minted.

### 7.3 Projection failures [CONFIRMED behaviour; PHP envelope U11]

A qualifying trigger whose data violates §7.1 rules (bad IDs/numbers, hash mismatch, incomplete record, empty lines, invalid draft phone) **fails the whole ingest transaction** — no entries, no projection, no watermark movement (fail closed; frozen internal codes: `PUBLIC_INVOICE_PROJECTION_INVALID`, `..._INCOMPLETE`, `PUBLIC_INVOICE_HASH_MISMATCH`, `PUBLIC_INVOICE_LINE_INVALID`, `PUBLIC_INVOICE_LINES_MISSING`, `PUBLIC_INVOICE_ID_INVALID`, `PUBLIC_INVOICE_NUMBER_INVALID`, `PUBLIC_INVOICE_TEXT_INVALID`). The frozen Cloud surfaces this as an unhandled 500; the PHP Cloud returns the `INTERNAL_ERROR` envelope (§5.4 step 17 note).

---

## 8. Public JSON view (`PublicInvoiceView`) [CONFIRMED]

Exact body (field order fixed; schema `docs/schemas/public-invoice-view.response.schema.json`; golden G-017):

```json
{"product_name":"Kandoo digital invoice","store_name":"…","display_number":42,"lifecycle_status":"ISSUED","issued_at":"2026-01-02T03:04:05+00:00","digital_invoice":{"invoice_id":"<uuid>","store_id":"<uuid>","issued_payload":{…as stored…},"issued_content_sha256":"<64hex>","issued_at":"2026-01-02T03:04:05+00:00"},"legal_copy":{"resource_id":"D14-PLACEHOLDER","version":"0","content":"D14 placeholder — needs lawyer approval","signature":"NOT_SIGNED_NEEDS_LAWYER_APPROVAL"},"legal_copy_status":"needs lawyer approval"}
```

- `product_name` is the fixed English wire constant `Kandoo digital invoice` (the Persian product wording فاکتور دیجیتال کندو is the UI/brand line on the HTML page; the wire constant is frozen contract).
- `display_number` ≥ 1 (issued-only projection; pre-issue nulls are never exposed).
- `lifecycle_status ∈ {ISSUED, REVOKED, SUPERSEDED}`.
- **Line items and payable are NOT part of the JSON view** in the frozen contract (they render only in HTML). Store isolation is strict: one token exposes exactly one invoice, never another store's data.
- `legal_copy` is the D14 placeholder constant (never a legal claim); `content` contains an em dash `—` sent as raw UTF-8.

## 9. Public HTML page [CONFIRMED invariants; PHP re-implementation]

Single light page, byte-weight target < ~60 KB excluding fonts; **no external/CDN requests** — inline CSS, local fonts only, no `http://` or `https://` URLs anywhere in the document, no JavaScript required. Invariants asserted by goldens G-018/G-023:

- `<!doctype html>`, `<html lang="fa" dir="rtl">`, `<meta charset="utf-8">`, viewport meta; one `<style>` block; one `:root` token block.
- Page header/eyebrow line: **فاکتور دیجیتال کندو**.
- Design tokens as CSS custom properties (exact values): `--honey: #F2A900; --ink: #2B1D0E; --cream: #FFF8E7; --wax: #F5E9C8; --green: #256B43; --review: #9A3B0A;` (plus derived paper/muted/line/space tokens). Light theme only (`color-scheme: light`).
- Local Vazirmatn fonts via `@font-face` from `/assets/fonts/Vazirmatn-Regular.ttf` and `…-Bold.ttf` (OFL-licensed, byte-pinned: SHA-256 `b69fd4c680b8f3f225feabcc655a2c585d97627b8f5f5c0f9985e894069f3a56` and `f635fdbea28f265de395ba83b4b1570dcf2f58d13c65469e61903b1c2d2ae723`), fallback `Tahoma, Arial, sans-serif`.
- Content blocks: header (eyebrow + product name + store name); facts grid — شماره فاکتور (display_number), وضعیت (lifecycle_status), تاریخ و زمان صدور (جلالی، Asia/Tehran) as `YYYY/MM/DD، HH:MM:SS`, شناسه فاکتور (invoice UUID, `dir="ltr"`); a **data-gap section** (محدودیت‌های داده صدور) stating that `customer_name` and `payment_method` are not available as separate fields in this invoice's issued data (v1.0 truthfulness — replaced by real values when v1.1 fields are recorded, §11); line-items table — کالا / تعداد (هزارم واحد) / قیمت واحد (ریال) / جمع (ریال); total line — `مبلغ قابل پرداخت: {N0} ریال`; legal placeholder line (the D14 placeholder text, never a legal claim).
- Formatting: all dynamic text HTML-escaped; integers thousand-grouped invariant-style (`1,000`, `25,000`); quantity column shows raw `quantity_millis`; the token never appears in the page.
- Jalali conversion: `issued_at` converted to **Asia/Tehran** local time, then Gregorian→Jalali (Persian calendar, break-based jalaali algorithm — verified anchors: 2024-03-20→1403/01/01, 2025-03-21→1404/01/01, 2026-01-02→1404/10/12); time shown `HH:mm:ss` Tehran.
- Print support: `@media print` rules and `@page` margin.

## 10. Data model (public projection & replication replica)

### 10.1 Frozen PostgreSQL schema [CONFIRMED] → PHP MariaDB 10.6 equivalent

| Frozen table | Columns (types) | PHP/MariaDB mapping |
|---|---|---|
| `cloud_stores` | `store_id uuid PK`, `active_writer_device_id uuid`, `active_writer_epoch bigint`, `accepted_through_seq bigint`, `entry_count bigint`, `head_entry_hash text NULL`, `projection_version bigint`, `throttled boolean` | `CHAR(36)` UUIDs, `BIGINT`, `CHAR(64) NULL`, `TINYINT(1)` |
| `cloud_entries` | `(store_id, operation_seq) PK`, `operation_id`, `entry_hash`, `entry_json jsonb`; `UNIQUE(store_id, operation_id)`; FK→stores cascade | `entry_json LONGTEXT` (canonical entry JSON) |
| `cloud_batches` | `(store_id, batch_id) PK`, `fingerprint` | `batch_id VARCHAR(191)` (U8), `fingerprint CHAR(64)` |
| `public_invoices` | `public_token text PK`, `public_token_hash text UNIQUE (index)`, `digital_invoice_id uuid`, `invoice_id uuid`, `store_id uuid`, `store_name text`, `display_number bigint`, `lifecycle_status text`, `issued_at timestamptz`, `issued_payload jsonb`, `issued_content_sha256 text`, `payable_rials bigint`, `line_items jsonb`, `customer_phone text NULL`, `UNIQUE(store_id, invoice_id)` | `public_token VARCHAR(128)`, `public_token_hash CHAR(64) UNIQUE`, `issued_at DATETIME(6)` UTC, payload/line_items `LONGTEXT` JSON-as-delivered, plus nullable v1.1 columns (§11) |
| `cloud_restore_states` | restore-protocol only | deferred (restore is out of PHP pilot scope) |
| — | — | PHP adds `public_rate_buckets` (DB-backed rate limiting) and `schema_migrations` (ordered .sql migrations); neither is contract-visible |

Storage fidelity note: the frozen Cloud stores `issued_payload` as `jsonb` (Postgres re-orders keys on read-back); the PHP Cloud stores the payload **exactly as delivered** (`LONGTEXT`), which is wire-compatible and strictly more faithful (U16). `line_items` JSON uses the field order of §7.2.

### 10.2 Visibility

Only the public projection is served. Replicated entries/outcomes/snapshots are never exposed by any v1 endpoint (log-read endpoints are normative-only, §2.2). No master data (products, inventory, customers, drafts) is stored beyond the replicated log and the projected row (D5).

### 10.3 Retention/size

Pilot budget ≈ 200–300 MB total DB (D5): entries dominate; the replicated log is append-only and never rewritten. Nightly `mysqldump` via host cron (our own backup duty, D5). Rate-bucket rows are pruned by cron.

### 10.4 Web-root layout (PHP, shared host)

Document root contains only `public/` contents (`index.php` front controller, `.htaccess`, `assets/`). Application code (`src/`), `config.php`, `migrations/`, `docs/`, `tests/`, `bin/` live **outside** the web root (or are denied by `.htaccess` when the whole tree is uploaded — defence in depth). `config.php` sits one level above the web root and is loaded by the bootstrap; missing config fails closed with a plain error. `base_url` (e.g. `https://invoice.example-cloud.test`) is configuration and the single source of every absolute link — no domain is ever hardcoded (D4).

## 11. Proposed v1.1 extension [PROPOSED v1.1 — exact PM-ordered shape; UNCONFIRMED U14 until the Hub emits it]

Additive, optional, backward compatible (v1 wire keeps working unchanged; absence changes nothing). Recorded values only — the Cloud **never computes, infers, defaults, or enriches** them:

```json
{
  "customer_name": "string, optional — recorded as delivered by the Hub",
  "payments": [
    {"type": "card-reader | card-to-card | cash | credit", "amount": "integer ≥ 0, unscaled, in the recorded currency_unit"}
  ],
  "currency_unit": "rial | toman — per-store unit AS RECORDED by the Hub; never assumed by the Cloud",
  "remaining_balance": "integer ≥ 0 — a RECORDED value; never computed (not Σpayments − payable, not payable − Σpayments)"
}
```

Rules: fields live on the issued invoice record inside `response_snapshot.response.invoice` (same channel as `items`); the projection copies them verbatim into nullable columns (`customer_name`, `payments`, `currency_unit`, `remaining_balance`); `payments` must be an array of objects with exactly `type` (one of the four literals) and `amount` (integer); invalid v1.1 content fails the ingest transaction like §7.3 (fail closed, no partial projection). The public page shows recorded values when present; when absent it keeps the truthful v1.0 data-gap notice (§9). The JSON view exposes them as optional fields `customer_name`, `payments`, `currency_unit`, `remaining_balance` (schemas: `docs/schemas/v1-1/*.schema.json`). Currency wording on the page uses the recorded unit (`ریال` / `تومان`); with no recorded unit, v1.0 field names (`*_rials`) remain the record and the page shows ریال exactly as the frozen contract does. No legal/tax meaning is attached to any of these fields.

## 12. Deferred surface (documented, NOT implemented in PHP pilot v1)

- **Customer OTP / accounts (frozen E5–E8):** issue challenge `{phone}` (normalized `+<digits>`, Idempotency-Key required ≤128 chars, 6-digit code, code lifetime 300 s, max 5 attempts, 3 issues/900 s window, session 30 days; responses `201 {status:"OTP_SENT", challenge_id, expires_at}` / `200 DUPLICATE` / `429 RATE_LIMITED` / `409 OPERATION_ID_CONFLICT|OTP_DELIVERY_INCOMPLETE` / `503` provider-not-configured), verify `{code}` (6 digits; attempt-limited), bearer access tokens (32 random bytes base64url; stored as SHA-256), claim-by-token, list invoices per account. **Next window per PM** (Cloud is the only SMS sender, D3; provider `api.iranpayamak.com`, fixed notification pattern + OTP pattern with one variable `code`; limits per D4; base URL from config, never hardcoded).
- **Normative-only operations** (§2.2): registration, pairing, locks, log reads, restore protocol (manifest/entries/verification/activation, `state_hash` over canonical replayed state), delivery SMS/QR/share endpoints.
- **Restore projections** (`cloud_restore_states`, `PublishRestoreProjection`, `ActivateRestoredWriter`): in-process APIs of the frozen Cloud, no HTTP surface; out of scope.

## 13. Error-code subset relevant to v1 (from the 51-code matrix) [CONFIRMED]

`VALIDATION_ERROR 400/FIX_REQUEST` · `IDEMPOTENCY_KEY_REQUIRED 400/FIX_REQUEST` · `REQUEST_HASH_MISMATCH 400/REVIEW` · `INCOMPLETE_BATCH 400/RETRY_SAME` · `SEQUENCE_ORDER_VIOLATION 400/RESUME_FROM_SEQUENCE` · `ENTRY_HASH_MISMATCH 400/REVIEW` · `UNAUTHENTICATED 401/REAUTHENTICATE` (PHP optional key only) · `STORE_NOT_FOUND 404/NONE` · `PUBLIC_INVOICE_NOT_FOUND 404/NONE` · `IDEMPOTENCY_CONFLICT 409/REVIEW` · `OPERATION_ID_CONFLICT 409/REVIEW` · `WRITER_FENCED 409/STOP_WRITER` · `GAP_DETECTED 409/RESUME_FROM_SEQUENCE` · `LOG_CONFLICT 409/REVIEW` · `PAYLOAD_TOO_LARGE 413/FIX_REQUEST` · `PUBLIC_RATE_LIMITED 429/RETRY_AFTER` · `RATE_LIMITED 429/RETRY_AFTER` · `THROTTLED 429/RETRY_AFTER` · `INTERNAL_ERROR 500/RETRY_AFTER` · `LEGAL_COPY_UNAVAILABLE 503/ENABLE_LEGAL_COPY` · `SERVICE_UNAVAILABLE 503/RETRY_AFTER`.

## 14. UNCONFIRMED register (every gap, no silent assumptions)

| # | Item | PHP pilot posture (most conservative compatible choice) |
|---|---|---|
| U1 | Replication transport auth: paper contract mandates `DeviceBearer`; frozen wire sends **no** credential and frozen Cloud checks none. | Optional pre-shared `X-Kandoo-Replication-Key` (config, default off = frozen-identical). Owner/PM decision needed before requiring it. |
| U2 | Store provisioning: no frozen HTTP path creates `cloud_stores` rows. | Out-of-band CLI/migration provisioning; `404 STORE_NOT_FOUND` until provisioned. |
| U3 | D14 legal-copy 503 gate vs PM order to serve the public page in the pilot. | Config flag `public_legal_copy_required` (default `false` = serve page; `true` = exact frozen 503). Placeholder constants remain; no legal claims. |
| U4 | Public rate-limit scope (paper: token+IP; frozen: IP only) and proxy headers on shared hosting. | Frozen semantics: per client IP, fixed 1-min window, default 60, DB-backed; `REMOTE_ADDR` only (no `X-Forwarded-For` trust by default). |
| U5 | Ingest 120/min: advertised by frozen headers, not enforced by frozen code. | Enforced DB-backed per store, configurable, default 120 (`RATE_LIMITED` envelope per paper matrix). |
| U6 | Paper path `GET /v1/public/invoices/{public_token}` vs implemented `GET /i/{token}`. | Implement `/i/{token}` only (frozen wire + SMS-link reality); paper path documented for a future window. |
| U7 | Exact .NET string-escaping of control characters/DEL in canonical JSON (cannot occur in Hub-generated data; no live serializer available to verify byte casing). | Conservative reconstruction (§5.5): uppercase `\u00XX`, DEL escaped. All official fixture hashes reproduce exactly with this profile. |
| U8 | `batch_id`/`Idempotency-Key` length: paper says 1–128 safe chars; frozen code has no explicit bound (Postgres `text` PK). | MariaDB `VARCHAR(191)`; longer keys rejected `VALIDATION_ERROR` (documented tightening risk). |
| U9 | Fractional-second digits in `issued_at` serialization (STJ trims trailing zeros; Postgres stores µs). | Emit fractional digits only when non-zero; fixtures use whole seconds. |
| U10 | Raw `public_token` stored in DB (frozen) — needed for deferred server-side delivery orchestration. | Mirror frozen storage; token never logged/echoed. Revisit (hash-only) when SMS window defines delivery. |
| U11 | Frozen 500s on projection failure are framework-dependent (empty body). | `INTERNAL_ERROR` JSON envelope; identical no-partial-effect guarantee. |
| U12 | Deferred endpoints (E5–E8, §2.2) exist in frozen Cloud but are out of PM scope for this window. | Generic unknown-route 404 in PHP pilot v1. |
| U13 | UUID wire leniency (braces/N/P forms) is a framework side effect, not contract text. | Accept the same forms, normalize to lowercase D; reject others as malformed. |
| U14 | v1.1 fields (§11) are PM-ordered proposals; the frozen Hub does not emit them yet; exact placement inside the issued record is inferred from where `items` live. | Pass-through storage + rendering when present; fail closed on invalid content; no computation ever. |
| U15 | Font URL path (`/fonts/...` frozen vs `/assets/fonts/...` PHP web-root layout). | `/assets/fonts/...`; byte-pinned font files keep the frozen SHA-256 digests. |
| U16 | `issued_payload` storage form (frozen `jsonb` re-orders keys; PHP stores as delivered). | Store as delivered (wire-compatible superset of fidelity). |

## 15. Golden request/response pairs (P-01 deliverable; replayed by P-04 contract tests)

Index — all files in `docs/golden/`, all values FAKE synthetic fixtures (vector IDs / `TEST-…`), format documented in `docs/golden/README.md` and `docs/schemas/golden-case.schema.json`:

| Golden | Covers | Expected |
|---|---|---|
| `g-001-health` | E1 | 200 `{"status":"ok"}` |
| `g-002-ingest-accept` | CLOUD-001 contiguous batch | 200 ACCEPTED ack, watermark 42, head `fa0d…` |
| `g-003-ingest-duplicate` | CLOUD-002 duplicate delivery | 200 DUPLICATE, 0 ingested, 2 duplicate |
| `g-004-ingest-gap` | CLOUD-003 gap | 409 GAP_DETECTED resume metadata |
| `g-005-ingest-sequence-order` | CLOUD-004 reorder | 400 SEQUENCE_ORDER_VIOLATION, no partial ingest |
| `g-006-ingest-committed-retry` | CLOUD-005 lost-response retry | 200 ACCEPTED then 200 DUPLICATE |
| `g-007-ingest-throttled` | CLOUD-006 backpressure | 429 THROTTLED + Retry-After: 2 + limits |
| `g-008-ingest-entry-hash-mismatch` | CLOUD-007 corruption | 400 ENTRY_HASH_MISMATCH, quarantine REVIEW, exact recomputed hash |
| `g-009-ingest-writer-fenced` | CLOUD-008 stale writer | 409 WRITER_FENCED, current_version 4 |
| `g-010…g-016` | STORE_NOT_FOUND / route UUID / idempotency / byte limit / empty batch / entry-count limit | exact 4xx envelopes |
| `g-017-public-json-view` | E3 JSON view | 200 exact `PublicInvoiceView` bytes |
| `g-018-public-html-view` | E3 HTML page | 200 markers (RTL, tokens, Jalali, escaping, no external URLs) |
| `g-019/g-020-public-404-*` | identical 404 rule | byte-identical bodies across malformed/unknown |
| `g-021-public-429-rate-limited` | rate limit | two 404s then 429 PUBLIC_RATE_LIMITED + Retry-After 1..60 |
| `g-022-public-503-legal-copy-gate` | D14 gate (config on) | 503 LEGAL_COPY_UNAVAILABLE |
| `g-023-replication-issue-projection-e2e` | §7 projection end-to-end | ACCEPTED ack → DB-resolved token → exact JSON view + HTML markers + row effects |

Divergence policy (PM ORDER 9 P-04): if the PHP implementation cannot pass a golden pair, the contract and the golden pair are **not** changed silently — the divergence is reported explicitly with analysis.

## 16. Extraction provenance (frozen C# sources → this document)

| Source file (repo `majid-ghanbari-dost/Kandoo` @ `4780ed22…`) | Extracted into |
|---|---|
| `src/Kandoo.Cloud/Program.cs` | §2, §5.4 (1–3), §6.2–6.5, headers |
| `src/Kandoo.Cloud/PostgresCloudStore.cs` | §5.4 (4–17), §5.6, §6.1, §7.2, §10.1 |
| `src/Kandoo.Cloud/Contracts.cs` | §3, §5.1, §5.2, error envelope |
| `src/Kandoo.Cloud/PublicInvoiceContracts.cs` | §8, legal-copy constants |
| `src/Kandoo.Cloud/PublicInvoiceReplicationProjection.cs` | §7 |
| `src/Kandoo.Cloud/PublicTokenGenerator.cs`, `PublicTokenSecurity.cs` | §6.1, §6.2 |
| `src/Kandoo.Cloud/PublicInvoiceHtml.cs` + `wwwroot/fonts` | §9, §2.1 E4 |
| `src/Kandoo.Cloud/CustomerAccount*.cs`, `SmsContracts.cs`, `SmsService.cs`, `FarazSmsProvider.cs` | §12 (deferred) |
| `src/Kandoo.Core/CanonicalJson.cs` | §5.5 |
| `src/Kandoo.Core/JalaliDateConverter.cs`, `PhoneNormalizer.cs`, `OperationLog.cs` | §9, §7.2 |
| `src/Kandoo.Hub/HubCloudReplicationClient.cs`, `ReplicationContracts.cs`, `SqliteHubTransaction.cs`, `HubOperationService.cs` | §5.1–5.3, §7.1 Hub shapes |
| `docs/cloud-api-contract.md`, `docs/openapi.yaml`, `docs/sync-protocol.md`, `docs/sync-protocol-test-vectors.json` | §2.2, §4.1, §5.5, §6, §13, §15 |
| `tests/Kandoo.Cloud.Tests/*` (CloudApiTests, PublicTokenEndpointSecurityTests, PublicInvoiceHtmlTests, R1VerticalSliceTests) | golden-pair expectations, §6, §9 invariants |

*End of frozen contract document (P-01).*
