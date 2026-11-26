<?php

namespace App\Support\Analytics;

use LogicException;

// A widget's data as the tables of a CSV: [[title, [headings], [rows]], ...]. The data is the
// same the page prints, read with Period::forExport() so lists are not cut to the page's length.
class Export
{
    public static function tables(string $key, array $data): array
    {
        $rows = fn (array $list, array $columns) => array_map(
            fn (array $row) => array_map(fn (string $column) => $row[$column] ?? '', $columns),
            array_values($list)
        );

        return match ($key) {
            'sales-summary' => [
                [__('Summary'), [__('Figure'), __('This range'), __('Compared with')], $rows($data['tiles'], ['label', 'value', 'before'])],
                [__('Still pending'), [__('Amount')], [[$data['pending']]]],
            ],
            'sales-trend' => [[__('Sales'), [__('From'), __('Orders'), __('Sales'), __('Still pending')], $rows($data['rows'], ['label', 'orders', 'sales', 'pending'])]],
            'order-times' => [[
                __('Orders by hour'),
                array_merge([__('Hour')], $data['days'], [__('All days')]),
                array_map(fn (array $hour) => array_merge([$hour['label']], $hour['byDay'], [$hour['orders']]), $data['hours']),
            ]],
            'order-status' => [[__('Order status'), [__('Status'), __('Orders'), __('Share %'), __('Amount')], $rows($data['statuses'], ['label', 'orders', 'share', 'amount'])]],
            'payment-methods' => [[__('Payment methods'), [__('Method'), __('Orders'), __('Share of orders %'), __('Sales')], $rows($data['methods'], ['name', 'orders', 'share', 'sales'])]],
            'basket-size' => [[__('Basket size'), [__('Plates'), __('Orders'), __('Share %')], $rows($data['sizes'], ['label', 'orders', 'share'])]],
            'cancelled-value' => [
                [__('Cancellations'), [__('Cancelled'), __('Orders'), __('Value')], $rows($data['who'], ['label', 'orders', 'amount'])],
                [__('Reasons'), [__('Reason'), __('Orders')], $rows($data['reasons'], ['reason', 'orders'])],
            ],
            'collections' => [
                [__('Collections by day'), array_merge([__('Day verified')], $data['methods'], [__('Total')]), array_map(fn (array $day) => array_merge([$day['label']], $day['amounts'], [$day['total']]), $data['days'])],
                [__('Who verified'), [__('Person'), __('Payments'), __('Cash'), __('All methods')], $rows($data['people'], ['name', 'payments', 'cash', 'total'])],
            ],
            'discounts' => [[__('Discounts'), [__('Discount'), __('Orders'), __('Approved'), __('Rejected'), __('Waiting'), __('Given')], $rows($data['discounts'], ['name', 'orders', 'approved', 'rejected', 'pending', 'given'])]],
            'review-time' => array_map(
                fn (array $group) => [$group['title'], [__('Wait'), __('Decisions'), __('Share %')], $rows($group['bands'], ['label', 'decisions', 'share'])],
                $data['groups']
            ),
            'duplicate-references' => [[
                __('Repeated reference numbers'),
                [__('Reference'), __('Order'), __('Customer'), __('Placed'), __('Method'), __('Amount'), __('Payment')],
                collect($data['groups'])->flatMap(fn (array $group) => array_map(
                    fn (array $order) => [$group['reference'], $order['number'], $order['customer'], $order['date'], $order['method'], $order['amount'], $order['payment']],
                    $group['orders']
                ))->all(),
            ]],
            'money-held' => [[__('Paid, not completed'), [__('Order'), __('Customer'), __('Session'), __('Method'), __('Amount')], $rows($data['rows'], ['number', 'customer', 'session', 'method', 'amount'])]],
            'prep-suggestions' => [[__('Prep suggestions'), [__('Dish'), __('Sessions looked at'), __('Average ordered'), __('Average prepared'), __('Times sold out'), __('Suggested plates')], $rows($data['dishes'], ['dish_name', 'sessions', 'ordered', 'prepared', 'soldOut', 'suggested'])]],
            'dish-sales' => [
                [__('Best sellers'), [__('Dish'), __('Plates'), __('Amount')], $rows($data['top'], ['dish_name', 'plates', 'amount'])],
                [__('Slowest when offered'), [__('Dish'), __('Sessions'), __('Plates each')], $rows($data['slow'], ['dish_name', 'sessions', 'average'])],
                [__('By dish type'), [__('Type'), __('Plates')], $rows($data['types'], ['type', 'plates'])],
            ],
            'sell-through' => [[__('Leftover plates'), [__('Dish'), __('Sessions'), __('Prepared'), __('Ordered'), __('Left over'), __('Left per session'), __('Ordered %')], $rows($data['dishes'], ['dish_name', 'sessions', 'prepared', 'ordered', 'left', 'leftPerSession', 'rate'])]],
            'sellout-speed' => [[__('Sell-out speed'), [__('Dish'), __('Times sold out'), __('Times offered'), __('How often %'), __('Minutes after opening')], $rows($data['dishes'], ['dish_name', 'soldOut', 'offered', 'rate', 'minutes'])]],
            'rice' => [
                [__('Rice'), [__('With rice'), __('Without rice'), __('Without %')], [[$data['with'], $data['without'], $data['rate']]]],
                [__('Often without rice'), [__('Dish'), __('With'), __('Without'), __('Without %')], $rows($data['dishes'], ['dish_name', 'with', 'without', 'rate'])],
            ],
            'weekday-demand' => [[
                __('Average plates ordered by weekday'),
                array_merge([__('Dish')], $data['days']),
                array_map(fn (array $dish) => array_merge([$dish['dish_name']], $dish['averages']), $data['dishes']),
            ]],
            'bring-back' => [[__('Bring back'), [__('Dish'), __('Sessions'), __('Plates each'), __('Last offered'), __('Days ago')], $rows($data['dishes'], ['dish_name', 'sessions', 'average', 'last', 'daysAgo'])]],
            'menu-fatigue' => [[__('Menu fatigue'), [__('Dish'), __('Sessions'), __('Earlier plates each'), __('Recent plates each'), __('Drop %')], $rows($data['dishes'], ['dish_name', 'sessions', 'before', 'now', 'drop'])]],
            'new-dish-performance' => [[__('New dishes'), [__('Dish'), __('Added'), __('Sessions'), __('Plates each'), __('Ordered %'), __('Against the average %')], $rows($data['dishes'], ['dish_name', 'added', 'sessions', 'average', 'rate', 'against'])]],
            'pairings' => [[__('Pairings'), [__('Dishes'), __('Orders together'), __('Share %'), __('Of the orders with'), __('Also had')], $rows($data['pairs'], ['dishes', 'orders', 'share', 'of', 'with'])]],
            'session-scorecard' => [[__('Session scorecard'), [__('Session'), __('Window'), __('Orders'), __('Sales'), __('Plates ordered'), __('Plates prepared'), __('Ordered %'), __('Dishes sold out'), __('Left over')], $rows($data['sessions'], ['date', 'window', 'orders', 'sales', 'ordered', 'prepared', 'rate', 'soldOut', 'left'])]],
            'customers' => [
                [__('Customers'), [__('Customers'), __('New'), __('Returning'), __('Orders each')], [[$data['customers'], $data['new'], $data['returning'], $data['perCustomer']]]],
                [__('Orders in the range'), [__('Orders'), __('Customers'), __('Share %')], $rows($data['buckets'], ['label', 'customers', 'share'])],
            ],
            'repeat-rate' => [[__('Repeat rate'), [__('Month'), __('Customers'), __('Ordered again'), __('Rate %')], $rows($data['months'], ['label', 'customers', 'returned', 'rate'])]],
            'never-ordered' => [[__('Never ordered'), [__('Accounts that can order'), __('Never ordered'), __('Share %'), __('Of those, older than a week')], [[$data['accounts'], $data['never'], $data['share'], $data['older']]]]],
            'companies' => [[__('Companies'), [__('Company'), __('Orders'), __('Customers'), __('Sales')], $rows($data['companies'], ['name', 'orders', 'customers', 'sales'])]],
            'company-participation' => [[__('Company participation'), [__('Company'), __('Accounts'), __('Ordered'), __('Rate %')], $rows($data['companies'], ['name', 'registered', 'ordering', 'rate'])]],
            'lapsed-customers' => [[__('Lapsed customers'), [__('Customer'), __('Orders'), __('Last order')], $rows($data['rows'], ['name', 'orders', 'last'])]],
            'top-customers' => [[__('Top customers'), [__('Customer'), __('Company'), __('Completed orders'), __('Sales')], $rows($data['rows'], ['name', 'company', 'orders', 'sales'])]],
            // A new widget without its CSV tables is a mistake, not an empty file.
            default => throw new LogicException("No CSV tables for the widget [{$key}]."),
        };
    }

    // Excel and friends run a cell that starts like a formula: a typed name or reason must not.
    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) && ! is_numeric($text) ? "'" . $text : $text;
    }
}
