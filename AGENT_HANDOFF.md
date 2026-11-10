# KANDOO Cloud PHP — permanent agent handoff

This document is maintained under standing order SO-01.

## Current task status

Manager-authoritative status as supplied in SO-01/STEP-2-PHP rev.2; acceptance is the Coordination Manager's verdict, not a CI result.

| Task | Status | Tag | Commit |
|---|---|---|---|
| P-05 | accepted | `w11-P-05` | `97b078978bb7ecc838a13246d972a8187f0f7efb` |
| P-06 | accepted | `w11-P-06` | `360b275fa62756036a33411e56c33f7c99e168a9` |
| P-07 | accepted | `w11-P-07` | `040d581e8fcabbf8c0a15adade6afdcff5e73e19` |
| P-08 | reported — awaiting PM GitHub spot-check | `w11-P-08` | `0e9aa38dce774ecf58d642bef6efc07884f3f466` |
| P-09 | reported — awaiting PM GitHub spot-check | `w11-P-09` | `4794776d87944b1df4d240a243e4a237d89a99ec` |
| T-DOC-C2C3 | delivered; manager review approved; report only, no repo change | none | none |
| P-10 | queued, scope not yet announced | see blocker-record below | see blocker-record below |
| P-11 | stretch, scope not yet announced | see blocker-record below | see blocker-record below |
| P-12 | stretch, scope not yet announced | see blocker-record below | see blocker-record below |

**blocker-record — P-10/P-11/P-12 status discrepancy:** The manager-authoritative queue/scope statements above conflict with existing remote branch history and pinned tags. `w11-P-10` points to `c5011428643b49b43d2d0baa86ad692e6ff723d4` (shared-hosting guide and isolated sample self-test); `w11-P-11` points to `69ad7aa30ee8b062f25dab846df086c0787cbf6e` (local in-process health measurement); `w11-P-12` points to `62c5558b9f1faf10ac5e305a340af21bc5d7fdc7` (empty marker commit for chat-only report). Their tag CI runs were successful: [P-10](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/42506816795), [P-11](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/42506941285), [P-12](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/42507043719). CI success is not manager acceptance. Await manager reconciliation; do not move or recreate tags.

## Decisions and open blockers

- `docs/CLOUD-CONTRACT.md` is the normative frozen contract: 58,755 bytes, SHA-256 `6d4f491a84e93803ab61831084713e5fe7209fdbfbc3d22d794037975037663e`, merged at `d3671024cc60c1437716a24ffdf62a454bd5fc74`. It is never rewritten; changes are made only through an addendum.
- v1.1 addendum chain: schemas delivered; T-DOC-C2C3 approved; technical challenge done; draft 1 is in PM review. PHP implementation work, including byte-for-byte addendum commit and later C4 400-behavior and `V11ProjectionTest` update, is authorized only after final PO approval and by separate order. **blocker-record — v1.1:** final approval and separate implementation order pending. Current P-09 projection invalid-data behavior returns 500, whereas PO C4 specifies 400 `VALIDATION_ERROR` with `FIX_REQUEST`; do not silently claim the new behavior is implemented.
- The v1.1 schema files below are PO-approved normative references, pinned by their SHA-256; changes require the formal contract-change process. C2 hash-scope details await addendum approval; C3 requires an explicit version marker, not presence-based versioning. PO forbids a marker in core invoice content without proven technical necessity.
- P-08 and P-09 are reported, not accepted, pending PM GitHub spot-check and Coordination Manager verdict. Real SMS stays gated behind the P-08 adapter; provider acceptance is not proof of delivery.
- Keep the CI policy failing on any skipped test for P-08 onward. Secure HTTPS, credentials, and provider delivery checks remain deployment responsibilities. Telegram is a skeleton.

## Verified reference paths

- Frozen contract: `docs/CLOUD-CONTRACT.md`.
- Issued invoice schema: `docs/schemas/v1-1/issued-invoice-v1-1.schema.json`; SHA-256 `04af72824d15bd17d01a98604265c39bf8d5d5da8ed11d406fe74a28bd5ad15c`.
- Public invoice view schema: `docs/schemas/v1-1/public-invoice-view-v1-1.schema.json`; SHA-256 `78620a233199dbe191e9c9cf4264a0080aaba744a53e2a820056bf98f4992650`.
- Existing repository decisions: `docs/DECISIONS.md`; existing report: `docs/REPORT-H-P1.md`. Current v1.1 addendum draft and cross-review decision document repository paths: to be confirmed. T-DOC-C2C3 was delivered in chat, not committed.
- Existing task documentation: `docs/CONTRACT-ADDENDUM-1.md`, `docs/ADMIN-P-06.md`, `docs/CUSTOMER-AUTH-P-07.md`, `docs/SMS-P-08.md`, `docs/V11-P-09.md`, `docs/DEPLOY-GUIDE-FA.md`, `docs/LOAD-P-11.md`. These historical documents do not supersede later manager decisions.

## Delivery and git boundaries

- Work only on the active session branch `arena/ae9da477-kandoo-cloud-php`; do not push or merge to `main`. No force-push; do not move tags; `w11-P-*` tags stay pinned to their commits. Reports are independent commits.
- Documents are exchanged only via the approved single-file courier path, with size and SHA-256 verified on both sides.
- Under SO-01, update and commit this handoff before every final report; follow any explicit report-only exception or later manager order. On lost context or unclear tasks consult this document first, and report unresolved conflicts rather than guessing.
