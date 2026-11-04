# Golden request/response pairs (P-01 deliverable)

Product: **فاکتور دیجیتال کندو (Kandoo Digital Invoice)**.

Every file in this directory is one golden case: an exact request (method, path, headers,
byte-exact body) plus the exact expected response (status, relevant headers, byte-exact JSON
body or contract markers for HTML), plus the database precondition (`given`) and durable
postconditions (`effects`). The P-04 contract tests replay each case against the PHP Cloud
kernel in CI and assert the golden response.

> **FAKE DATA NOTICE — applies to every file here.** All identifiers, tokens, hashes, keys and
> phone numbers are **synthetic test fixtures**: fixed vector IDs from the frozen C# repo's
> `docs/sync-protocol-test-vectors.json` (declared `test_only: true`, "No production
> credentials or customer data") and obviously fake `TEST-…` values. None of them is a real
> credential or token, and production configuration must never accept them.

Format: `docs/schemas/golden-case.schema.json`. Matching rules:

- `headers_match: "subset"` — only the listed response headers are asserted (lower-cased names), exact values; `header_ranges` asserts inclusive numeric ranges (e.g. `retry-after`).
- `body_match: "exact"` — `body_text` must equal the response body **byte-for-byte**.
- `body_match: "markers"` — every string in `body_markers` must appear in the body and every string in `body_markers_absent` must not (used for the HTML page: the PHP page is a re-implementation, so structural invariants are contract, frozen .NET bytes are not).
- `request.public_token_from` — the `{public_token}` placeholder in `path` is resolved from the database (public tokens are Cloud-generated, 128-bit, and deliberately not predictable).
- `request.content_length` — overrides the declared `Content-Length` seen by the kernel (the frozen Cloud checks the declared header before parsing the body).
- `config` — per-case configuration overrides applied by the harness (e.g. `public_rate_limit_per_minute: 2`).

Sources: frozen .NET Cloud behaviour (`src/Kandoo.Cloud` @ `4780ed22…` in `majid-ghanbari-dost/Kandoo`), the official synthetic vectors `CLOUD-001…CLOUD-008`, and the frozen Cloud's own test assertions. Entry/request hashes inside these files were independently recomputed with the P-02 canonical JSON profile at generation time and match the published fixture constants (`entry_41_hash`, `entry_42_hash`, `product_request_hash`, and the intentional CLOUD-007 corruption hash).

| File | Contract point (CLOUD-CONTRACT.md) |
|---|---|
| `g-001-health.json` | §2.1 E1 |
| `g-002-ingest-accept.json` | §5.4 step 17, CLOUD-001 |
| `g-003-ingest-duplicate.json` | §5.2 DUPLICATE, CLOUD-002 |
| `g-004-ingest-gap.json` | §5.4 step 14 GAP_DETECTED, CLOUD-003 |
| `g-005-ingest-sequence-order.json` | §5.4 step 13b, CLOUD-004 |
| `g-006-ingest-committed-retry.json` | §5.4 step 12 revalidation, CLOUD-005 |
| `g-007-ingest-throttled.json` | §5.4 step 9 THROTTLED, CLOUD-006 |
| `g-008-ingest-entry-hash-mismatch.json` | §5.4 step 13d, CLOUD-007 (intentional corruption) |
| `g-009-ingest-writer-fenced.json` | §5.4 step 5 fencing, CLOUD-008 |
| `g-010-ingest-store-not-found.json` | §5.4 step 4 |
| `g-011-ingest-store-id-not-uuid.json` | §5.4 step 1 |
| `g-012-ingest-idempotency-key-required.json` | §5.4 step 6 |
| `g-013-ingest-idempotency-conflict.json` | §5.4 step 7 |
| `g-014-ingest-payload-too-large-bytes.json` | §5.4 step 2 |
| `g-015-ingest-incomplete-batch.json` | §5.4 step 10 |
| `g-016-ingest-too-many-entries.json` | §5.4 step 11 |
| `g-017-public-json-view.json` | §6.4, §8 |
| `g-018-public-html-view.json` | §9 |
| `g-019-public-404-malformed.json` | §6.3 (byte-identical with g-020) |
| `g-020-public-404-unknown.json` | §6.3 (byte-identical with g-019) |
| `g-021-public-429-rate-limited.json` | §6.2 |
| `g-022-public-503-legal-copy-gate.json` | §6.5 (config flag on) |
| `g-023-replication-issue-projection-e2e.json` | §7 end-to-end projection + §6 read |

**Divergence policy (PM ORDER 9):** if the PHP implementation cannot pass a golden pair, the
contract and the golden pair are not changed silently — the divergence is reported explicitly
with analysis.
