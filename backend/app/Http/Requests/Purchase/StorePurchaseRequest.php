<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:purchases.create` middleware
     * on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Money totals (`total_amount`, `discount_amount`, `vat_amount`, `net_total`)
     * are not accepted here — they are computed server-side from `items` so a
     * client can never post a mismatched total.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')],
            'work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')->withoutTrashed()],
            'purchased_by_id' => ['nullable', 'integer', Rule::exists('employee_info', 'id')->withoutTrashed()],
            'purchase_type' => ['required', 'string', Rule::in(['product', 'service'])],
            'invoice_number' => ['required', 'string', 'max:255', Rule::unique('purchases', 'invoice_number')],
            'description' => ['nullable', 'string'],
            'issue_date' => ['required', 'date'],
            'purchase_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:20480'],
            // The journal entry's accounts, as on the payroll module's form; omitted
            // to use the predefined defaults.
            'debit_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('is_transaction', 1)->where('is_closed', 0)->whereNull('deleted_at'), 'different:credit_account_id'],
            'credit_account_id' => ['nullable', Rule::exists('chart_of_accounts', 'id')->where('is_transaction', 1)->where('is_closed', 0)->whereNull('deleted_at')],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'items.*.item_name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_id' => ['nullable', 'integer', Rule::exists('inventory_units', 'id')->where('status', 1)],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat' => ['nullable', 'numeric', 'min:0'],

            // Invoice-level amounts on top of the per-line discount and VAT.
            'overall_discount' => ['nullable', 'numeric', 'min:0'],
            'overall_vat' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
