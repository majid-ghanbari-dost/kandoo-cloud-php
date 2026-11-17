# تحویل اردر ۱۵ — وضعیت PHP

این گزارش ادعای پذیرش نهایی دمو نیست. ردیف‌های بدون پذیرش صریح مدیر همچنان در انتظار بررسی‌اند. CIها شخصاً مشاهده شده‌اند؛ سبزی مستقل دو مخزن به‌تنهایی اثبات اتصال نیست.

| کار | تگ | SHA کامل | CI شاخه / تگ | نتیجه |
|---|---|---|---|---|
| P-20 | `o15-P-20` | `91f305f8989a252624c3a663e9ed3cff722bd93d` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026313434) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026403257) | سبز؛ پذیرش در انتظار؛ Windows اجرا نشده |
| P-20b | `o15-P-20b` | `6776349c9ea95baaa99b939df037a76e0a96957d` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44037665035) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44037765746) | PHP 8.5.12 و 8.1.34 سبز در Linux؛ پذیرش در انتظار |
| P-21 | `o15-P-21` | `32bcda3fe01d3ae65b9f7b0709bc84674b8f37e3` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026682645) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44026757364) | تحویل مشروط پذیرفته شد؛ داوری نهایی ظاهری با دموی مالک |
| P-22 | `o15-P-22` | `d0552e5656009724ea61611309f77abf6d22ee5d` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44027031282) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44027138658) | شبیه‌سازی محلی، نه تحویل SMS؛ پذیرش در انتظار |
| P-27 | `o15-P-27` | `ac5bcc334d75563180abaad3356bd3d994bf3bb5` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44052773745) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44052902608) | DeviceBearer و legacy سبز؛ پذیرفته شد |
| P-23 | `o15-P-23` | `124573f05514a2914a46976cbde672105f693ae4` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44071968921) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44072197418) | transport واقعی Hub C-83، کلید معتبر ۲۰۰ و گم‌شده/نادرست ۴۰۱؛ پذیرش در انتظار |
| P-24 | `o15-P-24` | `bc66889a40bcd7e05749dc627d1852eaabec6f82` | [شاخه](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44027484955) / [تگ](https://github.com/majid-ghanbari-dost/kandoo-cloud-php/actions/runs/44027564145) | ۱۰۰ درخواست، ۵۰ هم‌زمان، خطا ۰٪، p95 تگ ۵۶٫۲۱ms؛ پذیرش در انتظار |
| P-25 | `o15-P-25` | در گزارش تکمیلی و `AGENT_HANDOFF.md` ثبت می‌شود | CI شاخه و تگ پس از ایجاد تگ ثبت می‌شود | هندآف؛ پذیرش در انتظار |

## راه‌اندازی و حدود اثبات

راهنماهای `DEMO-WINDOWS-P20-FA.md` و `DEMO-SMS-P22-FA.md` برای XAMPP محلی، صندوق پیام و اعلان دستی هستند. اعلان باید با CLI ارسال و به‌صورت شبیه‌سازی‌شده توسط اپراتور تأیید شود تا سهمیهٔ OTP ایجاد شود؛ SMS واقعی ارسال نمی‌شود. لاگ و صندوق شامل شماره و کد هستند و باید محلی و محافظت‌شده باقی بمانند. نصب‌های قدیمی P-20 که customer auth خاموش دارند خودکار بازنویسی نمی‌شوند. LAN خصوصیِ مورد اعتماد و دادهٔ ساختگی ضروری‌اند: HTTP رمزگذاری ندارد، مدیر فقط از رایانهٔ مالک قابل دسترسی است. اجرای واقعی ویندوز، LAN، گوشی، ورود OTP و مشاهدهٔ فاکتور به‌صورت یک مسیر کامل هنوز اثبات نشده‌اند.

در P-23، transport پین‌شدهٔ C-83 روی HTTP به PHP/MariaDB واقعی وصل شد و public HTML/JSON و صفحهٔ ورود مشتری کنترل شدند؛ snapshot نسخهٔ ۱.۱ و hash آن در دادهٔ آزمایش ساخته شد، نه توسط صدور فاکتور واقعی Hub. فیلدهای اضافهٔ سطح batch که C-83 می‌فرستد فعلاً توسط binder کلاود نادیده گرفته می‌شوند؛ این تست اثبات نمی‌کند آن فیلدها به‌تنهایی projection را پر کنند. جزئیات `DEMO-REPLAY-P23.md`. کلید دستگاه در بدنه نیست و C-83 و P-27 بدون دورزدن احراز هویت استفاده شدند.

آزمون P-24 تنها lookup فاکتور ناموجود ساختگی با یک worker محلی PHP و محدودکنندهٔ موقتاً خاموش بود؛ ادعای ظرفیت تولید یا SLA نیست. مرجع بصری P-21 به علت صفحهٔ ورود قابل مشاهده نبود؛ تطابق پیکسلی ادعا نمی‌شود. P-26 اختیاری در این تحویل انجام نشده است. مرور حریم خصوصی پیش از راه‌اندازی واقعی و داوری نهایی روی رایانهٔ مالک همچنان لازم‌اند.
