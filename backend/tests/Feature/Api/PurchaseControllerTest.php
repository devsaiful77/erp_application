<?php

use App\Models\AccountTransaction;
use App\Models\ChartOfAccount;
use App\Models\Employee;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\WorkOrder;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function purchasePayload(int $supplierId, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $supplierId,
        'purchase_type' => 'product',
        'invoice_number' => 'PINV-'.fake()->unique()->numerify('#####'),
        'description' => 'Office supplies',
        'issue_date' => now()->toDateString(),
        'purchase_date' => now()->toDateString(),
        'notes' => 'test purchase',
        'items' => [
            ['item_name' => 'Paper', 'qty' => 10, 'unit_price' => 5, 'discount' => 0, 'vat' => 0],
            ['item_name' => 'Pens', 'qty' => 20, 'unit_price' => 2, 'discount' => 5, 'vat' => 3],
        ],
    ], $overrides);
}

describe('index', function () {
    it('returns 401 when no token is provided', function () {
        $this->getJson('/api/purchases')->assertStatus(401);
    });

    it('returns a paginated list of purchases', function () {
        $actor = adminUser();
        Purchase::factory()->count(3)->create();

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/purchases');

        $response->assertOk()->assertJsonCount(3, 'data.purchases');
    });

    it('filters purchases by supplier', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        Purchase::factory()->for($supplier)->create();
        Purchase::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/purchases?supplier_id={$supplier->id}");

        $response->assertOk()->assertJsonCount(1, 'data.purchases');
    });
});

describe('store', function () {
    it('requires supplier_id, invoice_number, dates, and items', function () {
        $actor = adminUser();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['supplier_id', 'invoice_number', 'issue_date', 'purchase_date', 'items']]);
    });

    it('rejects an invoice_number that is already taken', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $existing = Purchase::factory()->for($supplier)->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, ['invoice_number' => $existing->invoice_number]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['invoice_number']]);
    });

    it('computes totals from items instead of trusting client-supplied totals', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();

        // Paper: 10 * 5 = 50 gross. Pens: 20 * 2 = 40 gross, -5 discount, +3 vat = 38 net.
        // total_amount = 90, discount_amount = 5, vat_amount = 3, net_total = 88.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, [
                'total_amount' => 999999,
                'net_total' => 999999,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.total_amount', 90)
            ->assertJsonPath('data.discount_amount', 5)
            ->assertJsonPath('data.vat_amount', 3)
            ->assertJsonPath('data.net_total', 88)
            ->assertJsonCount(2, 'data.items');
    });

    it('posts a Purchase ledger entry that increases the supplier balance', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create(['opening_balance' => 100]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->assertStatus(201);

        // net_total is 88 (see totals test above).
        expect($supplier->fresh()->current_balance)->toEqual('188.00');
        $this->assertDatabaseHas('supplier_transactions', [
            'supplier_id' => $supplier->id,
            'transaction_type' => 'Purchase',
            'credit' => 88,
        ]);
    });
});

describe('store with products', function () {
    it('links a line item to the selected product', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['name' => 'Cement Bag']);

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/purchases', purchasePayload($supplier->id, [
            'items' => [
                ['product_id' => $product->id, 'item_name' => 'Cement Bag', 'qty' => 2, 'unit_price' => 10],
                ['item_name' => 'Custom item', 'qty' => 1, 'unit_price' => 5],
            ],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.1.product_id', null);

        $this->assertDatabaseHas('purchase_items', ['product_id' => $product->id, 'item_name' => null]);
    });

    it('rejects a deleted product', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $product->delete();

        $this->actingAs($actor, 'sanctum')->postJson('/api/purchases', purchasePayload($supplier->id, [
            'items' => [['product_id' => $product->id, 'item_name' => 'Gone', 'qty' => 1, 'unit_price' => 1]],
        ]))->assertStatus(422);
    });
});

