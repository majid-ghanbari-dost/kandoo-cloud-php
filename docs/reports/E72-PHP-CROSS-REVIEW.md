# E-72 — بازبینی متقاطع PHP (پیش‌نویس)

**Verdict: ACCEPT برای همین بردار مصنوعی به‌عنوان fixture پیشنهادی؛ NOT ACCEPTED به‌عنوان قرارداد اجرایی یا تأیید پوشش همهٔ بایت‌ها.** منبع Python commit `1e620cc1f990bec51566b5e1af26dcec97f26fdb`، مسیر `tests/golden/e72/canonical-invoice-v1-2-draft-vector.json`، اندازه 1505 بایت، SHA-256 `0885c7571fb3ccc781f1a73e5505cd94805b065fb13e93f35fa4feb12d061d36`. کپی byte-identical ساختگی در `docs/vectors/e72/` است؛ بدنه اصلی v0 و ضمیمه جداگانه با schemaهای همان commit و jsonschema Draft 2020-12 اعتبارسنجی شدند و `invoice_id` مطابقت دارد. آزمون PHP `E72DraftVectorTest` فایل پین‌شده را با Parser/Writer فعلی بازپخش می‌کند؛ SHA آزمون `e7cadb0162c2a668f7e6d53cec8c6bd9e5773eb3`؛ [CI شاخه 45353403490](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45353403490) و [CI PR 45353415123](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45353415123) سبز روی PHP 8.1/8.5 و MariaDB 10.6.

**محدودیت‌های ادغام، پیش از تصویب PM:**

- `cheque` در schema و قرارداد منجمد Cloud v1.1 نیست؛ وارد کردن مستقیم آن به `response_snapshot.response.invoice.payments` نسخه 1.1 باید طبق C4 مردود شود. هیچ تغییر frozen یا الحاق runtime بدون تصمیم و addendum صریح انجام نشود.
- بردار Python شناسه‌های مصنوعی غیر UUID دارد و مستقیماً ingest Cloud نیست؛ درگاه Hub باید شناسه‌ها، نشانهٔ نسخه، نگاشت دادهٔ صادرشده و قرارداد Cloud را مشخص کند. یکسان بودن نام v1.2 با طرح API مشتری PHP به معنای یکی بودن دو قرارداد نیست.
- پیش‌نویس Python از `json.dumps(ensure_ascii=False)` استفاده می‌کند؛ نمایهٔ U7 منجمد PHP همه کنترل‌ها را با `\\u00XX` بزرگ و DEL را با `\\u007F` می‌نویسد، اما پیش‌فرض Python برای برخی کنترل‌ها escape کوتاه و برای DEL بایت خام دارد. بردار فعلی فاقد این موارد است؛ تطبیق همین بردار اثبات سازگاری همه ورودی‌ها نیست. پیش از هر hash مشترک، نمایهٔ واحد و بردارهای کنترل/DEL، فارسی و ZWNJ لازم است؛ U7 Cloud نباید بازنویسی شود.
- نسبت `invoice_discount` و `walk_in_customer` با فیلدهای ثبت‌شده و دامنه خصوصی/عمومی باید به تصویب برسد؛ از مقدار غایب یا شماره مشتری، حضوری بودن یا موجودی را استنتاج نکنید. `currency_unit=null` نیازمند بازبینی است، نه تبدیل یا پیش‌فرض واحد. از اختلاف اعداد نمونه هیچ محاسبه یا معنای حقوقی استنتاج نمی‌شود.

## نتیجه ماشهٔ بازبینی — لایه store/API

**نتیجه: مغایرت اجرایی؛ تأیید بایت‌به‌بایت محدود به سریال‌سازی همان تک fixture است، نه تطبیق store/API.** تگ منبع پایتون [`o16-E-72`](https://github.com/majid-ghanbari-dost/Kandoo-Digital-Invoice/releases/tag/o16-E-72) به commit `f99ab20f0d39b5e4a293d1c3bce9413d9b77d791` اشاره می‌کند؛ manifest همان یک بردار با همان 1505 بایت و SHA بالا را ثبت کرده و از خود تگ نیز دوباره hash گرفته شد.

۱. تست PHP `V11ProjectionTest::testE72FieldsDoNotSilentlyBecomeCloudV11Features` مقادیر cheque خود بردار را در fixture معتبر Cloud با نشانگر 1.1 به ingest می‌دهد: انتظار 400 `VALIDATION_ERROR` و عدم projection. برای آزمایش دو فیلد دیگر فقط پرداخت را با cash ساختگی جایگزین می‌کند: snapshot پذیرفته می‌شود ولی `invoice_discount` و `walk_in_customer` در projection و JSON عمومی ظاهر نمی‌شوند؛ لذا آزمون دوم proof انتقال کامل ضمیمه یا پذیرش cheque نیست. JSON حساب مشتری نیز طبق `src/Customer/CustomerPages.php` فقط whitelist فعلی را نشان می‌دهد و این دو فیلد را ارائه نمی‌کند؛ این حکم مبتنی بر بررسی کد است نه اجرای جداگانهٔ مسیر حساب.
۲. نشانگر `invoice_schema_version=1.2` در تست فعلی Cloud با 400 رد می‌شود، در حالی که ضمیمه Python از `schema_version=1.2-draft` در یک envelope مستقل استفاده می‌کند؛ این‌ها wire یکسان نیستند. شناسهٔ نمونه Python نیز UUID معتبر Cloud نیست.
۳. تطابق bytes تک بردار به دلیل نبود کنترل/DEL به همهٔ ورودی‌ها تعمیم‌پذیر نیست؛ تفاوت U7 و Python فوق باید پیش از توافق مشخص شود. هیچ قرارداد فروزن، store/API یا رفتار اجرایی برای اعمال E-72 تغییر نکرد.

CI آزمون تازه در انتظار بررسی است؛ [آزمون قبلی سریال‌سازی](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/45353403490) سبز بود.
