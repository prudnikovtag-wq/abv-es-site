#!/usr/bin/env python3
"""Генератор карточек товаров склада abv-es.ru.

Запуск:
    python3 tools/make_tovary.py tools/tovary/novye-tovary.json     # добавить/обновить товары
    python3 tools/make_tovary.py --proverka                          # самопроверка, ничего не пишет

Вход — JSON-массив товаров: {sku, name, qty, price, category, ...}.
  sku      — артикул ABB (латиница/цифры), из него имя страницы tovar-<sku>.html;
  name     — название для сайта;
  qty      — остаток, целое > 0;
  price    — цена в рублях с НДС, целое > 0;
  category — категория склада (как на кнопках-фильтрах sklad.html).
Необязательные поля — условия заказа (нет полей — карточка как раньше, без этих блоков):
  srok      — срок поставки, строка («1–2 недели»): в блоке цены и в «Характеристиках»;
  min_zakaz — минимальная сумма заказа, целое число рублей с НДС или null: в блоке цены,
              в «Характеристиках» и в JSON-LD (offers.eligibleTransactionVolume);
  dostavka  — true/false: панель «Доставка и самовывоз» рядом с «Описанием».
Плитки склада и feed.xml от этих полей не зависят.
Входной JSON — главный: карточка товара из него собирается только по его данным. Если у товара,
у которого на сайте уже есть условия заказа, во входном JSON этих полей нет — блоки условий
с карточки удаляются. Условия товаров, которых во входном JSON нет (их карточки не пересобираются),
для --proverka и служебных проверок берутся из их готовых карточек tovar-<sku>.html.
Поля raw_ru, raw_en игнорируются. На другие незнакомые поля — предупреждение; если незнакомое поле
похоже на поле условий (min_zakas, Srok, «срок», delivery…) — ошибка: иначе условия молча пропадут.
Поля с оптовой/закупочной ценой (имя содержит «opt», «закуп», «wholesale») запрещены: репозиторий
публичный, такой файл генератор не примет — уберите поле из JSON до того, как класть его в репозиторий.
Из строк при загрузке вычищаются управляющие и невидимые символы (переводы строк, табуляции — в пробел).

Что делает (повторный запуск с теми же данными ничего не меняет):
  1. sklad.html — добавляет плитку товара в конец его категории (или обновляет плитку,
     если артикул уже есть), пересчитывает числа на кнопках-фильтрах («Все» и по категориям).
     Новая категория — плитки в конец каталога, кнопка в конец ряда.
  2. tovar-<sku>.html — страница товара. Шапка, меню, стили, подвал, форма заявки, Метрика —
     дословно из эталонной карточки (REFERENCE); подставляются только данные товара.
     «Другие товары со склада» — 4 соседа по каталогу из той же категории
     (ближайшие: предыдущий, следующий, через один…), не хватает — следующие товары каталога.
  3. feed.xml — добавляет/обновляет <offer> (после последнего offer той же категории),
     дату yml_catalog ставит текущую — только если фид реально изменился.
Фото: images/tovar-<sku>.jpg (у уже существующих плиток — путь из sklad.html).
Если файла фото нет — предупреждение, страница всё равно создаётся.

Карточки, которых нет во входном JSON, не трогаются (их блок «Другие товары» не пересчитывается).
--proverka: собирает заново все существующие карточки, плитки sklad.html и offers feed.xml
из данных каталога и сравнивает побайтно с файлами — так видно, что шаблон совпадает с сайтом.
После работы: python3 tools/make_sitemap.py и python3 tools/check_site.py.
"""
import argparse
import datetime
import difflib
import html
import json
import os
import re
import sys
import unicodedata
from urllib.parse import quote

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = "https://abv-es.ru/"
SKLAD = "sklad.html"
FEED = "feed.xml"
REFERENCE = "tovar-1SDA073725R1.html"   # эталон: всё, кроме данных товара, берётся отсюда
RELATED_COUNT = 4
NBSP = "\xa0"

DESC_1 = ("{name} (артикул {sku}) — оригинальное оборудование ABB. АБВ Электросервис — сервисный центр "
          "низковольтного и средневольтного электрооборудования АББ: поставляем запасные части и комплектующие "
          "со склада в Москве и под заказ, помогаем с подбором и идентификацией аппаратов, выполняем монтаж, "
          "ремонт и обслуживание.")
DESC_2 = ("Чтобы уточнить наличие, сроки поставки и совместимость, оставьте запрос, напишите на info@abv-es.ru "
          "или позвоните 8 (800) 250 33 92.")

