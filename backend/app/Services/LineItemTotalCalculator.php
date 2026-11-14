<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class LineItemTotalCalculator
{
    /**
     * Compute per-line and invoice-level totals from a set of line items.
     * Shared by SaleService and PurchaseService so the same money math can't
     * drift between the two — a client-supplied total is never trusted.
     *
     * The invoice-level (overall) discount and VAT are added on top of the
     * per-line amounts, so `discount_amount` and `vat_amount` are the full
     * invoice totals.
     *
     * @param  array<int, array{product_id?: ?int, item_name?: ?string, description?: ?string, qty: float, unit_id?: ?int, unit_price: float, discount?: ?float, vat?: ?float}>  $items
     * @return array{items: array<int, array<string, mixed>>, total_amount: float, overall_discount: float, overall_vat: float, discount_amount: float, vat_amount: float, net_total: float}
     *
     * @throws ValidationException when the discounts exceed the invoice amount, or the invoice total is zero.
     */
    public static function calculate(array $items, float $overallDiscount = 0, float $overallVat = 0): array
    {
        $totalAmount = 0;
        $discountAmount = 0;
        $vatAmount = 0;
        $computedItems = [];

        foreach ($items as $item) {
            $qty = (float) $item['qty'];
            $unitPrice = (float) $item['unit_price'];
            $discount = (float) ($item['discount'] ?? 0);
            $vat = (float) ($item['vat'] ?? 0);
            $gross = $qty * $unitPrice;

            $totalAmount += $gross;
            $discountAmount += $discount;
            $vatAmount += $vat;

            $computedItems[] = [
                'product_id' => $item['product_id'] ?? null,
                // A product line is named by its product, so only a
                // free-text line keeps a name of its own.
                'item_name' => empty($item['product_id']) ? $item['item_name'] : null,
                'description' => $item['description'] ?? null,
                'qty' => $qty,
                'unit_id' => $item['unit_id'] ?? null,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'vat' => $vat,
            ];
        }

        $discountAmount += $overallDiscount;
        $vatAmount += $overallVat;

        if (round($discountAmount, 2) > round($totalAmount, 2)) {
            throw ValidationException::withMessages([
                'overall_discount' => __('The total discount (:discount) cannot be more than the invoice amount (:amount).', ['discount' => $discountAmount, 'amount' => $totalAmount]),
            ]);
        }

        $netTotal = $totalAmount - $discountAmount + $vatAmount;

        // A zero invoice has nothing to post to the ledger, and is almost always
        // a missing unit price.
        if (round($netTotal, 2) <= 0) {
            throw ValidationException::withMessages([
                'items' => __('The invoice total must be greater than zero. Please enter the unit price.'),
            ]);
        }

        return [
            'items' => $computedItems,
            'total_amount' => $totalAmount,
            'overall_discount' => $overallDiscount,
            'overall_vat' => $overallVat,
            'discount_amount' => $discountAmount,
            'vat_amount' => $vatAmount,
            'net_total' => $netTotal,
        ];
    }
}
