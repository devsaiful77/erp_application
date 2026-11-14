<?php

use App\Models\AccountTransaction;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Sale;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds the predefined chart of accounts (idempotent) and returns the Cash in Hand account id.
 */
function saleCashAccountId(): int
{
    (new ChartOfAccountSeeder)->run();

    return ChartOfAccount::query()->where('account_number', '1010')->value('id');
}

function salePayload(int $customerId, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customerId,
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('#####'),
        'description' => 'Consulting services',
        'issue_date' => now()->toDateString(),
        'notes' => 'test sale',
        'items' => [
            ['item_name' => 'Consulting', 'qty' => 10, 'unit_price' => 5, 'discount' => 0, 'vat' => 0],
            ['item_name' => 'Support', 'qty' => 20, 'unit_price' => 2, 'discount' => 5, 'vat' => 3],
        ],
    ], $overrides);
}

describe('index', function () {
    it('returns 401 when no token is provided', function () {
        $this->getJson('/api/sales')->assertStatus(401);
    });

    it('returns a paginated list of sales', function () {
        $actor = adminUser();
        Sale::factory()->count(3)->create();

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/sales');

        $response->assertOk()->assertJsonCount(3, 'data.sales');
    });

    it('filters sales by customer', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        Sale::factory()->for($customer)->create();
        Sale::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/sales?customer_id={$customer->id}");

        $response->assertOk()->assertJsonCount(1, 'data.sales');
    });
});

describe('store', function () {
    it('requires customer_id, invoice_number, issue_date, and items', function () {
        $actor = adminUser();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['customer_id', 'invoice_number', 'issue_date', 'items']]);
    });

    it('rejects an invoice_number that is already taken', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $existing = Sale::factory()->for($customer)->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, ['invoice_number' => $existing->invoice_number]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['invoice_number']]);
    });

    it('computes totals from items instead of trusting client-supplied totals', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();

        // Consulting: 10 * 5 = 50 gross. Support: 20 * 2 = 40 gross, -5 discount, +3 vat = 38 net.
        // total_amount = 90, discount_amount = 5, vat_amount = 3, net_total = 88.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, [
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

    it('posts an Invoice ledger entry that increases the customer balance', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create(['opening_balance' => 100]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->assertStatus(201);

        // net_total is 88 (see totals test above).
        expect($customer->fresh()->current_balance)->toEqual('188.00');
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'transaction_type' => 'Invoice',
            'debit' => 88,
        ]);
    });
});

describe('store with products', function () {
    it('links a line item to the selected product', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'Cement Bag']);

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, [
            'items' => [
                ['product_id' => $product->id, 'item_name' => 'Cement Bag', 'qty' => 2, 'unit_price' => 10],
                ['item_name' => 'Custom work', 'qty' => 1, 'unit_price' => 5],
            ],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.1.product_id', null);

        $this->assertDatabaseHas('sale_items', ['product_id' => $product->id, 'item_name' => null]);
    });

    it('rejects a deleted product', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $product->delete();

        $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, [
            'items' => [['product_id' => $product->id, 'item_name' => 'Gone', 'qty' => 1, 'unit_price' => 1]],
        ]))->assertStatus(422);
    });
});

describe('update', function () {
    it('ignores a customer_id sent in the update payload', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/sales/{$sale['id']}", ['customer_id' => $otherCustomer->id])
            ->assertOk();

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'customer_id' => $customer->id]);
    });

    it('recomputes totals and keeps the linked ledger entry in sync when items change', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create(['opening_balance' => 0]);
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        expect($customer->fresh()->current_balance)->toEqual('88.00');

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/sales/{$sale['id']}", [
                'items' => [
                    ['item_name' => 'Consulting', 'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'vat' => 0],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.net_total', 100)
            ->assertJsonCount(1, 'data.items');

        // The ledger entry the sale posted moves with it, not just the sale row.
        expect($customer->fresh()->current_balance)->toEqual('100.00');
    });

    it('rejects reducing the total below what has already been paid', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        // net_total is 88; pay 50 of it.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['payment_account_id' => saleCashAccountId(), 'amount' => 50, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/sales/{$sale['id']}", [
                'items' => [
                    ['item_name' => 'Consulting', 'qty' => 1, 'unit_price' => 10, 'discount' => 0, 'vat' => 0],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['items']]);
    });
});

