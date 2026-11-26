<?php

// What a spec does behind the page's back, as another person would:
//   php helper.php rice-price 30        an admin changes the rice price
//   php helper.php last-order orders    the newest saved order (or `pre_orders`) with its lines, as JSON

use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/app.php';
$app->make(Kernel::class)->bootstrap();

[, $command, $argument] = $argv + [null, null, null];

if ($command === 'rice-price') {
    Setting::setAlaCarte('both', (float) $argument);
} elseif ($command === 'last-order' && in_array($argument, ['orders', 'pre_orders'], true)) {
    [$key, $items] = $argument === 'orders' ? ['order_id', 'order_items'] : ['pre_order_id', 'pre_order_items'];
    $order = DB::table($argument)->orderByDesc($key)->first();

    echo json_encode($order === null ? null : [
        'total' => (float) $order->total_amount,
        'lines' => DB::table($items)->where($key, $order->{$key})->orderBy('no_rice')->get(['quantity', 'unit_price', 'no_rice'])
            ->map(fn (object $item) => ['quantity' => (int) $item->quantity, 'unitPrice' => (float) $item->unit_price, 'noRice' => (bool) $item->no_rice])
            ->all(),
    ]);
} else {
    fwrite(fopen('php://stderr', 'w'), "Unknown command.\n");
    exit(1);
}

// Cached copies of what was written are dropped now, not when this process ends.
App\Support\TableCache::settle();
