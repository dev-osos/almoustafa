import json
import sys

if len(sys.argv) < 3:
    print("Usage: python3 p.py pr.json products.sql")
    sys.exit(1)

input_file = sys.argv[1]
output_file = sys.argv[2]

TABLE_NAME = "products"  # اسم الجدول في قاعدة البيانات

with open(input_file, "r", encoding="utf-8") as f:
    data = json.load(f)

products = data.get("products", [])

def escape(value):
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return str(value)
    # escape quotes
    return "'" + str(value).replace("'", "''") + "'"

queries = []

for product in products:
    columns = []
    values = []

    for key, val in product.items():
        columns.append(key)
        values.append(escape(val))

    col_str = ", ".join(columns)
    val_str = ", ".join(values)

    query = f"INSERT INTO {TABLE_NAME} ({col_str}) VALUES ({val_str});"
    queries.append(query)

# حفظ في ملف SQL
with open(output_file, "w", encoding="utf-8") as f:
    f.write("\n".join(queries))

print(f"✅ تم إنشاء ملف SQL: {output_file}")