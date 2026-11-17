# ORDER 15 — technical decisions

- P-20: Windows XAMPP PHP/MariaDB with a PowerShell launcher and isolated random demo database, because it avoids downloads and protects existing data; rejected overwriting an existing database/configuration.
- P-20: Bind only the chosen RFC1918 IPv4 address and reject public peers; restrict admin to the owner machine, because HTTP is unencrypted; rejected a public/0.0.0.0 listener and remote admin over cleartext.
- P-21: Reuse the existing escaped, recorded-only invoice receipt and adapt the customer-only styling, because the reference board requires sign-in and the explicit palette is available; rejected guessing new invoice fields or changing the public contract. See Q-P21-DESIGN-BOARD.
- P-21: Permit non-Secure cookies only for the explicit private-LAN demo profile, because browsers reject Secure cookies on HTTP; rejected weakening cookies in production. The trusted LAN is unencrypted and must carry synthetic demo data only.
