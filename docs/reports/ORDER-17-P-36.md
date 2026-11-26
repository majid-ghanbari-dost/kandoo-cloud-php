# P-36 — آغاز، هنوز تحویل نشده

مرجع: `docs/design/README.md` و `docs/design/Customer-Invoice-v2.dc.html` در main مخزن C#، خوانده شد. بورد ۳۹۰px شامل پس‌زمینه wax، کارت سفید، سربرگ فروشگاه/لوگو، وضعیت، ردیف‌های اطلاعات، جداکنندهٔ خط‌چین، کالاها، مبلغ honey، پرداخت و دو دکمه است. فونت باید محلی باشد و عبارت محصول «فاکتور دیجیتال کندو» حفظ شود.

نقطهٔ اتصال: پیوند outbox به صفحهٔ عمومی `/i/{token}` است؛ renderer عمومی و خصوصی مشترک‌اند و markerها، JSON و gate قرارداد نباید تغییر ناخواسته کنند. دادهٔ واقعی هر فیلد فقط در صورت ثبت نمایش داده شود؛ جمع به ریال و واحد ثبت‌شدهٔ پرداخت‌ها قابل فرض یا تبدیل خودسرانه نیست. صفحهٔ عمومی 404 یکسان و gate حقوقی نباید دور زده شود. فهرست خالی و قفل OTP در `CustomerPages` هستند و بورد فاکتور v2 به‌تنهایی این حالت‌ها را نشان نمی‌دهد؛ باید با بوردهای حالت مربوط مقایسه شوند.

وضعیت: **IN PROGRESS**؛ پیاده‌سازی، تست و screenshotهای normal/empty/locked هنوز انجام نشده‌اند؛ هیچ ادعای تطابق تصویری یا green نیست. P-32 v2 بعد از green شدن P-36 شروع می‌شود.

پیشرفت: کارت mobile و سربرگ hex، خطوط جداکننده، رنگ پس‌زمینه و مبلغ و حالت‌های خالی/قفل آغاز شدند. تفاوت‌ها: جدول جزئیات چهارستونی برای حفظ واحد/دقت قرارداد، متن حقوقی و gapهای داده، به جای نمایش مقادیر فرضی؛ دکمه تماس بدون شمارهٔ ثبت‌شده و دکمهٔ ذخیرهٔ غیرعملکردی جعل نشده‌اند؛ از چاپ مرورگر استفاده می‌شود. قفل ممکن است بابت چند علت باشد و تعداد دفعات یا URL تلگرام مجهول نباید فرض شود. این نسخه هنوز تطبیق کامل با بورد ندارد.

## مقایسهٔ تصویری محلی، هنوز غیرسبز

اسکرین‌شات‌های مصنوعی ۳۹۰px در `p36-screenshots/normal.png`, `empty.png`, `locked.png` و خروجی مرجع همان سه بورد در `Customer-Invoice-v2.png`, `Customer-Empty.png`, `Customer-Locked.png` هستند. مرجع C# main `b2aac601415bcafeebbee4378feec0bc1a6ec9bc`؛ برای رندر مستقل، محتوای بورد از wrapper canvas جدا و فونت Vazirmatn محلی جایگزین لینک CDN شد. این‌ها فایل‌های مخزن‌اند، نه artifact تولیدشده در CI. محتوای صفحه از `tests/visual/p36-fixture.php`، داده کاملاً ساختگی؛ حالت عادی renderer صفحه عمومی و حالت‌های دیگر توابع صفحه را مستقیم با reflection رندر می‌کنند، نه جریان کامل HTTP/DB؛ تصویر خالی دکمه خروج واقعی را شامل نمی‌شود.

تفاوت آشکار: normal به جای ردیف‌های نقطه‌چین، جدول مقادیر ثبت‌شده، شناسه/وضعیت و متن D14 و gap را حفظ می‌کند؛ اندازه و ارتفاع متفاوت است و دکمه‌های ذخیره و تماس بورد جایگزین دستور چاپ مرورگر شده‌اند. empty و locked پوسته/چیدمان ساده‌تری دارند؛ nav غیرفعال و دکمهٔ تلگرام بدون URL تنظیم‌شده جعل نشده‌اند، متن قفل برای علت‌های گوناگون است. هنوز pixel parity، چاپ واقعی یا مسیر outbox→صفحه با Hub ثابت نشده.

تست محلی محدود PHP 8.5 WASM برای lint و replay مارکرهای frozen G-018 روی MemoryGateway سبز؛ جایگزین PHPUnit/MariaDB نیست. [CI شاخه 45645107086](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45645107086) و [CI PR 45645123378](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45645123378) اجرا نشدند: `The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings`. با رفع مشکل باید CI هر دو PHP، smoke و artifact و اسکرین‌شات‌های واقعی را بازآزمایی کرد؛ stage سبز نیست و تگ ندارد.


## بازآزمایی پس از رفع توقف

توقف اسکن طبق اعلام کاربر با رأی PM برداشته شد؛ دربارهٔ محتوای رأی ادعایی نمی‌شود. در `a77824cbbbebb22dbbee7033318105b9bdc1b986` هر دو اجرای [branch 45782395782](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45782395782) و [PR 45782403192](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45782403192) سبز: PHP 8.1/8.5 + MariaDB 10.6، PHPUnit شامل replay همهٔ goldenها روی Memory/MariaDB و smoke و artifact خروجی PHPUnit. این به معنی سبز بودن مرحله نیست؛ artifact اسکرین‌شات CI هنوز موجود نیست.

برای مسیر demo، اعلان محلی اکنون به فاکتور عمومی همان دادهٔ ثبت‌شده پیوند می‌دهد (نه `/account/login`)؛ مسیر real SMS تغییر نکرد. تست جدید CI باید outbox مصنوعی را به HTTP دنبال کند؛ تا گذر CI نتیجه‌ای ادعا نمی‌شود. این تست جایگزین outbox خود Hub یا آزمایش گوشی و ویندوز نیست. چون workflow موجود فقط خروجی PHPUnit را artifact می‌کند و ویرایش workflow قبلاً ممنوع شده، تأمین screenshot artifact نیازمند مجوز تغییر workflow یا مسیر CI تأییدشده است.

آزمون لینک outbox مصنوعی در commit `465ace1a3ba5b33caa9f66825b1a77f99d969eb0`: [branch CI 45782721083](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45782721083) و [PR CI 45782730998](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45782730998) سبز؛ دو PHP با MariaDB و golden replay و artifact خروجی PHPUnit. در smoke هاب C-83 دادهٔ ساختگی به Cloud فرستاده و اعلان از Cloud demo outbox به HTTP صفحهٔ فاکتور دنبال شد؛ ارائه‌دهندهٔ واقعی، outbox خود Hub و گوشی/Windows آزمایش نشده‌اند. لینک حاوی token است و outbox و log فقط برای اپراتور مجاز، خارج از web root و خصوصی نگهداری شوند. screenshotهای فعلی مصنوعی و داخل مخزن‌اند، نه CI artifact؛ مرحله هنوز green و تگ نشده است.
