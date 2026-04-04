<?php
/**
 * صفحة مستندات الموظفين
 * مدعومة للمدير والمحاسب والمطور
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (ob_get_level() === 0) {
    ob_start();
}

// تعطيل الكاش لضمان العرض السليم بعد التحديث
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$currentUserRole = strtolower($currentUser['role'] ?? '');
$canManageEmployeeDocuments = ($currentUserRole === 'manager');
$db = db();

// إنشاء الجدول إذا لم يكن موجودًا
try {
    $exists = $db->queryOne("SHOW TABLES LIKE 'employee_documents'");
    if (empty($exists)) {
        $db->execute("CREATE TABLE IF NOT EXISTS `employee_documents` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `employee_id` INT(11) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `original_filename` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(500) NOT NULL,
            `uploaded_by` INT(11) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`employee_id`),
            KEY (`uploaded_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    error_log('Error initializing employee_documents table: ' . $e->getMessage());
}

// مجلد التحميل
$uploadBaseDir = defined('BASE_PATH') ? rtrim(BASE_PATH, '/\\') . '/uploads/employee_documents' : __DIR__ . '/../../uploads/employee_documents';
if (!is_dir($uploadBaseDir)) {
    @mkdir($uploadBaseDir, 0755, true);
}

function safeText($value) {
    return trim((string) strip_tags($value));
}

function sendEmployeeDocumentsJson(array $payload) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function uploadEmployeeFile($employeeId, $fieldName = 'file') {
    global $uploadBaseDir;

    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return [false, 'يرجى اختيار ملف صالح قبل رفعه.'];
    }

    $file = $_FILES[$fieldName];
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv'];
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        return [false, 'نوع الملف غير مدعوم. الصيغ المسموح بها: ' . implode(', ', $allowedExt)];
    }

    $employeeDir = $uploadBaseDir . '/' . (int) $employeeId;
    if (!is_dir($employeeDir)) {
        @mkdir($employeeDir, 0755, true);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9\-_\.]/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
    if ($safeName === '') {
        $safeName = 'document';
    }

    $storedFileName = $safeName . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $storedFilePath = $employeeDir . '/' . $storedFileName;

    if (!move_uploaded_file($file['tmp_name'], $storedFilePath)) {
        return [false, 'حدث خطأ أثناء حفظ الملف.'];
    }

    $relativeFilePath = 'uploads/employee_documents/' . (int) $employeeId . '/' . $storedFileName;
    return [true, ['relative' => $relativeFilePath, 'original' => $originalName]];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_employee_docs' && isset($_GET['employee_id'])) {
    $employeeId = (int) ($_GET['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        sendEmployeeDocumentsJson(['success' => false, 'message' => 'معرف الموظف غير صالح']);
    }

    $docs = $db->query("SELECT ed.*, u.full_name AS uploaded_by_name FROM employee_documents ed LEFT JOIN users u ON ed.uploaded_by = u.id WHERE ed.employee_id = ? ORDER BY ed.created_at DESC", [$employeeId]);

    sendEmployeeDocumentsJson(['success' => true, 'documents' => $docs ?: []]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $action = $_POST['action'];
    $result = ['success' => false, 'message' => 'حدث خطأ غير معروف'];

    try {
        if ($action === 'upload_document') {
            if (!$canManageEmployeeDocuments) {
                $result['message'] = 'ليس لديك صلاحية إرفاق مستندات الموظفين.';
            } else {
                $employeeId = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
                $docName = safeText($_POST['name'] ?? '');

                if ($employeeId <= 0 || $docName === '') {
                    $result['message'] = 'يجب اختيار الموظف وكتابة اسم المستند.';
                } else {
                    $emp = $db->queryOne("SELECT id FROM users WHERE id = ? AND status = 'active'", [$employeeId]);
                    if (!$emp) {
                        $result['message'] = 'الموظف غير موجود.';
                    } else {
                        list($ok, $uploadData) = uploadEmployeeFile($employeeId, 'file');
                        if (!$ok) {
                            $result['message'] = $uploadData;
                        } else {
                            $db->execute("INSERT INTO employee_documents (employee_id, name, original_filename, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)", [$employeeId, $docName, $uploadData['original'], $uploadData['relative'], $currentUser['id']]);
                            logAudit($currentUser['id'], 'upload_employee_document', 'employee_documents', $db->getLastInsertId(), null, ['employee_id' => $employeeId, 'name' => $docName]);
                            $result = ['success' => true, 'message' => 'تم رفع المستند بنجاح'];
                        }
                    }
                }
            }
        } elseif ($action === 'update_document') {
            if (!$canManageEmployeeDocuments) {
                $result['message'] = 'ليس لديك صلاحية تعديل مستندات الموظفين.';
            } else {
                $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
                $docName = safeText($_POST['name'] ?? '');

                if ($documentId <= 0 || $docName === '') {
                    $result['message'] = 'يجب اختيار المستند وادخال الاسم.';
                } else {
                    $doc = $db->queryOne("SELECT * FROM employee_documents WHERE id = ?", [$documentId]);
                    if (!$doc) {
                        $result['message'] = 'المستند غير موجود.';
                    } else {
                        $params = [$docName, $documentId];

                        if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
                            list($ok, $uploadData) = uploadEmployeeFile($doc['employee_id'], 'file');
                            if (!$ok) {
                                $result['message'] = $uploadData;
                                sendEmployeeDocumentsJson($result);
                            }
                            // حذف الملف القديم إن وجد
                            if (!empty($doc['file_path'])) {
                                $oldPath = __DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $doc['file_path']);
                                if (file_exists($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                            $db->execute("UPDATE employee_documents SET name = ?, original_filename = ?, file_path = ?, updated_at = NOW() WHERE id = ?", [$docName, $uploadData['original'], $uploadData['relative'], $documentId]);
                            logAudit($currentUser['id'], 'update_employee_document_file', 'employee_documents', $documentId, null, ['employee_id' => $doc['employee_id']]);
                            $result = ['success' => true, 'message' => 'تم تحديث المستند بنجاح'];
                            sendEmployeeDocumentsJson($result);
                        }

                        $db->execute("UPDATE employee_documents SET name = ? WHERE id = ?", $params);
                        logAudit($currentUser['id'], 'update_employee_document', 'employee_documents', $documentId, null, ['employee_id' => $doc['employee_id']]);
                        $result = ['success' => true, 'message' => 'تم تحديث اسم المستند بنجاح'];
                    }
                }
            }
        } elseif ($action === 'delete_document') {
            if (!$canManageEmployeeDocuments) {
                $result['message'] = 'ليس لديك صلاحية حذف المستندات.';
            } else {
                $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
                if ($documentId <= 0) {
                    $result['message'] = 'معرف المستند غير صالح.';
                } else {
                    $doc = $db->queryOne("SELECT * FROM employee_documents WHERE id = ?", [$documentId]);
                    if (!$doc) {
                        $result['message'] = 'المستند غير موجود.';
                    } else {
                        if (!empty($doc['file_path'])) {
                            $fileOnDisk = __DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $doc['file_path']);
                            if (file_exists($fileOnDisk)) {
                                @unlink($fileOnDisk);
                            }
                        }
                        $db->execute("DELETE FROM employee_documents WHERE id = ?", [$documentId]);
                        logAudit($currentUser['id'], 'delete_employee_document', 'employee_documents', $documentId, null, ['employee_id' => $doc['employee_id']]);
                        $result = ['success' => true, 'message' => 'تم حذف المستند بنجاح'];
                    }
                }
            }
        } else {
            $result['message'] = 'إجراء غير معروف.';
        }
    } catch (Throwable $e) {
        error_log('employee_documents action error: ' . $e->getMessage());
        $result['message'] = 'حدث خطأ أثناء معالجة الطلب.';
    }

    if ($isAjax) {
        sendEmployeeDocumentsJson($result);
    }

    // إذا لم يكن AJAX، لا شيء
   $_SESSION['employee_documents_message'] = $result['message'];
    header('Location: ' . getRelativeUrl('dashboard/' . ($currentUserRole === 'manager' ? 'manager' : 'accountant') . '.php?page=employee_documents'));
    exit;
}

// جلب الموظفين النشطين
$employees = $db->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY role ASC, full_name ASC") ?: [];

?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="bi bi-file-earmark-text me-2"></i>مستندات الموظفين</h2>
        <span class="text-muted"><?php echo $canManageEmployeeDocuments ? 'يمكنك إرفاق وتعديل وحذف المستندات لكل موظف.' : 'يمكنك استعراض مستندات الموظفين، بينما الإرفاق والتعديل والحذف متاحان للمدير فقط.'; ?></span>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>اسم الموظف</th>
                            <th>الدور</th>
                            <th>عدد المستندات</th>
                            <th style="width: 180px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr><td colspan="5" class="text-center text-muted">لا يوجد موظفين</td></tr>
                        <?php else: ?>
                            <?php foreach ($employees as $idx => $emp): ?>
                                <?php
                                    $docsCount = $db->queryOne("SELECT COUNT(*) AS total FROM employee_documents WHERE employee_id = ?", [(int) $emp['id']]);
                                    $docsCount = (int)($docsCount['total'] ?? 0);
                                ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['role']); ?></td>
                                    <td><span class="badge bg-info"><?php echo $docsCount; ?></span></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary js-open-employee-docs" data-employee-id="<?php echo (int) $emp['id']; ?>" data-employee-name="<?php echo htmlspecialchars($emp['full_name'], ENT_QUOTES); ?>" data-jump-to-form="false">
                                            <i class="bi bi-folder2-open"></i> عرض المستندات
                                        </button>
                                        <?php if ($canManageEmployeeDocuments): ?>
                                            <button type="button" class="btn btn-sm btn-primary mt-1 mt-md-0 js-open-employee-docs" data-employee-id="<?php echo (int) $emp['id']; ?>" data-employee-name="<?php echo htmlspecialchars($emp['full_name'], ENT_QUOTES); ?>" data-jump-to-form="true">
                                                <i class="bi bi-paperclip"></i> إرفاق مستند
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media (min-width: 768px) {
    #employeeDocumentsCanvas {
        width: 80vw !important;
        max-width: 80vw;
        height: 80vh !important;
        max-height: 80vh !important;
        margin: auto;
        left: 50%;
        right: auto;
        bottom: 2vh;
        transform: translateX(-50%) translateY(100%);
        border-radius: 1rem;
    }

    #employeeDocumentsCanvas.show {
        transform: translateX(-50%) translateY(0);
    }
}

@media (max-width: 767.98px) {
    #employeeDocumentsCanvas {
        max-height: 80vh !important;
    }
}
</style>

<!-- بطاقة المستندات -->
<div class="offcanvas offcanvas-bottom rounded-top" tabindex="-1" id="employeeDocumentsCanvas" aria-labelledby="employeeDocumentsCanvasLabel" style="max-height: 80vh;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="employeeDocumentsCanvasLabel">مستندات الموظف</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        <input type="hidden" id="empDocsEmployeeId" value="">
        <p><strong>الموظف:</strong> <span id="empDocsEmployeeName"></span></p>

        <div id="employeeDocumentsAlert"></div>

        <div class="table-responsive mb-3">
            <table class="table table-sm table-striped align-middle" id="employeeDocumentsTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>اسم المستند</th>
                        <th>الملف</th>
                        <th>تاريخ الرفع</th>
                        <th>رفع بواسطة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <?php if ($canManageEmployeeDocuments): ?>
            <div class="card" id="employeeDocumentFormCard">
                <div class="card-header">إضافة / تعديل مستند</div>
                <div class="card-body">
                    <form id="employeeDocumentForm"> 
                        <input type="hidden" id="docAction" name="action" value="upload_document">
                        <input type="hidden" id="docId" name="document_id" value="0">
                        <input type="hidden" id="formEmployeeId" name="employee_id" value="0">

                        <div class="mb-3">
                            <label class="form-label">اسم المستند <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="docName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الملف <span class="text-muted">(اختياري عند التعديل)</span></label>
                            <input type="file" class="form-control" id="docFile" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv">
                        </div>
                        <button type="submit" class="btn btn-primary" id="docSubmitBtn">رفع المستند</button>
                        <button type="button" class="btn btn-secondary d-none" id="docCancelEditBtn">إلغاء التعديل</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">الإرفاق والتعديل والحذف متاحون للمدير فقط.</div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const employeeDocsCanvasEl = document.getElementById('employeeDocumentsCanvas');
    const alertContainer = document.getElementById('employeeDocumentsAlert');
    const canManageEmployeeDocuments = <?php echo $canManageEmployeeDocuments ? 'true' : 'false'; ?>;

    const empIdInput = document.getElementById('empDocsEmployeeId');
    const empNameSpan = document.getElementById('empDocsEmployeeName');
    const formEmployeeId = document.getElementById('formEmployeeId');
    const docAction = document.getElementById('docAction');
    const docIdInput = document.getElementById('docId');
    const docNameInput = document.getElementById('docName');
    const docFileInput = document.getElementById('docFile');
    const docSubmitBtn = document.getElementById('docSubmitBtn');
    const docCancelEditBtn = document.getElementById('docCancelEditBtn');
    const documentForm = document.getElementById('employeeDocumentForm');
    const formCard = document.getElementById('employeeDocumentFormCard');
    const documentsTableBody = document.querySelector('#employeeDocumentsTable tbody');
    let employeeDocsCanvas = null;

    function getEmployeeDocsCanvas() {
        if (employeeDocsCanvas) {
            return employeeDocsCanvas;
        }

        if (window.bootstrap && window.bootstrap.Offcanvas) {
            employeeDocsCanvas = window.bootstrap.Offcanvas.getOrCreateInstance(employeeDocsCanvasEl);
            return employeeDocsCanvas;
        }

        return null;
    }

    function showEmployeeDocsCanvas() {
        const offcanvas = getEmployeeDocsCanvas();
        if (offcanvas) {
            offcanvas.show();
            return;
        }

        employeeDocsCanvasEl.classList.add('show');
        employeeDocsCanvasEl.style.visibility = 'visible';
        employeeDocsCanvasEl.style.transform = 'translateY(0)';
        employeeDocsCanvasEl.setAttribute('aria-modal', 'true');
        employeeDocsCanvasEl.setAttribute('role', 'dialog');

        let backdrop = document.getElementById('employeeDocumentsCanvasFallbackBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'employeeDocumentsCanvasFallbackBackdrop';
            backdrop.className = 'offcanvas-backdrop fade show';
            backdrop.addEventListener('click', hideEmployeeDocsCanvas);
            document.body.appendChild(backdrop);
        }

        document.body.classList.add('offcanvas-open');
    }

    function hideEmployeeDocsCanvas() {
        const offcanvas = getEmployeeDocsCanvas();
        if (offcanvas) {
            offcanvas.hide();
            return;
        }

        employeeDocsCanvasEl.classList.remove('show');
        employeeDocsCanvasEl.style.visibility = '';
        employeeDocsCanvasEl.style.transform = '';
        employeeDocsCanvasEl.removeAttribute('aria-modal');
        employeeDocsCanvasEl.removeAttribute('role');

        const backdrop = document.getElementById('employeeDocumentsCanvasFallbackBackdrop');
        if (backdrop) {
            backdrop.remove();
        }

        document.body.classList.remove('offcanvas-open');
    }

    function openEmployeeDocumentsCard(button, jumpToForm = false) {
        const employeeId = button.getAttribute('data-employee-id');
        const employeeName = button.getAttribute('data-employee-name');

        empIdInput.value = employeeId;
        empNameSpan.textContent = employeeName;
        if (formEmployeeId) formEmployeeId.value = employeeId;
        if (docAction) docAction.value = 'upload_document';
        if (docIdInput) docIdInput.value = '0';
        if (docNameInput) docNameInput.value = '';
        if (docFileInput) docFileInput.value = '';
        if (docSubmitBtn) docSubmitBtn.textContent = 'رفع المستند';
        if (docCancelEditBtn) docCancelEditBtn.classList.add('d-none');

        loadEmployeeDocuments(employeeId);
        showEmployeeDocsCanvas();

        if (jumpToForm && canManageEmployeeDocuments && formCard) {
            setTimeout(() => {
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (docNameInput) {
                    docNameInput.focus();
                }
            }, 250);
        }
    }

    function showAlert(message, type = 'success') {
        alertContainer.innerHTML = `\n            <div class="alert alert-${type} alert-dismissible fade show" role="alert">\n                ${message}\n                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>\n            </div>`;
    }

    function clearAlert() { alertContainer.innerHTML = ''; }

    function parseJsonResponse(response) {
        return response.text().then((text) => {
            let data = null;

            try {
                data = text ? JSON.parse(text) : null;
            } catch (error) {
                data = null;
            }

            if (!response.ok) {
                const message = (data && data.message) ? data.message : (text || 'تعذر إتمام الطلب.');
                throw new Error(message);
            }

            if (!data) {
                throw new Error(text || 'استجابة غير صالحة من الخادم.');
            }

            return data;
        });
    }

    function loadEmployeeDocuments(employeeId) {
        clearAlert();
        documentsTableBody.innerHTML = '<tr><td colspan="6" class="text-center">جاري التحميل...</td></tr>';

        fetch('?page=employee_documents&action=get_employee_docs&employee_id=' + encodeURIComponent(employeeId), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(parseJsonResponse)
        .then(data => {
            if (!data.success) {
                showAlert(data.message || 'تعذر تحميل المستندات.', 'danger');
                documentsTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">فشل التحميل</td></tr>';
                return;
            }

            if (!Array.isArray(data.documents) || data.documents.length === 0) {
                documentsTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">لا توجد مستندات</td></tr>';
                return;
            }

            documentsTableBody.innerHTML = '';
            data.documents.forEach((doc, index) => {
                const row = document.createElement('tr');
                const readLink = (doc.file_path ? `<a href="${doc.file_path}" target="_blank">${doc.original_filename}</a>` : '-');
                const uploadedBy = doc.uploaded_by_name || '-';
                const createdAt = doc.created_at ? doc.created_at : '-';

                row.innerHTML = `
                    <td>${index + 1}</td>
                    <td>${doc.name ? doc.name : '-'}` + '</td>\n' +
                    `<td>${readLink}</td>\n` +
                    `<td>${createdAt}</td>\n` +
                    `<td>${uploadedBy}</td>\n` +
                    '<td>' +
                    (canManageEmployeeDocuments
                        ? `<button type="button" class="btn btn-sm btn-outline-secondary me-1 js-edit-document" data-doc-id="${doc.id}" data-doc-name="${(doc.name || '').replace(/"/g, '&quot;')}"><i class="bi bi-pencil"></i> تعديل</button>` +
                          `<button type="button" class="btn btn-sm btn-outline-danger js-delete-document" data-doc-id="${doc.id}"><i class="bi bi-trash"></i> حذف</button>`
                        : '-'
                    ) +
                    '</td>';

                documentsTableBody.appendChild(row);
            });
        })
        .catch(err => {
            console.error(err);
            showAlert(err.message || 'حدث خطأ غير متوقع أثناء جلب المستندات.', 'danger');
            documentsTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">فشل التحميل</td></tr>';
        });
    }

    function startEditDocument(button) {
        if (!canManageEmployeeDocuments) {
            showAlert('ليس لديك صلاحية تعديل المستندات.', 'danger');
            return;
        }

        const docId = button.getAttribute('data-doc-id');
        const docName = button.getAttribute('data-doc-name');

        docAction.value = 'update_document';
        docIdInput.value = docId;
        docNameInput.value = docName;
        docFileInput.value = '';
        docSubmitBtn.textContent = 'حفظ التعديل';
        docCancelEditBtn.classList.remove('d-none');
    }

    function deleteDocument(documentId) {
        if (!canManageEmployeeDocuments) {
            showAlert('ليس لديك صلاحية حذف المستندات.', 'danger');
            return;
        }

        if (!confirm('هل أنت متأكد من حذف هذا المستند؟')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete_document');
        formData.append('document_id', documentId);

        fetch('?page=employee_documents', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(parseJsonResponse)
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadEmployeeDocuments(empIdInput.value);
                } else {
                    showAlert(data.message || 'فشل حذف المستند', 'danger');
                }
            })
            .catch(err => { console.error(err); showAlert(err.message || 'حدث خطأ غير متوقع.', 'danger'); });
    }

    document.querySelectorAll('.js-open-employee-docs').forEach((button) => {
        button.addEventListener('click', function() {
            openEmployeeDocumentsCard(this, this.getAttribute('data-jump-to-form') === 'true');
        });
    });

    const closeCanvasButton = employeeDocsCanvasEl.querySelector('[data-bs-dismiss="offcanvas"]');
    if (closeCanvasButton) {
        closeCanvasButton.addEventListener('click', function() {
            if (!(window.bootstrap && window.bootstrap.Offcanvas)) {
                hideEmployeeDocsCanvas();
            }
        });
    }

    documentsTableBody.addEventListener('click', function(event) {
        const editButton = event.target.closest('.js-edit-document');
        if (editButton) {
            startEditDocument(editButton);
            return;
        }

        const deleteButton = event.target.closest('.js-delete-document');
        if (deleteButton) {
            deleteDocument(parseInt(deleteButton.getAttribute('data-doc-id') || '0', 10));
        }
    });

    if (docCancelEditBtn) {
        docCancelEditBtn.addEventListener('click', function() {
            docAction.value = 'upload_document';
            docIdInput.value = '0';
            docNameInput.value = '';
            docFileInput.value = '';
            docSubmitBtn.textContent = 'رفع المستند';
            docCancelEditBtn.classList.add('d-none');
        });
    }

    if (documentForm) {
        documentForm.addEventListener('submit', function(event) {
            event.preventDefault();
            clearAlert();

            const activeEmployeeId = formEmployeeId.value;
            if (!activeEmployeeId) {
                showAlert('يرجى اختيار موظف أولاً.', 'danger');
                return;
            }

            const nameVal = docNameInput.value.trim();
            if (!nameVal) {
                showAlert('يرجى إدخال اسم المستند.', 'danger');
                return;
            }

            const formData = new FormData(this);
            fetch('?page=employee_documents', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        docAction.value = 'upload_document';
                        docIdInput.value = '0';
                        docNameInput.value = '';
                        docFileInput.value = '';
                        docSubmitBtn.textContent = 'رفع المستند';
                        docCancelEditBtn.classList.add('d-none');
                        loadEmployeeDocuments(activeEmployeeId);
                    } else {
                        showAlert(data.message || 'فشل في العملية.', 'danger');
                    }
                })
                .catch(err => { console.error(err); showAlert(err.message || 'حدث خطأ غير متوقع.', 'danger'); });
        });
    }
})();
</script>
