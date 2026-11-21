# ORDER 16 delivery boundaries

The instruction says one PR per task; the session permits only the fixed branch `arena/f729618f-kandoo-cloud-php`. GitHub allows only one open PR for a given head/base pair, and creating additional task branches is forbidden by session constraints. Safe default: preserve one branch, isolate tasks in commits/reports, and do not claim five independent PRs; request manager coordination for task PR strategy without changing branches or merging.

The order text says one NEW tag per task, while OPERATING-RULES-v2 rule 6 says agents never tag. Safe default: do not create tags while this conflict is unresolved; branch CI and reports remain useful. Manager can decide tag ownership.

The order describes a baseline after PM merged O15. Inspected PHP `origin/main` is `af3a758e52eb9b3c2521ee317d4395b58021f75f`, and O15 changes remain on the fixed active branch; the start-now directive is followed, but the active branch differs from the stated post-merge baseline. Any PR from this branch includes prior O15 changes. Never merge it yourself.

## Executive ruling / remaining platform constraint

Manager D2 and amended `docs/OPERATING-RULES-v2.md` rule 8 accept one consolidated lane PR (#3); D3 and amended rule 6 resolve tag policy: each finished+green O16 task gets a new `o16-<task>` tag on the current branch. None of P-30–P-34 is complete yet, so none is tagged. The executive requested an O15-only baseline PR on a new branch before further O16 work. The Arena session permits only the fixed branch `arena/f729618f-kandoo-cloud-php`: creating, switching to, or pushing any other branch is not permitted. PR #3 already includes O16 work and is not an O15-only baseline PR. Do not rebase or rewrite history.

Baseline candidate `o15-P-25` -> `2724969fd85475c777016d54d61abe3789a6773a`; P-23 `124573f` and P-27 `ac5bcc3` are confirmed ancestors; this tag predates the O16 commits. Its existing branch and tag CI are https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44072538232 and https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44072854193, respectively; these are NOT CI for a new baseline PR. PHP `origin/main` remained `af3a758e52eb9b3c2521ee317d4395b58021f75f` on inspection.

Safe options for the Manager/platform: (1) create the O15-only branch and PR from the verified P-25 commit outside this fixed-branch session and run its PR CI, or (2) establish an authorized separate session for that baseline branch. Once the PM merges it, the current agent may inspect the new main and, if needed, merge it into the current branch without rewriting history (subject to session rules). Do not call the O16 PR a baseline PR or claim a baseline PR CI exists. Package 2 remains pending that merge.
