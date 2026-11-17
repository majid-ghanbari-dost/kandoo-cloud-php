# KANDOO Cloud PHP — permanent agent handoff

This document is maintained under standing order SO-01.

## ORDER 15 in progress (16 November 2026)

New session branch `arena/f729618f-kandoo-cloud-php` started at `af3a758e52eb9b3c2521ee317d4395b58021f75f`, exactly equal to origin/main at start. ORDER 15 supersedes earlier "do not start until issued" notes. P-20 Windows kit candidate delivered at tag `o15-P-20` -> `91f305f8989a252624c3a663e9ed3cff722bd93d`; branch CI https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026313434 and tag CI https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026403257 green with MariaDB and no PHPUnit skips. CI tests PHP provisioning on Linux, not Windows PowerShell or owner machine; Windows end-to-end remains unverified and manager acceptance pending. P-21–P-25 remain pending in the specified order, then optional P-26. Demo is restricted to trusted RFC1918 LAN, real SMS disabled; HTTP is unencrypted, so no public/guest networks or real data. P-20 admin restricted to the owner machine. Follow branch/tag CI and handoff/report cycle; no task acceptance claimed before manager review.

P-21 in progress: reference design URL requires sign-in in this environment; `docs/questions/Q-P21-DESIGN-BOARD.md` records the missing reference and safe approximation. Customer page uses explicit v2 tokens and existing recorded-only receipt; exact tile fidelity unverified. Demo HTTP cookies are allowed only under the validated private-LAN demo profile; production retains Secure cookies. P-22 outbox not yet implemented, so demo OTP cannot yet be delivered. CI and tag must be verified before claiming delivery.

## ORDER 14 status

- Starting tip `24d2d57429bf7186111f664a921083b84364ca06`; O14-PHP rev.2 authorizes work on this same branch; PR #2 remains open and untouched.
- P-13 and P-14 are delivered and manager accepted. Customer auth stays default-off; device identifiers are replaceable cookies/tokens and per-IP limits use the direct peer only. The 15 Nov PM retention ruling resolved P-15; P-15 delivered with green CI and manager accepted; ORDER 14 PHP closeout is complete. P-16, P-17 and P-18 are manager accepted.
- D-J: no production data exists; the pinned U7 profile applies from now and shared parity vectors are required. Full D-A through D-K are to be appended to `docs/DECISIONS.md` in P-17 without deleting history.

## O14 continuation — P-15 historical blocker (resolved by ruling below)

- P-14 delivered: `o14-P-14` points to `4606188dc4725a727c1182687180da25b6d53fe1`; push CI https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43687682569 and tag CI https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43687728002 green. CI enforces no skipped PHPUnit tests. Manager accepted P-14 on 15 Nov.
- Historical P-15 scope/precedence question (resolved by the ruling below): the instruction to retain idempotency keys for 30 days does not distinguish `cloud_batches` (replication batch fingerprints) from OTP keys. Frozen `docs/CLOUD-CONTRACT.md` sections 4 and 5 require conflict detection for a known batch key without a 30-day expiry; deleting batch fingerprints changes that contract. OTP idempotency is currently embedded in `cloud_otp_challenges`, while P-15 also calls for challenge retention of 24 hours; maintaining the 30-day OTP key behavior requires separating its replay metadata. The instruction to retain SMS rows 90 days also needs a classification: `cloud_sms_announcements` is the persistent proof of verified delivery and the live P-14 per-notification OTP budget, while `cloud_sms_delivery_requests` prevents duplicate/uncertain sends and stores provider receipts. Deleting these blindly can destroy active entitlement, permit duplicate sends, or erase audit evidence, contrary to the active-data/audit preservation requirement. Request a ruling on which records are disposable and whether the 30-day key window excludes contract-governed replication keys; do not change the frozen contract or guess. Manager reordered on 15 Nov: proceed with P-16, P-17, P-18 while P-15 awaits ruling; P-16 vectors are independent of retention.

- P-16: 57 vectors in `docs/vectors/canonical-vectors-v1.json`, including unchanged V1–V12, all controls, DEL, Unicode and int64 boundaries. PHP parity test uses that file. Branch CI 43690071248 and tag CI 43690115611 green; `o14-P-16` -> `76bb3a6ec6de1eb64067806dab0ed5183a736574`. Full vector file reported in one code fence for C# C-66.

- P-17: append-only D-A..D-K current decisions in `docs/DECISIONS.md`, explicitly highlighting D-B; `o14-P-17` -> `09b412dcd5ce2aad0c02ef048668aca3cbb14a80`; branch CI 43690282296 and tag CI 43690341208 green. P-15 subsequently delivered per ruling below.

## ORDER 14 reordered handoff and tag ledger (P-18)

