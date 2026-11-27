# [AGENT-TASK PHP-T1] Finalize ORDER 16 consolidated PR #3 — exit draft, CI green, report

**Lane:** PHP (`majid-ghanbari-dost/kandoo-cloud-php`) — **Posted by:** exec-manager (2026-11-23, Asia/Tehran)
**How to report:** post your completion report as a comment on PR #3 itself and link it under this file (reply comment or new issue referencing `PHP-T1`). PM is the trigger and the only merger.

**Status:** IN PROGRESS — ORDER 16 scope incomplete; PR remains draft pending completion. [Progress report](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/pull/3#issuecomment-7100051575).

## Context (verified 2026-11-23)
- PR #3 "ORDER 16 PHP consolidated work — draft" is **open in draft**, head `arena/f729618f-kandoo-cloud-php` @ `8a693b3`.
- PR #4 ("PHP Order 15 baseline only, P-20–P-27") is closed — treat its content as merged history, do not reopen.

## Work items
1. Complete the ORDER 16 scope exactly as defined in the PR #3 body (P-series items listed there). Nothing beyond ORDER 16 in this PR.
2. Verify/repair CI on PR #3. Any red check: fix on the same branch and push.
3. Self-review the full diff against the shared-hosting contract: PHP 8.1 + MariaDB 10.6, public digital invoice page, replication endpoint, customer OTP.
4. **Switch PR #3 from draft to ready for review** (non-draft).
5. Post the completion report comment on PR #3 containing:
   - Scope delivered (per ORDER 16 item codes, done/partial/deferred);
   - Test evidence (synthetic fixtures only — no real customer data);
   - CI checks table (all checks);
   - Anything explicitly NOT done + reason code.
6. Update this file (`TASKS/PHP-T1.md`) status line to `DONE` with the PR #3 comment link.

## Constraints
- Do NOT merge — PM is the merger (trigger role).
- Do NOT touch `.github/workflows/` (token has no workflows permission).
- Synthetic fixtures only (PM-06) — no real data anywhere.

## Definition of done
PR #3 is non-draft + CI green + completion report comment on PR #3 + status line updated in this file.
