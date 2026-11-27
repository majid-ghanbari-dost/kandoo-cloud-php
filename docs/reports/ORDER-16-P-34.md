# P-34 — گزارش مرحله‌ای

| تسک | شاخه | SHA کامل | CI | نتیجه | فایل‌ها |
|---|---|---|---|---|---|
| P-34 | `arena/f729618f-kandoo-cloud-php` | `bc7171b0943f5803bfbfa1cf8910506a7957288e` | [44882947151](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44882947151) سبز | PARTIAL؛ بازبینی اولیه، پیکربندی واقعی میزبان و ارزیابی نفوذ تأیید نشده | `docs/SECURITY-REVIEW-P34.md` |

محدودکنندهٔ brute-force مجزا برای admin و اعتبارسنجی تنظیمات proxy/HTTP و لاگ میزبان باید پیش از انتشار بررسی شوند. بنچمارک CI نسخهٔ P-24 فقط lookup ساختگی با یک worker است؛ شبیه‌سازی بار واقعی هاست E1 نیست.

در همین run: ۱۰۰ GET ساختگی، هم‌زمانی ۵۰، ۰ خطا و p95 برابر ۹۷٫۹۶ms در PHP 8.1؛ artifactهای PHPUnit موجودند. این بارِ پیکربندی‌شده مانند هاست هدف یا مجوز امنیتی نیست.

## بازبینی محلی PHP-T2

کنترل admin اجباری per-peer، تست 429 و reset و استقلال IP و مقاومت در برابر header جعلی، و آزمون MariaDB/prune اضافه شد. SHA `c2e9378b19bc1f2dabc7a4781266f7909dacd423`؛ [branch CI 45593387150](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45593387150) و [PR CI 45593395767](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45593395767) سبز (PHP 8.1/8.5، MariaDB و artifact). محدودیت‌ها و موارد انتشار در `docs/SECURITY-REVIEW-P34.md`؛ این penetration test یا تأیید میزبان واقعی نیست.

PHP-B (2026-11-27): the recorded "no independent limiter on the new list endpoint" finding is closed by `CustomerReadLimitDatabaseTest` (per-peer + per-session 429, isolation, no payload). Local load evidence for the customer read path comes from `tools/dev/load-customer-read.py` (synthetic seed, loopback, single worker, throttle disabled); the number is local latency only — not E1 host capacity and not a security audit.

Measured local evidence (https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45966232387, PHP 8.1 + MariaDB 10.6, loopback, single worker): customer API read mix (list/detail) requests=200, concurrency=25, errors=0, error_rate=0.00%, p95=44.94 ms; throttle disabled for the measurement. Local latency only — not plan-E1 capacity, not a penetration test.
