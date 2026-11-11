KANDOO CLOUD CONTRACT ADDENDUM v1.1 - DRAFT 2 (RECONSTRUCTION)
================================================================
Status   : DRAFT 2 - NOT NORMATIVE - for PM review, then Product
           Owner final approval.
Issued   : 2026-11-10 by the Coordination Manager.
Artifact : ART-006 (this draft). Document id: KANDOO-ADDENDUM-V1-1.

PROVENANCE
Draft 1 (artifact ART-005, 7,945 bytes, sha256
85deb4b35f6c720e7791115ef9837d712c1a0f198208c6fa5b2acfd79c29db3d)
was lost from the manager archive (incident M-1, 2026-11-10) before
reaching normative status. This Draft 2 is a reconstruction built
exclusively from documented, verified inputs:
  - PM ORDER 11 (2026-11-07): task definitions P-09, C-45b, E-40;
  - Product Owner decisions A1, C2 (in principle), C3 (rejection of
    presence-based detection), C4;
  - approval of "Option 3" (2026-11-09): schemas AND addendum;
  - T-DOC-C2C3 (hash-coverage matrix + version-marker options),
    delivered and manager-approved;
  - the C#+Python technical challenge answers (2026-11-09/10);
  - the frozen contract itself (sha256 6d4f491a...7663e).
On Product Owner approval this document becomes the normative
"Contract Addendum v1.1" and Draft 1 is void. The frozen contract is
never edited; this addendum is the only change instrument.

SECTION 1 - SCOPE AND PRECEDENCE
1.1 This addendum extends CLOUD-CONTRACT.md (frozen at 58,755
    bytes, sha256 6d4f491a84e93803ab61831084713e5fe7209fdbfbc3d22d
    794037975037663e, merge commit d3671024cc60c1437716a24ffdf62a45b
    d5fc74) for invoice payload version 1.1 ("v1.1").
1.2 On conflict, this addendum prevails over the contract text,
    explicitly including the "proposed shape" wording of contract
    section 11, which this addendum completes and, where stated
    below, supersedes.
1.3 The contract itself remains byte-frozen; all v1.1 rules live
    here and in the pinned schemas.

SECTION 2 - NORMATIVE DATA REFERENCE (PO DECISION A1)
2.1 The mandatory data-contract reference for v1.1 invoice payloads
    is the pair of JSON Schema files:
      docs/schemas/v1-1/issued-invoice-v1-1.schema.json
        (2,011 bytes, sha256
         04af72824d15bd17d01a98604265c39bf8d5d5da8ed11d406fe74a28b
         d5ad15c)
      docs/schemas/v1-1/public-invoice-view-v1-1.schema.json
        (2,174 bytes, sha256
         78620a233199dbe191e9c9cf4264a0080aaba744a53e2a820056bf98f
         4992650)
2.2 The schemas are pinned by the hashes above. Any change to them
    follows the formal contract-change process only.
2.3 The four optional v1.1 fields live inside
    response_snapshot.response.invoice (the same channel as
    "items" in contract section 11) and are ADDITIVE and OPTIONAL:
      - customer_name     : string, optional (length 1..255 per
                            schema);
      - payments          : array of {type, amount}; type is one
                            of card-reader | card-to-card | cash |
                            credit;
      - currency_unit     : "rial" | "toman", per store;
      - remaining_balance : recorded value (never computed).
    Absence changes nothing; the v1 wire format keeps working
    unchanged.

SECTION 3 - HASH COVERAGE (PO DECISION C2; T-DOC-C2C3)
3.1 Valid v1.1 field values are inside the integrity envelope:
    response_snapshot is one of the 16 LogEntry fields (contract
    section 5.1) and entry_hash covers all 16 fields (section 5.5);
    the batch fingerprint (section 5.4 step 12) covers all entries.
3.2 No additional portable commitment is required for ingest
    integrity (T-DOC-C2C3 conclusion, manager-approved). The
    semantics of issued_content_sha256 are unchanged: it covers
    issued_payload only, is verification-only, never recomputed,
    and is itself inside response_snapshot.
