<?php

namespace App\Http\Requests\Sale;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:sales.create` middleware
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
     * client can never post a mismatched total (same as purchases).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', Rule::exists('customers', 'id')],
            'work_order_id' => ['nullable', Rule::exists('work_orders', 'id')->where('customer_id', $this->input('customer_id'))->withoutTrashed()],
            'invoice_number' => ['required', 'string', 'max:255', Rule::unique('sales', 'invoice_number')],
            'description' => ['nullable', 'string'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
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

            // A previous advance / bill applied against the invoice; kept apart from the discount.
            'previous_advance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'previous_advance_purpose' => ['nullable', 'string', 'max:255'],
        ];
    }
}
