/**
 * شاشة التحميل الديناميكية التفاعلية
 * تظهر عند الإرسال وتختفي بعد التأكد من تخزين وعرض البيانات المحدثة
 */
(function() {
    'use strict';

    var overlay = document.getElementById('global-loading-overlay');
    var loadingCount = 0;

    function showPageLoading() {
        if (!overlay) return;
        loadingCount++;
        overlay.classList.add('is-active');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function hidePageLoading() {
        if (!overlay) return;
        loadingCount = Math.max(0, loadingCount - 1);
        if (loadingCount <= 0) {
            loadingCount = 0;
            overlay.classList.remove('is-active');
            overlay.setAttribute('aria-hidden', 'true');
        }
    }

    function resetPageLoading() {
        if (!overlay) return;
        loadingCount = 0;
        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
    }

    window.showPageLoading = showPageLoading;
    window.hidePageLoading = hidePageLoading;
    window.resetPageLoading = resetPageLoading;

    function isNoLoadingForm(form) {
        return !form || form.hasAttribute('data-no-loading') || form.dataset.noLoading === 'true';
    }

    function schedulePageLoading(form, event) {
        if (isNoLoadingForm(form)) return;
        if (event && event.__globalLoadingScheduled) return;
        if (event) event.__globalLoadingScheduled = true;

        setTimeout(function() {
            if (isNoLoadingForm(form)) return;

            if (event && (event.defaultPrevented || event.returnValue === false)) {
                resetPageLoading();
                return;
            }

            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                resetPageLoading();
                return;
            }

            // showPageLoading();
        }, 0);
    }

    function bindFormLoading() {
        overlay = document.getElementById('global-loading-overlay');
        if (!overlay || !document.body) return;

        if (!document.body.dataset.globalLoadingSubmitBound) {
            document.body.dataset.globalLoadingSubmitBound = 'true';
            document.body.addEventListener('submit', function(e) {
                var form = e.target && e.target.tagName === 'FORM' ? e.target : (e.target && e.target.closest ? e.target.closest('form') : null);
                schedulePageLoading(form, e);
            }, true);
        }

        window.addEventListener('pageshow', function onPageShow() {
            resetPageLoading();
        }, { once: false });

        if (typeof MutationObserver !== 'undefined') {
            var mo = new MutationObserver(function() {
                overlay = document.getElementById('global-loading-overlay') || overlay;
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindFormLoading);
    } else {
        bindFormLoading();
    }
})();
