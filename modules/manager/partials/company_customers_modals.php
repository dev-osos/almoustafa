<?php
/**
 * Modals for managing company customers (add / edit / delete).
 */
// التأكد من وجود المتغيرات المطلوبة
if (!isset($dashboardScript)) {
    $dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');
}
$formAction = getRelativeUrl($dashboardScript);
?>

<!-- Modal للكمبيوتر فقط - إضافة عميل -->
<div class="modal fade d-none d-md-block" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="addCustomerForm" method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="add_company_customer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01" value="0">
                        <div class="form-text">أدخل قيمة موجبة للديون الحالية (إن وجدت).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ العميل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal للكمبيوتر فقط - تعديل عميل -->
<div class="modal fade d-none d-md-block" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="edit_company_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="number" name="balance" class="form-control" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal للكمبيوتر فقط - حذف عميل -->
<div class="modal fade d-none d-md-block" id="deleteCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="hidden" name="section" value="company">
                <input type="hidden" name="action" value="delete_company_customer">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>حذف العميل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">هل أنت متأكد من حذف العميل <strong class="delete-customer-name">-</strong>؟ لا يمكن التراجع عن هذه العملية.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card للموبايل - إضافة عميل -->
<div class="card shadow-sm mb-4 d-md-none" id="addCustomerCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
    </div>
    <div class="card-body">
        <form id="addCustomerCardForm" method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
            <input type="hidden" name="page" value="customers">
            <input type="hidden" name="section" value="company">
            <input type="hidden" name="action" value="add_company_customer">
            <div class="mb-3">
                <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">رقم الهاتف</label>
                <input type="text" name="phone" class="form-control" maxlength="20">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea name="address" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">الرصيد الحالي</label>
                <input type="number" name="balance" class="form-control" step="0.01" value="0">
                <div class="form-text">أدخل قيمة موجبة للديون الحالية (إن وجدت).</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ العميل</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - تعديل عميل -->
<div class="card shadow-sm mb-4 d-md-none" id="editCustomerCard" style="display: none;">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات العميل</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
            <input type="hidden" name="page" value="customers">
            <input type="hidden" name="section" value="company">
            <input type="hidden" name="action" value="edit_company_customer">
            <input type="hidden" name="customer_id" id="editCustomerCardId" value="">
            <div class="mb-3">
                <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                <input type="text" name="name" id="editCustomerCardName" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">رقم الهاتف</label>
                <input type="text" name="phone" id="editCustomerCardPhone" class="form-control" maxlength="20">
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <textarea name="address" id="editCustomerCardAddress" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">الرصيد الحالي</label>
                <input type="number" name="balance" id="editCustomerCardBalance" class="form-control" step="0.01">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - حذف عميل -->
<div class="card shadow-sm mb-4 d-md-none" id="deleteCustomerCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-trash3 me-2"></i>حذف العميل</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>">
            <input type="hidden" name="page" value="customers">
            <input type="hidden" name="section" value="company">
            <input type="hidden" name="action" value="delete_company_customer">
            <input type="hidden" name="customer_id" id="deleteCustomerCardId" value="">
            <p class="mb-3">هل أنت متأكد من حذف العميل <strong class="delete-customer-card-name">-</strong>؟ لا يمكن التراجع عن هذه العملية.</p>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteCustomerCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== دوال أساسية =====

function isMobile() {
    return window.innerWidth <= 768;
}

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    const cards = ['addCustomerCard', 'editCustomerCard', 'deleteCustomerCard'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = ['addCustomerModal', 'editCustomerModal', 'deleteCustomerModal'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

function showAddCustomerModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addCustomerCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addCustomerModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

// ===== دوال إغلاق Cards =====

function closeAddCustomerCard() {
    const card = document.getElementById('addCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditCustomerCard() {
    const card = document.getElementById('editCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeDeleteCustomerCard() {
    const card = document.getElementById('deleteCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var editModal = document.getElementById('editCustomerModal');
    var deleteModal = document.getElementById('deleteCustomerModal');
    var addModal = document.getElementById('addCustomerModal');
    var addForm = document.getElementById('addCustomerForm');

    if (!editModal || !deleteModal) {
        console.warn('Company customers modals not found in DOM');
        return;
    }

    // معالجة إرسال نموذج إضافة العميل
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            // التأكد من صحة البيانات قبل الإرسال
            var nameInput = addForm.querySelector('input[name="name"]');
            if (!nameInput || !nameInput.value || nameInput.value.trim() === '') {
                e.preventDefault();
                alert('يجب إدخال اسم العميل');
                nameInput.focus();
                return false;
            }
            
            // السماح بإرسال النموذج بشكل طبيعي
            return true;
        });
    }

    // إعادة تعيين النموذج عند إغلاق الـ modal
    if (addModal && addForm) {
        addModal.addEventListener('hidden.bs.modal', function () {
            addForm.reset();
        });
    }

    editModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }

        closeAllForms();

        var customerId = button.getAttribute('data-customer-id') || '';
        var customerName = button.getAttribute('data-customer-name') || '';
        var customerPhone = button.getAttribute('data-customer-phone') || '';
        var customerAddress = button.getAttribute('data-customer-address') || '';
        var customerBalance = button.getAttribute('data-customer-balance') || '';

        if (isMobile()) {
            // على الموبايل: استخدام Card
            var card = document.getElementById('editCustomerCard');
            if (card) {
                document.getElementById('editCustomerCardId').value = customerId;
                document.getElementById('editCustomerCardName').value = customerName;
                document.getElementById('editCustomerCardPhone').value = customerPhone;
                document.getElementById('editCustomerCardAddress').value = customerAddress;
                document.getElementById('editCustomerCardBalance').value = customerBalance;
                
                card.style.display = 'block';
                setTimeout(function() {
                    scrollToElement(card);
                }, 50);
            }
        } else {
            // على الكمبيوتر: استخدام Modal
            var modal = this;
            modal.querySelector('input[name="customer_id"]').value = customerId;
            modal.querySelector('input[name="name"]').value = customerName;
            modal.querySelector('input[name="phone"]').value = customerPhone;
            modal.querySelector('textarea[name="address"]').value = customerAddress;
            modal.querySelector('input[name="balance"]').value = customerBalance;
        }
    });

    deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) {
            return;
        }
        
        closeAllForms();
        
        var customerId = button.getAttribute('data-customer-id') || '';
        var customerName = button.getAttribute('data-customer-name') || '-';
        
        if (isMobile()) {
            // على الموبايل: استخدام Card
            var card = document.getElementById('deleteCustomerCard');
            if (card) {
                document.getElementById('deleteCustomerCardId').value = customerId;
                card.querySelector('.delete-customer-card-name').textContent = customerName;
                
                card.style.display = 'block';
                setTimeout(function() {
                    scrollToElement(card);
                }, 50);
            }
        } else {
            // على الكمبيوتر: استخدام Modal
            var modal = this;
            modal.querySelector('input[name="customer_id"]').value = customerId;
            modal.querySelector('.delete-customer-name').textContent = customerName;
        }
    });
});
</script>

