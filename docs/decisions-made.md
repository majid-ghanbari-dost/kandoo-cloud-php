# ORDER 15 — technical decisions

- P-20: Windows XAMPP PHP/MariaDB with a PowerShell launcher and isolated random demo database, because it avoids downloads and protects existing data; rejected overwriting an existing database/configuration.
- P-20: Bind only the chosen RFC1918 IPv4 address and reject public peers; restrict admin to the owner machine, because HTTP is unencrypted; rejected a public/0.0.0.0 listener and remote admin over cleartext.
- P-21: Reuse the existing escaped, recorded-only invoice receipt and adapt the customer-only styling, because the reference board requires sign-in and the explicit palette is available; rejected guessing new invoice fields or changing the public contract. See Q-P21-DESIGN-BOARD.
- P-21: Permit non-Secure cookies only for the explicit private-LAN demo profile, because browsers reject Secure cookies on HTTP; rejected weakening cookies in production. The trusted LAN is unencrypted and must carry synthetic demo data only.
- P-22: A database-backed demo outbox plus protected local JSON-lines log implements both demo notification and OTP without network calls; rejected changing the Faraz adapter or treating provider acceptance as actual delivery.
- P-23: Preserve mandatory device authentication and mark actual Hub integration blocked when the accessible Hub transport omits credentials; rejected a bypass or representing synthetic golden replay as real Hub proof (Q-P23-HUB-DEVICE-AUTH).
- P-24: Measure 50 simultaneous synthetic unknown-invoice HTTP reads against real local MariaDB using the single PHP built-in worker, because that matches the Windows demo and avoids writes; rejected claiming production capacity or using in-process /health as a DB load test.
- P-20b: Run the full CI suite and installer self-test on both PHP 8.1 and 8.5 with real MariaDB, preserving the existing 8.1 baseline; rejected treating a single PHP version or Linux CI as proof of a Windows execution.
