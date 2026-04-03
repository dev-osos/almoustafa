/**
 * البحث عن عميل داخل قائمة منسدلة (دروب داون)
 * يطبّق على أي select لاختيار عميل (name="customer_id" أو class="select-customer-search")
 * يعمل مع التحميل العادي و AJAX (ajaxNavigationComplete)
 */
(function() {
    'use strict';

    // إضافة CSS للـ badge
    (function injectBadgeStyle() {
        if (document.getElementById('almostafa-id-badge-style')) return;
        var s = document.createElement('style');
        s.id = 'almostafa-id-badge-style';
        s.textContent = '.almostafa-id-badge{display:inline-block;padding:1px 6px;border-radius:4px;background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;font-size:0.72em;font-weight:700;font-family:monospace;vertical-align:middle;margin-left:4px;white-space:nowrap;}';
        document.head.appendChild(s);
    })();

    function makeIdBadge(id) {
        if (!id && id !== 0) return '';
        return '<span class="almostafa-id-badge">' + id + '</span>';
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var PLACEHOLDER = 'بحث عن عميل...';
    var DATA_INITED = 'data-customer-search-inited';
    var WRAPPER_CLASS = 'customer-select-search-wrapper';
    var INPUT_CLASS = 'customer-select-search-input';
    var DROPDOWN_CLASS = 'customer-select-search-dropdown';
    var LIST_CLASS = 'customer-select-search-list';

    function getCustomerSelects() {
        return document.querySelectorAll('select[name="customer_id"], select.select-customer-search');
    }

    function initOne(selectEl) {
        if (!selectEl || selectEl.getAttribute(DATA_INITED) === '1') return;
        selectEl.setAttribute(DATA_INITED, '1');

        var wrapper = document.createElement('div');
        wrapper.className = WRAPPER_CLASS + ' position-relative';
        selectEl.parentNode.insertBefore(wrapper, selectEl);
        wrapper.appendChild(selectEl);

        selectEl.classList.add('d-none');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control ' + INPUT_CLASS;
        input.autocomplete = 'off';
        input.placeholder = PLACEHOLDER;
        input.setAttribute('aria-label', PLACEHOLDER);
        wrapper.insertBefore(input, selectEl);

        var dropdown = document.createElement('div');
        dropdown.className = DROPDOWN_CLASS + ' position-absolute top-100 start-0 end-0 mt-1 border rounded bg-white shadow-sm overflow-hidden';
        dropdown.style.display = 'none';
        dropdown.style.zIndex = '1060';
        dropdown.setAttribute('role', 'listbox');
        wrapper.appendChild(dropdown);

        var list = document.createElement('ul');
        list.className = 'list-unstyled mb-0 p-0 ' + LIST_CLASS;
        list.style.maxHeight = '220px';
        list.style.overflowY = 'auto';
        dropdown.appendChild(list);

        function getOptions() {
            return Array.from(selectEl.options).filter(function(opt) { return opt.value !== ''; });
        }

        function setInputFromSelect() {
            var opt = selectEl.options[selectEl.selectedIndex];
            if (opt && opt.value !== '') {
                input.value = opt.textContent.trim();
            } else {
                input.value = '';
            }
        }

        function buildList(filter) {
            var opts = getOptions();
            var f = (filter || '').toLowerCase().trim();
            list.innerHTML = '';
            opts.forEach(function(opt) {
                var text = opt.textContent.trim();
                if (f && text.toLowerCase().indexOf(f) === -1) return;
                // فصل الـ ID عن الاسم (صيغة: "123 - الاسم")
                var dashIdx = text.indexOf(' - ');
                var badgeHtml = '';
                var displayName = text;
                if (dashIdx > 0) {
                    var idPart = text.substring(0, dashIdx).trim();
                    var namePart = text.substring(dashIdx + 3).trim();
                    if (/^\d+$/.test(idPart)) {
                        badgeHtml = makeIdBadge(idPart);
                        displayName = namePart;
                    }
                }
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.setAttribute('data-value', opt.value);
                li.className = 'px-3 py-2 cursor-pointer customer-select-option';
                li.style.cursor = 'pointer';
                li.innerHTML = badgeHtml + escHtml(displayName);
                li.addEventListener('click', function() {
                    selectEl.value = opt.value;
                    input.value = text;
                    dropdown.style.display = 'none';
                    input.focus();
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                });
                list.appendChild(li);
            });
            if (list.children.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'px-3 py-2 text-muted small';
                empty.textContent = 'لا توجد نتائج';
                list.appendChild(empty);
            }
        }

        function showDropdown() {
            buildList(input.value);
            dropdown.style.display = 'block';
        }

        function hideDropdown() {
            setTimeout(function() {
                dropdown.style.display = 'none';
            }, 200);
        }

        setInputFromSelect();

        input.addEventListener('focus', function() {
            showDropdown();
        });
        input.addEventListener('input', function() {
            showDropdown();
        });
        input.addEventListener('keydown', function(e) {
            var items = list.querySelectorAll('li[data-value]');
            if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                return;
            }
            if (e.key === 'ArrowDown' && items.length) {
                e.preventDefault();
                var idx = Array.from(items).findIndex(function(li) { return li.classList.contains('bg-light'); });
                idx = idx < 0 ? 0 : Math.min(idx + 1, items.length - 1);
                items.forEach(function(li) { li.classList.remove('bg-light'); });
                items[idx].classList.add('bg-light');
                items[idx].scrollIntoView({ block: 'nearest' });
                return;
            }
            if (e.key === 'ArrowUp' && items.length) {
                e.preventDefault();
                var idx = Array.from(items).findIndex(function(li) { return li.classList.contains('bg-light'); });
                idx = idx <= 0 ? items.length - 1 : idx - 1;
                items.forEach(function(li) { li.classList.remove('bg-light'); });
                items[idx].classList.add('bg-light');
                items[idx].scrollIntoView({ block: 'nearest' });
                return;
            }
            if (e.key === 'Enter') {
                var focused = list.querySelector('li.bg-light[data-value]');
                if (focused) {
                    e.preventDefault();
                    focused.click();
                }
            }
        });

        dropdown.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) hideDropdown();
        });

        selectEl.addEventListener('change', function() {
            setInputFromSelect();
        });
    }

    function initAll() {
        getCustomerSelects().forEach(initOne);
    }

    function onReady() {
        initAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    window.addEventListener('ajaxNavigationComplete', function() {
        initAll();
    });

    window.initCustomerSelectSearch = initAll;
})();
