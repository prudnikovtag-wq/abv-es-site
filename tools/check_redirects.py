#!/usr/bin/env python3
"""Проверка переадресаций старых карточек товаров в .htaccess (без сервера).

Запуск:  python3 tools/check_redirects.py

Читает .htaccess, берёт RewriteCond/RewriteRule из блока после редиректа http→https
(после строки «# Конец редиректа») и эмулирует их через Python re на списке адресов:
  - 32 старых адреса /<артикул>.html и те же через /sklad/<артикул>.html —
    должны вести на /tovar-<тот же артикул>.html, страница должна существовать
    и содержать "sku": "<артикул>";
  - контрольные адреса — не должны переадресовываться.
Эмуляция упрощённая: поддерживаются только условия вида «<строка> -f»
и переменная %{DOCUMENT_ROOT} (= корень репозитория). Этого достаточно для наших правил.
Код выхода 1, если есть ошибки.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HTACCESS = os.path.join(ROOT, ".htaccess")
START_MARKER = "# Конец редиректа"

# Артикулы удалённых старых страниц /<артикул>.html
OLD_SKUS = [
    "1SCA112710R1001", "1SCA148926R1001", "1SCA153630R1001", "1SDA038324R1",
    "1SDA058318R1", "1SDA058321R1", "1SDA058323R1", "1SDA059645R1",
    "1SDA062164R1", "1SDA062166R1", "1SDA063141R1", "1SDA063142R1",
    "1SDA066460R1", "1SDA072345R1", "1SDA073674R1", "1SDA073675R1",
    "1SDA073687R1", "1SDA073688R1", "1SDA073711R1", "1SDA073725R1",
    "1SDA073911R1", "1SDA074172R1", "1SDA074173R1", "1SDA074536R1",
    "1SDA104051R1", "1SDA107527R1", "1SDA107531R1", "1SDA107532R1",
    "1SVR730712R1200", "1VCF750090802", "2RCA025340A0001", "3WAA008111P0612",
]

CONTROL = [
    "/index.html", "/sklad.html", "/tovar-1SDA038324R1.html",
    "/contacts.html", "/9999ZZZ.html",
]


def parse_rules():
    """Возвращает список (условия, шаблон, подстановка, флаги)."""
    with open(HTACCESS, encoding="utf-8") as f:
        lines = f.read().splitlines()
    try:
        start = next(i for i, l in enumerate(lines) if l.strip() == START_MARKER)
    except StopIteration:
        sys.exit(f"В .htaccess не найдена строка «{START_MARKER}»")
    rules, conds = [], []
    for line in lines[start + 1:]:
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if parts[0] == "RewriteCond":
            if len(parts) < 3:
                sys.exit(f"Не понимаю условие: {line}")
            conds.append((parts[1], parts[2]))
        elif parts[0] == "RewriteRule":
            if len(parts) < 4:
                sys.exit(f"Не понимаю правило: {line}")
            flags = parts[3].strip("[]").split(",")
            rules.append((conds, parts[1], parts[2], flags))
            conds = []
        else:
            sys.exit(f"Неподдерживаемая директива: {line}")
    if conds:
        sys.exit("RewriteCond без следующей за ним RewriteRule")
    return rules


def expand(s, m):
    s = s.replace("%{DOCUMENT_ROOT}", ROOT)
    return re.sub(r"\$(\d)", lambda x: m.group(int(x.group(1))) or "", s)


def apply(rules, url_path):
    """Возвращает (адрес назначения, код) или None, если переадресации нет."""
    path = url_path.lstrip("/")  # в .htaccess корня шаблон матчится без ведущего слэша
    for conds, pattern, target, flags in rules:
        m = re.search(pattern, path)
        if not m:
            continue
        ok = True
        for test, cond in conds:
            if cond != "-f":
                sys.exit(f"Эмулятор поддерживает только условия -f, а не «{cond}»")
            if not os.path.isfile(expand(test, m)):
                ok = False
                break
        if not ok:
            continue
        code = next((f.split("=")[1] for f in flags if f.startswith("R=")), None)
        return expand(target, m), code
    return None


def main():
    rules = parse_rules()
    if not rules:
        sys.exit("В блоке после редиректа http→https нет ни одной RewriteRule")
    errors = []
    rows = []

    cases = [(f"/{s}.html", s) for s in OLD_SKUS] + \
            [(f"/sklad/{s}.html", s) for s in OLD_SKUS]
    for url, sku in cases:
        res = apply(rules, url)
        rows.append((url, f"{res[0]} [{res[1]}]" if res else "без изменений"))
        expected = f"/tovar-{sku}.html"
        if not res:
            errors.append(f"{url}: нет переадресации")
            continue
        if res[0] != expected:
            errors.append(f"{url}: ведёт на {res[0]}, ожидалось {expected}")
            continue
        if res[1] != "301":
            errors.append(f"{url}: код {res[1]}, ожидался 301")
        page = os.path.join(ROOT, expected.lstrip("/"))
        if not os.path.isfile(page):
            errors.append(f"{url}: страницы {expected} нет")
            continue
        with open(page, encoding="utf-8") as f:
            if f'"sku": "{sku}"' not in f.read():
                errors.append(f'{url}: в {expected} нет "sku": "{sku}"')

    for url in CONTROL:
        res = apply(rules, url)
        rows.append((url, f"{res[0]} [{res[1]}]" if res else "без изменений"))
        if res:
            errors.append(f"{url}: контрольный адрес переадресуется на {res[0]}")

    width = max(len(r[0]) for r in rows)
    for url, dest in rows:
        print(f"{url:<{width}}  →  {dest}")
    print()
    print(f"Правил: {len(rules)}. Адресов: {len(rows)}. Ошибок: {len(errors)}.")
    for e in errors:
        print("  ОШИБКА:", e)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
