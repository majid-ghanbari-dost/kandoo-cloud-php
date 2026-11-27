# P-30 — وضعیت مرحله‌ای

| تسک | شاخه | SHA کامل | CI | نتیجه | فایل‌ها |
|---|---|---|---|---|---|
| P-30 | `arena/f729618f-kandoo-cloud-php` | `bdee65fdd8f443ce3af8352161c62b174dcfb759` | [44887759003](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44887759003) سبز | PARTIAL | `docs/SHARED-HOST-P30-FA.md` |

چک‌لیست بدون دستور مخرب تهیه شد؛ self-test محافظت‌شده با secret، cron heartbeat، backup جداگانه و restore آزمایش‌شده هنوز پیاده‌سازی/تأیید نشده‌اند.

پیشرفت: heartbeat موفق cron در MariaDB و آزمون‌های وضعیت و smoke پیاده‌سازی شد؛ پس از prune و cleanup موفق ثبت می‌شود. SHA `6681d79fa93fea83fb3287738f611b91b06ef8ab` و [CI 45120898178](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45120898178) سبز: MariaDB 10.6، PHP 8.1/8.5، اجرای cron و PHPUnit؛ artifactها بارگذاری شدند. self-test محافظت‌شده، IP خروجی و backup/restore همچنان باقی‌اند؛ PARTIAL و بدون تگ.

گام PHP-T1: عیب‌یابی CLI با کلید مستقل از stdin، بررسی TLS فراز، IP عمومی از سرویس ثابت TLS، وضعیت heartbeat و round-trip خواندنی utf8mb4 اضافه شد. تست از probe ساختگی استفاده می‌کند و استفاده از سرویس echo فقط IP عمومی میزبان را به آن سرویس افشا می‌کند. صحت IP مسیر واقعی فراز و قابلیت‌های هاست واقعی هنوز تأیید نشده؛ backup و آزمون restore باقی‌اند. SHA `fe0d28e70e90806ed23fd94c0745b92a5e4f4255`، [CI شاخه 45193950783](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45193950783) و [CI PR 45193964983](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45193964983) سبز؛ PHP 8.1/8.5 و MariaDB 10.6 با probe شبکه ساختگی و آزمون رد کلید نادرست.

## جمع‌بندی محلی PHP-T2

Backup به دایرکتوری خصوصی جدا و restore در DB خالی `TEST_RESTORE_` با دادهٔ ساختگی، schema کامل و CLI آزموده شد؛ خطاهای checksum، نام مقصد و مقصد غیرخالی رد شدند. نصب demo، backup و restore در Linux/PHP/MariaDB شبیه محیط XAMPP در CI اجرا شد، نه Windows/XAMPP واقعی. SHA `f7f742e0b6c2cd2507edc8a59e8738f38466ee10`؛ [branch CI 45592999722](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45592999722) و [PR CI 45593010987](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45593010987) سبز، هر دو PHP و MariaDB و artifact. جزئیات و محدودیت‌های بازیابی در runbook؛ انتقال به محل فیزیکی جدا، Windows و بازیابی production تأیید نشده. PO-OWN-1..4 طبق چک‌لیست باقی‌اند.
