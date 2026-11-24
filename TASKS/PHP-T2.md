# [AGENT-TASK PHP-T2] M-007 §3 scope change (PO-OWN-1..4) + locally-provable MUST + ORDER 17 batch

**Lane:** PHP (`majid-ghanbari-dost/kandoo-cloud-php`) — **Posted by:** exec-manager (2026-11-24, Asia/Tehran)
**Source orders:** M-007 §3–§4, M-008 §2 (`docs/messages/M-007.md`, `docs/messages/M-008.md` on `main` of the C# repo `majid-ghanbari-dost/Kandoo`). Manager answers filed as R-007/R-008 in `docs/messages/MANAGER-TO-PM.md` on `arena/order-15-status` of the C# repo.
**Reporting (mandatory, GitHub only):** update the `## Status` line in THIS file + report comment on PR #3 and any new PR. PM is the trigger; PM/PO are the only mergers.

## 1. Definition-of-done change (M-007 §3) — REMOVE four items, RECORD as PO-OWN
The following are REMOVED from the lane's definition of done. They are PO items, done at launch readiness (nothing is bought before that). Record them EXACTLY under these IDs in the lane report (`docs/reports/`), each one line, status `PO-OWN (not started, launch-readiness)`:
- **PO-OWN-1**: buying the E1 host and a real-host deployment test.
- **PO-OWN-2**: registering and getting approval of the two Faraz patterns.
- **PO-OWN-3**: a real SMS delivery test.
- **PO-OWN-4**: the final domain and SSL.

## 2. What the lane still delivers (everything provable locally)
- **P-30**: runbook + host self-test script + local dry-run on an XAMPP-like setup.
- **P-33**: pattern kit — pattern texts ready to paste.
- **PO checklist** for the four PO-OWN items in `docs/reports/`.
- **PR #3 (ORDER 16) locally-provable MUST remaining**: tested backup/restore; SMS cost logic verified against the local stub/sandbox (the REAL service test is PO-OWN-3); the locally-provable part of the security review. PR #3 stays DRAFT until all MUST are green; PM-14 stands (the PR keeps the O15 evidence). PM-13/P-31: the cross-lane verdict ACCEPT is already recorded by the manager (R-006 item 7; `docs/reports/ORDER-16-C-92.md` on the C# repo) — close out P-31 acceptance on the PHP side from those vectors; the real Android E2E remains with the C# lane / PO.

## 3. ORDER 17 items (start after PR #3 MUST is green and reported; tag `o17-<task>`)
- **P-32** admin page v2.
- **P-34** security/load — locally-provable part only (no real host).
- **P-36** Customer web invoice page v2 from board `Customer-Invoice-v2.dc.html` (the board lives in `docs/design/` of the C# repo `majid-ghanbari-dost/Kandoo`, flat folder — read `docs/design/README.md` first): receipt look, mobile first, RTL, print-friendly; absorbs P-35; no legal claims; screenshot artifact per state (normal, empty, locked).

## 4. Constraints
- Real sales data from real customers is NEVER in git, CI or reports (PM-06/PM-17 unchanged).
- Rule from M-007 §2: any PR that changes `.github/workflows/` says so in its FIRST line; those PRs are merged by the PO, all others by the PM. The agent never merges.
- Tags: `o16-<task>` for ORDER 16 items, `o17-<task>` for ORDER 17; never move/delete existing tags.

---
## Status: IN PROGRESS — PO-OWN-1..4 recorded; PR #3 locally-provable MUST in progress; ORDER 17 not started
