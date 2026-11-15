# KANDOO Cloud PHP — permanent agent handoff

This document is maintained under standing order SO-01.

## ORDER 14 status

- Starting tip `24d2d57429bf7186111f664a921083b84364ca06`; O14-PHP rev.2 authorizes work on this same branch; PR #2 remains open and untouched.
- P-13: delivered, tag `o14-P-13` -> `7994de85591c504d12ec4a0b2f9e8074fd2c611b`; branch CI 43395803883 and tag CI 43395902795 green. P-14 is in progress: remembered-session login redirect and revocation/logout are uncommitted worktree changes; per-device number limits, per-IP controls, admin OTP reset, full DB tests and green CI remain outstanding. P-15 through P-18 are pending; no acceptance verdict claimed.
- D-J: no production data exists; the pinned U7 profile applies from now and shared parity vectors are required. Full D-A through D-K are to be appended to `docs/DECISIONS.md` in P-17 without deleting history.

## Current task status

W11-P-CLOSEOUT: the PHP W11 axis is code-complete and accepted by the Coordination Manager; the window is closing. Acceptance is the manager verdict, not merely a CI result.

| Task | Status | Tag | Commit |
|---|---|---|---|
| P-05 | accepted | `w11-P-05` | `97b078978bb7ecc838a13246d972a8187f0f7efb` |
| P-06 | accepted | `w11-P-06` | `360b275fa62756036a33411e56c33f7c99e168a9` |
| P-07 | accepted | `w11-P-07` | `040d581e8fcabbf8c0a15adade6afdcff5e73e19` |
| P-08 | accepted | `w11-P-08` | `0e9aa38dce774ecf58d642bef6efc07884f3f466` |
| P-09 | accepted | `w11-P-09` | `4794776d87944b1df4d240a243e4a237d89a99ec` |
| P-C4 | accepted; branch and tag CI green | `w11-P-C4` | `7ea79d0b97365477e211cf2942b1726b3c960c6f` |
| P-U7 | accepted; canonical profile and V1–V12 parity, 12/12; branch CI 43030337013 / tag CI 43030436480 green; PM spot-check remains on the verification list | `w11-P-U7` | `8c173a20b2f38e68577cae420e6e01fa50ee0869` |
| T-DOC-C2C3 | delivered; manager review approved; report only, no repo change | none | none |
| P-10 | MUST per ORDER 11; delivered (`docs/DEPLOY-GUIDE-FA.md` + `bin/selftest.php` + `.github/workflows/ci.yml`); CI 42506759719 / 42506816795 green; accepted | `w11-P-10` | `c5011428643b49b43d2d0baa86ad692e6ff723d4` |
| P-11 | STRETCH per ORDER 11 (CI load harness, 50–100 concurrent, error rate + p95); delivered in REDUCED scope: local in-process `/health` measurement, not a production-capacity benchmark (`docs/LOAD-P-11.md` + `tools/dev/load-health.php`); CI 42506890030 / 42506941285 green; accepted, with delivered reduced scope recorded | `w11-P-11` | `69ad7aa30ee8b062f25dab846df086c0787cbf6e` |
| P-12 | STRETCH per ORDER 11 (handoff + tag ledger, always last); delivered as chat-only report + marker commit (same pattern as E-44 in the Python repo); durable handoff now lives in `AGENT_HANDOFF.md` under SO-01; empty marker commit; CI 42506994164 / 42507043719 green; accepted, delivered per pattern | `w11-P-12` | `62c5558b9f1faf10ac5e305a340af21bc5d7fdc7` |

Resolved 2026-11-10: PM ORDER 11 text provided by the PM; scopes were announced; ledger reconciled by the Coordination Manager.

## Decisions and open blockers

- Action-1 CI: cancelled-superseded, non-blocking, per manager disposition in W11-P-C4-PROCEED; rerun unavailable (platform).
- W11-P-U7 requires uppercase `\u00XX` for all controls including short-escape characters, and `\u007F` for DEL, in canonical hashing for all payloads. The ordinary noncanonical response writer retains its prior escapes. The manager registered the deferred decision “U7 historical-hash replay compatibility” in the window-closeout decision set: historical stored entries containing short-escape control characters may have hashes produced under the previous profile; review is required, with no silent rewriting.
- W11-P-SHA-CONFIRM resolved the closeout-proof typo: `9b50cdd65091a5d103e42053b9c2714144755e49` changes only `AGENT_HANDOFF.md`; restate its stat line in the final W11 closeout report.

