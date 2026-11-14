<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `permission:purchases.update` middleware
     * on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `supplier_id` is intentionally not accepted here. Moving a purchase to a
     * different supplier would require transferring its ledger entry between
     * two suppliers' balances, which this API does not support — a purchase's
     * supplier is fixed at creation time, the same way a customer's
     * `opening_balance` is fixed after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_orders', 'id')->withoutTrashed()],
            'purchased_by_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employee_info', 'id')->withoutTrashed()],
            'purchase_type' => ['sometimes', 'required', 'string', Rule::in(['product', 'service'])],
            'invoice_number' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('purchases', 'invoice_number')->ignore($this->route('purchase'))],
            'description' => ['sometimes', 'nullable', 'string'],
            'issue_date' => ['sometimes', 'required', 'date'],
            'purchase_date' => ['sometimes', 'required', 'date'],
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
        ];
    }
}
