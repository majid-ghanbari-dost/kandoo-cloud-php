# ORDER 15 — technical decisions

- P-20: Windows XAMPP PHP/MariaDB with a PowerShell launcher and isolated random demo database, because it avoids downloads and protects existing data; rejected overwriting an existing database/configuration.
- P-20: Bind only the chosen RFC1918 IPv4 address and reject public peers; restrict admin to the owner machine, because HTTP is unencrypted; rejected a public/0.0.0.0 listener and remote admin over cleartext.