# Условия заказа (поля srok / min_zakaz / dostavka). Стили — только в карточках с условиями,
# дописываются в конец <style> карточки (остальные карточки остаются байт в байт как эталон).
DOSTAVKA = [("По Москве и Московской области", f"бесплатно при заказе от 50{NBSP}000{NBSP}₽ с{NBSP}НДС", "ok"),
            ("В другие регионы", f"бесплатно при заказе от 50{NBSP}000{NBSP}₽ с{NBSP}НДС", "ok"),
            ("Самовывоз из офиса", "Москва, ул. Профсоюзная, 57", "")]
TERMS_CSS = (
    "/* условия заказа */\n"
    ".abv-product .abv-terms{list-style:none;margin:16px 0 0;padding:14px 0 0;border-top:1px dashed #ffd3ad;"
    "display:flex;flex-direction:column;gap:6px}\n"
    ".abv-product .abv-terms li{position:relative;margin:0;padding:0 0 0 17px;font-size:14px;line-height:1.45;color:var(--t)}\n"
    ".abv-product .abv-terms li:before{content:\"\";position:absolute;left:0;top:.5em;width:7px;height:7px;"
    "border-radius:50%;background:var(--o)}\n"
    ".abv-product .abv-terms b{color:var(--h);font-weight:700;white-space:nowrap}\n"
    ".abv-side{display:grid;gap:28px;min-width:0}\n"
    ".abv-product .abv-side .abv-spec dt{flex:0 1 auto}\n")

GO_SVG = ('<span class="abv-go"><svg fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" '
          'stroke-width="2.4" viewbox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></span>')


# ---------- мелочи ----------

def path(name):
    return os.path.join(ROOT, name)


def read(name):
    """Читает файл как есть (CRLF сохраняются)."""
    with open(path(name), encoding="utf-8", newline="") as f:
        return f.read()


def write_if_changed(name, text):
    p = path(name)
    if os.path.exists(p) and read(name) == text:
        return False
    with open(p, "w", encoding="utf-8", newline="") as f:
        f.write(text)
    return True


def t(s):
    """Экранирование текста в HTML."""
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def a(s):
    """Экранирование значения атрибута в HTML."""
    return t(s).replace('"', "&quot;")


def x(s):
    """Экранирование текста в XML."""
    return t(s)


def money(n, sep=NBSP):
    """36000 -> '36 000' (разделитель разрядов — неразрывный пробел, как на сайте)."""
    return f"{n:,}".replace(",", sep)


def die(msg):
    print("ОШИБКА:", msg, file=sys.stderr)
    sys.exit(1)


class Item:
    __slots__ = ("sku", "name", "qty", "price", "cat", "img", "srok", "min_zakaz", "dostavka")

    def __init__(self, sku, name, qty, price, cat, img, srok=None, min_zakaz=None, dostavka=False):
        self.sku, self.name, self.qty, self.price, self.cat, self.img = sku, name, qty, price, cat, img
        self.srok, self.min_zakaz, self.dostavka = srok, min_zakaz, dostavka

    def key(self):
        return (self.sku, self.name, self.qty, self.price, self.cat, self.img,
                self.srok, self.min_zakaz, self.dostavka)

    def has_terms(self):
        return bool(self.srok or self.min_zakaz or self.dostavka)


# ---------- входные данные ----------

SKU_RE = re.compile(r"[A-Z0-9][A-Z0-9-]*")
KNOWN_KEYS = {"sku", "name", "qty", "price", "category", "srok", "min_zakaz", "dostavka", "raw_ru", "raw_en"}
TERM_KEYS = ("srok", "min_zakaz", "dostavka")
TERM_KEY_ROOTS = ("srok", "zakaz", "zakas", "dostav", "срок", "заказ", "достав", "deliver", "lead", "minorder",
                  "minsum", "pickup", "samovyvoz", "самовывоз")


def looks_like_term_key(k):
    """Незнакомое поле похоже на srok / min_zakaz / dostavka (опечатка, регистр, кириллица)."""
    n = re.sub(r"[\W_]+", "", k.lower())
    if any(r in n for r in TERM_KEY_ROOTS):
        return True
    return any(difflib.SequenceMatcher(None, n, tk.replace("_", "")).ratio() >= 0.75 for tk in TERM_KEYS)


FORBIDDEN_KEY_PARTS = ("opt", "закуп", "wholesale")   # оптовые/закупочные цены — в публичный репозиторий нельзя


