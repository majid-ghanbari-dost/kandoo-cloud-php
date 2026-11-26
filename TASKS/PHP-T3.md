# [AGENT-TASK PHP-T3] PHP lane — M-010/M-011 sprint chain (P-36 first)

**Lane:** PHP (`majid-ghanbari-dost/kandoo-cloud-php`) — **Posted by:** exec-manager (2026-11-25, Asia/Tehran)
**Source orders:** M-010 + M-011 (`docs/messages/` on `main` of the C# repo `majid-ghanbari-dost/Kandoo`). Manager answer: R-010 in `docs/messages/MANAGER-TO-PM.md` on `arena/order-15-status` of the C# repo.
**Reporting (GitHub only):** update the `## Status` line in THIS file + report comment on PR #3 and any new PR. One short line per green stage (branch, tag, run URL). Never merge/close — PM merges (PO merges workflow-touching PRs).

## Sprint ordering (M-010 — this replaces the PHP-T2 ordering for the sprint window)
PAUSED for the sprint: the remaining PR #3 MUST items (backup/restore tests, local security review) and P-30/P-31 close-outs. PR #3 stays DRAFT, untouched unless a P-36 fix must land on it. They resume at stage 4 below.

## Stage 1 — P-36 customer web invoice page v2 (T0–T+12h)
Board: `docs/design/Customer-Invoice-v2.dc.html` in the PUBLIC repo `majid-ghanbari-dost/Kandoo` (flat folder — read `docs/design/README.md` FIRST). Receipt look, mobile first, RTL, print-friendly; reachable from the local outbox link. States: normal / empty (`docs/design/Customer-Empty.dc.html`) / locked (`docs/design/Customer-Locked.dc.html`). Screenshot artifact per state. Tag `o17-P-36` on the green commit.

## Stage 2 — Local end-to-end with the C# Hub (by ~T+20h)
Verify the whole local Cloud flow with the C# Hub using the existing local-run kit; report the EXACT copy-paste commands for the PO (install -> first-run setup -> issue invoice (Mode A) -> SMS line in local outbox -> open the customer page from the outbox link over the LAN).

## Stage 3 — P-32 admin page v2 (ONLY if P-36 is green)
No dedicated admin board exists in `docs/design/` of the C# repo (manager-checked 2026-11-25): build v2 from the current admin page + the P-32 requirements, and file the missing board in `docs/questions/Q-<id>.md`. Tag `o17-P-32`.

## Stage 4 (resume after P-36; pre-approved)
P-31 finish with the C# review of the vectors, then P-30 runbook, P-33 kit, P-34 local load/security; then the paused PR #3 MUST items.

## MANAGER DISPATCH (2026-11-26) — PM-28 billing blocker CLEARED: secrets scan FIRST, then push (executes M-012 + the PM's 2026-11-26 instruction)

**PM-28 verified by the manager:** this repo is now PUBLIC (GitHub search API, `private=false`, checked 2026-11-26) — CI is free again; the billing blocker that stopped runs 45645107086 / 45645123378 is gone.

**NEW MANDATORY STEP 0 — before ANY push (PM order, will be recorded in R-011):**
Run a secrets scan over the WHOLE repo AND ITS FULL HISTORY (gitleaks-style, or an equivalent systematic grep pass): API keys, passwords, tokens, real phone numbers, real hostnames, and specifically the Faraz Api-Key.
- If ANYTHING is found: STOP. Do not push. Do not remove or edit it yourself. Report the exact file + commit SHA to the PM (via this task file's `## Status` line and a PR #3 comment) — the PO will rotate the key. Wait for the PM.
- If CLEAN: push to start CI on P-36 and PR #3; continue the chain exactly as dispatched (stage 1 P-36 per the original acceptance — screenshot artifact per state, HTTP-path proof, tag `o17-P-36`; then stage 2; P-32 only after P-36 is green); report run URLs one line per green stage.

**Q-C98-01 = A (M-012, affects your stage 2):** the PHP Cloud runs locally on the PO's PC as part of the local kit; THIS lane owns serving `/i/<token>` (P-36) in the local kit. The Hub's outbox SMS line carries `http://<LAN-IP>:<cloud-port>/i/<token>` (demo-profile LAN base URL setting). Align your stage-2 end-to-end report with that URL shape; report the EXACT copy-paste commands for the PO as planned.

## Constraints (unchanged)
PM-06/PM-17: real customer data NEVER in git/CI/reports. Workflow-touching PRs declare it in the FIRST body line. Tags `o17-<task>`; never move existing tags.

---
**Status:** DISPATCHED (T0 2026-11-25) — start stage 1 (P-36 v2) immediately. MANAGER DISPATCH 2026-11-26: PM-28 cleared (repo public) — secrets scan of repo + full history FIRST, then push for CI; see the dispatch section above.