describe('update', function () {
    it('ignores a supplier_id sent in the update payload', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase['id']}", ['supplier_id' => $otherSupplier->id])
            ->assertOk();

        $this->assertDatabaseHas('purchases', ['id' => $purchase['id'], 'supplier_id' => $supplier->id]);
    });

    it('recomputes totals and keeps the linked ledger entry in sync when items change', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create(['opening_balance' => 0]);
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        expect($supplier->fresh()->current_balance)->toEqual('88.00');

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase['id']}", [
                'items' => [
                    ['item_name' => 'Paper', 'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'vat' => 0],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.net_total', 100)
            ->assertJsonCount(1, 'data.items');

        // The ledger entry the purchase posted moves with it, not just the purchase row.
        expect($supplier->fresh()->current_balance)->toEqual('100.00');
    });

    it('rejects reducing the total below what has already been paid', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        $this->seed(ChartOfAccountSeeder::class);
        $cash = ChartOfAccount::query()->where('account_number', '1010')->first();

        // net_total is 88; pay 50 of it.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $cash->id, 'amount' => 50, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase['id']}", [
                'items' => [
                    ['item_name' => 'Paper', 'qty' => 1, 'unit_price' => 10, 'discount' => 0, 'vat' => 0],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['items']]);
    });
});

describe('payments', function () {
    beforeEach(function () {
        $this->seed(ChartOfAccountSeeder::class);
        $this->cash = ChartOfAccount::query()->where('account_number', '1010')->first();
    });

    it('rejects a payment that exceeds the due amount', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        // net_total is 88.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 200, 'payment_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['amount']]);
    });

    it('records a partial payment, reduces the due amount, and decreases the supplier balance', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create(['opening_balance' => 0]);
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        // net_total is 88.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", [
                'payment_account_id' => $this->cash->id,
                'amount' => 30,
                'payment_date' => now()->toDateString(),
                'notes' => 'partial payment',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.paid_amount', 30)
            ->assertJsonPath('data.due_amount', 58);

        expect($supplier->fresh()->current_balance)->toEqual('58.00');
        $this->assertDatabaseHas('supplier_transactions', [
            'supplier_id' => $supplier->id,
            'transaction_type' => 'Bill Payment',
            'debit' => 30,
        ]);
    });

    it('allows paying off the remaining due amount across multiple partial payments', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        // net_total is 88.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 58, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 30, 'payment_date' => now()->toDateString()]);

        $response->assertStatus(201)
            ->assertJsonPath('data.paid_amount', 88)
            ->assertJsonPath('data.due_amount', 0);
    });

    it('requires an open cash/bank credit account', function () {
        $actor = adminUser();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload(Supplier::factory()->create()->id))
            ->json('data');
        $expenseAccount = ChartOfAccount::query()->where('account_number', '5010')->first();

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 10, 'payment_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['payment_account_id']]);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $expenseAccount->id, 'amount' => 10, 'payment_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['payment_account_id']]);
    });

    it('saves the payment as a supplier payment and posts it to the chart of accounts', function () {
        $actor = adminUser();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload(Supplier::factory()->create()->id))
            ->json('data');
        $payable = ChartOfAccount::query()->where('account_number', '2010')->first();

        // net_total is 88, credited to Accounts Payable when the purchase was saved.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 30, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        expect((float) $this->cash->fresh()->balance)->toBe(-30.0)
            ->and((float) $payable->fresh()->balance)->toBe(58.0);

        $this->assertDatabaseHas('supplier_payments', [
            'purchase_id' => $purchase['id'],
            'payment_account_id' => $this->cash->id,
            'bill_amount' => 30,
            'bank_charge' => 0,
        ]);
    });
});

describe('destroy', function () {
    it('soft-deletes the purchase and reverses its ledger entry', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create(['opening_balance' => 0]);
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        expect($supplier->fresh()->current_balance)->toEqual('88.00');

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/purchases/{$purchase['id']}")
            ->assertOk();

        $this->assertSoftDeleted('purchases', ['id' => $purchase['id']]);
        expect($supplier->fresh()->current_balance)->toEqual('0.00');
    });
});