- `docs/CLOUD-CONTRACT.md` is the normative frozen contract: 58,755 bytes, SHA-256 `6d4f491a84e93803ab61831084713e5fe7209fdbfbc3d22d794037975037663e`, merged at `d3671024cc60c1437716a24ffdf62a454bd5fc74`. It is never rewritten; changes are made only through an addendum.
- Contract Addendum v1.1 is approved normative by the Product Owner: `docs/CONTRACT-ADDENDUM-V1-1.md`, 7,841 bytes, SHA-256 `4206c23f18fa4cc2f1f5f216099aec51a3ab6480eabf8af07b16863c0c385e69`; committed at `f7f40a5a91b228bfb80d6219d00aeb3367d69fdc`. The schemas below are normative and pinned; U7 option (b) pins conservative escaping and requires shared cross-stack vectors including control characters, DEL, Persian ZWNJ and Arabic yeh and kaf. C4 specifies 400 `VALIDATION_ERROR` / `FIX_REQUEST` for invalid explicitly versioned v1.1 content or invalid marker; implementation is in `7ea79d0b97365477e211cf2942b1726b3c960c6f`, with green branch CI `42994983100` and tag CI `42995084021`. Marker absence uses legacy semantics; section 7.3 projection failures remain 500.
- Pinned schemas verified already present in-repo at docs/schemas/v1-1/ (byte-identical to the addendum section 2.1 references; no schema commit needed). Both were present at base commit `d3671024cc60c1437716a24ffdf62a454bd5fc74`; the issued schema is 2,011 bytes and the public-view schema is 2,174 bytes.
- C3 requires an explicit version marker, not presence-based versioning; follow the normative addendum. E-40/E-42/E-43 and C-45b resume per addendum section 7.3 and the manager's resumption instruction.
- P-08 and P-09 are accepted per W11-P-CLOSEOUT. Real SMS stays gated behind the P-08 adapter; provider acceptance is not proof of delivery.
- Keep the CI policy failing on any skipped test for P-08 onward. Secure HTTPS, credentials, and provider delivery checks remain deployment responsibilities. Telegram is a skeleton.

## Verified reference paths

- Frozen contract: `docs/CLOUD-CONTRACT.md`.
- Issued invoice schema: `docs/schemas/v1-1/issued-invoice-v1-1.schema.json`; SHA-256 `04af72824d15bd17d01a98604265c39bf8d5d5da8ed11d406fe74a28bd5ad15c`.
- Public invoice view schema: `docs/schemas/v1-1/public-invoice-view-v1-1.schema.json`; SHA-256 `78620a233199dbe191e9c9cf4264a0080aaba744a53e2a820056bf98f4992650`.
- Existing repository decisions: `docs/DECISIONS.md`; existing report: `docs/REPORT-H-P1.md`. Normative v1.1 addendum: `docs/CONTRACT-ADDENDUM-V1-1.md`; cross-review decision document repository path: to be confirmed. T-DOC-C2C3 was delivered in chat, not committed.
- Existing task documentation: `docs/CONTRACT-ADDENDUM-1.md`, `docs/ADMIN-P-06.md`, `docs/CUSTOMER-AUTH-P-07.md`, `docs/SMS-P-08.md`, `docs/V11-P-09.md`, `docs/DEPLOY-GUIDE-FA.md`, `docs/LOAD-P-11.md`. These historical documents do not supersede later manager decisions.

## Delivery and git boundaries

- Work only on the active session branch `arena/ae9da477-kandoo-cloud-php`; do not push or merge to `main`. No force-push; do not move tags; `w11-P-*` tags stay pinned to their commits. Reports are independent commits.
- Documents are exchanged only via the approved single-file courier path, with size and SHA-256 verified on both sides.
- Under SO-01, update and commit this handoff before every final report; follow any explicit report-only exception or later manager order. On lost context or unclear tasks consult this document first, and report unresolved conflicts rather than guessing.
