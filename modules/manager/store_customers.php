<?php
/**
 * صفحة عملاء المتجر (WooCommerce / Online Store)
 * تجلب البيانات من API خارجي: https://almoustafa.site/apis/customers.php
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
?>


<style>
#storeCustomersPage .sc-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #1e40af 100%);
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
#storeCustomersPage .sc-header::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}
#storeCustomersPage .sc-header h4 {
    color: #fff;
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0;
}
#storeCustomersPage .sc-header p {
    color: rgba(255,255,255,0.65);
    font-size: 0.85rem;
    margin: 4px 0 0;
}
#storeCustomersPage .sc-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 16px 20px;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 14px;
}
#storeCustomersPage .sc-stat-icon {
    width: 46px; height: 46px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
#storeCustomersPage .sc-stat-label {
    font-size: 0.78rem;
    color: #64748b;
    margin: 0;
}
#storeCustomersPage .sc-stat-value {
    font-size: 1.4rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    line-height: 1.2;
}

/* Search & Filter bar */
#storeCustomersPage .sc-toolbar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}
#storeCustomersPage .sc-search-input {
    flex: 1;
    min-width: 200px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.88rem;
    outline: none;
    transition: border-color .2s;
}
#storeCustomersPage .sc-search-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

/* Table */
#storeCustomersPage .sc-table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}
#storeCustomersPage .sc-table {
    margin: 0;
    font-size: 0.875rem;
}
#storeCustomersPage .sc-table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 12px 14px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
#storeCustomersPage .sc-table tbody td {
    padding: 13px 14px;
    vertical-align: middle;
    border-color: #f1f5f9;
}
#storeCustomersPage .sc-table tbody tr:hover {
    background: #f8fafc;
}
#storeCustomersPage .sc-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}
#storeCustomersPage .sc-badge-country {
    font-size: 0.72rem;
    padding: 3px 8px;
    border-radius: 20px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-weight: 500;
}

/* Pagination */
#storeCustomersPage .sc-pagination {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
#storeCustomersPage .sc-page-btn {
    min-width: 36px; height: 36px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #374151;
    font-size: 0.84rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    padding: 0 10px;
}
#storeCustomersPage .sc-page-btn:hover:not(:disabled) {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
}
#storeCustomersPage .sc-page-btn.active {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}
#storeCustomersPage .sc-page-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

/* Loading skeleton */
#storeCustomersPage .sc-skeleton td {
    padding: 13px 14px;
}
#storeCustomersPage .sc-skeleton-line {
    height: 14px;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    border-radius: 6px;
    animation: sc-shimmer 1.4s infinite;
}
@keyframes sc-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>

<div id="storeCustomersPage" dir="rtl">

    <!-- Header -->
    <div class="sc-header">
        <div class="d-flex align-items-center gap-3">
            <div style="width:52px;height:52px;background:rgba(255,255,255,0.12);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">
                <i class="bi bi-shop-window text-white"></i>
            </div>
            <div>
                <h4>عملاء المتجر الإلكتروني</h4>
                <p>قائمة العملاء المسجلين في المتجر — مزامنة مباشرة</p>
            </div>
            <div class="me-auto">
                <button class="btn btn-sm" id="scRefreshBtn"
                    style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:8px;">
                    <i class="bi bi-arrow-clockwise"></i> تحديث
                </button>
            </div>
        </div>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="sc-stat-card">
                <div class="sc-stat-icon" style="background:#eff6ff;color:#2563eb;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <p class="sc-stat-label">إجمالي العملاء</p>
                    <p class="sc-stat-value" id="scStatTotal">—</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc-stat-card">
                <div class="sc-stat-icon" style="background:#f0fdf4;color:#16a34a;">
                    <i class="bi bi-file-earmark-person"></i>
                </div>
                <div>
                    <p class="sc-stat-label">الصفحة الحالية</p>
                    <p class="sc-stat-value" id="scStatPage">—</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc-stat-card">
                <div class="sc-stat-icon" style="background:#fefce8;color:#ca8a04;">
                    <i class="bi bi-layout-text-sidebar"></i>
                </div>
                <div>
                    <p class="sc-stat-label">عدد الصفحات</p>
                    <p class="sc-stat-value" id="scStatPages">—</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sc-stat-card">
                <div class="sc-stat-icon" style="background:#fdf4ff;color:#9333ea;">
                    <i class="bi bi-list-ol"></i>
                </div>
                <div>
                    <p class="sc-stat-label">في هذه الصفحة</p>
                    <p class="sc-stat-value" id="scStatCount">—</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="sc-toolbar">
        <i class="bi bi-search text-muted"></i>
        <input type="text" id="scSearchInput" class="sc-search-input"
               placeholder="ابحث بالاسم أو البريد أو رقم الهاتف..." />

        <select id="scLimitSelect" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:0.85rem;">
            <option value="10">10 لكل صفحة</option>
            <option value="25" selected>25 لكل صفحة</option>
            <option value="50">50 لكل صفحة</option>
            <option value="100">100 لكل صفحة</option>
        </select>

        <span id="scStatusBadge" class="badge text-bg-secondary" style="border-radius:20px;font-size:0.78rem;">جاري التحميل...</span>
    </div>

    <!-- Table -->
    <div class="sc-table-wrap">
        <div class="table-responsive">
            <table class="table sc-table" id="scTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>العميل</th>
                        <th>اسم المستخدم</th>
                        <th>البريد الإلكتروني</th>
                        <th>الهاتف</th>
                        <th>المدينة / الدولة</th>
                        <th>تاريخ التسجيل</th>
                    </tr>
                </thead>
                <tbody id="scTableBody">
                    <!-- skeleton rows -->
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <tr class="sc-skeleton">
                        <td><div class="sc-skeleton-line" style="width:24px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:140px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:100px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:160px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:90px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:80px"></div></td>
                        <td><div class="sc-skeleton-line" style="width:90px"></div></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="scPagination" class="sc-pagination" style="border-top:1px solid #f1f5f9;"></div>
    </div>

    <!-- Error alert (hidden by default) -->
    <div id="scError" class="alert alert-danger mt-3 d-none" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span id="scErrorMsg">حدث خطأ أثناء جلب البيانات.</span>
    </div>

