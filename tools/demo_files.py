#!/usr/bin/env python3
"""Собирает список файлов для демо-ссылки (Artifact) по одной или нескольким страницам.

Запуск:  python3 tools/demo_files.py index.html [contacts.html ...]
Печатает JSON {"опубликованный/путь": "путь/в/репозитории"} — его передают в
параметр files при публикации демо. Берёт саму страницу и все локальные файлы,
на которые она ссылается (CSS, JS, картинки), плюс картинки и шрифты из url() в CSS.
Ссылки на другие HTML-страницы не включаются (демо показывает только изменённые).
"""
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from check_site import Collector, resolve, ROOT  # noqa: E402

ASSET_EXT = (".css", ".js", ".png", ".jpg", ".jpeg", ".webp", ".gif", ".svg", ".ico",
             ".woff", ".woff2", ".ttf", ".otf", ".mp4", ".webm")


def local_refs(page):
    with open(os.path.join(ROOT, page), encoding="utf-8", errors="replace") as fh:
        c = Collector()
        c.feed(fh.read())
    for _tag, _attr, url, _line in c.refs:
        if re.match(r"^[a-z]+:|^//|^#", url, re.I):
            continue
        clean = url.split("?")[0].split("#")[0]
        if clean.lower().endswith(ASSET_EXT):
            yield resolve(page, clean)


def css_refs(css_rel):
    with open(os.path.join(ROOT, css_rel), encoding="utf-8", errors="replace") as fh:
        css = fh.read()
    for m in re.finditer(r"url\(\s*['\"]?([^'\")]+)['\"]?\s*\)", css):
        u = m.group(1).split("?")[0].split("#")[0]
        if re.match(r"^[a-z]+:|^//", u, re.I):
            continue
        yield resolve(css_rel, u)
    for m in re.finditer(r"@import\s+['\"]([^'\"]+)['\"]", css):
        if not re.match(r"^[a-z]+:|^//", m.group(1), re.I):
            yield resolve(css_rel, m.group(1))


def main():
    files = {}
    for page in sys.argv[1:]:
        page = page.lstrip("./")
        files[page] = page
        for ref in local_refs(page):
            if os.path.isfile(os.path.join(ROOT, ref)):
                files[ref] = ref
                if ref.endswith(".css"):
                    for sub in css_refs(ref):
                        if os.path.isfile(os.path.join(ROOT, sub)):
                            files[sub] = sub
    print(json.dumps(files, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
