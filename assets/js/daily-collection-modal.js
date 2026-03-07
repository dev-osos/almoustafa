/**
 * بطاقة تسجيل التحصيل من جداول التحصيل اليومية (للسائق / الإنتاج / المبيعات)
 * يعمل بتفويض الأحداث على document ليعمل حتى مع تحميل المحتوى عبر AJAX
 */
(function() {
    'use strict';
    var formatNum = function(n) {
        return (typeof n === 'number' && !isNaN(n)) ? n.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
    };

    function hideDailyCollectionCard() {
        var cardEl = document.getElementById('dailyCollectionCard');
        if (!cardEl) return;
        cardEl.style.display = 'none';
        cardEl.setAttribute('aria-hidden', 'true');
    }

    function showDailyCollectionCard(el) {
        if (!el) return;
        el.style.display = 'block';
        el.removeAttribute('aria-hidden');
        var firstInput = el.querySelector('#modal_collection_amount');
        setTimeout(function() {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (firstInput) firstInput.focus();
        }, 100);
    }

    window.openDailyCollectionModal = function(btn) {
        if (!btn) return;
        var cardEl = document.getElementById('dailyCollectionCard');
        if (!cardEl) return;
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
        }
        showDailyCollectionCard(cardEl);
    };

    window.hideDailyCollectionModal = hideDailyCollectionCard;

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
        var card = document.getElementById('dailyCollectionCard');
        if (card && (target.closest('.btn-close-card') || target.closest('#dailyCollectionCard .btn-close-card'))) {
            e.preventDefault();
            hideDailyCollectionCard();
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
