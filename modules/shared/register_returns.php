<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
$apiUrl = getRelativeUrl('api/register_returns.php');
?>

<style>
.autocomplete-dropdown-ret {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    background: white !important;
    border: 1px solid #dee2e6 !important;
    border-radius: 0 0 0.375rem 0.375rem !important;
    z-index: 1000 !important;
    max-height: 200px !important;
    overflow-y: auto !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

.autocomplete-item-ret {
    padding: 0.5rem 0.75rem !important;
    border-bottom: 1px solid #e9ecef !important;
    cursor: pointer !important;
    transition: background-color 0.15s ease-in-out !important;
}

.autocomplete-item-ret:hover,
.autocomplete-item-ret.highlighted {
    background-color: #f8f9fa !important;
}

.autocomplete-item-ret:last-child {
    border-bottom: none !important;
}
</style>

<div class="page-header mb-4">
    <h2><i class="bi bi-arrow-return-left me-2"></i>تسجيل المرتجعات</h2>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0">نموذج تسجيل المرتجعات</h5></div>
    <div class="card-body">
        <form id="registerReturnsForm">
            <!-- Customer Selection -->
            <div class="border rounded p-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">نوع العميل</label>
                        <div class="d-flex gap-3 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="custTypeLocal" value="local" checked>
                                <label class="form-check-label" for="custTypeLocal">عملاء محليين</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="custTypeDelegate" value="delegate" checked>
                                <label class="form-check-label" for="custTypeDelegate">عملاء مندوبين</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">العميل *</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearchInput" placeholder="ابحث عن العميل بالاسم أو رقم الهاتف..." autocomplete="off">
                            <div class="autocomplete-dropdown-ret" id="customerDropdown" style="display:none;"></div>
                            <input type="hidden" id="selectedCustomerId" value="">
                            <input type="hidden" id="selectedCustomerType" value="">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div id="customerInfoBox" class="alert alert-info py-2 mb-0 d-none">
                            <small id="customerInfoText"></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Item Rows -->
            <div id="returnRows"></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" id="addReturnRowBtn"><i class="bi bi-plus-circle me-1"></i>إضافة صف</button>
                <button type="submit" class="btn btn-success" id="submitReturnBtn"><i class="bi bi-check2-circle me-1"></i>حفظ فاتورة المرتجعات</button>
                <div class="ms-auto">
                    <strong>الإجمالي: <span id="grandTotalDisplay">0.00</span> ج.م</strong>
                </div>
            </div>
        </form>
        <div id="returnAlert" class="alert mt-3 d-none"></div>
    </div>
</div>

<!-- Returns List -->
<div class="card shadow-sm">
    <div class="card-header">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label mb-1">من تاريخ</label><input type="date" id="retFilterDateFrom" class="form-control"></div>
            <div class="col-md-3"><label class="form-label mb-1">إلى تاريخ</label><input type="date" id="retFilterDateTo" class="form-control"></div>
            <div class="col-md-4"><label class="form-label mb-1">بحث برقم الفاتورة</label><input type="text" id="retFilterSearch" class="form-control"></div>
            <div class="col-md-2 d-grid"><button type="button" class="btn btn-primary" id="retApplyFilterBtn">تصفية</button></div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th><th>الإجمالي</th><th>عدد الأصناف</th><th>المستخدم</th><th>إجراءات</th></tr></thead>
                <tbody id="returnsTableBody"><tr><td colspan="7" class="text-center text-muted">جاري التحميل...</td></tr></tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <small class="text-muted" id="returnsPaginationInfo"></small>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="retPrevPageBtn">السابق</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="retNextPageBtn">التالي</button>
            </div>
        </div>
    </div>
</div>

<div id="returnReceiptContainer" style="display: none;">
    <div id="returnReceiptBody"></div>
</div>

<script>
(function () {
    const apiUrl = <?php echo json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const departmentOptions = [
        { value: 'raw_materials', label: 'خامات', qtyLabel: 'الكمية / الوزن' },
        { value: 'packaging', label: 'أدوات تعبئة', qtyLabel: 'الكمية' },
        { value: 'external_products', label: 'منتجات خارجية', qtyLabel: 'الكمية' }
    ];

    const returnRows = document.getElementById('returnRows');
    const addReturnRowBtn = document.getElementById('addReturnRowBtn');
    const returnForm = document.getElementById('registerReturnsForm');
    const returnAlert = document.getElementById('returnAlert');
    const submitReturnBtn = document.getElementById('submitReturnBtn');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');
    const tableBody = document.getElementById('returnsTableBody');
    const receiptBody = document.getElementById('returnReceiptBody');
    const paginationInfo = document.getElementById('returnsPaginationInfo');
    const prevPageBtn = document.getElementById('retPrevPageBtn');
    const nextPageBtn = document.getElementById('retNextPageBtn');
    const filterDateFrom = document.getElementById('retFilterDateFrom');
    const filterDateTo = document.getElementById('retFilterDateTo');
    const filterSearch = document.getElementById('retFilterSearch');
    const applyFilterBtn = document.getElementById('retApplyFilterBtn');

    const customerSearchInput = document.getElementById('customerSearchInput');
    const customerDropdown = document.getElementById('customerDropdown');
    const selectedCustomerId = document.getElementById('selectedCustomerId');
    const selectedCustomerType = document.getElementById('selectedCustomerType');
    const customerInfoBox = document.getElementById('customerInfoBox');
    const customerInfoText = document.getElementById('customerInfoText');
    const custTypeLocal = document.getElementById('custTypeLocal');
    const custTypeDelegate = document.getElementById('custTypeDelegate');

    let listPage = 1;
    let totalPages = 1;
    let customerSearchTimer = null;

    function showAlert(type, message) {
        returnAlert.className = 'alert mt-3 alert-' + type;
        returnAlert.textContent = message;
        returnAlert.classList.remove('d-none');
    }

    function clearNode(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function createOption(value, label) {
        const o = document.createElement('option');
        o.value = value;
        o.textContent = label;
        return o;
    }

    function updateGrandTotal() {
        let total = 0;
        returnRows.querySelectorAll('.return-row-total').forEach(el => {
            total += parseFloat(el.textContent || '0');
        });
        grandTotalDisplay.textContent = total.toFixed(2);
    }

    // ─── Customer Search ───
    function getCustomerFilterType() {
        const local = custTypeLocal.checked;
        const delegate = custTypeDelegate.checked;
        if (local && delegate) return 'all';
        if (local) return 'local';
        if (delegate) return 'delegate';
        return 'all';
    }

    async function searchCustomers(query) {
        const type = getCustomerFilterType();
        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('action', 'get_customers');
        url.searchParams.set('type', type);
        if (query) url.searchParams.set('search', query);
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        return res.json();
    }

    function showCustomerDropdown(customers) {
        clearNode(customerDropdown);
        if (!customers.length) {
            customerDropdown.style.display = 'none';
            return;
        }

        customers.forEach((c, idx) => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item-ret';
            const typeLabel = c.type === 'local' ? 'محلي' : 'مندوب';
            const balanceLabel = c.balance > 0 ? 'مدين: ' + c.balance.toFixed(2) : (c.balance < 0 ? 'دائن: ' + Math.abs(c.balance).toFixed(2) : 'لا رصيد');
            div.textContent = c.name + ' (' + typeLabel + ') - ' + balanceLabel;

            div.addEventListener('click', function() { selectCustomer(c); });
            div.addEventListener('mouseenter', function() {
                const prev = customerDropdown.querySelector('.highlighted');
                if (prev) prev.classList.remove('highlighted');
                customerDropdown._selectedIdx = idx;
                div.classList.add('highlighted');
            });

            customerDropdown.appendChild(div);
        });
        customerDropdown.style.display = 'block';
        customerDropdown._items = customers;
        customerDropdown._selectedIdx = -1;
    }

    function selectCustomer(c) {
        selectedCustomerId.value = c.id;
        selectedCustomerType.value = c.type;
        customerSearchInput.value = c.name;
        customerDropdown.style.display = 'none';

        const typeLabel = c.type === 'local' ? 'محلي' : 'مندوب';
        const balanceLabel = c.balance > 0 ? 'مدين بمبلغ ' + c.balance.toFixed(2) + ' ج.م' : (c.balance < 0 ? 'دائن بمبلغ ' + Math.abs(c.balance).toFixed(2) + ' ج.م' : 'الرصيد: صفر');
        customerInfoText.innerHTML = '<strong>' + c.name + '</strong> (' + typeLabel + ') | ' + balanceLabel + (c.phone ? ' | هاتف: ' + c.phone : '');
        customerInfoBox.classList.remove('d-none');
    }

    customerSearchInput.addEventListener('input', function () {
        const val = this.value.trim();
        selectedCustomerId.value = '';
        selectedCustomerType.value = '';
        customerInfoBox.classList.add('d-none');

        clearTimeout(customerSearchTimer);
        if (val.length < 1) {
            customerDropdown.style.display = 'none';
            return;
        }
        customerSearchTimer = setTimeout(async function() {
            try {
                const data = await searchCustomers(val);
                if (data.success) showCustomerDropdown(data.customers || []);
            } catch (e) { /* ignore */ }
        }, 300);
    });

    customerSearchInput.addEventListener('keydown', function (e) {
        if (customerDropdown.style.display === 'none') return;
        const items = customerDropdown.children;
        const custs = customerDropdown._items || [];
        let idx = customerDropdown._selectedIdx ?? -1;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (idx >= 0 && items[idx]) items[idx].classList.remove('highlighted');
            idx = Math.min(idx + 1, items.length - 1);
            if (items[idx]) { items[idx].classList.add('highlighted'); items[idx].scrollIntoView({ block: 'nearest' }); }
            customerDropdown._selectedIdx = idx;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (idx >= 0 && items[idx]) items[idx].classList.remove('highlighted');
            idx = Math.max(idx - 1, 0);
            if (items[idx]) { items[idx].classList.add('highlighted'); items[idx].scrollIntoView({ block: 'nearest' }); }
            customerDropdown._selectedIdx = idx;
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (idx >= 0 && idx < custs.length) selectCustomer(custs[idx]);
        } else if (e.key === 'Escape') {
            customerDropdown.style.display = 'none';
        }
    });

    customerSearchInput.addEventListener('focus', function () {
        if (this.value.trim().length >= 1 && customerDropdown.children.length) {
            customerDropdown.style.display = 'block';
        }
    });

    document.addEventListener('click', function (e) {
        if (!customerSearchInput.contains(e.target) && !customerDropdown.contains(e.target)) {
            customerDropdown.style.display = 'none';
        }
    });

    [custTypeLocal, custTypeDelegate].forEach(function(cb) {
        cb.addEventListener('change', function () {
            if (customerSearchInput.value.trim()) {
                customerSearchInput.dispatchEvent(new Event('input'));
            }
        });
    });

    // ─── Item Rows ───
    async function getItems(department) {
        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('action', 'get_items');
        url.searchParams.set('department', department);
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        return res.json();
    }

    function createRow() {
        const wrapper = document.createElement('div');
        wrapper.className = 'border rounded p-3 mb-3';
        wrapper.style.position = 'relative';

        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end';

        // Department
        const c1 = document.createElement('div');
        c1.className = 'col-md-2';
        const l1 = document.createElement('label');
        l1.className = 'form-label';
        l1.textContent = 'القسم *';
        const department = document.createElement('select');
        department.className = 'form-select';
        department.appendChild(createOption('', 'اختر القسم'));
        departmentOptions.forEach(function(d) { department.appendChild(createOption(d.value, d.label)); });
        c1.appendChild(l1);
        c1.appendChild(department);

        // Item
        const c2 = document.createElement('div');
        c2.className = 'col-md-3';
        const l2 = document.createElement('label');
        l2.className = 'form-label';
        l2.textContent = 'الصنف *';
        const itemContainer = document.createElement('div');
        itemContainer.className = 'position-relative';
        const itemInput = document.createElement('input');
        itemInput.type = 'text';
        itemInput.className = 'form-control';
        itemInput.placeholder = 'اكتب اسم الصنف';
        itemInput.disabled = true;
        const item = document.createElement('select');
        item.className = 'd-none';
        item.disabled = true;
        item.appendChild(createOption('', 'اختر القسم أولاً'));
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown-ret';
        dropdown.style.display = 'none';
        itemContainer.appendChild(itemInput);
        itemContainer.appendChild(dropdown);
        itemContainer.appendChild(item);
        c2.appendChild(l2);
        c2.appendChild(itemContainer);

        // Quantity
        const c3 = document.createElement('div');
        c3.className = 'col-md-2';
        const l3 = document.createElement('label');
        l3.className = 'form-label';
        l3.textContent = 'الكمية *';
        const qtyContainer = document.createElement('div');
        qtyContainer.className = 'input-group';
        const qty = document.createElement('input');
        qty.type = 'number';
        qty.className = 'form-control';
        qty.min = '0.001';
        qty.step = 'any';
        const calcBtn = document.createElement('button');
        calcBtn.type = 'button';
        calcBtn.className = 'btn btn-outline-secondary';
        calcBtn.innerHTML = '<i class="bi bi-calculator"></i>';
        calcBtn.title = 'آلة حاسبة';
        qtyContainer.appendChild(qty);
        qtyContainer.appendChild(calcBtn);
        c3.appendChild(l3);
        c3.appendChild(qtyContainer);

        // Unit Price
        const c4 = document.createElement('div');
        c4.className = 'col-md-2';
        const l4 = document.createElement('label');
        l4.className = 'form-label';
        l4.textContent = 'سعر الوحدة';
        const unitPrice = document.createElement('input');
        unitPrice.type = 'number';
        unitPrice.className = 'form-control';
        unitPrice.min = '0';
        unitPrice.step = 'any';
        unitPrice.placeholder = '0.00';
        c4.appendChild(l4);
        c4.appendChild(unitPrice);

        // Total Price
        const c5 = document.createElement('div');
        c5.className = 'col-md-2';
        const l5 = document.createElement('label');
        l5.className = 'form-label';
        l5.textContent = 'الإجمالي';
        const totalDisplay = document.createElement('div');
        totalDisplay.className = 'form-control bg-light return-row-total';
        totalDisplay.textContent = '0.00';
        totalDisplay.style.cursor = 'default';
        c5.appendChild(l5);
        c5.appendChild(totalDisplay);

        // Remove
        const c6 = document.createElement('div');
        c6.className = 'col-md-1 d-grid';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger';
        removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
        c6.appendChild(removeBtn);

        row.appendChild(c1);
        row.appendChild(c2);
        row.appendChild(c3);
        row.appendChild(c4);
        row.appendChild(c5);
        row.appendChild(c6);
        wrapper.appendChild(row);
        returnRows.appendChild(wrapper);

        // Auto-calculate total
        function recalcTotal() {
            const q = parseFloat(qty.value || '0');
            const p = parseFloat(unitPrice.value || '0');
            totalDisplay.textContent = (q * p).toFixed(2);
            updateGrandTotal();
        }
        qty.addEventListener('input', recalcTotal);
        unitPrice.addEventListener('input', recalcTotal);

        // Department change
        department.addEventListener('change', async function () {
            const dep = this.value;
            const depMeta = departmentOptions.find(function(d) { return d.value === dep; });
            l3.textContent = (depMeta ? depMeta.qtyLabel : 'الكمية') + ' *';
            clearNode(item);
            clearNode(dropdown);

            if (!dep) {
                item.appendChild(createOption('', 'اختر القسم أولاً'));
                itemInput.disabled = true;
                itemInput.value = '';
                dropdown.style.display = 'none';
                return;
            }

            itemInput.disabled = false;
            itemInput.placeholder = 'اكتب اسم الصنف أو اختر من القائمة';
            itemInput.value = '';
            item.appendChild(createOption('', 'اختر الصنف'));

            try {
                const data = await getItems(dep);
                const fetchedItems = data && data.success && Array.isArray(data.items) ? data.items : [];
                fetchedItems.forEach(function(x) {
                    const o = createOption(String(x.id), x.name + ' (' + (x.current_quantity || 0) + ' ' + (x.unit || '') + ')');
                    o.dataset.table = x.table || '';
                    o.dataset.field = x.quantity_field || '';
                    o.dataset.name = x.name || '';
                    o.dataset.unit = x.unit || '';
                    o.dataset.searchText = x.name.toLowerCase();
                    item.appendChild(o);
                });
                setupItemAutocomplete(itemInput, dropdown, item, fetchedItems);
            } catch (e) {
                itemInput.disabled = true;
                dropdown.style.display = 'none';
            }
        });

        removeBtn.addEventListener('click', function () {
            wrapper.remove();
            updateRemoveState();
            updateGrandTotal();
        });

        calcBtn.addEventListener('click', function () {
            createCalculator(qty);
        });

        updateRemoveState();
    }

    function updateRemoveState() {
        const rows = returnRows.querySelectorAll('.border.rounded');
        rows.forEach(function(r) {
            const b = r.querySelector('.btn-outline-danger');
            b.disabled = rows.length <= 1;
        });
    }

    function setupItemAutocomplete(input, dropdown, select, items) {
        let selectedIndex = -1;
        let filteredItems = [];

        input.addEventListener('input', function () {
            const value = this.value.toLowerCase().trim();
            clearNode(dropdown);
            selectedIndex = -1;

            if (value.length === 0) {
                dropdown.style.display = 'none';
                select.value = '';
                return;
            }

            filteredItems = items.filter(function(item) { return item.name.toLowerCase().includes(value); });
            if (filteredItems.length === 0) {
                dropdown.style.display = 'none';
                return;
            }

            filteredItems.forEach(function(fItem, index) {
                const div = document.createElement('div');
                div.className = 'autocomplete-item-ret';
                div.textContent = fItem.name + ' (' + (fItem.current_quantity || 0) + ' ' + (fItem.unit || '') + ')';
                div.addEventListener('click', function () {
                    input.value = fItem.name;
                    select.value = fItem.id;
                    dropdown.style.display = 'none';
                    selectedIndex = -1;
                });
                div.addEventListener('mouseenter', function () {
                    if (selectedIndex >= 0 && dropdown.children[selectedIndex]) dropdown.children[selectedIndex].classList.remove('highlighted');
                    selectedIndex = index;
                    div.classList.add('highlighted');
                });
                dropdown.appendChild(div);
            });
            dropdown.style.display = 'block';
        });

        input.addEventListener('keydown', function (e) {
            if (dropdown.style.display === 'none') return;
            const els = dropdown.children;
            if (els.length === 0) return;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (selectedIndex < els.length - 1) {
                        if (selectedIndex >= 0) els[selectedIndex].classList.remove('highlighted');
                        selectedIndex++;
                        els[selectedIndex].classList.add('highlighted');
                        els[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (selectedIndex > 0) {
                        els[selectedIndex].classList.remove('highlighted');
                        selectedIndex--;
                        els[selectedIndex].classList.add('highlighted');
                        els[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (selectedIndex >= 0 && selectedIndex < filteredItems.length) {
                        input.value = filteredItems[selectedIndex].name;
                        select.value = filteredItems[selectedIndex].id;
                        dropdown.style.display = 'none';
                        selectedIndex = -1;
                    }
                    break;
                case 'Escape':
                    dropdown.style.display = 'none';
                    selectedIndex = -1;
                    break;
            }
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
                selectedIndex = -1;
            }
        });
    }

    function createCalculator(targetInput) {
        const calcModal = document.createElement('div');
        calcModal.className = 'modal fade';
        calcModal.id = 'calculatorModal_' + Date.now();
        calcModal.setAttribute('tabindex', '-1');
        calcModal.innerHTML = '<div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title">آلة حاسبة</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-3"><div class="mb-3"><input type="text" id="calcDisplay" class="form-control text-end" readonly style="font-size: 1.2rem; font-family: monospace;"></div><div class="row g-2"><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="7">7</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="8">8</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="9">9</button></div><div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="/">÷</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="4">4</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="5">5</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="6">6</button></div><div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="*">×</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="1">1</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="2">2</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="3">3</button></div><div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="-">-</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="0">0</button></div><div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value=".">.</button></div><div class="col-3"><button class="btn btn-danger w-100 calc-btn" data-op="clear">C</button></div><div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="+">+</button></div></div><div class="row g-2 mt-2"><div class="col-6"><button class="btn btn-success w-100" id="calcEquals">=</button></div><div class="col-6"><button class="btn btn-primary w-100" id="calcInsert">إضافة للكمية</button></div></div></div></div></div>';
        document.body.appendChild(calcModal);
        const display = calcModal.querySelector('#calcDisplay');
        let currentExpression = '';
        function updateDisplay() { display.value = currentExpression || '0'; }
        function calculate() {
            if (!currentExpression) return 0;
            try {
                const expr = currentExpression.replace(/×/g, '*').replace(/÷/g, '/');
                return parseFloat(Function('"use strict"; return (' + expr + ')')().toFixed(6));
            } catch (e) { return 0; }
        }
        calcModal.querySelectorAll('.calc-btn').forEach(function(btn) {
            btn.addEventListener('click', function () {
                const value = this.dataset.value;
                const op = this.dataset.op;
                if (value !== undefined) currentExpression += value;
                else if (op === 'clear') currentExpression = '';
                else if (op) currentExpression += ' ' + op + ' ';
                updateDisplay();
            });
        });
        calcModal.querySelector('#calcEquals').addEventListener('click', function () {
            currentExpression = calculate().toString();
            updateDisplay();
        });
        calcModal.querySelector('#calcInsert').addEventListener('click', function () {
            targetInput.value = currentExpression ? calculate() : 0;
            targetInput.dispatchEvent(new Event('input'));
            var modalInst = bootstrap.Modal.getInstance(calcModal);
            if (modalInst) modalInst.hide();
        });
        var modal = new bootstrap.Modal(calcModal);
        modal.show();
        calcModal.addEventListener('hidden.bs.modal', function () { document.body.removeChild(calcModal); });
        updateDisplay();
    }

    function collectRows() {
        return Array.from(returnRows.querySelectorAll('.border.rounded')).map(function(r) {
            var s = r.querySelectorAll('select');
            var inputs = r.querySelectorAll('input[type="number"]');
            var itemInput = r.querySelector('input[type="text"]');
            var dep = s[0].value;
            var itemSel = s[1];
            var itemOpt = itemSel.options[itemSel.selectedIndex];
            return {
                department: dep,
                item_id: parseInt(itemSel.value || '0', 10),
                table: itemOpt ? (itemOpt.dataset.table || '') : '',
                quantity_field: itemOpt ? (itemOpt.dataset.field || '') : '',
                item_name: itemOpt ? (itemOpt.dataset.name || '') : (itemInput ? itemInput.value : ''),
                unit: itemOpt ? (itemOpt.dataset.unit || '') : '',
                quantity: parseFloat(inputs[0] ? inputs[0].value : '0'),
                unit_price: parseFloat(inputs[1] ? inputs[1].value : '0')
            };
        });
    }

    function validateRows(rows) {
        if (!rows.length) return 'يرجى إضافة صف واحد على الأقل';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (!r.department) return 'يرجى اختيار القسم';
            if (!r.item_id || !r.table || !r.quantity_field) return 'يرجى اختيار صنف من القائمة';
            if (!(r.quantity > 0)) return 'الكمية يجب أن تكون أكبر من صفر';
        }
        return '';
    }

    function appendCell(tr, text) {
        var td = document.createElement('td');
        td.textContent = text;
        tr.appendChild(td);
    }

    // ─── Receipt ───
    function buildReceiptHTML(inv) {
        var createdAt = inv.created_at || '-';
        var dateStr = '-', timeStr = '-';
        if (createdAt !== '-') {
            var date = new Date(createdAt);
            dateStr = date.toLocaleDateString('ar-SA', { year: 'numeric', month: 'long', day: 'numeric' });
            timeStr = date.toLocaleTimeString('ar-SA', { hour: 'numeric', minute: '2-digit', hour12: true });
        }

        var itemsHTML = '';
        var items = inv.items || [];
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            itemsHTML += '<tr><td>' + (it.item_name || '-') + '</td><td>' + parseFloat(it.added_quantity || it.quantity || 0) + '</td><td>' + parseFloat(it.unit_price || 0).toFixed(2) + ' ج.م</td><td>' + parseFloat(it.total_price || 0).toFixed(2) + ' ج.م</td></tr>';
        }
        itemsHTML += '<tr style="font-weight:700"><td colspan="3" style="text-align:center">الإجمالي النهائي</td><td>' + parseFloat(inv.grand_total || 0).toFixed(2) + ' ج.م</td></tr>';

        return '<div id="returnReceiptPrintable"><h4 style="text-align:center;margin:20px 0">فاتورة مرتجعات رقم - ' + (inv.invoice_number || '-') + '</h4>'
            + '<p>التاريخ: ' + dateStr + '</p>'
            + '<p>الوقت: ' + timeStr + '</p>'
            + '<p>المستخدم: ' + (inv.created_by_name || inv.created_by_username || inv.created_by || '-') + '</p>'
            + '<p>العميل: ' + (inv.customer_name || '-') + '</p>'
            + '<table class="table table-bordered" style="border:2px solid #000;font-weight:600">'
            + '<thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>'
            + '<tbody>' + itemsHTML + '</tbody></table></div>';
    }

    function renderReturnReceipt(inv) {
        receiptBody.innerHTML = buildReceiptHTML(inv);

        var w = window.open('', '_blank');
        if (w) {
            var htmlContent = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>فاتورة مرتجعات</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body { font-family: "Tajawal", "Cairo", sans-serif; } .table { margin: 20px 0; } h4 { text-align: center; margin: 20px 0; } p { margin: 10px 0; }</style></head><body><div class="container">' + receiptBody.innerHTML + '</div></body></html>';
            w.document.open();
            w.document.writeln(htmlContent);
            w.document.close();
        }
    }

    function printReturnReceipt(existingWindow) {
        var src = document.getElementById('returnReceiptPrintable');
        if (!src) return;

        var w = existingWindow || window.open('', '_blank');
        if (!w) return;

        var printCSS = '@page { size: 80mm auto; margin: 3mm; }'
            + '* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-sizing: border-box !important; }'
            + 'html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; width: 100% !important; font-family: "Tajawal", "Cairo", "Arial", sans-serif !important; font-size: 13px !important; line-height: 1.3 !important; color: #000 !important; direction: rtl !important; text-align: right !important; font-weight: 500 !important; }'
            + 'body * { max-width: 100% !important; box-sizing: border-box !important; }'
            + '.receipt-80mm { width: 100% !important; margin: 0 !important; padding: 2mm !important; border: 2px solid #000 !important; direction: rtl !important; text-align: right !important; }'
            + '.receipt-header-80mm { text-align: center !important; padding: 1mm 0.5mm !important; border-bottom: 2px solid #000 !important; }'
            + '.receipt-title { font-size: 14px !important; font-weight: 700 !important; margin-bottom: 4px !important; }'
            + '.receipt-divider { border-top: 1px solid #000 !important; margin: 1px 0 !important; }'
            + '.receipt-info { padding: 0.5mm 0.5mm !important; }'
            + '.info-row { display: flex !important; justify-content: space-between !important; margin-bottom: 1px !important; font-size: 14px !important; direction: rtl !important; }'
            + '.info-row .label { font-weight: 900 !important; margin-left: 1px !important; white-space: nowrap !important; font-size: 14px !important; }'
            + '.info-row .value { text-align: right !important; flex: 1 !important; font-weight: 900 !important; font-size: 14px !important; }'
            + '.items-table { width: 100% !important; border-collapse: collapse !important; font-size: 13px !important; table-layout: fixed !important; }'
            + '.items-table thead { background: #f0f0f0 !important; border-bottom: 2px solid #000 !important; }'
            + '.items-table th { padding: 0.5mm 0.3mm !important; text-align: center !important; font-weight: 700 !important; font-size: 12px !important; border-right: 1px solid #000 !important; }'
            + '.items-table th:first-child { border-right: none !important; text-align: right !important; }'
            + '.items-table td { padding: 0.5mm 0.3mm !important; text-align: center !important; border-bottom: 1px solid #000 !important; border-right: 1px solid #000 !important; font-size: 12px !important; font-weight: 500 !important; }'
            + '.items-table td:first-child { border-right: none !important; text-align: right !important; }'
            + '.receipt-footer { text-align: center !important; padding: 0.5mm !important; border-top: 1px solid #000 !important; margin-top: 1mm !important; }';

        var originalMeta = src.querySelectorAll('p');
        var originalTable = src.querySelector('table');
        var originalTitle = src.querySelector('h4');

        var receiptHTML = '<div class="receipt-80mm">';
        receiptHTML += '<div class="receipt-header-80mm"><div class="receipt-title">' + (originalTitle ? originalTitle.textContent : 'فاتورة مرتجعات') + '</div></div>';
        receiptHTML += '<div class="receipt-info">';
        if (originalMeta.length >= 2) {
            receiptHTML += '<div class="info-row"><span class="label">التاريخ:</span><span class="value" style="margin-left:10px;">' + originalMeta[0].textContent.replace('التاريخ: ', '') + '</span><span class="label" style="margin-right:15px;">الوقت:</span><span class="value">' + originalMeta[1].textContent.replace('الوقت: ', '') + '</span></div>';
        }
        if (originalMeta.length >= 4) {
            receiptHTML += '<div class="info-row"><span class="label">العميل:</span><span class="value">' + originalMeta[3].textContent.replace('العميل: ', '') + '</span></div>';
        }
        receiptHTML += '</div><div class="receipt-divider"></div>';

        if (originalTable) {
            var clonedTable = originalTable.cloneNode(true);
            clonedTable.className = 'items-table';
            clonedTable.querySelectorAll('th').forEach(function(th) { th.style.fontSize = '14px'; th.style.fontWeight = '900'; });
            clonedTable.querySelectorAll('td').forEach(function(td) { td.style.fontSize = '14px'; td.style.padding = '4px 8px'; });
            receiptHTML += clonedTable.outerHTML;
        }

        receiptHTML += '<div class="receipt-footer"><div style="display:flex;justify-content:space-between;width:100%;direction:rtl;"><div style="flex:1;text-align:center;border-top:1px solid #000;padding-top:4px;margin-left:5px;"><div style="font-size:14px;font-weight:600;">توقيع أمين المخزن</div><div style="height:25px;"></div></div><div style="flex:1;text-align:center;border-top:1px solid #000;padding-top:4px;margin-right:5px;"><div style="height:25px;"></div></div></div></div>';
        receiptHTML += '</div>';

        var fullHTML = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8"><title>فاتورة مرتجعات</title><style>' + printCSS + '</style></head><body>' + receiptHTML + '</body></html>';
        w.document.open();
        w.document.writeln(fullHTML);
        w.document.close();
        w.focus();
        w.print();
    }

    async function loadReturnDetails(id, printAfter) {
        var printWindow = null;
        if (printAfter) printWindow = window.open('', '_blank');

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('action', 'get_return_details');
        url.searchParams.set('id', String(id));
        var res = await fetch(url.toString(), { credentials: 'same-origin' });
        var data = await res.json();
        if (!data.success) {
            showAlert('danger', data.message || 'تعذر تحميل الفاتورة');
            if (printWindow) printWindow.close();
            return;
        }
        var inv = data.return_invoice;
        receiptBody.innerHTML = buildReceiptHTML(inv);
        if (printAfter) {
            setTimeout(function() { printReturnReceipt(printWindow); }, 150);
        } else {
            renderReturnReceipt(inv);
        }
    }

    // ─── Load Returns List ───
    async function loadReturns(showLoading) {
        if (showLoading === undefined) showLoading = true;
        clearNode(tableBody);
        if (showLoading) {
            var waitTr = document.createElement('tr');
            var waitTd = document.createElement('td');
            waitTd.colSpan = 7;
            waitTd.className = 'text-center text-muted';
            waitTd.textContent = 'جاري التحميل...';
            waitTr.appendChild(waitTd);
            tableBody.appendChild(waitTr);
        }

        try {
            var url = new URL(apiUrl, window.location.href);
            url.searchParams.set('action', 'get_returns');
            url.searchParams.set('page', String(listPage));
            if (filterDateFrom.value) url.searchParams.set('date_from', filterDateFrom.value);
            if (filterDateTo.value) url.searchParams.set('date_to', filterDateTo.value);
            if (filterSearch.value.trim()) url.searchParams.set('search', filterSearch.value.trim());

            var res = await fetch(url.toString(), { credentials: 'same-origin' });
            var data = await res.json();
            if (!data.success) throw new Error('failed');
            var returns = Array.isArray(data.returns) ? data.returns : [];
            totalPages = Math.max(1, parseInt(data.pagination && data.pagination.total_pages ? data.pagination.total_pages : 1, 10));
            paginationInfo.textContent = 'صفحة ' + listPage + ' من ' + totalPages;
            prevPageBtn.disabled = listPage <= 1;
            nextPageBtn.disabled = listPage >= totalPages;

            clearNode(tableBody);
            if (!returns.length) {
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.colSpan = 7;
                td.className = 'text-center text-muted';
                td.textContent = 'لا توجد فواتير مرتجعات';
                tr.appendChild(td);
                tableBody.appendChild(tr);
                return;
            }

            returns.forEach(function(r) {
                var tr = document.createElement('tr');
                appendCell(tr, r.invoice_number || '-');

                var createdAt = r.created_at || '-';
                var dateStr = createdAt;
                if (createdAt !== '-') {
                    var date = new Date(createdAt);
                    dateStr = date.toLocaleDateString('ar-SA', { year: 'numeric', month: '2-digit', day: '2-digit' });
                }
                appendCell(tr, dateStr);
                appendCell(tr, r.customer_name || '-');
                appendCell(tr, parseFloat(r.grand_total || 0).toFixed(2) + ' ج.م');
                appendCell(tr, String(r.items_count || 0));
                appendCell(tr, r.created_by_name || r.created_by_username || '-');

                var td = document.createElement('td');
                var viewBtn = document.createElement('button');
                viewBtn.type = 'button';
                viewBtn.className = 'btn btn-sm btn-outline-primary me-1';
                viewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>';
                viewBtn.addEventListener('click', function() { loadReturnDetails(r.id, false); });
                var printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-sm btn-outline-secondary';
                printBtn.innerHTML = '<i class="bi bi-printer me-1"></i>';
                printBtn.addEventListener('click', function() { loadReturnDetails(r.id, true); });
                td.appendChild(viewBtn);
                td.appendChild(printBtn);
                tr.appendChild(td);
                tableBody.appendChild(tr);
            });
        } catch (e) {
            clearNode(tableBody);
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 7;
            td.className = 'text-center text-danger';
            td.textContent = 'فشل تحميل البيانات';
            tr.appendChild(td);
            tableBody.appendChild(tr);
        }
    }

    // ─── Form Submit ───
    returnForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (!selectedCustomerId.value || !selectedCustomerType.value) {
            showAlert('danger', 'يرجى اختيار العميل');
            return;
        }

        var rows = collectRows();
        var err = validateRows(rows);
        if (err) {
            showAlert('danger', err);
            return;
        }

        submitReturnBtn.disabled = true;
        try {
            var res = await fetch(apiUrl + '?action=submit_return', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_id: parseInt(selectedCustomerId.value),
                    customer_type: selectedCustomerType.value,
                    rows: rows
                })
            });
            var data = await res.json();
            if (!data.success) throw new Error(data.message || 'فشل الحفظ');
            showAlert('success', data.message || 'تم الحفظ بنجاح');

            // Reset form
            clearNode(returnRows);
            createRow();
            customerSearchInput.value = '';
            selectedCustomerId.value = '';
            selectedCustomerType.value = '';
            customerInfoBox.classList.add('d-none');
            grandTotalDisplay.textContent = '0.00';

            renderReturnReceipt(data.return_invoice);
            loadReturns(false);
        } catch (x) {
            showAlert('danger', x.message || 'حدث خطأ أثناء الحفظ');
        } finally {
            submitReturnBtn.disabled = false;
        }
    });

    addReturnRowBtn.addEventListener('click', createRow);
    prevPageBtn.addEventListener('click', function () { if (listPage > 1) { listPage--; loadReturns(); } });
    nextPageBtn.addEventListener('click', function () { if (listPage < totalPages) { listPage++; loadReturns(); } });
    applyFilterBtn.addEventListener('click', function () { listPage = 1; loadReturns(); });

    createRow();
    loadReturns();
})();
</script>
