D1. One app, several roles: on first run the user picks store, customer, or both and can switch later. Customer mode is Android-only for now. Customer mode is part of the MVP in a minimal form (role choice, OTP login, invoice list per store, invoice page, "new invoice" banner); buying from stores comes later.
D2. Production Cloud for the pilot = PHP 8.1+ and MariaDB 10.6 on a shared host (e.g., plan E1: no shell/Composer assumed, config outside web root, outbound HTTPS to api.iranpayamak.com, cron every minute, shared outbound IP so never rely on IP allow-listing). The existing .NET Cloud is FROZEN as-is and kept for later.
D3. All SMS are sent ONLY by the Cloud. The Hub never holds the SMS key.
D4. SMS policy (provider confirmed): fixed notification pattern (no variables), OTP pattern (one variable "code"); limits: 2 OTP per number per rolling 24 h, 3 wrong entries per request, a device may use at most 3 numbers per day, per-IP limits. Domain/base address is configuration (a "base URL" setting), never hardcoded.
D5. Hosting limits to design for: DB about 200-300 MB, about 2000 visits/day on the first plan, nightly DB backup by our own cron; the Cloud keeps only the public/compact form of each invoice (the Hub holds the master copy).

## PHP Cloud implementation decisions (P-03)

PD1. Jalali conversion is a self-contained PHP port of the Borkowski break algorithm, faithful to jalaali-js v2 (jalCal takes the JALAALI year; mathematical modulo). Verified anchors: 2024-03-20 → 1403/01/01, 2025-03-21 → 1404/01/01, 2026-01-02 → 1404/10/12 (no intl on shared hosts).
PD2. Rate windows are fixed 1-minute buckets aligned to floor(unix/60)*60, counted in the database (atomic hit + prune by cron). Limit 0 disables. Ingest is keyed by normalized store id AFTER the route UUID parse; public is keyed by client IP. UNCONFIRMED: the frozen contract does not state keying/window details (U5) — values are conservative and configurable.
PD3. Advertised ingest RateLimit-* headers stay the frozen fixed values (120/119/60) even under a custom configured limit — §5.2 records them as fixed advertised values; enforcement is separate.
PD4. Wire entries must carry payload/outcome/response_snapshot as JSON objects (§5.4 13a); explicit nulls are envelope violations, matching the frozen DTO round-trip.
PD5. Fingerprint handling: known (store_id,batch_id) with EQUAL fingerprint → natural DUPLICATE re-validation; with a DIFFERENT fingerprint → 409 IDEMPOTENCY_CONFLICT. No golden covers the conflict path — pinned by PHP tests only (flagged UNCONFIRMED in the report).
PD6. Projection failures never enrich or guess: a strict rule violation rolls the whole batch back and answers the frozen INTERNAL_ERROR envelope; the silent-skip trigger (non-invoice operation types) keeps the projection exactly as delivered (D5/§7).
PD7. The public page renders v1.1 fields ONLY when recorded; otherwise an explicit data-gap section names the missing fields (customer_name/payment_method) instead of inventing values (U14). Remaining balance is displayed as recorded, never computed.
PD8. Golden replay harness (tests/Support/GoldenHarness.php) is gateway-agnostic: the same 23 cases run against MemoryGateway (local) and MariaDbGateway (CI) through one code path; divergences are reported explicitly, never absorbed. operation_id_count in effects means "operation ids carried by the batch" (g-002/g-003/g-006 all assert 2 across accept/duplicate/committed-retry).
PD9. bin/cron.php prunes rate buckets older than the previous window every minute (idempotent; never touches contract data). bin/store.php provisions/throttles stores for operations (CLI-only, HTTP-guarded).

## W11 Product Owner decisions (PM ORDER 11)

