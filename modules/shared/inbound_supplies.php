<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}
$apiUrl = getRelativeUrl('api/inbound_supplies.php');
?>

<style>
.autocomplete-dropdown {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    background: white !important;
    border: 1px solid #dee2e6 !important;
    border-top: 1px solid #dee2e6 !important;
    border-radius: 0 0 0.375rem 0.375rem !important;
    z-index: 1000 !important;
    max-height: 200px !important;
    overflow-y: auto !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

.autocomplete-item {
    padding: 0.5rem 0.75rem !important;
    border-bottom: 1px solid #e9ecef !important;
    cursor: pointer !important;
    transition: background-color 0.15s ease-in-out !important;
}

.autocomplete-item:hover,
.autocomplete-item.highlighted {
    background-color: #f8f9fa !important;
}

.autocomplete-item:last-child {
    border-bottom: none !important;
}
</style>

<div class="page-header mb-4">
    <h2><i class="bi bi-box-arrow-in-down me-2"></i>تسجيل الواردات</h2>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0">نموذج تسجيل الواردات</h5></div>
    <div class="card-body">
        <form id="inboundSuppliesForm">
            <div id="inboundRows"></div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" id="addInboundRowBtn"><i class="bi bi-plus-circle me-1"></i>إضافة صف</button>
                <button type="submit" class="btn btn-success" id="submitInboundBtn"><i class="bi bi-check2-circle me-1"></i>حفظ الواردات</button>
            </div>
        </form>
        <div id="inboundAlert" class="alert mt-3 d-none"></div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label mb-1">من تاريخ</label><input type="date" id="filterDateFrom" class="form-control"></div>
            <div class="col-md-3"><label class="form-label mb-1">إلى تاريخ</label><input type="date" id="filterDateTo" class="form-control"></div>
            <div class="col-md-4"><label class="form-label mb-1">بحث برقم الوارد</label><input type="text" id="filterSearch" class="form-control"></div>
            <div class="col-md-2 d-grid"><button type="button" class="btn btn-primary" id="applyFilterBtn">تصفية</button></div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead><tr><th>رقم الوارد</th><th>التاريخ</th><th>المستخدم</th><th>العناصر</th><th>إجراءات</th></tr></thead>
                <tbody id="suppliesTableBody"><tr><td colspan="5" class="text-center text-muted">جاري التحميل...</td></tr></tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <small class="text-muted" id="suppliesPaginationInfo"></small>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="prevPageBtn">السابق</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="nextPageBtn">التالي</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="supplyReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إيصال الوارد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="receiptBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" id="printReceiptBtn"><i class="bi bi-printer me-1"></i>طباعة</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const apiUrl = <?php echo json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const departmentOptions = [
        { value: 'raw_materials', label: 'خامات', qtyLabel: 'إضافة وزن' },
        { value: 'packaging', label: 'أدوات تعبئة', qtyLabel: 'إضافة كمية أو وزن' },
        { value: 'external_products', label: 'منتجات خارجية', qtyLabel: 'إضافة كمية (وحدات)' }
    ];

    const inboundRows = document.getElementById('inboundRows');
    const addInboundRowBtn = document.getElementById('addInboundRowBtn');
    const inboundForm = document.getElementById('inboundSuppliesForm');
    const inboundAlert = document.getElementById('inboundAlert');
    const submitInboundBtn = document.getElementById('submitInboundBtn');
    const tableBody = document.getElementById('suppliesTableBody');
    const receiptBody = document.getElementById('receiptBody');
    const receiptModalEl = document.getElementById('supplyReceiptModal');
    const receiptModalInstance = (window.bootstrap && receiptModalEl)
        ? new window.bootstrap.Modal(receiptModalEl)
        : null;
    const paginationInfo = document.getElementById('suppliesPaginationInfo');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const printReceiptBtn = document.getElementById('printReceiptBtn');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const filterSearch = document.getElementById('filterSearch');
    const applyFilterBtn = document.getElementById('applyFilterBtn');

    let listPage = 1;
    let totalPages = 1;

    function showAlert(type, message) {
        inboundAlert.className = 'alert mt-3 alert-' + type;
        inboundAlert.textContent = message;
        inboundAlert.classList.remove('d-none');
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

        const c1 = document.createElement('div');
        c1.className = 'col-md-3';
        const l1 = document.createElement('label');
        l1.className = 'form-label';
        l1.textContent = 'القسم *';
        const department = document.createElement('select');
        department.className = 'form-select';
        department.appendChild(createOption('', 'اختر القسم'));
        departmentOptions.forEach(d => department.appendChild(createOption(d.value, d.label)));
        c1.appendChild(l1);
        c1.appendChild(department);

        const c2 = document.createElement('div');
        c2.className = 'col-md-5';
        const l2 = document.createElement('label');
        l2.className = 'form-label';
        l2.textContent = 'العنصر *';
        
        // Create a container for the input and dropdown
        const itemContainer = document.createElement('div');
        itemContainer.className = 'position-relative';
        
        // Create autocomplete input
        const itemInput = document.createElement('input');
        itemInput.type = 'text';
        itemInput.className = 'form-control';
        itemInput.placeholder = 'اكتب اسم العنصر أو اختر من القائمة';
        itemInput.disabled = true;
        
        // Create hidden select to store options
        const item = document.createElement('select');
        item.className = 'd-none';
        item.disabled = true;
        item.appendChild(createOption('', 'اختر القسم أولاً'));
        
        // Create dropdown for autocomplete suggestions
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.display = 'none';
        
        itemContainer.appendChild(itemInput);
        itemContainer.appendChild(dropdown);
        itemContainer.appendChild(item);
        c2.appendChild(l2);
        c2.appendChild(itemContainer);

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
        qty.step = '0.001';
        const calcBtn = document.createElement('button');
        calcBtn.type = 'button';
        calcBtn.className = 'btn btn-outline-secondary';
        calcBtn.innerHTML = '<i class="bi bi-calculator"></i>';
        calcBtn.title = 'آلة حاسبة';
        qtyContainer.appendChild(qty);
        qtyContainer.appendChild(calcBtn);
        c3.appendChild(l3);
        c3.appendChild(qtyContainer);

        const c4 = document.createElement('div');
        c4.className = 'col-md-1 d-grid';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger';
        removeBtn.textContent = 'حذف';
        c4.appendChild(removeBtn);

        row.appendChild(c1);
        row.appendChild(c2);
        row.appendChild(c3);
        row.appendChild(c4);
        wrapper.appendChild(row);
        inboundRows.appendChild(wrapper);

        department.addEventListener('change', async function () {
            const dep = this.value;
            const depMeta = departmentOptions.find(d => d.value === dep);
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
            itemInput.placeholder = 'اكتب اسم العنصر أو اختر من القائمة';
            item.appendChild(createOption('', 'اختر العنصر'));
            
            try {
                const data = await getItems(dep);
                const items = data && data.success && Array.isArray(data.items) ? data.items : [];
                
                items.forEach(x => {
                    const o = createOption(String(x.id), x.name + ' (' + (x.current_quantity || 0) + ' ' + (x.unit || '') + ')');
                    o.dataset.table = x.table || '';
                    o.dataset.field = x.quantity_field || '';
                    o.dataset.name = x.name || '';
                    o.dataset.unit = x.unit || '';
                    o.dataset.searchText = x.name.toLowerCase();
                    item.appendChild(o);
                });
                
                // Setup autocomplete functionality
                setupAutocomplete(itemInput, dropdown, item, items);
                
            } catch (e) {
                itemInput.disabled = true;
                dropdown.style.display = 'none';
            }
        });

        removeBtn.addEventListener('click', function () {
            wrapper.remove();
            updateRemoveState();
        });

        calcBtn.addEventListener('click', function () {
            createCalculator(qty);
        });

        updateRemoveState();
    }

    function updateRemoveState() {
        const rows = inboundRows.querySelectorAll('.border.rounded');
        rows.forEach(r => {
            const b = r.querySelector('.btn-outline-danger');
            b.disabled = rows.length <= 1;
        });
    }

    function createCalculator(targetInput) {
        // Create calculator modal
        const calcModal = document.createElement('div');
        calcModal.className = 'modal fade';
        calcModal.id = 'calculatorModal_' + Date.now();
        calcModal.setAttribute('tabindex', '-1');
        
        calcModal.innerHTML = `
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">آلة حاسبة</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <input type="text" id="calcDisplay" class="form-control text-end" readonly 
                                   style="font-size: 1.2rem; font-family: monospace;">
                        </div>
                        <div class="row g-2">
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="7">7</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="8">8</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="9">9</button></div>
                            <div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="/">÷</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="4">4</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="5">5</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="6">6</button></div>
                            <div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="*">×</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="1">1</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="2">2</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="3">3</button></div>
                            <div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="-">-</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value="0">0</button></div>
                            <div class="col-3"><button class="btn btn-light w-100 calc-btn" data-value=".">.</button></div>
                            <div class="col-3"><button class="btn btn-danger w-100 calc-btn" data-op="clear">C</button></div>
                            <div class="col-3"><button class="btn btn-warning w-100 calc-btn" data-op="+">+</button></div>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-6"><button class="btn btn-success w-100" id="calcEquals">=</button></div>
                            <div class="col-6"><button class="btn btn-primary w-100" id="calcInsert">إضافة للكمية</button></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(calcModal);
        
        // Calculator logic
        const display = calcModal.querySelector('#calcDisplay');
        let currentExpression = '';
        let lastResult = 0;
        
        function updateDisplay() {
            display.value = currentExpression || '0';
        }
        
        function calculate() {
            if (!currentExpression) return 0;
            try {
                // Replace × with * and ÷ with /
                const expr = currentExpression.replace(/×/g, '*').replace(/÷/g, '/');
                // Safely evaluate the expression
                const result = Function('"use strict"; return (' + expr + ')')();
                return parseFloat(result.toFixed(6));
            } catch (e) {
                return 0;
            }
        }
        
        // Button events
        calcModal.querySelectorAll('.calc-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const value = this.dataset.value;
                const op = this.dataset.op;
                
                if (value !== undefined) {
                    currentExpression += value;
                } else if (op === 'clear') {
                    currentExpression = '';
                    lastResult = 0;
                } else if (op) {
                    currentExpression += ' ' + op + ' ';
                }
                updateDisplay();
            });
        });
        
        calcModal.querySelector('#calcEquals').addEventListener('click', function() {
            lastResult = calculate();
            currentExpression = lastResult.toString();
            updateDisplay();
        });
        
        calcModal.querySelector('#calcInsert').addEventListener('click', function() {
            const result = currentExpression ? calculate() : lastResult;
            targetInput.value = result;
            const modal = bootstrap.Modal.getInstance(calcModal);
            if (modal) modal.hide();
        });
        
        // Show modal
        const modal = new bootstrap.Modal(calcModal);
        modal.show();
        
        // Clean up when modal is hidden
        calcModal.addEventListener('hidden.bs.modal', function() {
            document.body.removeChild(calcModal);
        });
        
        updateDisplay();
    }

    function setupAutocomplete(input, dropdown, select, items) {
        let selectedIndex = -1;
        let filteredItems = [];
        
        input.addEventListener('input', function() {
            const value = this.value.toLowerCase().trim();
            clearNode(dropdown);
            selectedIndex = -1;
            
            if (value.length === 0) {
                dropdown.style.display = 'none';
                select.value = '';
                return;
            }
            
            filteredItems = items.filter(item => 
                item.name.toLowerCase().includes(value)
            );
            
            if (filteredItems.length === 0) {
                dropdown.style.display = 'none';
                return;
            }
            
            filteredItems.forEach((item, index) => {
                const div = document.createElement('div');
                div.className = 'autocomplete-item';
                div.textContent = item.name + ' (' + (item.current_quantity || 0) + ' ' + (item.unit || '') + ')';
                
                div.addEventListener('click', function() {
                    input.value = item.name;
                    select.value = item.id;
                    dropdown.style.display = 'none';
                    selectedIndex = -1;
                });
                
                div.addEventListener('mouseenter', function() {
                    if (selectedIndex >= 0) {
                        dropdown.children[selectedIndex].classList.remove('highlighted');
                    }
                    selectedIndex = index;
                    div.classList.add('highlighted');
                });
                
                dropdown.appendChild(div);
            });
            
            dropdown.style.display = 'block';
        });
        
        input.addEventListener('keydown', function(e) {
            if (dropdown.style.display === 'none') return;
            
            const items = dropdown.children;
            if (items.length === 0) return;
            
            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (selectedIndex < items.length - 1) {
                        if (selectedIndex >= 0) items[selectedIndex].classList.remove('highlighted');
                        selectedIndex++;
                        items[selectedIndex].classList.add('highlighted');
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                    
                case 'ArrowUp':
                    e.preventDefault();
                    if (selectedIndex > 0) {
                        items[selectedIndex].classList.remove('highlighted');
                        selectedIndex--;
                        items[selectedIndex].classList.add('highlighted');
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                    
                case 'Enter':
                    e.preventDefault();
                    if (selectedIndex >= 0 && selectedIndex < filteredItems.length) {
                        const selectedItem = filteredItems[selectedIndex];
                        input.value = selectedItem.name;
                        select.value = selectedItem.id;
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
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
                selectedIndex = -1;
            }
        });
    }

    function collectRows() {
        return Array.from(inboundRows.querySelectorAll('.border.rounded')).map((r) => {
            const s = r.querySelectorAll('select');
            const q = r.querySelector('input[type="number"]');
            const itemInput = r.querySelector('input[type="text"]:not([type="number"])');
            const dep = s[0].value;
            const itemSel = s[1];
            const itemOpt = itemSel.options[itemSel.selectedIndex];
            
            // Check if user typed a custom item name or selected from dropdown
            const isCustomItem = itemInput.value && !itemSel.value;
            
            return {
                department: dep,
                item_id: isCustomItem ? null : parseInt(itemSel.value || '0', 10),
                table: isCustomItem ? '' : (itemOpt ? (itemOpt.dataset.table || '') : ''),
                quantity_field: isCustomItem ? '' : (itemOpt ? (itemOpt.dataset.field || '') : ''),
                item_name: isCustomItem ? itemInput.value : (itemOpt ? (itemOpt.dataset.name || '') : ''),
                unit: isCustomItem ? '' : (itemOpt ? (itemOpt.dataset.unit || '') : ''),
                quantity: parseFloat(q.value || '0'),
                is_custom_item: isCustomItem
            };
        });
    }

    function validateRows(rows) {
        if (!rows.length) return 'يرجى إضافة صف واحد على الأقل';
        for (const r of rows) {
            if (!r.department) return 'يرجى اختيار القسم';
            if (!r.item_name || r.item_name.trim() === '') return 'يرجى إدخال اسم العنصر';
            if (!r.is_custom_item && (!r.item_id || !r.table || !r.quantity_field)) return 'يرجى اختيار عنصر من القائمة';
            if (!(r.quantity > 0)) return 'الكمية يجب أن تكون أكبر من صفر';
        }
        return '';
    }

    function appendCell(tr, text) {
        const td = document.createElement('td');
        td.textContent = text;
        tr.appendChild(td);
    }

    function renderReceipt(supply) {
        clearNode(receiptBody);
        const root = document.createElement('div');
        root.id = 'receiptPrintable';
        const title = document.createElement('h4');
        title.textContent = 'إيصال واردات - ' + (supply.supply_number || '-');
        root.appendChild(title);

        const meta = document.createElement('p');
        meta.textContent = 'التاريخ: ' + (supply.created_at || '-') + ' | المستخدم: ' + (supply.created_by || supply.created_by_name || supply.created_by_username || '-');
        root.appendChild(meta);

        const table = document.createElement('table');
        table.className = 'table table-bordered';
        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['القسم', 'العنصر', 'قبل', 'المضاف', 'بعد'].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        (supply.items || []).forEach(it => {
            const tr = document.createElement('tr');
            appendCell(tr, it.department_label || it.department || '-');
            appendCell(tr, it.item_name || '-');
            appendCell(tr, String(it.before_quantity || 0) + ' ' + (it.unit || ''));
            appendCell(tr, String(it.added_quantity || 0) + ' ' + (it.unit || ''));
            appendCell(tr, String(it.after_quantity || 0) + ' ' + (it.unit || ''));
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        root.appendChild(table);
        receiptBody.appendChild(root);
        if (receiptModalInstance) {
            receiptModalInstance.show();
        } else if (receiptModalEl) {
            receiptModalEl.classList.add('show');
            receiptModalEl.style.display = 'block';
            receiptModalEl.setAttribute('aria-modal', 'true');
            receiptModalEl.removeAttribute('aria-hidden');
            document.body.classList.add('modal-open');
        }
    }

    async function loadSupplyDetails(id, printAfter) {
        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('action', 'get_supply_details');
        url.searchParams.set('id', String(id));
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) {
            showAlert('danger', data.message || 'تعذر تحميل الإيصال');
            return;
        }
        renderReceipt(data.supply);
        if (printAfter) setTimeout(printReceipt, 150);
    }

    function printReceipt() {
        const src = document.getElementById('receiptPrintable');
        if (!src) return;
        const w = window.open('', '_blank');
        if (!w) return;
        const doc = w.document;
        const head = doc.createElement('head');
        const title = doc.createElement('title');
        title.textContent = 'إيصال الوارد';
        head.appendChild(title);
        const style = doc.createElement('style');
        style.textContent = 'body{font-family:Arial;padding:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:right}';
        head.appendChild(style);
        const body = doc.createElement('body');
        body.appendChild(src.cloneNode(true));
        doc.documentElement.replaceChildren(head, body);
        w.focus();
        w.print();
    }

    function closeReceiptModalFallback() {
        if (!receiptModalEl || receiptModalInstance) return;
        receiptModalEl.classList.remove('show');
        receiptModalEl.style.display = 'none';
        receiptModalEl.setAttribute('aria-hidden', 'true');
        receiptModalEl.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
    }

    async function loadSupplies() {
        clearNode(tableBody);
        const waitTr = document.createElement('tr');
        const waitTd = document.createElement('td');
        waitTd.colSpan = 5;
        waitTd.className = 'text-center text-muted';
        waitTd.textContent = 'جاري التحميل...';
        waitTr.appendChild(waitTd);
        tableBody.appendChild(waitTr);

        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('action', 'get_supplies');
        url.searchParams.set('page', String(listPage));
        if (filterDateFrom.value) url.searchParams.set('date_from', filterDateFrom.value);
        if (filterDateTo.value) url.searchParams.set('date_to', filterDateTo.value);
        if (filterSearch.value.trim()) url.searchParams.set('search', filterSearch.value.trim());

        try {
            const res = await fetch(url.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) throw new Error('failed');
            const supplies = Array.isArray(data.supplies) ? data.supplies : [];
            totalPages = Math.max(1, parseInt(data.pagination && data.pagination.total_pages ? data.pagination.total_pages : 1, 10));
            paginationInfo.textContent = 'صفحة ' + listPage + ' من ' + totalPages;
            prevPageBtn.disabled = listPage <= 1;
            nextPageBtn.disabled = listPage >= totalPages;

            clearNode(tableBody);
            if (!supplies.length) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = 5;
                td.className = 'text-center text-muted';
                td.textContent = 'لا توجد بيانات';
                tr.appendChild(td);
                tableBody.appendChild(tr);
                return;
            }

            supplies.forEach(s => {
                const tr = document.createElement('tr');
                appendCell(tr, s.supply_number || '-');
                appendCell(tr, s.created_at || '-');
                appendCell(tr, s.created_by_name || s.created_by_username || '-');
                appendCell(tr, String(s.items_count || 0));
                const td = document.createElement('td');
                const viewBtn = document.createElement('button');
                viewBtn.type = 'button';
                viewBtn.className = 'btn btn-sm btn-outline-primary me-1';
                viewBtn.textContent = 'عرض الإيصال';
                viewBtn.addEventListener('click', () => loadSupplyDetails(s.id, false));
                const printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-sm btn-outline-secondary';
                printBtn.textContent = 'إعادة الطباعة';
                printBtn.addEventListener('click', () => loadSupplyDetails(s.id, true));
                td.appendChild(viewBtn);
                td.appendChild(printBtn);
                tr.appendChild(td);
                tableBody.appendChild(tr);
            });
        } catch (e) {
            clearNode(tableBody);
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-center text-danger';
            td.textContent = 'فشل تحميل البيانات';
            tr.appendChild(td);
            tableBody.appendChild(tr);
        }
    }

    inboundForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const rows = collectRows();
        const err = validateRows(rows);
        if (err) {
            showAlert('danger', err);
            return;
        }

        submitInboundBtn.disabled = true;
        try {
            const res = await fetch(apiUrl + '?action=submit_supply', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ rows: rows })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'فشل الحفظ');
            showAlert('success', data.message || 'تم الحفظ بنجاح');
            clearNode(inboundRows);
            createRow();
            renderReceipt(data.supply);
            loadSupplies();
        } catch (x) {
            showAlert('danger', x.message || 'حدث خطأ أثناء الحفظ');
        } finally {
            submitInboundBtn.disabled = false;
        }
    });

    addInboundRowBtn.addEventListener('click', createRow);
    prevPageBtn.addEventListener('click', function () { if (listPage > 1) { listPage -= 1; loadSupplies(); } });
    nextPageBtn.addEventListener('click', function () { if (listPage < totalPages) { listPage += 1; loadSupplies(); } });
    applyFilterBtn.addEventListener('click', function () { listPage = 1; loadSupplies(); });
    printReceiptBtn.addEventListener('click', printReceipt);
    if (!receiptModalInstance && receiptModalEl) {
        receiptModalEl.querySelectorAll('[data-bs-dismiss="modal"], .btn-close').forEach(btn => {
            btn.addEventListener('click', closeReceiptModalFallback);
        });
        receiptModalEl.addEventListener('click', function (e) {
            if (e.target === receiptModalEl) closeReceiptModalFallback();
        });
    }

    createRow();
    loadSupplies();
})();
</script>
