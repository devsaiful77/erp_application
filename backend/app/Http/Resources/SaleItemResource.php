<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'item_name' => $this->item_name,
            'description' => $this->description,
            'qty' => (float) $this->qty,
            'unit_id' => $this->unit_id,
            'unit' => $this->unit?->short_name,
            'unit_name' => $this->unit?->name,
            'unit_price' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'vat' => (float) $this->vat,
            'total_amount' => (float) $this->total_amount,
        ];
    }
}
