/**
 * بطاقة تسجيل التحصيل من جداول التحصيل اليومية (للسائق / الإنتاج / المبيعات)
 * يعمل بتفويض الأحداث على document ليعمل حتى مع تحميل المحتوى عبر AJAX
 */
(function() {
    'use strict';
    var formatNum = function(n) {
        return (typeof n === 'number' && !isNaN(n)) ? n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
    };

    function hideDailyCollectionModal() {
        var modalEl = document.getElementById('dailyCollectionModal');
        if (!modalEl) return;
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var m = bootstrap.Modal.getInstance(modalEl);
                if (m) m.hide();
            } else {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                modalEl.setAttribute('aria-hidden', 'true');
                var b = document.getElementById('dailyCollectionModalBackdrop');
                if (b) b.remove();
                document.body.classList.remove('modal-open');
            }
        } catch (err) {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            var b = document.getElementById('dailyCollectionModalBackdrop');
            if (b) b.remove();
            document.body.classList.remove('modal-open');
        }
    }

    function showDailyCollectionModal(modalEl) {
        if (!modalEl) return;
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var m = bootstrap.Modal.getOrCreateInstance(modalEl);
                m.show();
            } else {
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
                modalEl.setAttribute('aria-modal', 'true');
                modalEl.removeAttribute('aria-hidden');
                var backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.id = 'dailyCollectionModalBackdrop';
                backdrop.addEventListener('click', hideDailyCollectionModal);
                document.body.appendChild(backdrop);
                document.body.classList.add('modal-open');
            }
        } catch (err) {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            if (!document.getElementById('dailyCollectionModalBackdrop')) {
                var backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.id = 'dailyCollectionModalBackdrop';
                backdrop.addEventListener('click', hideDailyCollectionModal);
                document.body.appendChild(backdrop);
                document.body.classList.add('modal-open');
            }
        }
    }

    window.openDailyCollectionModal = function(btn) {
        if (!btn) return;
        var modalEl = document.getElementById('dailyCollectionModal');
        if (!modalEl) return;
        var itemId = btn.getAttribute('data-item-id');
        var recordDate = btn.getAttribute('data-record-date');
        var customerId = btn.getAttribute('data-customer-id');
        var customerName = btn.getAttribute('data-customer-name') || '—';
        var customerBalance = parseFloat(btn.getAttribute('data-customer-balance')) || 0;
        var dailyAmount = parseFloat(btn.getAttribute('data-daily-amount')) || 0;
        var mid = document.getElementById('modal_item_id');
        var mrd = document.getElementById('modal_record_date');
        var mlcid = document.getElementById('modal_local_customer_id');
        var mlcname = document.getElementById('modal_local_customer_name');
        var mname = document.getElementById('modal_customer_name_display');
        var mbal = document.getElementById('modal_customer_balance_display');
        var mamt = document.getElementById('modal_collection_amount');
        if (mid) mid.value = itemId || '';
        if (mrd) mrd.value = recordDate || '';
        if (mlcid) mlcid.value = customerId || '';
        if (mlcname) mlcname.value = customerName;
        if (mname) mname.textContent = customerName;
        if (mbal) mbal.textContent = formatNum(customerBalance) + ' ج.م';
        if (mamt) {
            mamt.value = dailyAmount > 0 ? dailyAmount : '';
            setTimeout(function() { mamt.focus(); }, 150);
        }
        showDailyCollectionModal(modalEl);
    };

    window.hideDailyCollectionModal = hideDailyCollectionModal;

    document.addEventListener('click', function(e) {
        var target = e.target;
        if (!target || !target.closest) return;
        var btn = target.closest('.btn-mark-collected');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            window.openDailyCollectionModal(btn);
            return;
        }
        var modalEl = document.getElementById('dailyCollectionModal');
        if (modalEl && (target.closest('#dailyCollectionModal .btn-close') || target.closest('#dailyCollectionModal [data-bs-dismiss="modal"]'))) {
            e.preventDefault();
            hideDailyCollectionModal();
        }
    }, true);

    document.addEventListener('submit', function(e) {
        if (!e.target || e.target.id !== 'daily-collection-modal-form') return;
        e.preventDefault();
        var form = e.target;
        var amountEl = document.getElementById('modal_collection_amount');
        var amount = amountEl ? parseFloat(amountEl.value) || 0 : 0;
        if (amount <= 0) {
            alert('يرجى إدخال مبلغ التحصيل أكبر من صفر.');
            return;
        }
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'جاري التسجيل...';
        }
        var fd = new FormData(form);
        var postUrl = window.location.href;
        if (postUrl.indexOf('?') === -1) postUrl += '?page=daily_collection_my_tables';
        else if (postUrl.indexOf('page=') === -1) postUrl += '&page=daily_collection_my_tables';
        fetch(postUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) return r.json();
            return r.text().then(function(t) {
                try { return JSON.parse(t); } catch (err) { return { success: false, message: 'استجابة غير متوقعة' }; }
            });
        }).then(function(data) {
            window.hideDailyCollectionModal();
            if (data && data.success) window.location.reload();
            else if (data && data.message) alert(data.message);
            else alert('تم التسجيل.');
        }).catch(function() {
            alert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.');
        }).finally(function() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تسجيل التحصيل';
            }
        });
    }, true);
})();