def forbidden_keys(obj, prefix=""):
    """Имена полей (на любой глубине), похожие на оптовую/закупочную цену."""
    found = []
    if isinstance(obj, dict):
        for k, v in obj.items():
            name = prefix + str(k)
            if any(p in str(k).lower() for p in FORBIDDEN_KEY_PARTS):
                found.append(name)
            found += forbidden_keys(v, name + ".")
    elif isinstance(obj, list):
        for v in obj:
            found += forbidden_keys(v, prefix)
    return found


def clean(obj):
    """Убирает из строк управляющие и невидимые символы; пробельные (\t, \n, \u2028…) заменяет пробелом."""
    if isinstance(obj, str):
        out = []
        for ch in obj:
            cat = unicodedata.category(ch)
            if ch == " ":
                out.append(ch)
            elif cat in ("Cc", "Zl", "Zp"):
                out.append(" " if ch.isspace() else "")
            elif cat == "Cf":
                continue
            else:
                out.append(ch)
        return "".join(out)
    if isinstance(obj, dict):
        return {k: clean(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [clean(v) for v in obj]
    return obj


def load_input(fname):
    with open(fname, encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, list):
        die(f"{fname}: ожидался JSON-массив")
    bad = forbidden_keys(data)
    if bad:
        die(f"{fname}: есть поля с оптовой/закупочной ценой ({', '.join(sorted(set(bad)))}). "
            "Репозиторий публичный — такие цены туда класть нельзя. Удалите эти поля из JSON "
            "(и не коммитьте файл с ними), затем запустите снова.")
    data = clean(data)
    items, seen, errs = [], set(), []
    unknown = {}                                   # незнакомое поле -> сколько записей
    for d in data:
        if isinstance(d, dict):
            for k in d:
                if k not in KNOWN_KEYS:
                    unknown[k] = unknown.get(k, 0) + 1
    similar = sorted(k for k in unknown if looks_like_term_key(str(k)))
    if similar:
        errs.append("поля похожи на условия заказа, но названы иначе: "
                    + ", ".join(f"«{k}» ({unknown[k]} зап.)" for k in similar)
                    + f" — допустимые имена: {', '.join(TERM_KEYS)}")
    for k in sorted(set(unknown) - set(similar), key=str):
        print(f"ПРЕДУПРЕЖДЕНИЕ: {fname}: незнакомое поле «{k}» ({unknown[k]} зап.) — не используется.",
              file=sys.stderr)
    for n, d in enumerate(data, 1):
        where = f"запись {n} ({d.get('sku', '?') if isinstance(d, dict) else '?'})"
        if not isinstance(d, dict):
            errs.append(f"{where}: не объект")
            continue
        sku = str(d.get("sku", "")).strip()
        name = " ".join(str(d.get("name", "")).split())
        cat = " ".join(str(d.get("category", "")).split())
        qty, price = d.get("qty"), d.get("price")
        if not SKU_RE.fullmatch(sku):
            errs.append(f"{where}: артикул пустой или с недопустимыми символами")
        if sku in seen:
            errs.append(f"{where}: артикул повторяется во входном файле")
        seen.add(sku)
        if not name:
            errs.append(f"{where}: нет названия")
        if not cat:
            errs.append(f"{where}: нет категории")
        if not (isinstance(qty, int) and not isinstance(qty, bool) and qty > 0):
            errs.append(f"{where}: qty должно быть целым > 0 (сейчас {qty!r})")
        if not (isinstance(price, int) and not isinstance(price, bool) and price > 0):
            errs.append(f"{where}: price должно быть целым числом рублей > 0 (сейчас {price!r})")
        srok, min_zakaz, dostavka = d.get("srok"), d.get("min_zakaz"), d.get("dostavka", False)
        if srok is not None:
            srok = " ".join(str(srok).split()) if isinstance(srok, str) else srok
            if not (isinstance(srok, str) and srok):
                errs.append(f"{where}: srok должно быть непустой строкой или отсутствовать (сейчас {d.get('srok')!r})")
        if min_zakaz is not None and not (isinstance(min_zakaz, int) and not isinstance(min_zakaz, bool)
                                          and min_zakaz > 0):
            errs.append(f"{where}: min_zakaz должно быть целым числом рублей > 0 или null (сейчас {min_zakaz!r})")
        if dostavka is None:
            dostavka = False
        if not isinstance(dostavka, bool):
            errs.append(f"{where}: dostavka должно быть true или false (сейчас {dostavka!r})")
        items.append(Item(sku, name, qty, price, cat, f"images/tovar-{sku}.jpg", srok or None, min_zakaz, dostavka))
    if errs:
        die("неверные данные:\n  " + "\n  ".join(errs))
    return items


# ---------- sklad.html ----------

CARDS_OPEN = '<div class="abv-cards">'
CARD_RE = re.compile(r'<div><a class="abv-pcard" .*?</a></div>', re.S)
CHIPS_RE = re.compile(r'(<div class="abv-chips">)(.*?)(</div>)', re.S)


def parse_card(c):
    def g(rx):
        m = re.search(rx, c, re.S)
        if not m:
            die(f"не разобрана плитка в {SKLAD}: {c[:150]}")
        return m.group(1)
    sku = g(r'href="tovar-([^"]+)\.html"')
    price = int(g(r'class="abv-price">([\d\xa0 ]+)\xa0₽').replace(NBSP, "").replace(" ", ""))
    return Item(sku=sku,
                name=html.unescape(g(r'class="abv-pcard-name"[^>]*>(.*?)</p>')),
                qty=int(g(r'class="abv-badge">(\d+) шт\.<')),
                price=price,
                cat=html.unescape(g(r'data-cat="([^"]*)"')),
                img=html.unescape(g(r'<img [^>]*src="([^"]+)"')))


def split_sklad(s):
    """-> (до плиток, [плитки], после плиток)."""
    i = s.index(CARDS_OPEN) + len(CARDS_OPEN)
    cards = []
    j = i
    while True:
        m = CARD_RE.match(s, j)
        if not m:
            break
        cards.append(m.group(0))
        j = m.end()
    if not cards or not s.startswith("</div>", j):
        die(f"{SKLAD}: не найден ожидаемый список плиток")
    return s[:i], cards, s[j:]


def render_tile(it, catalog=True):
    """Плитка товара. catalog=True — для sklad.html (data-cat, data-q, alt), иначе — для «Других товаров»."""
    attrs = (f' data-cat="{a(it.cat)}" data-q="{a(it.sku + " " + it.name + " " + it.cat)}"' if catalog else "")
    alt = a(it.name) if catalog else ""
    return (f'<div><a class="abv-pcard"{attrs} href="tovar-{it.sku}.html"><div class="abv-pcard-img">'
            f'<span class="abv-badge">{it.qty} шт.</span><img alt="{alt}" loading="lazy" src="{a(it.img)}" '
            f'style="view-transition-name:p-{it.sku}"/></div><div class="abv-pcard-body">'
            f'<p class="abv-pcard-cat">{t(it.cat)}</p>'
            f'<p class="abv-pcard-name" style="view-transition-name:t-{it.sku}">{t(it.name)}</p>'
            f'<p class="abv-pcard-code">Арт. {it.sku}</p><div class="abv-pcard-foot">'
            f'<p class="abv-price">{money(it.price)}{NBSP}₽ <small>с НДС</small></p>{GO_SVG}</div></div></a></div>')


def render_chips(items):
    order = []
    for it in items:
        if it.cat not in order:
            order.append(it.cat)
    out = f'<button class="abv-chip on" data-cat="" type="button">Все<span>{len(items)}</span></button>'
    for c in order:
        n = sum(1 for it in items if it.cat == c)
        out += f'<button class="abv-chip" data-cat="{a(c)}" type="button">{t(c)}<span>{n}</span></button>'
    return out


def render_sklad(s, items):
    head, _, tail = split_sklad(s)
    s = head + "".join(render_tile(it) for it in items) + tail
    if len(CHIPS_RE.findall(s)) != 1:
        die(f"{SKLAD}: блок кнопок-фильтров не найден или их несколько")
    return CHIPS_RE.sub(lambda m: m.group(1) + render_chips(items) + m.group(3), s)


def upsert(catalog, new_items):
    """Вставляет/обновляет товары в списке каталога. Возвращает (новый список, добавлено, обновлено)."""
    items = list(catalog)
    added, updated = [], []
    for it in new_items:
        idx = next((i for i, c in enumerate(items) if c.sku == it.sku), None)
        if idx is not None:
            old = items[idx]
            it.img = old.img          # у существующего товара фото уже есть — путь не меняем
            if old.key() != it.key():
                updated.append(it.sku)
            if old.cat == it.cat:
                items[idx] = it
                continue
            del items[idx]
        else:
            added.append(it.sku)
        last = max((i for i, c in enumerate(items) if c.cat == it.cat), default=None)
        if last is None:
            items.append(it)
        else:
            items.insert(last + 1, it)
    return items, added, updated


def related(it, items):
    same = [c for c in items if c.cat == it.cat]
    i = next(k for k, c in enumerate(same) if c.sku == it.sku)
    out = []
    for d in range(1, len(same)):
        for j in (i - d, i + d):
            if 0 <= j < len(same):
                out.append(same[j])
    out = out[:RELATED_COUNT]
    if len(out) < RELATED_COUNT:          # мало товаров в категории — добираем следующими по каталогу
        last = max(k for k, c in enumerate(items) if c.cat == it.cat)
        for d in range(1, len(items)):
            c = items[(last + d) % len(items)]
            if c.cat != it.cat and c not in out:
                out.append(c)
            if len(out) == RELATED_COUNT:
                break
    return out


# ---------- страница товара ----------

def render_page(ref, it, items):
    nl = "\r\n" if "\r\n" in ref else "\n"
    url = f"{SITE}tovar-{it.sku}.html"
    img_abs = SITE + it.img.split("?")[0]          # в абсолютных адресах фото — без ?v=
    title = f"{it.name} ({it.sku}) — купить | АБВ Электросервис"
    descr = (f"{it.name}, артикул {it.sku}. В наличии {it.qty} шт. Цена {money(it.price)} ₽ с НДС. "
             f"Оригинал ABB, склад в Москве.")
    d1, d2 = DESC_1.format(name=it.name, sku=it.sku), DESC_2
    ld = {"@context": "https://schema.org", "@type": "Product", "name": it.name, "sku": it.sku, "mpn": it.sku,
          "brand": {"@type": "Brand", "name": "ABB"}, "category": it.cat, "url": url,
          "description": d1 + " " + d2, "image": [img_abs],
          "offers": {"@type": "Offer", "price": it.price, "priceCurrency": "RUB",
                     "availability": "https://schema.org/InStock",
                     "itemCondition": "https://schema.org/NewCondition", "url": url,
                     "seller": {"@type": "Organization", "name": "ООО «АБВ Электросервис»"}}}
    if it.min_zakaz:   # минимальная сумма заказа (schema.org Offer.eligibleTransactionVolume)
        ld["offers"]["eligibleTransactionVolume"] = {"@type": "PriceSpecification", "minPrice": it.min_zakaz,
                                                     "priceCurrency": "RUB", "valueAddedTaxIncluded": True}
    ld_json = json.dumps(ld, ensure_ascii=False).replace("<", "\\u003c")  # «<» внутри <script> — всегда экранировать
    head_line = (f'<meta content="product" property="og:type"/><meta content="{a(title)}" property="og:title"/>'
                 f'<meta content="{a(descr)}" property="og:description"/><meta content="{url}" property="og:url"/>'
                 f'<meta content="АБВ Электросервис" property="og:site_name"/><meta content="ru_RU" property="og:locale"/>'
                 f'<link href="{url}" rel="canonical"/><meta content="{a(img_abs)}" property="og:image"/>'
                 f'<script type="application/ld+json">{ld_json}</script></head>')

    subj = quote(f"Запрос: {it.sku} — {it.name}", safe="/")
    body = quote(f"Прошу уточнить наличие и цену:\n{it.sku} — {it.name}\nКоличество: \n", safe="/")
    price_txt = money(it.price)
    min_txt = f"от {money(it.min_zakaz)}{NBSP}₽ с{NBSP}НДС" if it.min_zakaz else ""
    terms = []
    if it.min_zakaz:
        terms.append(f"<li>Минимальная сумма заказа — <b>{min_txt}</b></li>")
    if it.srok:
        terms.append(f"<li>Срок поставки: <b>{t(it.srok)}</b></li>")
    spec = lambda k, v, cls="": (f'<div class="abv-spec"><dt>{k}</dt><span class="abv-lead"></span>'
                                 f'<dd class="{cls}">{v}</dd></div>')
    shield = ('<svg fill="none" stroke="#2fa652" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" '
              'viewbox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"></path>'
              '<path d="M9 12l2 2 4-4"></path></svg>')
    copy_svg = ('<svg fill="none" stroke="#F5821E" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" '
                'viewbox="0 0 24 24"><rect height="11" rx="2" width="11" x="9" y="9"></rect>'
                '<path d="M5 15V5a2 2 0 0 1 2-2h8"></path></svg>')
    lines = [
        f'<nav class="abv-crumbs"><a href="index.html">Главная</a><span>/</span><a href="sklad.html">Склад</a>'
        f'<span>/</span><span>{it.sku}</span></nav>',
        '<div class="abv-hero">',
        f'<div class="abv-gal"><div class="abv-main-img"><span class="abv-chip-orig">{shield}Оригинал ABB</span>'
        f'<img alt="{a(it.name)}" src="{a(it.img)}" style="view-transition-name:p-{it.sku}"/></div></div>',
        '<div>',
        f'<p class="abv-eyebrow">{t(it.cat)}</p>',
        f'<h1 class="abv-title" style="view-transition-name:t-{it.sku}">{t(it.name)}</h1>',
        '<div class="abv-meta">',
        f'<span class="abv-sku">Артикул <b>{it.sku}</b><span class="abv-copy" data-copy="{it.sku}" role="button" '
        f'title="Скопировать артикул">{copy_svg}</span></span>',
        '<span class="abv-brand">ABB</span>',
        '</div>',
        f'<p class="abv-stock"><i></i>В наличии {it.qty} шт. · склад в Москве</p>',
        '<div class="abv-pricebox">',
        f'<p class="abv-price-lbl">Цена за 1 шт.</p><p class="abv-bigprice">{price_txt}{NBSP}<span>₽</span>'
        f'<small>с НДС</small></p>',
    ]
    if terms:
        lines.append('<ul class="abv-terms">' + "".join(terms) + "</ul>")
    lines += [
        '<div class="abv-actions">',
        f'<a class="abv-btn abv-btn-main" data-code="{it.sku}" data-name="{a(it.name)}" '
        f'href="mailto:info@abv-es.ru?subject={subj}&amp;body={body}" onclick="abvRequest(event)">ЗАПРОСИТЬ</a>',
    ]
    # Неизменяемая часть (кнопки звонка/Telegram, подсказка, блок преимуществ) — из эталона.
    lines.append(REF_PARTS["actions_tail"])
    desc = (f'<div class="abv-panel"><h2 class="abv-h2">Описание</h2><div class="abv-desc"><p>{t(d1)}</p>'
            f'<p>{t(d2)}</p></div></div>')
    if it.dostavka:
        desc = nl.join(['<div class="abv-side">', desc,
                        '<div class="abv-panel"><h2 class="abv-h2">Доставка и самовывоз</h2><dl class="abv-specs">'
                        + "".join(spec(k, v, c) for k, v, c in DOSTAVKA) + "</dl></div>",
                        '</div>'])
    lines += [
        '<div class="abv-info">',
        '<div class="abv-panel"><h2 class="abv-h2">Характеристики</h2><dl class="abv-specs">'
        + spec("Артикул", it.sku) + spec("Производитель", "ABB") + spec("Категория", t(it.cat))
        + spec("Наличие", f"{it.qty} шт.", "ok") + spec("Склад", "Москва, ул. Профсоюзная, 57")
        + (spec("Срок поставки", t(it.srok)) if it.srok else "")
        + spec("Цена", f"{price_txt}{NBSP}₽ с НДС", "pr")
        + (spec("Минимальный заказ", min_txt, "pr") if it.min_zakaz else "") + "</dl>",
        REF_PARTS["note"],
        desc,
        '</div>',
        '<div class="abv-psec"><h2 class="abv-h2">Другие товары со склада</h2><div class="abv-rel">'
        + "".join(render_tile(r, catalog=False) for r in related(it, items)) + "</div></div>",
    ]
    block = nl.join(lines)

    out = ref
    out = sub_once(out, r"<title>.*?</title>", f"<title>{t(title)}</title>", "title")
    out = sub_once(out, r'<meta content="[^"]*" name="description"/>',
                   f'<meta content="{a(descr)}" name="description"/>', "meta description")
    out = sub_once(out, r'<meta content="product" property="og:type"/>.*?</head>', head_line, "og/ld+json")
    out = sub_once(out, r'<nav class="abv-crumbs">.*?(?=\r?\n</div>\r?\n<script>\r?\ndocument\.querySelectorAll)',
                   block, "тело карточки")
    if it.has_terms():
        css = TERMS_CSS.replace("\n", nl)
        out = sub_once(out, r"</style>", css + "</style>", "</style> карточки")
    return out


def terms_from_page(it):
    """Условия заказа уже готовой карточки (для товаров, которых нет во входном JSON, и для --proverka)."""
    name = f"tovar-{it.sku}.html"
    if not os.path.exists(path(name)):
        return
    pg = read(name)
    def spec_val(k):
        m = re.search(r'<dt>' + re.escape(k) + r'</dt><span class="abv-lead"></span><dd class="[^"]*">(.*?)</dd>', pg)
        return html.unescape(m.group(1)) if m else None
    it.srok = spec_val("Срок поставки")
    mz = spec_val("Минимальный заказ")
    it.min_zakaz = int(re.sub(r"\D", "", mz)) if mz else None
    it.dostavka = '<h2 class="abv-h2">Доставка и самовывоз</h2>' in pg


REF_PARTS = {}


def load_reference(ref):
    """Достаёт из эталона неизменяемые куски тела карточки и проверяет, что он нужной структуры."""
    m = re.search(r'onclick="abvRequest\(event\)">ЗАПРОСИТЬ</a>\r?\n(.*?)\r?\n<div class="abv-info">', ref, re.S)
    n = re.search(r'</dl>\r?\n(<p class="abv-note">.*?</p></div>)\r?\n', ref, re.S)
    if not m or not n:
        die(f"эталон {REFERENCE} не похож на карточку товара (не найдены блоки кнопок/примечания)")
    REF_PARTS["actions_tail"] = m.group(1)
    REF_PARTS["note"] = n.group(1)


def sub_once(s, rx, repl, what):
    new, cnt = re.subn(rx, lambda _m: repl, s, count=0, flags=re.S)
    if cnt != 1:
        die(f"в эталоне {REFERENCE} блок «{what}» найден {cnt} раз (ожидался 1)")
    return new


# ---------- feed.xml ----------

OFFER_RE = re.compile(r'      <offer id="([^"]+)"[^>]*>.*?</offer>\r?\n', re.S)


def render_offer(it, cid, nl):
    d = DESC_1.format(name=it.name, sku=it.sku) + " " + DESC_2
    rows = [
        f'      <offer id="{it.sku}" available="true">',
        f"        <name>{x(it.name)}</name>",
        "        <vendor>ABB</vendor>",
        f"        <vendorCode>{it.sku}</vendorCode>",
        f"        <url>{SITE}tovar-{it.sku}.html</url>",
        f"        <price>{it.price}</price>",
        "        <currencyId>RUR</currencyId>",
        f"        <categoryId>{cid}</categoryId>",
        f"        <picture>{x(SITE + it.img.split('?')[0])}</picture>",
        f"        <description>{x(d)}</description>",
        f"        <count>{it.qty}</count>",
        "        <manufacturer_warranty>true</manufacturer_warranty>",
        f'        <param name="Артикул">{it.sku}</param>',
        "      </offer>",
    ]
    return nl.join(rows) + nl


def feed_categories(f):
    return {html.unescape(name): cid for cid, name in re.findall(r'<category id="(\d+)">(.*?)</category>', f)}


def update_feed(f, new_items):
    nl = "\r\n" if "\r\n" in f else "\n"
    cats = feed_categories(f)
    for it in new_items:
        if it.cat not in cats:
            cid = str(max([int(v) for v in cats.values()] + [0]) + 1)
            line = f'      <category id="{cid}">{x(it.cat)}</category>{nl}'
            f = f.replace("    </categories>", line + "    </categories>", 1)
            cats[it.cat] = cid
    inv = {v: k for k, v in cats.items()}
    for it in new_items:
        offer = render_offer(it, cats[it.cat], nl)
        offers = list(OFFER_RE.finditer(f))
        same = next((m for m in offers if m.group(1) == it.sku), None)
        if same:
            old_cat = re.search(r"<categoryId>(\d+)</categoryId>", same.group(0))
            if old_cat and inv.get(old_cat.group(1)) == it.cat:
                f = f[:same.start()] + offer + f[same.end():]
                continue
            f = f[:same.start()] + f[same.end():]
            offers = list(OFFER_RE.finditer(f))
        in_cat = [m for m in offers if f"<categoryId>{cats[it.cat]}</categoryId>" in m.group(0)]
        pos = in_cat[-1].end() if in_cat else (offers[-1].end() if offers else f.index("<offers>") + 8 + len(nl))
        f = f[:pos] + offer + f[pos:]
    return f


def set_feed_date(f):
    now = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=3)))  # Москва
    return re.sub(r'(<yml_catalog date=")[^"]*(")', lambda m: m.group(1) + now.isoformat(timespec="seconds") + m.group(2),
                  f, count=1)


