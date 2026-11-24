<?php

namespace App\Support;

use App\Exceptions\AlaCarteRefusedException;
use App\Models\Setting;
use Illuminate\Support\Collection;

// Ala carte = a plate WITHOUT its rice, for the rice price less. Shared by the regular checkout
// (OrderController) and the pre-order checkout (PreOrderController) so both do exactly the same thing:
//
//   rules() / clean()   the two request fields a checkout accepts
//   state()             what the menu needs to draw the choice (also sent with a refusal and the live poll)
//   judge()             check the customer's counts against the settings and the dishes, found NOW
//   lines()             split one dish into its "with rice" and "without rice" order lines
//   suffix()            the words printed after a dish's name on a line sold without rice
//
// $kind is 'orders' (the live menu) or 'pre_orders': Setting::alaCarteFor() decides per kind. The server
// does all the math; the JavaScript only previews it.
class AlaCarte
{
    // The request fields of a checkout. ala_carte[session_dish_id] = how many of that dish's plates
    // are WITHOUT rice; expected_rice_price = the deduction the cart showed. Whether it may be used
    // is judged in judge(), not by a validation rule, so the answer can carry the fresh state.
    public static function rules(): array
    {
        return [
            'ala_carte' => ['nullable', 'array'],
            'ala_carte.*' => ['integer', 'min:1', 'max:100000'],
            'expected_rice_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    // The posted counts as [int id => int count], keeping only positive ones (like the items).
    public static function clean(mixed $raw): array
    {
        $counts = [];

        foreach ((array) $raw as $id => $count) {
            if (is_numeric($id) && (int) $count > 0) {
                $counts[(int) $id] = (int) $count;
            }
        }

        return $counts;
    }

    // The words after the dish's name on a line sold without rice ("Adobo (no rice)").
    public static function suffix(): string
    {
        return ' (' . __('no rice') . ')';
    }

    // Can this dish be ordered without rice? It has to come with rice, and cost more than the rice.
    public static function canGo(object $dish, float $rice): bool
    {
        return (bool) ($dish->includes_rice ?? false) && round((float) $dish->price, 2) > $rice;
    }

    // The two settings the menu needs: whether the choice is offered and the deduction per plate.
    // Setting reads come from one cached array, so this costs no query (the live poll sends it).
    public static function settings(string $kind): array
    {
        return ['enabled' => Setting::alaCarteFor($kind), 'rice_price' => Setting::ricePrice()];
    }

    // The settings plus the ids of the dishes the choice applies to. $offered are the rows of the
    // session's menu; $idField is their id column ('session_dish_id' or 'pre_order_session_dish_id').
    // Drawn with the page and sent with a refused order (a dish flag may have moved meanwhile).
    public static function state(string $kind, iterable $offered, string $idField): array
    {
        $state = self::settings($kind);
        $state['dishes'] = [];

        if ($state['enabled']) {
            foreach ($offered as $dish) {
                if (self::canGo($dish, $state['rice_price'])) {
                    $state['dishes'][] = (int) $dish->{$idField};
                }
            }
        }

        return $state;
    }

    // Judge what the customer asked for and return it as [id => count] (empty when nothing was asked).
    // Throws AlaCarteRefusedException (show its message together with state()) when it cannot be sold.
    // $validated holds `items` and the cleaned `ala_carte`; $offered is the menu keyed by id.
    public static function judge(string $kind, array $validated, Collection $offered): array
    {
        $counts = $validated['ala_carte'] ?? [];

        if ($counts === []) {
            return [];
        }

        if (! Setting::alaCarteFor($kind)) {
            throw new AlaCarteRefusedException('Ordering a plate without rice is not available any more.');
        }

        $rice = Setting::ricePrice();
        $expected = $validated['expected_rice_price'] ?? null;

        if ($expected !== null && round((float) $expected, 2) !== $rice) {
            throw new AlaCarteRefusedException('The rice price changed: a plate without rice now takes ₱'
                . number_format($rice, 2) . ' off (was ₱' . number_format((float) $expected, 2) . ').');
        }

        foreach ($counts as $id => $count) {
            $dish = $offered->get((int) $id);

            // A dish that left the menu is refused by the caller's own "no longer on the menu" check.
            if (! $dish) {
                continue;
            }

            if ($count > (int) ($validated['items'][$id] ?? 0)) {
                throw new AlaCarteRefusedException('You asked for more plates of ' . $dish->dish_name . ' without rice than you ordered.');
            }

            if (! self::canGo($dish, $rice)) {
                throw new AlaCarteRefusedException($dish->dish_name . ' cannot be ordered without rice.');
            }
        }

        return $counts;
    }

    // One dish as order lines: the plates WITH rice at the dish's price, and the plates WITHOUT rice at
    // the price less the rice (never below 0). Cents, not floats: 3 x 0.10 must be 0.30. The lines
    // carry only the price columns and `no_rice`; the caller adds its own ids and names.
    public static function lines(float $price, int $quantity, int $withoutRice, float $rice): array
    {
        $withoutRice = max(0, min($withoutRice, $quantity));
        $withRice = $quantity - $withoutRice;
        $lines = [];

        if ($withRice > 0) {
            $lines[] = [
                'quantity' => $withRice,
                'unit_price' => $price,
                'sub_total' => round($price * $withRice, 2),
                'no_rice' => false,
            ];
        }

        if ($withoutRice > 0) {
            $unit = round(max($price - $rice, 0), 2);

            $lines[] = [
                'quantity' => $withoutRice,
                'unit_price' => $unit,
                'sub_total' => round($unit * $withoutRice, 2),
                'no_rice' => true,
            ];
        }

        return $lines;
    }
}