| Task | Status | Tag | Full commit | Personally inspected green CI |
|---|---|---|---|---|
| P-13 | manager accepted | `o14-P-13` | `7994de85591c504d12ec4a0b2f9e8074fd2c611b` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43395902795 |
| P-14 | manager accepted | `o14-P-14` | `4606188dc4725a727c1182687180da25b6d53fe1` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43687728002 |
| P-15 | manager accepted | `o14-P-15` | `7c531620ffab5ad1c62f773293a2e16aab00e791` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43815994810 |
| P-16 | manager accepted | `o14-P-16` | `76bb3a6ec6de1eb64067806dab0ed5183a736574` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690115611 |
| P-17 | manager accepted | `o14-P-17` | `09b412dcd5ce2aad0c02ef048668aca3cbb14a80` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690341208 |
| P-18 | manager accepted | `o14-P-18` | `a8c926496b1856736824ab1d110c8c7317b9bd9c` | https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690474495 |

P-16 file `docs/vectors/canonical-vectors-v1.json` has 57 vectors and is independent of P-15; complete contents were sent in chat for C# C-66. P-17 only adds decisions and corrects escape notation, preserving history. P-15 was delivered and manager accepted; ORDER 14 PHP closeout is complete. Do not start ORDER 15. PR #2 stays untouched. Do not claim manager acceptance for items not explicitly accepted. Preserve existing tags and frozen contract. P-18 commit and tag CI are now recorded above.

## P-15 ruling and accepted delivery

PM ruling received in `PHP_P15_RULING.md` (3,931 bytes, SHA-256 `6b894c0e3d55537075d8f33e6aa8c31e2af2b9cdce89ff9e868c7471827b7331`). Replication fingerprints, confirmed announcements, all SMS request rows and audit records never pruned. OTP replay metadata separated and backfilled; OTP challenges 24h, expired sessions 30d, idempotency metadata 30d, undeliverable data 90d configurable. Failed OTP deliveries wait both challenge and undeliverable periods; pending sends are retained. No separate disposable undeliverable SMS rows exist. The launch privacy minimization review is explicitly deferred to owner/legal. Delivered as `o14-P-15` at `7c531620ffab5ad1c62f773293a2e16aab00e791`; branch CI 43815906617 and tag CI 43815994810 green, including DB tests and no skipped PHPUnit tests. Manager accepted on 15 Nov based on the submitted report, without independent git/CI verification because the private repo was inaccessible to the manager. ORDER 14 PHP is closed; do not start ORDER 15 until issued.

## گزارش پایانی اردر ۱۴ — محور PHP

| کار | تگ | SHA کامل | CI بررسی‌شده | نتیجه |
|---|---|---|---|---|
| P-13 | `o14-P-13` | `7994de85591c504d12ec4a0b2f9e8074fd2c611b` | [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43395902795) | پذیرفته شد |
| P-14 | `o14-P-14` | `4606188dc4725a727c1182687180da25b6d53fe1` | [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43687728002) | پذیرفته شد |
| P-15 | `o14-P-15` | `7c531620ffab5ad1c62f773293a2e16aab00e791` | [شاخه 43815906617](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43815906617)، [تگ 43815994810](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43815994810) | سبز؛ پذیرفته شد — اردر ۱۴ بسته شد |
| P-16 | `o14-P-16` | `76bb3a6ec6de1eb64067806dab0ed5183a736574` | [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690115611) | تایید مستقل و پذیرفته شد |
| P-17 | `o14-P-17` | `09b412dcd5ce2aad0c02ef048668aca3cbb14a80` | [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690341208) | پذیرفته شد |
| P-18 | `o14-P-18` | `a8c926496b1856736824ab1d110c8c7317b9bd9c` | [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/43690474495) | پذیرفته شد |

در ۱۵ نوامبر ارجاع‌های remote همه شش تگ با SHAهای دفتر بالا تطبیق داده شدند؛ نسبت به دفتر ثبت‌شده جابه‌جایی مشاهده نشد. پذیرش P-15 بر تطبیق گزارش با حکم بنا شد؛ مدیر به مخزن خصوصی برای راستی‌آزمایی مستقل git/CI دسترسی نداشت. ارجاعات دو اجرای CI P-15 توسط ایجنت بررسی شدند و هر دو سبز بودند. فایل قرارداد منجمد SHA-256 `6d4f491a84e93803ab61831084713e5fe7209fdbfbc3d22d794037975037663e` را حفظ کرده است.

### درس‌های آموخته اردر ۱۴

پرسش یکپارچه و صریح از PM درباره تعارض‌های retention، با ثبت مانع و ادامه کارهای مستقل، از حدس و حذف داده فعال جلوگیری کرد. CI پایگاه‌داده با منع skip، تگ بعد از CI سبز، و تأیید مستقل بردارها تحویل را قابل پیگیری کرد. دفعه بعد دامنه retention و استثناهای قرارداد را پیش از پیاده‌سازی مشخص کنیم، برای انتقال داده‌های خصوصی فایل واحد با اندازه و SHA را مبنا قرار دهیم، و وضعیت پذیرش و دفتر تگ‌ها را هم‌زمان با رسید هر تصمیم به‌روزرسانی کنیم.

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