describe('chart of account posting', function () {
    beforeEach(function () {
        $this->seed(ChartOfAccountSeeder::class);

        $this->inventory = ChartOfAccount::query()->where('account_number', '1040')->first();
        $this->purchaseAccount = ChartOfAccount::query()->where('account_number', '5010')->first();
        $this->payable = ChartOfAccount::query()->where('account_number', '2010')->first();
    });

    it('debits Inventory/Stock for a product purchase and credits Accounts Payable with the net total', function () {
        $supplier = Supplier::factory()->create();

        $purchaseId = $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->assertStatus(201)
            ->json('data.id');

        $purchase = Purchase::find($purchaseId);

        expect((float) $this->inventory->fresh()->balance)->toBe(88.0)
            ->and((float) $this->payable->fresh()->balance)->toBe(88.0)
            ->and($purchase->journalEntry->source->is($purchase))->toBeTrue()
            ->and($purchase->ledgerTransaction->account_transaction_id)->toBe($purchase->journalEntry->id);
    });

    it('debits Purchase for a service purchase', function () {
        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/purchases', purchasePayload(Supplier::factory()->create()->id, ['purchase_type' => 'service']))
            ->assertStatus(201);

        expect((float) $this->purchaseAccount->fresh()->balance)->toBe(88.0)
            ->and((float) $this->inventory->fresh()->balance)->toBe(0.0);
    });

    it('posts to the debit and credit accounts chosen on the purchase', function () {
        $cash = ChartOfAccount::query()->where('account_number', '1010')->first();
        $repairs = ChartOfAccount::query()->where('account_number', '5060')->first();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/purchases', purchasePayload(Supplier::factory()->create()->id, [
                'debit_account_id' => $repairs->id,
                'credit_account_id' => $cash->id,
            ]))
            ->assertStatus(201);

        expect((float) $repairs->fresh()->balance)->toBe(88.0)
            ->and((float) $cash->fresh()->balance)->toBe(-88.0)
            ->and((float) $this->payable->fresh()->balance)->toBe(0.0);
    });

    it('rewrites its journal entry in place when the items change', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();

        $purchaseId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchaseId}", [
                'items' => [['item_name' => 'Paper', 'qty' => 20, 'unit_price' => 5, 'discount' => 0, 'vat' => 0]],
            ])
            ->assertOk();

        expect((float) $this->inventory->fresh()->balance)->toBe(100.0)
            ->and((float) $this->payable->fresh()->balance)->toBe(100.0)
            ->and(AccountTransaction::count())->toBe(1);
    });

    it('reverses the posting when the purchase is deleted', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();

        $purchaseId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/purchases/{$purchaseId}")->assertOk();

        expect((float) $this->inventory->fresh()->balance)->toBe(0.0)
            ->and((float) $this->payable->fresh()->balance)->toBe(0.0)
            ->and(AccountTransaction::count())->toBe(0);
    });

    it('deletes a purchase that never posted a journal entry', function () {
        $purchase = Purchase::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')->deleteJson("/api/purchases/{$purchase->id}")->assertOk();

        expect((float) $this->payable->fresh()->balance)->toBe(0.0);
    });

    it('nets Accounts Payable to zero once a supplier payment settles the purchase', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $cash = ChartOfAccount::query()->where('account_number', '1010')->first();

        $purchaseId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/accounting/purchase/payment/store', [
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchaseId,
                'payment_account_id' => $cash->id,
                'payment_date' => today()->toDateString(),
                'bill_amount' => 88,
                'bank_charge' => 0,
            ])
            ->assertStatus(201);

        expect((float) $this->payable->fresh()->balance)->toBe(0.0)
            ->and((float) $cash->fresh()->balance)->toBe(-88.0);
    });
});

