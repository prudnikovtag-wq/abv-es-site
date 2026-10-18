#!/usr/bin/env python3
"""Проверка статичного сайта abv-es.ru без браузера.

Запуск:  python3 tools/check_site.py [страница.html ...]
Без аргументов проверяет все .html сайта.

Что ищет:
  ОШИБКИ  — ссылки/картинки/скрипты на файлы, которых нет в репозитории;
            страницы без <title> или без meta viewport;
            подключения по http:// (браузер их заблокирует на https-сайте).
  ЗАМЕЧАНИЯ — картинки без alt, страницы без meta description,
            подключения с новых внешних доменов (не из списка известных).
Код выхода 1, если есть ошибки.
"""
import os
import re
import sys
from html.parser import HTMLParser
from urllib.parse import urlparse, unquote

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKIP_DIRS = {".git", ".claude", "tools", "out", "node_modules"}

# Внешние домены, которые сайт уже использует. Новый домен = замечание ревизору.
KNOWN_DOMAINS = {
    "fonts.googleapis.com", "fonts.gstatic.com", "mc.yandex.ru", "yandex.ru",
    "formsubmit.co", "t.me", "max.ru", "abv-es.ru", "www.abv-es.ru",
    "cdn.jsdelivr.net", "new.abb.com", "global.abb", "library.e.abb.com",
    "wa.me", "vk.com", "api-maps.yandex.ru", "yandex.ru",
}


class Collector(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.refs = []          # (тег, атрибут, значение, строка)
        self.title = False
        self.viewport = False
        self.description = False
        self.img_no_alt = []
        self._in_title = False
        self._title_text = ""

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        line = self.getpos()[0]
        for attr in ("href", "src", "data-src", "poster"):
            if a.get(attr):
                self.refs.append((tag, attr, a[attr].strip(), line))
        if a.get("srcset"):
            for part in a["srcset"].split(","):
                url = part.strip().split(" ")[0]
                if url:
                    self.refs.append((tag, "srcset", url, line))
        if tag == "title":
            self._in_title = True
        if tag == "meta":
            name = (a.get("name") or "").lower()
            if name == "viewport":
                self.viewport = True
            if name == "description" and (a.get("content") or "").strip():
                self.description = True
        if tag == "img" and "alt" not in a:
            self.img_no_alt.append((a.get("src", "?"), line))

    def handle_endtag(self, tag):
        if tag == "title":
            self._in_title = False
            if self._title_text.strip():
                self.title = True

    def handle_data(self, data):
        if self._in_title:
            self._title_text += data


def resolve(page_rel, url):
    """Путь к локальному файлу так, как его увидит браузер на сервере."""
    if url.startswith("/"):
        parts = []
        base = []
    else:
        base = page_rel.split("/")[:-1]
        parts = base[:]
    for seg in url.split("/"):
        if seg in ("", "."):
            continue
        if seg == "..":
            if parts:
                parts.pop()   # выше корня сайта браузер не уходит
            continue
        parts.append(seg)
    return "/".join(parts)


def all_pages():
    pages = []
    for d, dirs, files in os.walk(ROOT):
        dirs[:] = [x for x in dirs if x not in SKIP_DIRS]
        for f in files:
            if f.lower().endswith((".html", ".htm")):
                pages.append(os.path.relpath(os.path.join(d, f), ROOT).replace(os.sep, "/"))
    return sorted(pages)


def check_page(page):
    errors, warns = [], []
    path = os.path.join(ROOT, page)
    with open(path, encoding="utf-8", errors="replace") as fh:
        html = fh.read()
    c = Collector()
    c.feed(html)

    if not c.title:
        errors.append("нет <title>")
    if not c.viewport:
        errors.append("нет <meta name=\"viewport\"> — страница плохо выглядит на телефоне")
    if not c.description:
        warns.append("нет meta description")
    for src, line in c.img_no_alt[:5]:
        warns.append(f"стр.{line}: картинка без alt: {src}")
    if len(c.img_no_alt) > 5:
        warns.append(f"…и ещё {len(c.img_no_alt) - 5} картинок без alt")

    for tag, attr, url, line in c.refs:
        low = url.lower()
        if low.startswith(("mailto:", "tel:", "javascript:", "data:", "#", "sms:", "viber:", "whatsapp:")):
            continue
        p = urlparse(url)
        if p.scheme in ("http", "https") or url.startswith("//"):
            host = p.netloc.lower()
            if p.scheme == "http" and tag in ("script", "link", "img", "iframe", "source"):
                errors.append(f"стр.{line}: подключение по http:// (заблокируется на https): {url}")
            if host and host not in KNOWN_DOMAINS and tag in ("script", "link", "iframe", "img", "source"):
                warns.append(f"стр.{line}: новый внешний домен {host} ({tag})")
            continue
        if p.scheme:
            continue
        local = unquote(p.path)
        if not local:
            continue
        target = resolve(page, local)
        full = os.path.join(ROOT, target)
        if local.endswith("/") or os.path.isdir(full):
            full = os.path.join(full, "index.html")
        if not os.path.exists(full):
            errors.append(f"стр.{line}: нет файла «{target or '/'}» ({tag} {attr}=\"{url}\")")
    return errors, warns


def main():
    pages = sys.argv[1:] or all_pages()
    total_e = total_w = 0
    for page in pages:
        page = page.replace("\\", "/").lstrip("./")
        e, w = check_page(page)
        if e or w:
            print(f"\n== {page}")
            for x in e:
                print(f"  ОШИБКА: {x}")
            for x in w:
                print(f"  замечание: {x}")
        total_e += len(e)
        total_w += len(w)
    print(f"\nПроверено страниц: {len(pages)}. Ошибок: {total_e}. Замечаний: {total_w}.")
    sys.exit(1 if total_e else 0)


if __name__ == "__main__":
    main()
