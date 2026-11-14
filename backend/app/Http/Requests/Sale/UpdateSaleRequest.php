<?php

namespace App\Http\Requests\Sale;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:sales.update` middleware
     * on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `customer_id` is intentionally not accepted here, for the same reason
     * as a purchase's `supplier_id` — moving a sale to a different customer
     * would require transferring its ledger entry between two customers'
     * balances, which this API does not support.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_order_id' => ['sometimes', 'nullable', Rule::exists('work_orders', 'id')->where('customer_id', $this->route('sale')?->customer_id)->withoutTrashed()],
            'invoice_number' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('sales', 'invoice_number')->ignore($this->route('sale'))],
            'description' => ['sometimes', 'nullable', 'string'],
            'issue_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:20480'],
            'remove_attachment' => ['sometimes', 'boolean'],
            // The journal entry's accounts, as on the payroll module's form; omitted
            // to use the predefined defaults.
            'debit_account_id' => ['sometimes', 'nullable', Rule::exists('chart_of_accounts', 'id')->where('is_transaction', 1)->where('is_closed', 0)->whereNull('deleted_at'), 'different:credit_account_id'],
            'credit_account_id' => ['sometimes', 'nullable', Rule::exists('chart_of_accounts', 'id')->where('is_transaction', 1)->where('is_closed', 0)->whereNull('deleted_at')],

            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'items.*.item_name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_id' => ['nullable', 'integer', Rule::exists('inventory_units', 'id')],
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

    /**
     * Payments already received on the sale are tagged with its work order,
     * so the work order can only change while nothing has been paid.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sale = $this->route('sale');

            if (! $this->exists('work_order_id') || ! $sale || (float) $sale->paid_amount <= 0) {
                return;
            }

            if ((int) $this->input('work_order_id') !== (int) $sale->work_order_id) {
                $validator->errors()->add('work_order_id', __('The work order cannot be changed after a payment has been received.'));
            }
        });
    }
}