3.3 The Cloud never computes, infers, defaults or enriches v1.1
    fields (contract section 11 rule, restated; unchanged).

SECTION 4 - VERSION MARKER (PO DECISION C3; RECOMMENDATION D)
4.1 Presence-based version detection is FORBIDDEN. When the Hub
    emits v1.1 data it MUST stamp an explicit marker:
      response_snapshot.response.invoice.invoice_schema_version
    with the exact string value "1.1".
4.2 Absence of the marker means legacy v1.0 semantics. The Cloud
    MUST NOT infer v1.1 from the presence of any field.
4.3 The marker sits outside the core invoice content, is
    data-covered by entry_hash (section 3.1), and is stable on
    replay. ACK fields and ACK matching rules (contract section
    5.2) are unchanged by the marker.
4.4 Mixed batches (legacy entries and v1.1 entries together) are
    valid: validation is per-entry (contract section 5.4) and the
    batch fingerprint is unaffected by marker presence.
    Operational note (non-normative): a per-store emission
    boundary is the recommended rollout pattern, not a
    requirement.
4.5 The field name and placement above constitute
    "Recommendation D" and become final upon Product Owner
    approval of this addendum.

SECTION 5 - ERROR BEHAVIOR AT INGEST (PO DECISION C4)
5.1 Invalid v1.1 content - schema-violating values in the four
    v1.1 fields, or an invalid value in the version marker - fails
    ingest with HTTP 400 and error code VALIDATION_ERROR.
5.2 This paragraph explicitly OVERRIDES the whole-transaction 500
    path that contract section 11 indicated for invalid v1.1
    content. Fail-closed semantics are kept: no partial
    projection.
5.3 FIX_REQUEST is retry GUIDANCE for the emitter ("fix the
    request, do not blindly retry"); it is not a substitute for
    the 400 API error.
5.4 Projection failures of contract section 7.3 remain 500, with
    the existing U11 divergence note (frozen-Cloud empty-body 500
    vs PHP envelope) unchanged in effect for the Hub.

SECTION 6 - CANONICAL JSON AND U7 (OPEN POINT - PO CHOICE)
The Hub and the Cloud must produce identical canonical JSON for
entry_hash. U7 (contract section 14) records that the exact .NET
escaping of control characters and DEL is unverified; the
conservative reconstruction is uppercase \u00XX with DEL as
\u007F (contract section 5.5). v1.1 string fields (customer_name
in particular) may legally carry such characters. Two closures:
  (a) PROHIBIT control characters and DEL in v1.1 string fields:
      low emitter cost (validate/sanitize at emission), but a
      real data restriction (some customer names become
      unrecordable);
  (b) PIN the conservative escaping profile (uppercase \u00XX,
      DEL as \u007F) as NORMATIVE for both stacks, plus shared
      cross-stack golden vectors including control chars/DEL:
      medium emitter cost, no data restriction, consistent with
      section 5.5's existing profile.
MANAGER RECOMMENDATION: (b).
FINAL CHOICE: Product Owner - mark the chosen letter in the
approval reply. If the approval is silent on this point, option
(b) applies.

SECTION 7 - ADOPTION AND DELIVERY
7.1 The Cloud advertises v1.1 only when enabled by config (P-09
    behavior). The Hub activates v1.1 emission (C-45b) only when
    the Cloud advertises v1.1.
7.2 This addendum is committed to the private repository as a new
    file (target path docs/CONTRACT-ADDENDUM-V1-1.md; the final
    filename is confirmed at commit time against existing
    addendum files) and delivered to the Python repository via
    the approved single-file courier path (size + sha256
    verification on both sides).
7.3 Blocked tasks E-40, E-42, E-43 and C-45b resume on delivery
    of the approved addendum plus a resumption amendment from the
    Coordination Manager.

SECTION 8 - VERSIONING OF THIS DOCUMENT
8.1 Document id KANDOO-ADDENDUM-V1-1; draft 2; 2026-11-10.
8.2 On approval: status becomes NORMATIVE, the sha256 of the
    approved text is recorded in the coordination ledger, and
    further changes follow only the formal contract-change
    process.
