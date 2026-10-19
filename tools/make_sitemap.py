#!/usr/bin/env python3
"""Собирает sitemap.xml в корне сайта abv-es.ru.

Запуск:  python3 tools/make_sitemap.py

Берёт все *.html из корня репозитория, кроме EXCLUDE и страниц с <meta name="robots" content="...noindex...">.
Адреса: https://abv-es.ru/<файл>, для index.html — https://abv-es.ru/.
Порядок: главная, затем разделы по алфавиту, затем tovar-*.html по алфавиту. Без lastmod/priority.
Перезапускать при добавлении/удалении страниц или товаров.
"""
import os
import re
from xml.sax.saxutils import escape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = "https://abv-es.ru/"
EXCLUDE = {"source.html"}  # старая страница, ни откуда не ссылается

META_RE = re.compile(r"<meta\b[^>]*>", re.I)
NOINDEX_RE = re.compile(r"""\bname\s*=\s*["']?robots\b""", re.I)


def is_noindex(path):
    with open(path, encoding="utf-8", errors="replace") as f:
        text = f.read()
    for tag in META_RE.findall(text):
        if NOINDEX_RE.search(tag) and re.search(r"noindex|\bnone\b", tag, re.I):
            return True
    return False


def main():
    pages = sorted(f for f in os.listdir(ROOT)
                   if f.endswith(".html") and os.path.isfile(os.path.join(ROOT, f)) and f not in EXCLUDE)
    skipped = [f for f in pages if is_noindex(os.path.join(ROOT, f))]
    pages = [f for f in pages if f not in skipped]
    home = [f for f in pages if f == "index.html"]
    tovary = [f for f in pages if f.startswith("tovar-")]
    razdely = [f for f in pages if f not in home and f not in tovary]
    urls = [SITE] * len(home) + [SITE + f for f in razdely + tovary]

    lines = ['<?xml version="1.0" encoding="UTF-8"?>',
             '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    lines += [f"  <url><loc>{escape(u)}</loc></url>" for u in urls]
    lines.append("</urlset>")
    with open(os.path.join(ROOT, "sitemap.xml"), "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines) + "\n")

    print(f"sitemap.xml: {len(urls)} адресов (главная {len(home)}, разделы {len(razdely)}, товары {len(tovary)})")
    if skipped:
        print("Пропущены (noindex):", ", ".join(skipped))


if __name__ == "__main__":
    main()
