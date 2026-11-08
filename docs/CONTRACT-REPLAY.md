# CONTRACT REPLAY — golden pairs vs the PHP Cloud (P-04)

Product: **فاکتور دیجیتال کندو** (Kandoo Digital Invoice).

## What is replayed

Every case in `docs/golden/*.json` (23 files, frozen since P-01, schema
`docs/schemas/golden-case.schema.json`) is replayed end-to-end against the PHP Cloud
by `tests/Support/GoldenHarness.php`:

1. `config` overrides are merged onto a fake base configuration and validated fail-closed.
2. `given` seeds the gateway (stores, entries, batches, public invoices — including
   `{public_token}` resolution: the harness looks the stored token up by
   `(store_id, invoice_id)`, never invents one). Projection-only cases (g-017…g-022)
   omit the parent `cloud_stores` row; on MariaDB the FK `fk_public_invoices_store`
   requires it, so the harness seeds an **inert synthetic parent** (epoch 1, all
   counters zero) for any child row referencing an unseeded store. This is fixture
   completion only — no golden file, no assertion and no implementation behaviour is
   changed (first observed as CI errors on 92d12bbe, both stable-p-04 runs red).
3. Every `steps[i].request` is dispatched through the real `Kernel`
   (deterministic clock `2026-01-02T03:04:05Z`, fake client IP `203.0.113.7`);
   `content_length` overrides reproduce declared-length behaviour (U4).
4. Assertions per step: status; `headers` exact (case-insensitive names);
   `header_ranges` numeric bounds; body — byte-exact (`body_match: "exact"`) or
   `body_markers` present / `body_markers_absent` absent.
5. `effects` are asserted against the durable state: store row counters
   (`accepted_through_seq`, `entry_count`, `head_entry_hash`, `projection_version`,
   `throttled`), `operation_id_count` (= operation ids carried by the replayed batch,
   per the generator semantics shared by g-002/g-003/g-006), and the public projection
   row (including `public_token_shape` and the SHA-256 token-hash index integrity).

## Where it runs

- **CI (GitHub Actions, job `phpunit`)**: `tests/Contract/GoldenReplayTest.php` runs each
  golden TWICE — on `MemoryGateway` (always) and on `MariaDbGateway` against the
  MariaDB 10.6 service after `bin/migrate.php` (self-skips when no DB is reachable).
- **Local (no DB required)**: `php tools/dev/replay-goldens.php [filter]` replays all
  goldens on the memory gateway; the dev shim runner also executes the contract tests.

## Divergence policy (PM ORDER 9)

- A divergence **fails loudly** with the full problem list
  (`CONTRACT DIVERGENCE (memory|mariadb): …`). Divergences are **reported explicitly and
  never silently changed**: the golden files and `docs/CLOUD-CONTRACT.md` are frozen;
  only the PHP implementation may be corrected — or an explicit PM decision (recorded in
  `docs/DECISIONS.md` and the Persian report) may reinterpret a case.
- Known gaps disclosed as UNCONFIRMED (no golden exists; pinned by PHP tests only):
  the batch fingerprint-conflict path (`409 IDEMPOTENCY_CONFLICT` "different content"),
  the ingest `RATE_LIMITED` 429 (U5), replication-key `401` enforcement (U1), and the
  v1.1 recorded-values HTML replacement (U14).
