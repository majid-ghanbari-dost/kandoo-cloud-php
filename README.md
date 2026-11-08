# kandoo-cloud-php

Production Cloud for **فاکتور دیجیتال کندو (Kandoo Digital Invoice)** — PHP 8.1+ / MariaDB 10.6
re-implementation of the frozen Kandoo Cloud contract, built for a shared host (no shell, no
Composer on the host; deploy by copy/upload). The existing .NET Cloud in
`majid-ghanbari-dost/Kandoo` stays **frozen as-is**; this repo is the new production Cloud
built strictly to the same contract (decision D2).

No legal or tax claims are made anywhere in this product, and there is **no tax-authority
integration**. The Cloud stores only the compact **public projection** of each invoice; the
local Hub holds the master copy (D5).

## What lives here

| Path | Purpose |
|---|---|
| `docs/CLOUD-CONTRACT.md` | The extracted contract (FROZEN from creation, task P-01). Every endpoint, the replication protocol, public-token rules, data model, the proposed v1.1 extension, and the UNCONFIRMED register. |
| `docs/DECISIONS.md` | Recorded decisions D1–D5 (PM ORDER 9). |
| `docs/schemas/` | JSON Schemas for every request/response body (+ proposed v1.1). |
| `docs/golden/` | Golden request/response pairs — all values are FAKE synthetic fixtures. Replayed by contract tests in CI. |
| `public/` | The web root: front controller + `.htaccess` + local assets (added in P-02). |
| `src/`, `bin/`, `migrations/`, `config.example.php` | Application code, CLI tools, ordered SQL migrations, example config (added in P-02). Real `config.php` lives **outside** the web root and is never committed. |
| `tests/` | PHPUnit tests, including the golden-pair contract tests (P-04). |
| `tools/ci/` | CI-side validation scripts. |

## Deployment layout (shared host, no shell)

```
<app root>/            ← uploaded tree; NOT the document root
├── config.php         ← real config, created from config.example.php (never committed)
├── config.example.php ← obviously FAKE placeholders only
├── src/  bin/  migrations/  docs/  tests/  tools/   ← denied by .htaccess (defense in depth)
└── public/            ← the host's DOCUMENT ROOT points here
    ├── index.php      ← front controller (everything routes through it)
    ├── .htaccess      ← rewrite-all except real files under /assets/
    └── assets/fonts/  ← local Vazirmatn (OFL), byte-pinned — no CDN, no external requests
```

`config.php` sits **one level above the web root** and the bootstrap fails **closed** with a
plain error when it is missing. `base_url` in the config is the single source of every
absolute link (D4) — no domain is hardcoded anywhere.

## Ground rules

- **CREATE-ONLY workflow:** work happens on the session branch with one commit group + one new
  tag per task (`stable-p-01`, `stable-p-02`, `stable-p-03`, `stable-p-04`, `stable-h-p1`).
  Merges/approvals/closes happen only on the PM/Product-Owner side. Never push to `main`.
- **No credentials in this repo, ever.** Docs, tests and golden examples use obviously fake
  values (`TEST-…`) and are marked fake.
- All absolute links come from the configured `base_url` (D4) — no domain is ever hardcoded.
- SMS/OTP, customer pages, deployment guide and hosting tests are **deferred** to a later window.

## CI

GitHub Actions (`.github/workflows/ci.yml`): contract-docs/golden validation, and — from P-02 —
PHP 8.1 + a MariaDB 10.6 service running PHPUnit (unit suite; from P-04 also the contract
suite, which replays every golden pair in `docs/golden/` on both the memory and MariaDB
gateways — see `docs/CONTRACT-REPLAY.md`).