describe('destroy', function () {
    it('soft-deletes the sale and reverses its ledger entry', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create(['opening_balance' => 0]);
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        expect($customer->fresh()->current_balance)->toEqual('88.00');

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/sales/{$sale['id']}")
            ->assertOk();

        $this->assertSoftDeleted('sales', ['id' => $sale['id']]);
        expect($customer->fresh()->current_balance)->toEqual('0.00');
    });
});

describe('payments', function () {
    it('rejects a payment that exceeds the due amount', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        // net_total is 88.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['payment_account_id' => saleCashAccountId(), 'amount' => 200, 'payment_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['amount']]);
    });

    it('records a partial payment, reduces the due amount, and decreases the customer balance', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create(['opening_balance' => 0]);
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        // net_total is 88.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", [
                'payment_account_id' => saleCashAccountId(),
                'amount' => 30,
                'payment_date' => now()->toDateString(),
                'notes' => 'partial payment',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.paid_amount', 30)
            ->assertJsonPath('data.due_amount', 58);

        expect($customer->fresh()->current_balance)->toEqual('58.00');
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'transaction_type' => 'Payment Received',
            'credit' => 30,
        ]);
    });

    it('keeps the payments of a sale whose invoice number changes', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create(['opening_balance' => 0]);
        $sale = $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id))->json('data');
        $this->actingAs($actor, 'sanctum')->postJson("/api/sales/{$sale['id']}/payments", [
            'payment_account_id' => saleCashAccountId(),
            'amount' => 30,
            'payment_date' => now()->toDateString(),
        ])->assertStatus(201);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/sales/{$sale['id']}", ['invoice_number' => 'RENUMBERED-1'])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 30)
            ->assertJsonPath('data.due_amount', 58);
    });

    it('allows paying off the remaining due amount across multiple partial payments', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $sale = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data');

        // net_total is 88.
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['payment_account_id' => saleCashAccountId(), 'amount' => 58, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['payment_account_id' => saleCashAccountId(), 'amount' => 30, 'payment_date' => now()->toDateString()]);

        $response->assertStatus(201)
            ->assertJsonPath('data.paid_amount', 88)
            ->assertJsonPath('data.due_amount', 0);
    });
});