</div>

<script>
(function () {
    'use strict';

    const API_BASE = 'https://almoustafa.site/apis/customers.php';
    const API_TOKEN = '';   // ضع التوكن هنا إذا طُلب

    let currentPage = 1;
    let currentLimit = 25;
    let totalPages = 1;
    let searchTimeout = null;
    let searchQuery = '';

    /* ---- DOM refs ---- */
    const tbody       = document.getElementById('scTableBody');
    const pagination  = document.getElementById('scPagination');
    const statTotal   = document.getElementById('scStatTotal');
    const statPage    = document.getElementById('scStatPage');
    const statPages   = document.getElementById('scStatPages');
    const statCount   = document.getElementById('scStatCount');
    const statusBadge = document.getElementById('scStatusBadge');
    const errorDiv    = document.getElementById('scError');
    const errorMsg    = document.getElementById('scErrorMsg');
    const searchInput = document.getElementById('scSearchInput');
    const limitSelect = document.getElementById('scLimitSelect');
    const refreshBtn  = document.getElementById('scRefreshBtn');

    /* ---- Helpers ---- */
    function initials(first, last) {
        return ((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?';
    }

    function formatDate(raw) {
        if (!raw) return '—';
        try {
            const d = new Date(raw);
            return d.toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });
        } catch { return raw; }
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    /* ---- Fetch ---- */
    async function loadCustomers(page, limit, q) {
        showLoading();
        hideError();

        let url = `${API_BASE}?page=${page}&limit=${limit}`;
        if (q) url += `&search=${encodeURIComponent(q)}`;

        try {
            const headers = { 'Content-Type': 'application/json' };
            if (API_TOKEN) headers['Authorization'] = API_TOKEN;

            const res = await fetch(url, { headers });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }

            const data = await res.json();

            if (!data.ok) {
                throw new Error(data.message || 'استجابة غير صحيحة من الخادم');
            }

            renderTable(data.customers || [], page, limit);
            renderStats(data.total, data.page, data.pages, (data.customers || []).length);
            renderPagination(data.pages, data.page);
            totalPages = data.pages;

            statusBadge.textContent = `${data.total} عميل`;
            statusBadge.className = 'badge text-bg-success';
            statusBadge.style.borderRadius = '20px';
            statusBadge.style.fontSize = '0.78rem';

        } catch (err) {
            showError(err.message || 'تعذر الاتصال بالخادم');
            statusBadge.textContent = 'خطأ في التحميل';
            statusBadge.className = 'badge text-bg-danger';
        }
    }

    /* ---- Render Table ---- */
    function renderTable(customers, page, limit) {
        if (!customers.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        لا توجد نتائج
                    </td>
                </tr>`;
            return;
        }

        const offset = (page - 1) * limit;
        tbody.innerHTML = customers.map((c, i) => {
            const fullName = esc((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
            const username = esc(c.username || c.login || '');
            const email    = esc(c.email || '—');
            const phone    = esc(c.billing_phone || c.phone || '—');
            const city     = esc(c.billing_city || c.city || '');
            const country  = esc(c.billing_country || c.country || '');
            const location = [city, country].filter(Boolean).join(' / ') || '—';
            const date     = formatDate(c.date_registered || c.created_at || null);
            const init     = initials(c.first_name, c.last_name);
            const rowNum   = offset + i + 1;

            return `
            <tr>
                <td class="text-muted" style="font-size:.8rem;">${rowNum}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="sc-avatar">${init}</div>
                        <div>
                            <div style="font-weight:600;color:#0f172a;">${fullName}</div>
                            ${c.id ? `<div style="font-size:.75rem;color:#94a3b8;">#${c.id}</div>` : ''}
                        </div>
                    </div>
                </td>
                <td style="color:#475569;">${username || '—'}</td>
                <td style="color:#475569;direction:ltr;text-align:right;">${email}</td>
                <td style="direction:ltr;text-align:right;">${phone}</td>
                <td>
                    ${location !== '—'
                        ? `<span class="sc-badge-country">${location}</span>`
                        : '<span class="text-muted">—</span>'}
                </td>
                <td style="color:#64748b;font-size:.82rem;">${date}</td>
            </tr>`;
        }).join('');
    }

    /* ---- Stats ---- */
    function renderStats(total, page, pages, count) {
        statTotal.textContent  = total  ?? '—';
        statPage.textContent   = page   ?? '—';
        statPages.textContent  = pages  ?? '—';
        statCount.textContent  = count  ?? '—';
    }

    /* ---- Pagination ---- */
    function renderPagination(pages, current) {
        if (pages <= 1) { pagination.innerHTML = ''; return; }

        const MAX_VISIBLE = 7;
        let html = '';

        // Prev
        html += `<button class="sc-page-btn" onclick="scGoTo(${current - 1})" ${current <= 1 ? 'disabled' : ''}>
                    <i class="bi bi-chevron-right"></i>
                 </button>`;

        // Page numbers with ellipsis
        const range = buildRange(current, pages, MAX_VISIBLE);
        let lastVal = null;
        range.forEach(p => {
            if (lastVal !== null && p - lastVal > 1) {
                html += `<button class="sc-page-btn" disabled>…</button>`;
            }
            html += `<button class="sc-page-btn ${p === current ? 'active' : ''}"
                              onclick="scGoTo(${p})">${p}</button>`;
            lastVal = p;
        });

        // Next
        html += `<button class="sc-page-btn" onclick="scGoTo(${current + 1})" ${current >= pages ? 'disabled' : ''}>
                    <i class="bi bi-chevron-left"></i>
                 </button>`;

        pagination.innerHTML = html;
    }

    function buildRange(current, total, max) {
        if (total <= max) return Array.from({ length: total }, (_, i) => i + 1);
        const half = Math.floor(max / 2);
        let start = Math.max(1, current - half);
        let end   = Math.min(total, start + max - 1);
        if (end - start < max - 1) start = Math.max(1, end - max + 1);
        return Array.from({ length: end - start + 1 }, (_, i) => start + i);
    }

    /* ---- UI states ---- */
    function showLoading() {
        tbody.innerHTML = Array.from({ length: 5 }, () => `
            <tr class="sc-skeleton">
                <td><div class="sc-skeleton-line" style="width:24px"></div></td>
                <td><div class="sc-skeleton-line" style="width:150px"></div></td>
                <td><div class="sc-skeleton-line" style="width:100px"></div></td>
                <td><div class="sc-skeleton-line" style="width:170px"></div></td>
                <td><div class="sc-skeleton-line" style="width:90px"></div></td>
                <td><div class="sc-skeleton-line" style="width:80px"></div></td>
                <td><div class="sc-skeleton-line" style="width:90px"></div></td>
            </tr>`).join('');
        pagination.innerHTML = '';
        statusBadge.textContent = 'جاري التحميل...';
        statusBadge.className = 'badge text-bg-secondary';
    }

    function showError(msg) {
        errorMsg.textContent = msg;
        errorDiv.classList.remove('d-none');
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">
            <i class="bi bi-wifi-off fs-2 d-block mb-2"></i>
            ${esc(msg)}
        </td></tr>`;
    }

    function hideError() {
        errorDiv.classList.add('d-none');
    }

    /* ---- Exposed navigation ---- */
    window.scGoTo = function (page) {
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        loadCustomers(currentPage, currentLimit, searchQuery);
        document.getElementById('storeCustomersPage').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /* ---- Event listeners ---- */
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            searchQuery = this.value.trim();
            currentPage = 1;
            loadCustomers(currentPage, currentLimit, searchQuery);
        }, 450);
    });

    limitSelect.addEventListener('change', function () {
        currentLimit = parseInt(this.value, 10);
        currentPage  = 1;
        loadCustomers(currentPage, currentLimit, searchQuery);
    });

    refreshBtn.addEventListener('click', function () {
        loadCustomers(currentPage, currentLimit, searchQuery);
    });

    /* ---- Initial load ---- */
    loadCustomers(currentPage, currentLimit, searchQuery);
})();
</script>
