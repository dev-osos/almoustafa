# نظام Modal/Card المزدوج - صفحة العملاء المحليين

## 📋 نظرة عامة

صفحة `modules/manager/local_customers.php` تستخدم النظام المزدوج (Modal/Card Dual System) لعرض نموذج إضافة عميل محلي جديد:

- **على الكمبيوتر (≥768px)**: يستخدم Bootstrap Modal
- **على الموبايل (<768px)**: يستخدم Card بسيطة

---

## 🔧 البنية الأساسية

### 1. الزر الرئيسي

```html
<button class="btn btn-primary" onclick="showAddLocalCustomerModal()">
    <i class="bi bi-person-plus me-2"></i>إضافة عميل محلي جديد
</button>
```

### 2. Modal للكمبيوتر فقط

```html
<!-- Modal إضافة عميل محلي جديد - للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addLocalCustomerModal" tabindex="-1">
    <!-- محتوى Modal -->
</div>
```

**الملاحظات:**
- `d-none`: إخفاء على جميع الشاشات
- `d-md-block`: إظهار على الشاشات المتوسطة فما فوق (≥768px)
- ID: `addLocalCustomerModal`

### 3. Card للموبايل فقط

```html
<!-- Card إضافة عميل محلي جديد - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="addLocalCustomerCard" style="display: none;">
    <!-- محتوى Card -->
</div>
```

**الملاحظات:**
- `d-md-none`: إخفاء على الشاشات المتوسطة فما فوق
- `style="display: none;"`: إخفاء افتراضي
- ID: `addLocalCustomerCard`

---

## 💻 JavaScript Functions

### دالة فتح النموذج الرئيسية

```javascript
function showAddLocalCustomerModal() {
    closeAllForms();
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('addLocalCustomerCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('addLocalCustomerModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}
```

**آلية العمل:**
1. إغلاق جميع النماذج المفتوحة (`closeAllForms()`)
2. التحقق من نوع الجهاز (`isMobile()`)
3. فتح النموذج المناسب (Modal أو Card)
4. Scroll تلقائي للـ Card على الموبايل

### دالة إغلاق Card

```javascript
function closeAddLocalCustomerCard() {
    const card = document.getElementById('addLocalCustomerCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}
```

---

## 📱 الميزات التفاعلية

### 1. إدارة أرقام الهواتف المتعددة

#### في Modal (الكمبيوتر):
- Container ID: `phoneNumbersContainer`
- Button ID: `addPhoneBtn`
- Input IDs: `phone-input` (class)

#### في Card (الموبايل):
- Container ID: `addCustomerCardPhoneNumbersContainer`
- Button ID: `addCustomerCardPhoneBtn`
- Input IDs: `phone-input` (class)

**الكود JavaScript:**

```javascript
// للـ Card على الموبايل
const addCustomerCardPhoneBtn = document.getElementById('addCustomerCardPhoneBtn');
const cardPhoneContainer = document.getElementById('addCustomerCardPhoneNumbersContainer');

if (addCustomerCardPhoneBtn && cardPhoneContainer) {
    // إضافة رقم هاتف جديد
    addCustomerCardPhoneBtn.addEventListener('click', function() {
        const phoneInputGroup = document.createElement('div');
        phoneInputGroup.className = 'input-group mb-2';
        phoneInputGroup.innerHTML = `
            <input type="text" class="form-control phone-input" name="phones[]" placeholder="مثال: 01234567890">
            <button type="button" class="btn btn-outline-danger remove-phone-btn">
                <i class="bi bi-trash"></i>
            </button>
        `;
        cardPhoneContainer.appendChild(phoneInputGroup);
        updateRemoveButtons(cardPhoneContainer);
    });
    
    // حذف رقم هاتف
    cardPhoneContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-phone-btn')) {
            e.target.closest('.input-group').remove();
            updateRemoveButtons(cardPhoneContainer);
        }
    });
    
    updateRemoveButtons(cardPhoneContainer);
}
```

### 2. الحصول على الموقع الجغرافي

#### في Modal (الكمبيوتر):
- Button ID: `getLocationBtn`
- Latitude Input ID: `addCustomerLatitude`
- Longitude Input ID: `addCustomerLongitude`

#### في Card (الموبايل):
- Button ID: `getLocationCardBtn`
- Latitude Input ID: `addCustomerCardLatitude`
- Longitude Input ID: `addCustomerCardLongitude`

**الكود JavaScript:**