D-A One app, several roles (store / customer / both), switchable. Customer mode is Android-only for now. Identity = verified mobile number; roles live under that number. Store accounts need only a verified mobile at first.
D-B Replication authentication is MANDATORY in production: one secret key per store, sent by the Hub, checked by the Cloud (constant-time); off only in tests. This is NOT business-licence verification (that is post-MVP).
D-C Customer OTP rules (replace the frozen contract's numbers; record every divergence as an addendum, never edit the frozen file): (1) a number can request a code ONLY if at least one invoice exists for it; (2) each time a store sends an invoice-notification SMS for that number, that number is allowed TWO verification codes; (3) after two, the customer must contact Kandoo support on Telegram; the Product Owner resets the counter from the admin page; (4) a device verified once is remembered and does not ask again. Also: 3 wrong entries per request then lock; one device may try at most 3 different numbers per day; per-IP limits; all values configurable.
D-D SMS (Faraz): per-invoice link variables are impossible (provider confirmed). ONE fixed notification pattern (no variables, fixed link) and ONE OTP pattern (ONE variable: code; the recipient number is the request's recipient field, not part of the text). API: POST https://api.iranpayamak.com/ws/v1/sms/pattern, header Api-Key. Pattern codes, line number, key, and the base URL are configuration. No sandbox exists: CI uses a fake provider; the live test is manual by the owner. Shared host => the outbound IP is shared and can change: never rely on IP allow-listing.
D-E Product organisation: the numeric tag is only an identifier (short, stable, optional). Category is separate: optional two-level GROUP and SUBGROUP chosen with cascading selects; core fields: name, type, color, price, numeric tag, stock; optional purchase price; store-private custom fields. Holoo codes are kept as an optional "old code" and are searchable.
D-F Holoo: the store's data is read from its SQL Server with the manager's consent (read-only account). Two sources run in parallel when Kandoo is NOT the primary software: (1) PRIORITY: direct read-only connection to the Holoo database; (2) FALLBACK: print capture. The analysis of a Holoo backup shows a plain SQL Server backup of a database with ~330 tables; invoices live in FACTURE/FACTART, customers in CUSTOMER, products in ARTICLE, groups in M_GROUP/S_GROUP.
D-G Roles on the Hub: seller (invoice only), operator (invoice + define products), manager (everything); profit visible to the manager only; line prices editable by operator and manager only.
D-H Hosting for the pilot: shared host E1 class (PHP 8.1+, MariaDB, cron every minute, outbound HTTPS open). Nothing is bought until launch readiness.

2026-11-10 - Contract Addendum v1.1 (KANDOO-ADDENDUM-V1-1, docs/CONTRACT-ADDENDUM-V1-1.md, 7,841 bytes, sha256 4206c23f18fa4cc2f1f5f216099aec51a3ab6480eabf8af07b16863c0c385e69) APPROVED NORMATIVE by Product Owner reply (relayed by PM). U7 closed as option (b): conservative escaping profile pinned for both stacks (uppercase \u00XX, DEL as \u007F) plus shared cross-stack golden vectors including control characters, DEL, Persian text with ZWNJ (U+200C), and the Arabic letters yeh (U+064A) and kaf (U+0643). E-40/E-42/E-43 and C-45b resume on delivery per addendum section 7.3.

Pinned schemas verified already present in-repo at docs/schemas/v1-1/ (byte-identical to the addendum section 2.1 references; no schema commit needed).

## ORDER 14 Product Owner decisions — current (D-A through D-K)

These decisions supplement earlier history without deleting it; where older summaries differ, the current decision below applies. The frozen contract is changed only via an approved addendum.

**D-A — Application and identity.** One app supports store, customer, or both, with switching. Customer mode is Android-only for now. Identity is a verified mobile number.

**D-B — PRODUCTION REPLICATION AUTHENTICATION IS MANDATORY.** Each device has its own secret scoped to its store; the Hub is the only device in the MVP. This is transport authentication, not business-licence verification. Test-only disabling does not authorize disabling production authentication.

**D-C — Customer OTP.** A code may be requested only when an invoice exists for the number. Each delivered invoice notification grants two codes; thereafter direct the customer to Telegram/support and allow the Product Owner to reset the number's counter in admin. Remember a verified device with configurable lifetime and revocation. Lock a challenge after three wrong entries; limit each device to three different numbers per day and enforce separate per-IP request and verification limits. All limits are configurable; customer authentication is off by default.

**D-D — Faraz SMS.** Cloud sends SMS using one fixed notification pattern without per-invoice link variables and an OTP pattern with the single code variable. POST `https://api.iranpayamak.com/ws/v1/sms/pattern` with `Api-Key`; pattern codes, line number, key and base URL are configurable. There is no sandbox and no IP allow-list. Provider acceptance is not proof of delivery.

**D-E — Products.** Numeric tag is only an identifier; group/subgroup is optional and two-level. Purchase price and Holoo old code are optional.

**D-F — Holoo.** With store manager consent prefer direct read-only SQL Server access; print capture is the fallback. FastReport `.fr3` thermal print and FACTURE/FACTART/CUSTOMER/ARTICLE are relevant. `Sum_Price = FNaghd + FCheck + FNesieh + Card + Takhfif`; walk-in customer 00001 is approximately 87%; use one non-stock service article.

**D-G — Permissions.** Seller handles invoices; operator handles invoices and products; manager has all permissions and alone sees profit.

**D-H — Hosting.** Shared-host E1 class for the pilot; purchase only at launch readiness.

**D-I — Window scope.** E-43 is waived for this window; E-47 and H-2 are the basis.

**D-J — Canonical profile.** No production data exists. The pinned conservative U7 profile applies from now to canonical hashing of all payloads: uppercase `\u00XX` controls and `\u007F` DEL. Regenerate prior test fixtures from the shared vectors and require PHP/C# parity; no implicit v1.1 gate.

**D-K — AI boundaries.** `kando-internal-ai-v01` is frozen; `kandoo-internal-engine-v1` is a separate agent outside this order.
