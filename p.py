import json
import sys

if len(sys.argv) < 2:
    print("Usage: python3 p.py prod.json")
    sys.exit(1)

file_path = sys.argv[1]

with open(file_path, "r", encoding="utf-8") as f:
    data = json.load(f)

# تأكد إن المفتاح موجود
if "products" in data and isinstance(data["products"], list):
    for product in data["products"]:
        product["image_url"] = ""

        

# حفظ التعديلات
with open(file_path, "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

print("✅ تم تعديل الملف بنجاح")