describe('chart of account posting', function () {
    beforeEach(function () {
        $this->seed(ChartOfAccountSeeder::class);

        $this->cash = ChartOfAccount::query()->where('account_number', '1010')->first();
        $this->receivable = ChartOfAccount::query()->where('account_number', '1030')->first();
        $this->revenue = ChartOfAccount::query()->where('account_number', '4010')->first();
    });

    it('debits Accounts Receivable and credits Sales Revenue with the net total', function () {
        $saleId = $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id))
            ->assertStatus(201)
            ->json('data.id');

        $sale = Sale::find($saleId);
        $entry = $sale->journalEntry;

        expect((float) $this->receivable->fresh()->balance)->toBe(88.0)
            ->and((float) $this->revenue->fresh()->balance)->toBe(88.0)
            ->and($entry->source->is($sale))->toBeTrue()
            ->and($entry->tr_no)->toBe($sale->invoice_number)
            ->and((float) $entry->total_amount)->toBe(88.0)
            ->and($entry->details)->toHaveCount(2)
            ->and($sale->ledgerTransaction->account_transaction_id)->toBe($entry->id);
    });

    it('posts to the debit and credit accounts chosen on the sale', function () {
        $serviceIncome = ChartOfAccount::query()->where('account_number', '4020')->first();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id, [
                'debit_account_id' => $this->cash->id,
                'credit_account_id' => $serviceIncome->id,
            ]))
            ->assertStatus(201);

        expect((float) $this->cash->fresh()->balance)->toBe(88.0)
            ->and((float) $serviceIncome->fresh()->balance)->toBe(88.0)
            ->and((float) $this->receivable->fresh()->balance)->toBe(0.0);
    });

    it('rejects a group account as the sale debit account', function () {
        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id, [
                'debit_account_id' => ChartOfAccount::query()->where('account_number', '1000')->value('id'),
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['debit_account_id']]);
    });

    it('rewrites its journal entry in place when the items change and removes it on delete', function () {
        $actor = adminUser();
        $saleId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/sales/{$saleId}", [
                'items' => [['item_name' => 'Consulting', 'qty' => 20, 'unit_price' => 5, 'discount' => 0, 'vat' => 0]],
            ])
            ->assertOk();

        expect((float) $this->receivable->fresh()->balance)->toBe(100.0)
            ->and((float) $this->revenue->fresh()->balance)->toBe(100.0)
            ->and(AccountTransaction::count())->toBe(1);

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/sales/{$saleId}")->assertOk();

        expect((float) $this->receivable->fresh()->balance)->toBe(0.0)
            ->and((float) $this->revenue->fresh()->balance)->toBe(0.0)
            ->and(AccountTransaction::count())->toBe(0);
    });

    it('deletes a sale that never posted a journal entry', function () {
        $sale = Sale::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')->deleteJson("/api/sales/{$sale->id}")->assertOk();

        expect((float) $this->receivable->fresh()->balance)->toBe(0.0);
    });

    it('debits the cash account and nets Accounts Receivable to zero once paid', function () {
        $actor = adminUser();
        $saleId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$saleId}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 88, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $payment = CustomerTransaction::query()->where('transaction_type', 'Payment Received')->sole();

        expect((float) $this->cash->fresh()->balance)->toBe(88.0)
            ->and((float) $this->receivable->fresh()->balance)->toBe(0.0)
            ->and($payment->accountTransaction->source->is($payment))->toBeTrue();

        $this->assertDatabaseHas('customer_transactions', [
            'transaction_type' => 'Payment Received',
            'payment_account_id' => $this->cash->id,
            'credit' => 88,
        ]);
    });

    it('reverses the cash posting when the payment ledger entry is reversed', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $saleId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$saleId}/payments", ['payment_account_id' => $this->cash->id, 'amount' => 30, 'payment_date' => now()->toDateString()])
            ->assertStatus(201);

        $paymentEntryId = $customer->transactions()->where('transaction_type', 'Payment Received')->value('id');

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/customers/{$customer->id}/transactions/{$paymentEntryId}")
            ->assertOk();

        expect((float) $this->cash->fresh()->balance)->toBe(0.0)
            ->and((float) $this->receivable->fresh()->balance)->toBe(88.0);
    });

    it('requires an open cash/bank payment account', function () {
        $actor = adminUser();
        $saleId = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload(Customer::factory()->create()->id))
            ->json('data.id');

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/sales/{$saleId}/payments", ['payment_account_id' => $this->revenue->id, 'amount' => 10, 'payment_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['payment_account_id']]);
    });
});

describe('units and overall discount/VAT', function () {
    it('stores line units and adds the overall discount and VAT to the totals', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $hour = InventoryUnit::factory()->create(['short_name' => 'hr']);
        $day = InventoryUnit::factory()->create(['short_name' => 'day']);

        // Lines: 90 gross, 5 discount, 3 VAT. Overall: -10 discount, +4 VAT.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, [
                'items' => [
                    ['item_name' => 'Consulting', 'qty' => 10, 'unit_id' => $hour->id, 'unit_price' => 5],
                    ['item_name' => 'Support', 'qty' => 20, 'unit_id' => $day->id, 'unit_price' => 2, 'discount' => 5, 'vat' => 3],
                ],
                'overall_discount' => 10,
                'overall_vat' => 4,
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.items.1.unit_id', $day->id)
            ->assertJsonPath('data.items.1.unit', 'day')
            ->assertJsonPath('data.overall_discount', 10)
            ->assertJsonPath('data.overall_vat', 4)
            ->assertJsonPath('data.discount_amount', 15)
            ->assertJsonPath('data.vat_amount', 7)
            ->assertJsonPath('data.net_total', 82);

        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'transaction_type' => 'Invoice',
            'debit' => 82,
        ]);
    });
});

