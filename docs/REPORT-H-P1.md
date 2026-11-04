# گزارش یک‌صفحه‌ای — PM ORDER 9 · ابر تولید «فاکتور دیجیتال کندو» با PHP

**نقش:** عامل PHP (فقط ایجاد؛ بدون ادغام/تأیید/بستن) · **مخزن:** `majid-ghanbari-dost/kandoo-cloud-php`
**تاریخ گزارش:** ۲۰۲۶-11-04 · **قرارداد مرجع:** `docs/CLOUD-CONTRACT.md` (منجمد، استخراج‌شده از Kandoo@`4780ed224db17159eaad4f0e17e0dac7ce88bf69`)
**نتیجهٔ کلی:** هر چهار وظیفهٔ P-01 تا P-04 انجام و سبز شد؛ پخش ۲۳ جفت طلایی روی هر دو درگاه (حافظه و MariaDB 10.6) در CI با **صفر واگرایی**. همهٔ مقادیر آزمون ساختگی (TEST-…) هستند.

## جدول وظیفه‌ها

| وظیفه | برچسب | SHA (۴۰ نویسه) | اجرای CI (سبز) |
|---|---|---|---|
| P-01 استخراج قرارداد | `stable-p-01` | `dc55321b5d674e701cb3e327f099a9a464df9834` | [شاخه 41851771810](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41851771810) · [برچسب 41851774307](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41851774307) |
| P-02 اسکلت PHP 8.1 | `stable-p-02` | `1fcf08766eb60361226bddd903b8ac5f29fc7a41` | [شاخه 41862421941](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41862421941) · [برچسب 41862424731](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41862424731) |
| P-03 اندپوینت همانندسازی + خوانش عمومی | `stable-p-03` | `a3006e39e799c47549b08e1401ee4d6f3ef1e236` | [شاخه 41868679641](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41868679641) · [برچسب 41868680618](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41868680618) |
| P-04 پخش طلایی در CI | `stable-p-04` (قرمز — جابه‌جا نشد) | `92d12bbeca9281c06901c7b53ddacaabac9face4` | قرمز — بخش زیر |
| P-04 رفع قرمزی | `stable-p-04-o9` | `61049b58317c774afaa8265bbc343e0603d05931` | [شاخه 41870546730](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41870546730) · [برچسب 41870969040](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41870969040) |
| H-P1 همین گزارش | `stable-h-p1` | خودارجاع ممکن نیست — `git rev-parse stable-h-p1^{commit}` | اجرای برچسب `stable-h-p1` در Actions |

کامیت میانی `c26f2a5af9dac8db7745e9dcd541cc4e4232c25a` (ابزار تشخیص CI) نیز قرمز بود — همان ۶ خطا.

## افشای کامل اجرای‌های قرمز (بدون استثنا)

| اجرا | SHA | علت |
|---|---|---|
| [41869356860](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41869356860) (شاخه) | `92d12bbe…` | ۶ خطا در پخش MariaDB: پرونده‌های طلایی فقط-فرافکنی (g-017…g-022) در `given` سطر والد `cloud_stores` ندارند؛ کلید خارجی `fk_public_invoices_store` روی MariaDB خطای 1452 داد (خروج PHPUnit=2). لاگ حبابی از محیط کار قابل خوانش نبود. |
| [41869361039](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41869361039) (برچسب `stable-p-04`) | `92d12bbe…` | همان ۶ خطا. |
| [41870041007](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/41870041007) (شاخه) | `c26f2a5a…` | اجرای تشخیصی: افزودن گام «در صورت شکست، خروجی PHPUnit به‌صورت نظر روی کامیت ثبت شود» — همان ۶ خطا، این‌بار با متن کامل قابل خوانش از API ([نظر تشخیصی روی همین کامیت](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/commit/c26f2a5af9dac8db7745e9dcd541cc4e4232c25a)). |

**رفع (`61049b58…`):** فقط «تکمیل دادهٔ اولیه» در هارنس — کاشت سطر والد خنثی (epoch=1، همهٔ شمارنده‌ها صفر) وقتی `given` آن را ندارد. هیچ پروندهٔ طلایی، هیچ گزاره و هیچ رفتار سامانه تغییر نکرد؛ در `docs/CONTRACT-REPLAY.md` مستند شد. بقیهٔ ۹۷ آزمون — از جمله همهٔ پخش‌های بلعیدن (g-002…g-016)، سلامت و g-023 روی MariaDB واقعی — در همان اجرای قرمز هم سبز بودند.

