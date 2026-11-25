<?php

declare(strict_types=1);
// Offline, fictional visual fixture. This is not a route and uses no production data.
require dirname(__DIR__, 2).'/src/bootstrap.php';
use Kandoo\Cloud\Customer\CustomerPages;
use Kandoo\Cloud\Http\Html;

$state = $argv[1] ?? '';
if ($state === 'normal') {
    echo Html::renderInvoicePage([
        'store_name'=>'فروشگاه نمونه', 'display_number'=>14025, 'lifecycle_status'=>'ISSUED',
        'invoice_id'=>'TEST-invoice', 'issued_at'=>'2026-11-20 11:05:00', 'payable_rials'=>2850000,
        'line_items'=>'[{"product_name":"بادکنک فویلی","quantity_millis":2000,"unit_price_rials":450000,"line_total_rials":900000},{"product_name":"بسته دکور تولد","quantity_millis":1000,"unit_price_rials":1500000,"line_total_rials":1500000}]',
        'customer_name'=>'مشتری نمونه', 'payments'=>'[{"type":"cash","amount":200000}]',
        'currency_unit'=>'toman', 'remaining_balance'=>85000,
    ]);
} elseif ($state === 'empty' || $state === 'locked') {
    $type = new ReflectionClass(CustomerPages::class);
    $page = $type->newInstanceWithoutConstructor();
    if ($state === 'empty') {
        $body = '<h1>فاکتورهای من</h1>'.$type->getMethod('emptyState')->invoke(null);
        echo $type->getMethod('page')->invoke($page, $body)->body();
    } else {
        $type->getProperty('config')->setValue($page, ['telegram_url'=>null]);
        echo $type->getMethod('telegram')->invoke($page)->body();
    }
} else { fwrite(STDERR, "expected normal|empty|locked\n"); exit(2); }