describe('attachment', function () {
    it('stores an uploaded invoice file and returns its url', function () {
        Storage::fake('public');
        $customer = Customer::factory()->create();

        $response = $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, ['attachment' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')]))
            ->assertStatus(201);

        $sale = Sale::sole();

        Storage::disk('public')->assertExists($sale->attachment);
        $response->assertJsonPath('data.attachment_url', Storage::disk('public')->url($sale->attachment));
    });

    it('replaces the attachment through a method-spoofed multipart update and deletes the old file', function () {
        Storage::fake('public');
        $customer = Customer::factory()->create();
        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, ['attachment' => UploadedFile::fake()->create('old.pdf', 100, 'application/pdf')]))
            ->assertStatus(201);
        $sale = Sale::sole();
        $oldAttachment = $sale->attachment;

        $this->actingAs(adminUser(), 'sanctum')
            ->post("/api/sales/{$sale->id}", ['_method' => 'PUT', 'attachment' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        expect($sale->fresh()->attachment)->not->toBe($oldAttachment);
        Storage::disk('public')->assertMissing($oldAttachment);
        Storage::disk('public')->assertExists($sale->fresh()->attachment);
    });

    it('removes the attachment on request', function () {
        Storage::fake('public');
        Storage::disk('public')->put('sales/invoice.pdf', 'pdf');
        $sale = Sale::factory()->create(['attachment' => 'sales/invoice.pdf']);

        $this->actingAs(adminUser(), 'sanctum')
            ->putJson("/api/sales/{$sale->id}", ['remove_attachment' => true])
            ->assertOk()
            ->assertJsonPath('data.attachment_url', null);

        Storage::disk('public')->assertMissing('sales/invoice.pdf');
    });

    it('rejects an executable file', function () {
        $customer = Customer::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, ['attachment' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php')]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['attachment']]);
    });
});

describe('zero total', function () {
    it('rejects a sale whose total is zero', function () {
        $customer = Customer::factory()->create();

        $this->actingAs(adminUser(), 'sanctum')
            ->postJson('/api/sales', salePayload($customer->id, [
                'items' => [['item_name' => 'Consulting', 'qty' => 1, 'unit_price' => 0]],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['items']]);
    });
});

describe('previous advance adjustment', function () {
    // Net total of salePayload() is 50 + 40 - 5 + 3 = 88.
    it('stores the adjustment apart from the discount and lowers the payable and due amounts', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, [
            'previous_advance_amount' => 20,
            'previous_advance_purpose' => 'Previous Advance',
        ]));

        $response->assertCreated();
        $sale = Sale::query()->latest('id')->first();
        expect((float) $sale->discount_amount)->toBe(5.0)
            ->and((float) $sale->net_total)->toBe(88.0)
            ->and((float) $sale->previous_advance_amount)->toBe(20.0)
            ->and($sale->previous_advance_purpose)->toBe('Previous Advance')
            ->and($sale->payable_amount)->toBe(68.0)
            ->and($sale->due_amount)->toBe(68.0);

        // The customer ledger still carries the full invoice.
        expect((float) $sale->ledgerTransaction->debit)->toBe(88.0);
    });

    it('rejects an adjustment above the net total or a negative one', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();

        $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, ['previous_advance_amount' => 88.01]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['previous_advance_amount']]);
        $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, ['previous_advance_amount' => -1]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['previous_advance_amount']]);
    });

    it('defaults to no adjustment', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();

        $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id))->assertCreated();

        $sale = Sale::query()->latest('id')->first();
        expect((float) $sale->previous_advance_amount)->toBe(0.0)
            ->and($sale->payable_amount)->toBe(88.0);
    });

    it('updates and removes the adjustment', function () {
        $actor = adminUser();
        $customer = Customer::factory()->create();
        $this->actingAs($actor, 'sanctum')->postJson('/api/sales', salePayload($customer->id, ['previous_advance_amount' => 20, 'previous_advance_purpose' => 'Other']))->assertCreated();
        $sale = Sale::query()->latest('id')->first();

        $this->actingAs($actor, 'sanctum')->putJson("/api/sales/{$sale->id}", ['previous_advance_amount' => 30])->assertOk();
        expect((float) $sale->fresh()->previous_advance_amount)->toBe(30.0)
            ->and($sale->fresh()->previous_advance_purpose)->toBe('Other');

        $this->actingAs($actor, 'sanctum')->putJson("/api/sales/{$sale->id}", ['previous_advance_amount' => 0])->assertOk();
        expect((float) $sale->fresh()->previous_advance_amount)->toBe(0.0)
            ->and($sale->fresh()->previous_advance_purpose)->toBeNull();
    });
});
