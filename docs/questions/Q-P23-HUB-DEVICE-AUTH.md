# Q-P23-HUB-DEVICE-AUTH — actual Hub transport lacks mandatory credentials

P-23 requires proving real Hub-to-PHP replication with the store-scoped device key and testing missing/wrong credentials. The accessible Hub at `majid-ghanbari-dost/Kandoo` main ref `75a8aa7a148209ab0b037f527c8fb23fb1ee292d`, `src/Kandoo.Hub/HubCloudReplicationClient.cs` blob `465f56786e5cb75d5108a6c51e22b353f46f34ab`, sends JSON and `Idempotency-Key` only. It does not send `X-Kandoo-Device-Id` or `X-Kandoo-Device-Secret`. The PHP Cloud requires both; its D-B authentication must not be bypassed. The repository golden replay is synthetic and is not proof of an actual Hub call. A different Hub revision may exist; none has been identified or tested here.

Options:
1. **Recommended:** use a Hub build/revision that implements store-scoped device credentials; provide its revision/build via the existing cross-team handoff, then run real E2E on isolated demo data.
2. Provide a compatible test-only Hub transport/client that sends the same authenticated wire requests and identify it explicitly; retain the separate actual-Hub verification as pending.
3. Continue independent P-24 work now and defer real-Hub proof; never disable device authentication as a workaround.

Safe default: option 3 while pursuing option 1. Do not expose real data or secrets and do not weaken auth. P-23 must not be reported complete, tagged, or accepted until actual Hub proof exists.
