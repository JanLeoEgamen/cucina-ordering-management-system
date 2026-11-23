<?php

namespace App\Support;

use App\Exceptions\DiscountRefusedException;
use App\Models\Discount;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// What placing an order with a discount needs, shared by the regular checkout (OrderController) and
// the pre-order checkout (PreOrderController) so both do exactly the same thing:
//
//   rules() / messages()   the three request fields a checkout accepts
//   offered($kind)         the discounts the checkout may show (none while System Configuration is off)
//   resolve(...)           judge the customer's pick and do the math, from the percent found NOW
//   storeProof() / discardProof()   the ID picture, on the PRIVATE disk
//
// $kind is 'orders' (the live menu) or 'pre_orders': Setting::discountsFor() decides per kind.
class OrderDiscount
{
    // The request fields of a checkout. Whether the discount may be used (on, inside its dates, needs
    // proof) is judged in resolve(), not by a validation rule, so the answer can carry the fresh list.
    public static function rules(): array
    {
        return [
            'discount_id' => ['nullable', 'integer'],
            'expected_discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            // Raster pictures only (no SVG: it would run scripts when opened on its own URL).
            'discount_proof' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }

    public static function messages(): array
    {
        return [
            'discount_proof.image' => 'The ID for the discount has to be a picture (JPG, PNG or WebP).',
            'discount_proof.mimes' => 'The ID for the discount has to be a JPG, PNG or WebP picture.',
            'discount_proof.max' => 'The ID for the discount may not be larger than 2 MB.',
        ];
    }

    // The discounts a checkout of this kind can offer right now.
    public static function offered(string $kind): array
    {
        return Setting::discountsFor($kind) ? Discount::offered() : [];
    }

    // Judge the pick and do the math. Returns:
    //   fields    the columns the order stores (empty when there is no discount): a COPY of the name and
    //             percent, so editing or deleting the discount later never rewrites the order
    //   total     what the customer pays (the subtotal when there is no discount)
    //   discount  the discount row, or null
    // Throws DiscountRefusedException (show its message with the fresh list) when the discount cannot be
    // used, and a ValidationException on `discount_proof` when it needs an ID and none came.
    public static function resolve(string $kind, array $validated, Request $request, float $subtotal): array
    {
        if (empty($validated['discount_id'])) {
            return ['fields' => [], 'total' => $subtotal, 'discount' => null];
        }

        $discount = Setting::discountsFor($kind) ? Discount::usable((int) $validated['discount_id']) : null;

        if (! $discount) {
            throw new DiscountRefusedException('That discount is not available any more.');
        }

        $expected = $validated['expected_discount_percent'] ?? null;

        if ($expected !== null && round((float) $expected, 2) !== round((float) $discount->percent, 2)) {
            throw new DiscountRefusedException('The discount changed: ' . $discount->discount_name . ' is now ' . (float) $discount->percent . '%.');
        }

        if ($discount->requires_proof && ! $request->hasFile('discount_proof')) {
            throw ValidationException::withMessages([
                'discount_proof' => 'Upload your ID to use the ' . $discount->discount_name . ' discount.',
            ]);
        }

        [$amount, $total] = Discount::amounts($subtotal, (float) $discount->percent);

        return [
            'fields' => [
                'discount_id' => (int) $discount->discount_id,
                'discount_name' => $discount->discount_name,
                'discount_percent' => (float) $discount->percent,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $amount,
                // A discount that needs no ID is approved at once; the rest wait for staff.
                'discount_status' => $discount->requires_proof ? 'pending' : 'approved',
            ],
            'total' => $total,
            'discount' => $discount,
        ];
    }

    // Keep the ID on the PRIVATE disk (never the public one): it is only served back through the
    // guarded route of OrderDiscountController. Null when there is nothing to keep.
    public static function storeProof(Request $request, ?object $discount, string $folder = 'discount-proofs'): ?string
    {
        if (! $discount || ! $discount->requires_proof) {
            return null;
        }

        return $request->file('discount_proof')->store($folder, 'local');
    }

    // Remove an uploaded ID when its order was not written.
    public static function discardProof(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