describe('work order', function () {
    it('tags a purchase with a work order', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 'WO-PUR-1']);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, ['work_order_id' => $workOrder->id]));

        $response->assertStatus(201)
            ->assertJsonPath('data.work_order_id', $workOrder->id)
            ->assertJsonPath('data.work_order_no', 'WO-PUR-1');
    });

    it('rejects a deleted work order', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->delete();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, ['work_order_id' => $workOrder->id]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['work_order_id']]);
    });

    it('moves a purchase to another work order or clears it', function () {
        $actor = adminUser();
        $purchase = Purchase::factory()->create(['work_order_id' => WorkOrder::factory()]);
        $other = WorkOrder::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase->id}", ['work_order_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.work_order_id', $other->id);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase->id}", ['work_order_id' => null])
            ->assertOk()
            ->assertJsonPath('data.work_order_id', null);
    });
});

describe('units, overall discount/VAT and purchased by', function () {
    it('stores line units and adds the overall discount and VAT to the totals', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create(['opening_balance' => 0]);
        $buyer = Employee::factory()->create();
        $ream = InventoryUnit::factory()->create(['short_name' => 'rm']);
        $pcs = InventoryUnit::factory()->create(['short_name' => 'pcs']);

        // Lines: 90 gross, 5 discount, 3 VAT (see the totals test). Overall: -8 discount, +10 VAT.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, [
                'items' => [
                    ['item_name' => 'Paper', 'qty' => 10, 'unit_id' => $ream->id, 'unit_price' => 5],
                    ['item_name' => 'Pens', 'qty' => 20, 'unit_id' => $pcs->id, 'unit_price' => 2, 'discount' => 5, 'vat' => 3],
                ],
                'overall_discount' => 8,
                'overall_vat' => 10,
                'purchased_by_id' => $buyer->id,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.unit_id', $ream->id)
            ->assertJsonPath('data.items.0.unit', 'rm')
            ->assertJsonPath('data.overall_discount', 8)
            ->assertJsonPath('data.overall_vat', 10)
            ->assertJsonPath('data.discount_amount', 13)
            ->assertJsonPath('data.vat_amount', 13)
            ->assertJsonPath('data.net_total', 90)
            ->assertJsonPath('data.purchased_by_id', $buyer->id)
            ->assertJsonPath('data.purchased_by_name', "{$buyer->name} ({$buyer->employee_code})");

        expect($supplier->fresh()->current_balance)->toEqual('90.00');
    });

    it('recomputes the totals when only the overall discount changes', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();
        $purchase = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id))
            ->json('data');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/purchases/{$purchase['id']}", ['overall_discount' => 8])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', 13)
            ->assertJsonPath('data.net_total', 80)
            ->assertJsonCount(2, 'data.items');
    });

    it('rejects a discount larger than the invoice amount', function () {
        $actor = adminUser();
        $supplier = Supplier::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, ['overall_discount' => 1000]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['overall_discount']]);
    });
});

describe('attachment', function () {
    it('stores an uploaded invoice file and replaces it on update', function () {
        Storage::fake('public');
        $supplier = Supplier::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, ['attachment' => UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf')]))
            ->assertStatus(201);
        $purchase = Purchase::sole();
        $oldAttachment = $purchase->attachment;
        Storage::disk('public')->assertExists($oldAttachment);

        $this->actingAs(adminUser(), 'sanctum')
            ->post("/api/purchases/{$purchase->id}", ['_method' => 'PUT', 'attachment' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.attachment_url', Storage::disk('public')->url($purchase->fresh()->attachment));

        Storage::disk('public')->assertMissing($oldAttachment);
    });
});

describe('zero total', function () {
    it('rejects a purchase whose total is zero instead of failing to post it', function () {
        $supplier = Supplier::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/purchases', purchasePayload($supplier->id, [
                'items' => [['item_name' => 'Paper', 'qty' => 1, 'unit_price' => 0]],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['items']]);

        expect(Purchase::count())->toBe(0);
    });
});