# ---------- самопроверка ----------

def strip_related(page):
    return re.sub(r'<div class="abv-rel">.*?</div></div>\r?\n', "", page, flags=re.S)


def proverka():
    s = read(SKLAD)
    ref = read(REFERENCE)
    load_reference(ref)
    _, cards, _ = split_sklad(s)
    items = [parse_card(c) for c in cards]
    for it in items:
        terms_from_page(it)
    bad = rel_only = 0
    if render_sklad(s, items) != s:
        print(f"РАЗЛИЧИЕ: {SKLAD} — плитки/кнопки собираются не так, как в файле")
        bad += 1
    for it in items:
        name = f"tovar-{it.sku}.html"
        if not os.path.exists(path(name)):
            print(f"нет файла {name}")
            bad += 1
            continue
        got, have = render_page(ref, it, items), read(name)
        if got != have:
            if strip_related(got) == strip_related(have):
                # блок «Другие товары» на сайте местами собран иначе (исторически) — не ошибка шаблона
                print(f"к сведению: {name} — отличается только блок «Другие товары»")
                rel_only += 1
            else:
                print(f"РАЗЛИЧИЕ: {name}")
                bad += 1
    f = read(FEED)
    cats = feed_categories(f)
    nl = "\r\n" if "\r\n" in f else "\n"
    offers = {m.group(1): m.group(0) for m in OFFER_RE.finditer(f)}
    for it in items:
        if it.cat not in cats or offers.get(it.sku) != render_offer(it, cats[it.cat], nl):
            print(f"РАЗЛИЧИЕ: {FEED}, offer {it.sku}")
            bad += 1
    extra = set(offers) - {it.sku for it in items}
    if extra:
        print(f"в {FEED} есть offer без плитки в {SKLAD}: {', '.join(sorted(extra))}")
        bad += 1
    print(f"Самопроверка: товаров {len(items)}, расхождений шаблона {bad}, "
          f"карточек с иным блоком «Другие товары» {rel_only}.")
    return 1 if bad else 0


