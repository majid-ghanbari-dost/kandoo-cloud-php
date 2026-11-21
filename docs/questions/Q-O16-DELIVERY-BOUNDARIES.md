# ORDER 16 delivery boundaries

The instruction says one PR per task; the session permits only the fixed branch `arena/f729618f-kandoo-cloud-php`. GitHub allows only one open PR for a given head/base pair, and creating additional task branches is forbidden by session constraints. Safe default: preserve one branch, isolate tasks in commits/reports, and do not claim five independent PRs; request manager coordination for task PR strategy without changing branches or merging.

The order text says one NEW tag per task, while OPERATING-RULES-v2 rule 6 says agents never tag. Safe default: do not create tags while this conflict is unresolved; branch CI and reports remain useful. Manager can decide tag ownership.

The order describes a baseline after PM merged O15. Inspected PHP `origin/main` is `af3a758e52eb9b3c2521ee317d4395b58021f75f`, and O15 changes remain on the fixed active branch; the start-now directive is followed, but the active branch differs from the stated post-merge baseline. Any PR from this branch includes prior O15 changes. Never merge it yourself.
