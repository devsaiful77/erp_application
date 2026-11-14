<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly CustomerTransactionService $customerTransactionService,
        private readonly LedgerPostingService $ledgerPostingService,
        private readonly AttachmentService $attachmentService,
    ) {}

    public function paginate(int $perPage = 15, ?string $search = null, ?int $customerId = null): LengthAwarePaginator
    {
        return Sale::query()
            ->with(['customer:id,name', 'workOrder:id,work_order_no', 'creator:id,name', 'approver:id,name'])
            ->withLineTotals()
            ->withPaidAmount()
            ->when($search, fn ($query) => $query->where('invoice_number', 'like', "%{$search}%"))
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->latest('issue_date')
            ->paginate($perPage);
    }

    public function find(Sale $sale): Sale
    {
        return $sale->load(['items.unit', 'customer', 'workOrder']);
    }

    /**
     * Create a sale invoice, its line items, its journal entry, and the
     * "Invoice" ledger entry it posts to the customer's subsidiary ledger —
     * all totals are computed here from `items` rather than trusted from the
     * request. As on the payroll module's sale form, the debit and credit
     * accounts can be chosen; they default to Accounts Receivable and Sales
     * Revenue.
     *
     * @param  array{customer_id: int, work_order_id?: ?int, invoice_number: string, description?: ?string, issue_date: string, due_date?: ?string, notes?: ?string, debit_account_id?: ?int, credit_account_id?: ?int, overall_discount?: ?float, overall_vat?: ?float, previous_advance_amount?: ?float, previous_advance_purpose?: ?string, attachment?: ?UploadedFile, items: array<int, array{item_name: string, description?: ?string, qty: float, unit_id?: ?int, unit_price: float, discount?: ?float, vat?: ?float}>}  $data
     */
    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $totals = LineItemTotalCalculator::calculate(
                $data['items'],
                (float) ($data['overall_discount'] ?? 0),
                (float) ($data['overall_vat'] ?? 0),
            );
            $advance = $this->previousAdvance($data, $totals['net_total']);

            $sale = Sale::create([
                'customer_id' => $data['customer_id'],
                'work_order_id' => $data['work_order_id'] ?? null,
                'invoice_number' => $data['invoice_number'],
                'description' => $data['description'] ?? null,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'attachment' => $this->attachmentService->store($data['attachment'] ?? null, 'sales'),
                'debit_account_id' => $data['debit_account_id'] ?? $this->ledgerPostingService->predefinedAccount(LedgerPostingService::ACCOUNTS_RECEIVABLE_NUMBER)?->id,
                'credit_account_id' => $data['credit_account_id'] ?? $this->ledgerPostingService->predefinedAccount(LedgerPostingService::SALES_REVENUE_NUMBER)?->id,
                'overall_discount' => $totals['overall_discount'],
                'overall_vat' => $totals['overall_vat'],
                'previous_advance_amount' => $advance['amount'],
                'previous_advance_purpose' => $advance['purpose'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $sale->items()->createMany($totals['items']);

            $customer = Customer::findOrFail($data['customer_id']);
            $entry = $this->postToLedger($sale);

            $transaction = $this->customerTransactionService->create($customer, [
                'transaction_type' => 'Invoice',
                'work_order_id' => $sale->work_order_id,
                'invoice_no' => $data['invoice_number'],
                'debit' => $totals['net_total'],
                'account_transaction_id' => $entry?->id,
                'transaction_date' => $data['issue_date'],
                'notes' => $data['description'] ?? null,
            ]);

            $sale->update(['customer_transaction_id' => $transaction->id]);

            return $sale->load(['items.unit', 'customer', 'workOrder']);
        });
    }

    /**
     * Update a sale invoice. When `items` is supplied, its line items are
     * replaced and every total is recomputed; either way, the linked ledger
     * entry is kept in sync in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($sale, $data) {
            $totals = $this->recalculatedTotals($sale, $data);

            $advance = $this->updatedPreviousAdvance($sale, $data, $totals['net_total'] ?? (float) $sale->net_total);
            $payable = round(($totals['net_total'] ?? (float) $sale->net_total) - $advance['amount'], 2);

            if (($totals || $advance['changed']) && $payable < $sale->paid_amount) {
                throw ValidationException::withMessages([
                    'items' => __('The new payable amount (:total) cannot be less than the amount already paid (:paid).', ['total' => $payable, 'paid' => $sale->paid_amount]),
                ]);
            }

            $sale->fill([
                ...collect($data)->except(['items', 'overall_discount', 'overall_vat', 'previous_advance_amount', 'previous_advance_purpose', ...AttachmentService::FIELDS])->all(),
                'previous_advance_amount' => $advance['amount'],
                'previous_advance_purpose' => $advance['purpose'],
                'updated_by' => Auth::id(),
            ]);

            $this->attachmentService->sync($sale, $data, 'sales');

            $sale->debit_account_id ??= $this->ledgerPostingService->predefinedAccount(LedgerPostingService::ACCOUNTS_RECEIVABLE_NUMBER)?->id;
            $sale->credit_account_id ??= $this->ledgerPostingService->predefinedAccount(LedgerPostingService::SALES_REVENUE_NUMBER)?->id;

            if ($totals) {
                $sale->overall_discount = $totals['overall_discount'];
                $sale->overall_vat = $totals['overall_vat'];
            }

            // Payments are matched to the sale by invoice number, so they follow a renumbered invoice.
            if ($sale->isDirty('invoice_number')) {
                CustomerTransaction::query()
                    ->where('transaction_type', CustomerTransaction::TYPE_PAYMENT_RECEIVED)
                    ->where('customer_id', $sale->customer_id)
                    ->where('invoice_no', $sale->getOriginal('invoice_number'))
                    ->update(['invoice_no' => $sale->invoice_number]);
            }

            $sale->save();

            if (isset($data['items'])) {
                $sale->items()->delete();
                $sale->items()->createMany($totals['items']);
                $sale->unsetRelation('items');
            }

            // Rewrite the journal entry in place, or post it now if the sale was
            // created before the predefined accounts were seeded.
            if ($entry = $this->ledgerPostingService->entryFor($sale)) {
                $this->ledgerPostingService->repost($entry, $this->journalHeader($sale), $this->journalLines($sale));
            } else {
                $entry = $this->postToLedger($sale);
            }

            if ($sale->ledgerTransaction) {
                $this->customerTransactionService->updateAmounts($sale->ledgerTransaction, [
                    'work_order_id' => $sale->work_order_id,
                    'invoice_no' => $sale->invoice_number,
                    'debit' => $sale->net_total,
                    'account_transaction_id' => $entry?->id,
                    'transaction_date' => $sale->issue_date,
                    'notes' => $sale->description,
                ]);
            }

            return $sale->load(['items.unit', 'customer', 'workOrder']);
        });
    }

    /**
     * Totals recomputed for an update: when the line items or the overall
     * discount/VAT change. Whichever of them is not sent is taken from the
     * sale as it is now. Returns null when none of them changed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function recalculatedTotals(Sale $sale, array $data): ?array
    {
        if (! isset($data['items']) && ! array_key_exists('overall_discount', $data) && ! array_key_exists('overall_vat', $data)) {
            return null;
        }

        return LineItemTotalCalculator::calculate(
            $data['items'] ?? $sale->items->toArray(),
            (float) (array_key_exists('overall_discount', $data) ? $data['overall_discount'] : $sale->overall_discount),
            (float) (array_key_exists('overall_vat', $data) ? $data['overall_vat'] : $sale->overall_vat),
        );
    }

    /**
     * The previous advance to store with a sale: a non-negative amount that
     * cannot exceed the net total, and its purpose (kept only with an amount).
     *
     * @param  array<string, mixed>  $data
     * @return array{amount: float, purpose: ?string}
     *
     * @throws ValidationException when the advance is more than the net total.
     */
    private function previousAdvance(array $data, float $netTotal): array
    {
        $amount = round((float) ($data['previous_advance_amount'] ?? 0), 2);

        if ($amount > round($netTotal, 2)) {
            throw ValidationException::withMessages([
                'previous_advance_amount' => __('The adjustment (:amount) cannot be more than the net total (:total).', ['amount' => $amount, 'total' => round($netTotal, 2)]),
            ]);
        }

        $purpose = trim((string) ($data['previous_advance_purpose'] ?? ''));

        return ['amount' => $amount, 'purpose' => $amount > 0 && $purpose !== '' ? $purpose : null];
    }

    /**
     * The advance after an update: what was sent, else what the sale has now —
     * re-checked against the (possibly changed) net total.
     *
     * @param  array<string, mixed>  $data
     * @return array{amount: float, purpose: ?string, changed: bool}
     */
    private function updatedPreviousAdvance(Sale $sale, array $data, float $netTotal): array
    {
        $sent = array_key_exists('previous_advance_amount', $data) || array_key_exists('previous_advance_purpose', $data);

        $advance = $this->previousAdvance([
            'previous_advance_amount' => array_key_exists('previous_advance_amount', $data) ? $data['previous_advance_amount'] : $sale->previous_advance_amount,
            'previous_advance_purpose' => array_key_exists('previous_advance_purpose', $data) ? $data['previous_advance_purpose'] : $sale->previous_advance_purpose,
        ], $netTotal);

        return [...$advance, 'changed' => $sent];
    }

    /**
     * Soft-delete a sale and reverse the ledger entry it posted, so the
     * customer's balance no longer reflects an invoice that no longer exists.
     */
    public function delete(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            if ($sale->ledgerTransaction && $sale->ledgerTransaction->status) {
                $this->customerTransactionService->reverse($sale->ledgerTransaction);
            }

            $this->ledgerPostingService->reverse($sale);

            $sale->delete();
        });
    }

    /**
     * Record a payment received against a sale, posting a credit ledger
     * entry that reduces what the customer owes and tracking how much of
     * the sale has been paid so far. The payment is also posted to the
     * general ledger: debit the cash/bank account, credit Accounts Receivable.
     *
     * @param  array{payment_account_id: int, amount: float, payment_date: string, notes?: ?string}  $data
     */
    public function recordPayment(Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($sale, $data) {
            $this->customerTransactionService->create($sale->customer, [
                'transaction_type' => CustomerTransaction::TYPE_PAYMENT_RECEIVED,
                'work_order_id' => $sale->work_order_id,
                'invoice_no' => $sale->invoice_number,
                'credit' => $data['amount'],
                'payment_account_id' => $data['payment_account_id'],
                'transaction_date' => $data['payment_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $sale->load(['items.unit', 'customer', 'workOrder']);
        });
    }

    /**
     * Post the sale's journal entry: debit its debit account (Accounts
     * Receivable by default) and credit its credit account (Sales Revenue by
     * default) with the net total, mirroring the debit the sale adds to the
     * customer ledger.
     *
     * Returns null without posting when either account is unknown (the
     * predefined accounts have not been seeded), so sales still work before
     * the chart is set up, as they do in the payroll module.
     */
    private function postToLedger(Sale $sale): ?AccountTransaction
    {
        if (! $sale->debit_account_id || ! $sale->credit_account_id) {
            return null;
        }

        return $this->ledgerPostingService->post($sale, $this->journalHeader($sale), $this->journalLines($sale));
    }

    /**
     * @return array{tr_no: string, date: string, general_particular: ?string}
     */
    private function journalHeader(Sale $sale): array
    {
        return [
            'tr_no' => $sale->invoice_number,
            'date' => $sale->issue_date->toDateString(),
            'general_particular' => $sale->notes,
        ];
    }

    /**
     * @return array<int, array{account_id: int, debit?: float, credit?: float, particular: string}>
     */
    private function journalLines(Sale $sale): array
    {
        $amount = (float) $sale->net_total;

        return [
            ['account_id' => $sale->debit_account_id, 'debit' => $amount, 'particular' => "{$amount} amount debit from {$sale->debit_account_id}"],
            ['account_id' => $sale->credit_account_id, 'credit' => $amount, 'particular' => "{$amount} amount credit from {$sale->credit_account_id}"],
        ];
    }
}
