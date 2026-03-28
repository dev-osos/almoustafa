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
            <div class="col-md-4"><label class="form-label mb-1">بحث برقم الايصال</label><input type="text" id="filterSearch" class="form-control"></div>
            <div class="col-md-2 d-grid"><button type="button" class="btn btn-primary" id="applyFilterBtn">تصفية</button></div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead><tr><th>رقم الايصال</th><th>التاريخ</th><th>المستخدم</th><th>عدد الاصناف</th><th>إجراءات</th></tr></thead>
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

<div id="supplyReceiptContainer" style="display: none;">
    <div id="receiptBody"></div>
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
        const url = new URL(apiUrl, window.location.href);
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
        qty.min = '1';
        qty.step = '1';
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
        removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
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
        title.textContent = 'إيصال واردات رقم - ' + (supply.supply_number || '-');
        root.appendChild(title);

        // Format date and time separately
        const createdAt = supply.created_at || '-';
        let dateStr = '-';
        let timeStr = '-';
        
        if (createdAt !== '-') {
            const date = new Date(createdAt);
            dateStr = date.toLocaleDateString('ar-SA', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            timeStr = date.toLocaleTimeString('ar-SA', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
        }

        const dateMeta = document.createElement('p');
        dateMeta.textContent = 'التاريخ: ' + dateStr;
        root.appendChild(dateMeta);

        const timeMeta = document.createElement('p');
        timeMeta.textContent = 'الوقت: ' + timeStr;
        root.appendChild(timeMeta);

        const userMeta = document.createElement('p');
        userMeta.textContent = 'المستخدم: ' + (supply.created_by_name || supply.created_by_username || supply.created_by || '-');
        root.appendChild(userMeta);

        const table = document.createElement('table');
        table.className = 'table table-bordered';
        table.style.cssText = 'border: 2px solid #000 !important; font-weight: 600 !important;';
        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['الصنف', 'قبل', 'وارد', 'بعد'].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        (supply.items || []).forEach(it => {
            const tr = document.createElement('tr');
            appendCell(tr, it.item_name || '-');
            
            // Format quantity based on unit
            function formatQuantity(quantity, unit) {
                const qty = parseFloat(quantity || 0);
                if (unit === 'قطعة' || unit === 'ق') {
                    return Math.round(qty).toString() + ' ق';
                } else if (unit === 'كجم' || unit === 'كج') {
                    // Show with 3 decimal places for weight, remove trailing zeros
                    const formatted = qty.toFixed(3).replace(/\.?0+$/, '');
                    return formatted + ' كج';
                } else {
                    // Default formatting
                    return qty.toString() + ' ' + (unit || '');
                }
            }
            
            appendCell(tr, formatQuantity(it.before_quantity, it.unit));
            appendCell(tr, formatQuantity(it.added_quantity, it.unit));
            appendCell(tr, formatQuantity(it.after_quantity, it.unit));
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        root.appendChild(table);
        receiptBody.appendChild(root);
        
        // Open in new tab
        const w = window.open('', '_blank');
        if (w) {
            w.document.write(`
                <!DOCTYPE html>
                <html dir="rtl" lang="ar">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>إيصال الوارد</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Tajawal', 'Cairo', sans-serif; }
                        .table { margin: 20px 0; }
                        h4 { text-align: center; margin: 20px 0; }
                        p { margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        ${root.outerHTML}
                    </div>
                </body>
                </html>
            `);
            w.document.close();
        }
    }

    async function loadSupplyDetails(id, printAfter) {
        // Open window immediately to avoid popup blocker
        let printWindow = null;
        if (printAfter) {
            printWindow = window.open('', '_blank');
        }
        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('action', 'get_supply_details');
        url.searchParams.set('id', String(id));
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) {
            showAlert('danger', data.message || 'تعذر تحميل الإيصال');
            if (printWindow) printWindow.close();
            return;
        }
        renderReceipt(data.supply);
        if (printAfter) setTimeout(() => printReceipt(printWindow), 150);
    }

    function printReceipt(existingWindow) {
        const src = document.getElementById('receiptPrintable');
        if (!src) return;

        // Create 80mm print version
        const w = existingWindow || window.open('', '_blank');
        if (!w) return;
        
        const doc = w.document;
        const head = doc.createElement('head');
        const title = doc.createElement('title');
        title.textContent = 'إيصال الوارد';
        head.appendChild(title);
        
        // 80mm specific styles
        const style = doc.createElement('style');
        style.textContent = `
            @page {
                size: 80mm auto;
                margin: 3mm;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
                box-sizing: border-box !important;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow: hidden !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
                font-size: 13px !important;
                line-height: 1.3 !important;
                color: #000 !important;
                direction: rtl !important;
                text-align: right !important;
                font-weight: 500 !important;
            }

            body * {
                max-width: 100% !important;
                box-sizing: border-box !important;
            }

            .receipt-80mm {
                width: 100% !important;
                margin: 0 !important;
                padding: 1mm !important;
                border: 1px solid #000 !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
                direction: rtl !important;
                text-align: right !important;
            }
            
            .receipt-header-80mm {
                text-align: center !important;
                padding: 1mm 0.5mm 0.5mm 0.5mm !important;
                border-bottom: 2px solid #000 !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            
            .receipt-title {
                font-size: 14px !important;
                font-weight: 700 !important;
                margin-bottom: 4px !important;
                text-transform: uppercase !important;
                line-height: 1.3 !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .receipt-number {
                font-size: 14px !important;
                font-weight: 600 !important;
                margin-bottom: 4px !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .receipt-divider {
                border-top: 1px solid #000 !important;
                margin: 1px 0 !important;
            }
            
            .receipt-info {
                padding: 0.5mm 0.5mm !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            
            .info-row {
                display: flex !important;
                justify-content: space-between !important;
                margin-bottom: 1px !important;
                font-size: 14px !important;
                line-height: 1.3 !important;
                width: 100% !important;
                box-sizing: border-box !important;
                align-items: center !important;
                direction: rtl !important;
                text-align: right !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .info-row .label {
                font-weight: 600 !important;
                margin-left: 1px !important;
                margin-right: 0 !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
                font-size: 14px !important;
                text-align: right !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .info-row .value {
                text-align: right !important;
                flex: 1 !important;
                font-weight: 500 !important;
                min-width: 0 !important;
                font-size: 14px !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .receipt-items {
                padding: 0.5mm 0mm !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            
            .items-table {
                width: 100% !important;
                max-width: 100% !important;
                border-collapse: collapse !important;
                font-size: 13px !important;
                margin-top: 0.5px !important;
                table-layout: fixed !important;
                border-spacing: 0 !important;
                box-sizing: border-box !important;
                word-wrap: break-word !important;
                overflow-wrap: break-word !important;
            }
            
            .items-table thead {
                background: #f0f0f0 !important;
                border-bottom: 2px solid #000 !important;
            }
            
            .items-table th {
                padding: 0.5mm 0.3mm !important;
                text-align: center !important;
                font-weight: 700 !important;
                font-size: 12px !important;
                border-right: 1px solid #000 !important;
                border-left: none !important;
                line-height: 1.3 !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .items-table th:first-child {
                border-right: none !important;
                text-align: right !important;
            }
            
            .items-table td {
                padding: 0.5mm 0.3mm !important;
                text-align: center !important;
                border-bottom: 1px solid #000 !important;
                border-right: 1px solid #000 !important;
                border-left: none !important;
                font-size: 12px !important;
                line-height: 1.3 !important;
                vertical-align: middle !important;
                font-weight: 500 !important;
                font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            }
            
            .items-table td:first-child {
                border-right: none !important;
                text-align: right !important;
            }
            
            .items-table .col-item {
                width: 40% !important;
                text-align: right !important;
                padding-left: 0.5mm !important;
                padding-right: 0.5mm !important;
            }

            .items-table .col-qty {
                width: 20% !important;
            }

            .items-table .col-before,
            .items-table .col-added,
            .items-table .col-after {
                width: 20% !important;
            }
            
            .receipt-footer {
                text-align: center !important;
                padding: 0.5mm 0.5mm !important;
                border-top: 1px solid #000 !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                margin-top: 1mm !important;
            }
            
            .footer-text {
                font-size: 14px !important;
                font-weight: 600 !important;
                line-height: 1.4 !important;
            }
            
            .no-print {
                display: none !important;
            }
        `;
        head.appendChild(style);
        
        const body = doc.createElement('body');
        
        // Create 80mm receipt content
        const receiptDiv = doc.createElement('div');
        receiptDiv.className = 'receipt-80mm';
        receiptDiv.style.cssText = `
            border: 2px solid #000 !important;
            padding: 2mm !important;
            box-sizing: border-box !important;
        `;
        
        // Get data from the original receipt
        const originalTitle = src.querySelector('h4')?.textContent || 'إيصال واردات رقم -';
        const originalMeta = src.querySelectorAll('p');
        const originalTable = src.querySelector('table');
        
        // Header with title and number on same line
        const header = doc.createElement('div');
        header.className = 'receipt-header-80mm';
        
        const titleContainer = doc.createElement('div');
        titleContainer.style.cssText = `
            padding: 1mm 1mm !important;
            margin-bottom: 1mm !important;
            text-align: center !important;
            background: #ffffff !important;
        `;
        
        const receiptTitle = doc.createElement('div');
        receiptTitle.className = 'receipt-title';
        receiptTitle.style.cssText = `
            font-size: 14px !important;
            font-weight: 700 !important;
            margin: 0 !important;
            display: inline !important;
        `;
        receiptTitle.textContent = 'إيصال واردات رقم - ';
        
        const number = doc.createElement('span');
        number.className = 'receipt-number';
        number.style.cssText = `
            font-size: 14px !important;
            font-weight: 700 !important;
            margin: 0 !important;
            display: inline !important;
            color: #000 !important;
        `;
        number.textContent = originalTitle.replace('إيصال واردات رقم - ', '').trim() || '-';
        
        titleContainer.appendChild(receiptTitle);
        titleContainer.appendChild(number);
        header.appendChild(titleContainer);
        
        receiptDiv.appendChild(header);
        
        // Info section
        const info = doc.createElement('div');
        info.className = 'receipt-info';
        
        if (originalMeta.length >= 2) {
            // Date and Time in same row
            const dateTimeRow = doc.createElement('div');
            dateTimeRow.className = 'info-row';
            dateTimeRow.style.marginBottom = '1px';
            dateTimeRow.style.fontSize = '14px';
            dateTimeRow.innerHTML = `
                <span class="label" style="font-size: 14px; font-weight: 900;">التاريخ:</span>
                <span class="value" style="font-size: 14px; font-weight: 900; margin-left: 10px;">${originalMeta[0].textContent.replace('التاريخ: ', '')}</span>
                <span class="label" style="font-size: 14px; font-weight: 900; margin-right: 15px;">الوقت:</span>
                <span class="value" style="font-size: 14px; font-weight: 900;">${originalMeta[1].textContent.replace('الوقت: ', '')}</span>
            `;
            info.appendChild(dateTimeRow);
        }
        
        receiptDiv.appendChild(info);
        
        // Divider
        const divider1 = doc.createElement('div');
        divider1.className = 'receipt-divider';
        receiptDiv.appendChild(divider1);
        
        // Items table
        const items = doc.createElement('div');
        items.className = 'receipt-items';
        
        if (originalTable) {
            const table = doc.createElement('table');
            table.className = 'items-table';
            table.style.fontSize = '14px';
            
            // Copy header
            const thead = originalTable.querySelector('thead');
            if (thead) {
                const newThead = thead.cloneNode(true);
                const headerRow = newThead.querySelector('tr');
                if (headerRow) {
                    headerRow.querySelectorAll('th').forEach(th => {
                        th.style.fontSize = '14px';
                        th.style.fontWeight = '900';
                    });
                }
                table.appendChild(newThead);
            }

            // Copy body
            const tbody = originalTable.querySelector('tbody');
            if (tbody) {
                const newTbody = tbody.cloneNode(true);
                newTbody.querySelectorAll('td').forEach(td => {
                    td.style.fontSize = '14px';
                    td.style.padding = '4px 8px';
                });
                table.appendChild(newTbody);
            }
            
            items.appendChild(table);
        }
        
        receiptDiv.appendChild(items);
        
        // Footer
        const footer = doc.createElement('div');
        footer.className = 'receipt-footer';
        
        const footerText = doc.createElement('div');
        footerText.className = 'footer-text';
        footerText.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; direction: rtl; text-align: right;">
                <div style="flex: 1; text-align: center; border-top: 1px solid #000; padding-top: 4px; margin-left: 5px;">
                    <div style="font-size: 14px; margin-bottom: 4px; font-weight: 600; font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif;">توقيع أمين المخزن</div>
                    <div style="height: 25px;"></div>
                </div>
                <div style="flex: 1; text-align: center; border-top: 1px solid #000; padding-top: 4px; margin-right: 5px;">
                    <div style="height: 25px;"></div>
                </div>
            </div>
        `;
        footer.appendChild(footerText);
        
        // Add approval stamp section
        const approvalStamp = doc.createElement('div');
        approvalStamp.className = 'approval-stamp';
        approvalStamp.style.cssText = 'text-align: center; margin-top: 20px; padding: 10px; border: 2px solid #000; border-radius: 5px;';
        approvalStamp.innerHTML = `
            <div style="font-size: 16px; font-weight: 700; margin-bottom: 5px;">ختم الاعتماد</div>
            <div style="font-size: 10px; margin-top: 5px;">التاريخ: </div>
        `;
        footer.appendChild(approvalStamp);
        
        receiptDiv.appendChild(footer);
        
        body.appendChild(receiptDiv);
        doc.documentElement.replaceChildren(head, body);
        w.focus();
        w.print();
    }


    function getSelectedSupply() {
        const selectedRow = document.querySelector('#suppliesTableBody tr.selected');
        if (selectedRow) {
            return {
                id: selectedRow.dataset.supplyId
            };
        }
        return null;
    }

    async function loadSupplies(showLoading = true) {
        clearNode(tableBody);
        
        if (showLoading) {
            const waitTr = document.createElement('tr');
            const waitTd = document.createElement('td');
            waitTd.colSpan = 5;
            waitTd.className = 'text-center text-muted';
            waitTd.textContent = 'جاري التحميل...';
            waitTr.appendChild(waitTd);
            tableBody.appendChild(waitTr);
        }

        try {
            const url = new URL(apiUrl, window.location.href);
            url.searchParams.set('action', 'get_supplies');
            url.searchParams.set('page', String(listPage));
            if (filterDateFrom.value) url.searchParams.set('date_from', filterDateFrom.value);
            if (filterDateTo.value) url.searchParams.set('date_to', filterDateTo.value);
            if (filterSearch.value.trim()) url.searchParams.set('search', filterSearch.value.trim());

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
                
                // Format date to show only date without time
                const createdAt = s.created_at || '-';
                let dateStr = createdAt;
                if (createdAt !== '-') {
                    const date = new Date(createdAt);
                    dateStr = date.toLocaleDateString('ar-SA', { 
                        year: 'numeric', 
                        month: '2-digit', 
                        day: '2-digit' 
                    });
                }
                appendCell(tr, dateStr);
                
                appendCell(tr, s.created_by_name || s.created_by_username || '-');
                appendCell(tr, String(s.items_count || 0));
                const td = document.createElement('td');
                const viewBtn = document.createElement('button');
                viewBtn.type = 'button';
                viewBtn.className = 'btn btn-sm btn-outline-primary me-1';
                viewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>';
                viewBtn.addEventListener('click', () => loadSupplyDetails(s.id, false));
                const printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-sm btn-outline-secondary';
                printBtn.innerHTML = '<i class="bi bi-printer me-1"></i>';
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
            loadSupplies(false);
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
    if (printReceiptBtn) {
        printReceiptBtn.addEventListener('click', function () {
            const selectedSupply = getSelectedSupply();
            if (selectedSupply) {
                loadSupplyDetails(selectedSupply.id, true);
            } else {
                showAlert('warning', 'الرجاء تحديد إيصال للطباعة');
            }
        });
    }

    createRow();
    loadSupplies();
})();
</script>