## UNCONFIRMED (قرارداد منجمد ساکت است؛ مقدارها محافظه‌کارانه و قابل تنظیم‌اند)

1. **U5 محدودسازی نرخ:** ابر منجمد فقط سرصفحه‌های ثابت (120/119/60) را اعلام و اجرا نمی‌کرد؛ اجرای واقعی سمت PHP افزوده شد: پنجرهٔ ثابت ۱ دقیقه‌ای پایگاه‌داده‌ای، بلعیدن به‌تفکیک store (پیش‌فرض ۱۲۰)، صفحهٔ عمومی به‌تفکیک IP کارخواه (پیش‌فرض ۶۰)، `0`=خاموش. پاک‌سازی با cron هر دقیقه (`bin/cron.php`).
2. **کلید IP عمومی:** فقط `REMOTE_ADDR` — به سرصفحه‌های پروکسی (X-Forwarded-For) اعتماد نمی‌شود (میزبانی اشتراکی، IP خروجی مشترک).
3. **تعارض اثر انگشت دسته (409 IDEMPOTENCY_CONFLICT «محتوای متفاوت»):** هیچ طلایی ندارد — فقط با آزمون PHP میخ شده است.
4. **پاکت 429 RATE_LIMITED بلعیدن** (`retry_after_ms` = میلی‌ثانیه تا پایان پنجره): بدون طلایی.
5. **U1 کلید همانندسازی:** `null` = خاموش (سازگار با انجماد)؛ مسیر فعال‌شده (401 UNAUTHENTICATED، پیش از تجزیهٔ store-id) بدون طلایی — آزمون PHP.
6. **U14 جایگزینی مقادیر v1.1 در صفحهٔ HTML** (customer_name/payments/currency_unit/remaining_balance): بدون طلایی — آزمون PHP.
7. **پاسخ 405 + Allow برای روش نادرست** و **HEAD = سرصفحه‌ها با Content-Length و بدنهٔ خالی**: قرارداد ساکت است؛ انتخاب محافظه‌کارانهٔ PHP.
8. **سرصفحه‌های اعلامی RateLimit-* ثابت (120/119/60)** حتی با حد پیکربندی‌شدهٔ دیگر — مطابق §5.2 «مقادیر اعلامی ثابت».

## BLOCKED / معوق (طبق دستور شروع نشدند)

- OTP/SMS، صفحات مشتری، راهنمای استقرار، آزمون میزبانی واقعی — معوق.
- متن حقوقی D14 همچنان placeholder و «needs lawyer approval» است (بدون ادعای حقوقی/مالیاتی).
- هیچ مورد مسدودکنندهٔ فعالی برای P-01…P-04 باقی نماند.

## یادداشت‌ها

- **جای‌نام شاخه:** دستور PM شاخهٔ `php/o9` خواست؛ محیط اجرا شاخهٔ `arena/928d944b-kandoo-cloud-php` را تحمیل می‌کند — همهٔ کارها فقط روی همین شاخه انجام و افشا شد. هیچ push به `main` صورت نگرفت.
- **برچسب‌ها:** طبق قاعده، برچسب موجود جابه‌جا نمی‌شود؛ `stable-p-04` روی کامیت قرمز باقی ماند و برچسب سبز با پسوند `-o9` ساخته شد (`stable-p-04-o9`).
- **CI:** GitHub Actions — اعتبارسنجی اسناد/طلایی (Python) + PHPUnit 9.6 با PHP 8.1 و سرویس MariaDB 10.6؛ در اجرای سبز نهایی: **۱۰۳ آزمون، صفر شکست** (شامل ۲۳×۲ پخش طلایی). درگاه محلی: lint ۵۰/۰، سوئیت ۷۷ سبز/۲۶ skip (skipها = آزمون‌های DB که در CI اجرا می‌شوند)، پخش ۲۳/۲۳.
- **خودارجاعی H-P1:** SHA و URL اجرای همین گزارش پیش از کامیت وجود ندارند؛ از طریق `git rev-parse stable-h-p1^{commit}` و فهرست Actions قابل بازیابی است و در پاسخ چت به مدیر اجرا اعلام می‌شود.