# ---------- главное ----------

def main():
    ap = argparse.ArgumentParser(description="Генератор карточек товаров склада abv-es.ru")
    ap.add_argument("json", nargs="?", help="JSON-массив товаров")
    ap.add_argument("--proverka", action="store_true", help="только самопроверка шаблона, ничего не пишет")
    args = ap.parse_args()
    if args.proverka:
        sys.exit(proverka())
    if not args.json:
        ap.error("укажите путь к JSON с товарами (или --proverka)")

    new_items = load_input(args.json)
    ref = read(REFERENCE)
    load_reference(ref)

    s = read(SKLAD)
    _, cards, _ = split_sklad(s)
    catalog = [parse_card(c) for c in cards]
    for c in catalog:
        terms_from_page(c)
    items, added, updated = upsert(catalog, new_items)
    new_cats = sorted({it.cat for it in new_items} - {c.cat for c in catalog})

    changed = []
    if write_if_changed(SKLAD, render_sklad(s, items)):
        changed.append(SKLAD)
    for it in new_items:
        name = f"tovar-{it.sku}.html"
        if write_if_changed(name, render_page(ref, it, items)):
            changed.append(name)
    f_old = read(FEED)
    f_new = update_feed(f_old, new_items)
    if f_new != f_old and write_if_changed(FEED, set_feed_date(f_new)):
        changed.append(FEED)

    print(f"Товаров во входном файле: {len(new_items)}; новых: {len(added)}, изменённых: {len(updated)}; "
          f"всего в каталоге: {len(items)}.")
    if new_cats:
        print("Новые категории (кнопка добавлена в конец):", "; ".join(new_cats))
    print("Изменены файлы:" if changed else "Изменений нет.", " ".join(changed))
    missing = [it.img for it in new_items if not os.path.exists(path(it.img))]
    for m in missing:
        print(f"ПРЕДУПРЕЖДЕНИЕ: нет фото {m} — страница и фид ссылаются на него, загрузите файл до выкатки.")
    # Каких существующих карточек коснулся бы пересчёт «Других товаров» (сами они не правятся).
    ids = {it.sku for it in new_items}
    stale = [c.sku for c in items if c.sku not in ids and os.path.exists(path(f"tovar-{c.sku}.html"))
             and render_page(ref, c, items) != read(f"tovar-{c.sku}.html")]
    if stale:
        print("К сведению: у этих карточек блок «Другие товары» теперь отличается от расчётного "
              "(не правились):", ", ".join(stale))
    if changed:
        print("Дальше: python3 tools/make_sitemap.py && python3 tools/check_site.py " + " ".join(changed[:-1] if FEED in changed else changed))


if __name__ == "__main__":
    main()
