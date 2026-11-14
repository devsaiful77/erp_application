<template>
    <h5 class="mb-3">{{ $t('Items') }}</h5>
    <div v-if="errors.items" class="error-msg mb-2">{{ errors.items }}</div>

    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead>
                <tr>
                    <th style="width: 200px;">{{ $t('Product') }}</th>
                    <th>{{ $t('Item Name') }}</th>
                    <th>{{ $t('Description') }}</th>
                    <th style="width: 90px;">{{ $t('Qty') }}</th>
                    <th style="width: 110px;">{{ $t('Unit') }}</th>
                    <th style="width: 120px;">{{ $t('Unit Price') }}</th>
                    <th style="width: 110px;">{{ $t('Discount') }}</th>
                    <th style="width: 110px;">{{ $t('VAT') }}</th>
                    <th style="width: 120px;">{{ $t('Line Total') }}</th>
                    <th style="width: 50px;"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(item, index) in items" :key="index">
                    <td>
                        <select class="form-control" v-model="item.product_id" @change="onProductChange(item)">
                            <option value="">{{ $t('Custom item') }}</option>
                            <option v-for="product in products" :key="product.id" :value="product.id">
                                {{ product.code }} - {{ product.name }}
                            </option>
                        </select>
                    </td>
                    <td><input type="text" class="form-control" v-model="item.item_name" required /></td>
                    <td><input type="text" class="form-control" v-model="item.description" /></td>
                    <td><input type="number" min="0.01" step="0.01" class="form-control" v-model.number="item.qty" required /></td>
                    <td>
                        <select class="form-control" v-model="item.unit_id">
                            <option value="">{{ $t('Select Unit') }}</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.short_name }}</option>
                        </select>
                        <div v-if="errors[`items.${index}.unit_id`]" class="error-msg">{{ errors[`items.${index}.unit_id`] }}</div>
                    </td>
                    <td><input type="number" min="0" step="0.01" class="form-control" v-model.number="item.unit_price" required /></td>
                    <td><input type="number" min="0" step="0.01" class="form-control" v-model.number="item.discount" /></td>
                    <td><input type="number" min="0" step="0.01" class="form-control" v-model.number="item.vat" /></td>
                    <td class="text-end">{{ lineTotal(item).toFixed(2) }}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger"
                            :disabled="items.length === 1" @click="removeItem(index)">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <v-btn type="button" class="text-none mb-3" color="grey-lighten-3" rounded="0" variant="flat" @click="addItem">
        {{ $t('+ Add Item') }}
    </v-btn>

    <div class="row justify-content-end">
        <div class="col-md-5">
            <table class="table table-sm align-middle">
                <tbody>
                    <tr>
                        <td>{{ $t('Total Amount') }}</td>
                        <td class="text-end">{{ totals.totalAmount.toFixed(2) }}</td>
                    </tr>
                    <tr>
                        <td>{{ $t('Line Discount') }}</td>
                        <td class="text-end">{{ totals.lineDiscount.toFixed(2) }}</td>
                    </tr>
                    <tr>
                        <td>
                            {{ $t('Overall Discount') }}
                            <div v-if="errors.overall_discount" class="error-msg">{{ errors.overall_discount }}</div>
                        </td>
                        <td class="text-end" style="width: 150px;">
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end"
                                v-model.number="overallDiscount" />
                        </td>
                    </tr>
                    <tr>
                        <td>{{ $t('Line VAT') }}</td>
                        <td class="text-end">{{ totals.lineVat.toFixed(2) }}</td>
                    </tr>
                    <tr>
                        <td>
                            {{ $t('Overall VAT') }}
                            <div v-if="errors.overall_vat" class="error-msg">{{ errors.overall_vat }}</div>
                        </td>
                        <td class="text-end">
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end"
                                v-model.number="overallVat" />
                        </td>
                    </tr>
                    <tr class="fw-bold">
                        <td>{{ $t('Net Total') }}</td>
                        <td class="text-end">{{ totals.netTotal.toFixed(2) }}</td>
                    </tr>
                    <slot name="totals" :net-total="totals.netTotal" />
                </tbody>
            </table>
            <small class="text-muted">{{ note || $t('Totals are recalculated by the server on save.') }}</small>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'

/**
 * The line items table and invoice totals shared by the sale and purchase
 * forms: product, unit, qty, price, per-line discount/VAT, plus an overall
 * (invoice-level) discount and VAT. The server recomputes every total.
 */
const items = defineModel('items', { type: Array, required: true })
const overallDiscount = defineModel('overallDiscount', { type: [Number, String], default: 0 })
const overallVat = defineModel('overallVat', { type: [Number, String], default: 0 })

const props = defineProps({
    products: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    note: { type: String, default: '' },
})

const units = ref([])

// Picking a product fills in its name, which is stored on the line as a snapshot.
const onProductChange = (item) => {
    const product = props.products.find((p) => p.id === item.product_id)
    if (product) item.item_name = product.name
}

const addItem = () => items.value.push(emptyInvoiceItem())
const removeItem = (index) => {
    if (items.value.length > 1) items.value.splice(index, 1)
}

const lineTotal = (item) =>
    (Number(item.qty) || 0) * (Number(item.unit_price) || 0) - (Number(item.discount) || 0) + (Number(item.vat) || 0)

const totals = computed(() => {
    let totalAmount = 0
    let lineDiscount = 0
    let lineVat = 0

    items.value.forEach((item) => {
        totalAmount += (Number(item.qty) || 0) * (Number(item.unit_price) || 0)
        lineDiscount += Number(item.discount) || 0
        lineVat += Number(item.vat) || 0
    })

    const discountAmount = lineDiscount + (Number(overallDiscount.value) || 0)
    const vatAmount = lineVat + (Number(overallVat.value) || 0)

    return {
        totalAmount,
        lineDiscount,
        lineVat,
        netTotal: totalAmount - discountAmount + vatAmount,
    }
})

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/asset/units/options')
        if (data.success) units.value = data.data
    } catch {
        // Without the list the unit is simply left unselected.
    }
})
</script>

<script>
/** A blank line for the form. */
export const emptyInvoiceItem = () => ({ product_id: '', item_name: '', description: '', qty: 1, unit_id: '', unit_price: 0, discount: 0, vat: 0 })

/** An API line item mapped back onto the form's fields. */
export const toInvoiceFormItem = (item) => ({
    product_id: item.product_id ?? '',
    item_name: item.item_name,
    description: item.description,
    qty: item.qty,
    unit_id: item.unit_id ?? '',
    unit_price: item.unit_price,
    discount: item.discount,
    vat: item.vat,
})
</script>
