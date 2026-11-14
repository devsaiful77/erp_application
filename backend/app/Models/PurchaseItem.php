<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'vat' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * qty x unit price, less the line discount, plus the line VAT.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->qty * (float) $this->unit_price - (float) $this->discount + (float) $this->vat, 2));
    }

    /**
     * A product line is named by its product; only a free-text line (no
     * product) stores its own name.
     */
    protected function itemName(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->product_id ? ($this->product?->name ?? $value) : $value);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }
}