```javascript
// معالج الحصول على الموقع عند إضافة عميل جديد (للموبايل - Card)
var getLocationCardBtn = document.getElementById('getLocationCardBtn');
var addCustomerCardLatitudeInput = document.getElementById('addCustomerCardLatitude');
var addCustomerCardLongitudeInput = document.getElementById('addCustomerCardLongitude');

if (getLocationCardBtn && addCustomerCardLatitudeInput && addCustomerCardLongitudeInput) {
    getLocationCardBtn.addEventListener('click', function() {
        if (!navigator.geolocation) {
            showAlert('المتصفح لا يدعم تحديد الموقع الجغرافي.');
            return;
        }

        var button = this;
        var originalText = button.innerHTML;
        
        function requestGeolocationForNewCustomerCard() {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحصول...';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    addCustomerCardLatitudeInput.value = position.coords.latitude.toFixed(8);
                    addCustomerCardLongitudeInput.value = position.coords.longitude.toFixed(8);
                    button.disabled = false;
                    button.innerHTML = originalText;
                    showAlert('تم الحصول على الموقع بنجاح!');
                },
                function(error) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                    // معالجة الأخطاء...
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
        
        // التحقق من الصلاحيات والتنفيذ
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function(result) {
                if (result.state === 'denied') {
                    showAlert('تم رفض إذن الموقع الجغرافي.');
                    return;
                }
                requestGeolocationForNewCustomerCard();
            }).catch(function() {
                requestGeolocationForNewCustomerCard();
            });
        } else {
            requestGeolocationForNewCustomerCard();
        }
    });
}
```

---

## 🎨 CSS Classes المستخدمة

### للتمييز بين Modal و Card:

```css
/* إخفاء Modal على الموبايل */
@media (max-width: 768px) {
    #addLocalCustomerModal {
        display: none !important;
    }
}

/* إخفاء Card على الكمبيوتر */
@media (min-width: 769px) {
    #addLocalCustomerCard {
        display: none !important;
    }
}
```

---

## 📊 مقارنة بين Modal و Card

| الميزة | Modal (الكمبيوتر) | Card (الموبايل) |
|--------|-------------------|-----------------|
| **العرض** | نافذة منبثقة | قسم في الصفحة |
| **Backdrop** | ✅ موجود | ❌ غير موجود |
| **Scroll** | داخل Modal | في الصفحة |
| **JavaScript** | Bootstrap Modal | Display block/none |
| **التفاعل** | معقد | بسيط |
| **الأداء على الموبايل** | ⚠️ قد يسبب مشاكل | ✅ ممتاز |

---

## 🔍 العناصر المهمة

### IDs للـ Modal:
- `addLocalCustomerModal` - Modal الرئيسي
- `phoneNumbersContainer` - Container أرقام الهواتف
- `addPhoneBtn` - زر إضافة رقم هاتف
- `getLocationBtn` - زر الحصول على الموقع
- `addCustomerLatitude` - حقل خط العرض
- `addCustomerLongitude` - حقل خط الطول
- `addLocalCustomerRegionId` - قائمة المناطق

### IDs للـ Card:
- `addLocalCustomerCard` - Card الرئيسي
- `addCustomerCardPhoneNumbersContainer` - Container أرقام الهواتف
- `addCustomerCardPhoneBtn` - زر إضافة رقم هاتف
- `getLocationCardBtn` - زر الحصول على الموقع
- `addCustomerCardLatitude` - حقل خط العرض
- `addCustomerCardLongitude` - حقل خط الطول
- `addCustomerCardRegionId` - قائمة المناطق

---

## ✅ Checklist للتأكد من عمل النظام

- [x] Modal موجود مع class `d-none d-md-block`
- [x] Card موجود مع class `d-md-none`
- [x] دالة `showAddLocalCustomerModal()` موجودة
- [x] دالة `closeAddLocalCustomerCard()` موجودة
- [x] دالة `closeAllForms()` تتضمن `addLocalCustomerCard`
- [x] معالج أرقام الهواتف للـ Card موجود
- [x] معالج الموقع الجغرافي للـ Card موجود
- [x] CSS لإخفاء/إظهار حسب الشاشة موجود

---

## 🎯 المزايا

1. **أداء أفضل على الموبايل**: لا توجد تعارضات مع Touch Events
2. **تجربة مستخدم أفضل**: Scroll تلقائي وفتح نموذج واحد فقط
3. **كود منظم**: كل ميزة لها handler خاص
4. **سهولة الصيانة**: كود واضح ومنظم
5. **توافق كامل**: يعمل على جميع الأجهزة

---

## 📝 ملاحظات مهمة

1. **نفس البيانات**: Modal و Card يستخدمان نفس الـ form fields و action
2. **IDs مختلفة**: كل عنصر في Card له ID مختلف عن Modal
3. **Event Listeners**: كل handler يجب أن يعمل على Modal و Card
4. **Form Reset**: يجب إعادة تعيين النماذج عند الإغلاق

---

**آخر تحديث:** 2024  
**الملف:** `modules/manager/local_customers.php`
