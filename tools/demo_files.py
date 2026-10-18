#!/usr/bin/env python3
"""Собирает список файлов для демо-ссылки (Artifact) по одной или нескольким страницам.

Запуск:  python3 tools/demo_files.py index.html [contacts.html ...]
         python3 tools/demo_files.py --copy index.html   ← рекомендуемый способ

С --copy файлы копируются в out/demo/ (пути сохраняются), а из HTML вырезается
счётчик Яндекс.Метрики — чтобы просмотры демо не попадали в статистику сайта.
Тогда публиковать: file_path = out/demo/<страница>, files = напечатанный JSON, root = out/demo.
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


METRIKA = re.compile(r"<!--\s*Yandex\.Metrika counter\s*-->.*?<!--\s*/Yandex\.Metrika counter\s*-->", re.S | re.I)


def main():
    args = sys.argv[1:]
    copy = "--copy" in args
    args = [a for a in args if a != "--copy"]
    files = {}
    for page in args:
        page = page.lstrip("./")
        files[page] = page
        for ref in local_refs(page):
            if os.path.isfile(os.path.join(ROOT, ref)):
                files[ref] = ref
                if ref.endswith(".css"):
                    for sub in css_refs(ref):
                        if os.path.isfile(os.path.join(ROOT, sub)):
                            files[sub] = sub
    if copy:
        import shutil
        out = os.path.join(ROOT, "out", "demo")
        shutil.rmtree(out, ignore_errors=True)
        for rel in files:
            dst = os.path.join(out, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            if rel.lower().endswith((".html", ".htm")):
                with open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace") as fh:
                    html = METRIKA.sub("<!-- Метрика убрана в демо -->", fh.read())
                with open(dst, "w", encoding="utf-8") as fh:
                    fh.write(html)
            elif rel.lower().endswith(".svg"):
                # Хостинг демо не принимает SVG с <!DOCTYPE> — для показа он не нужен.
                with open(os.path.join(ROOT, rel), encoding="utf-8", errors="replace") as fh:
                    svg = re.sub(r"<!DOCTYPE[^>\[]*(\[.*?\])?\s*>", "", fh.read(), flags=re.S | re.I)
                with open(dst, "w", encoding="utf-8") as fh:
                    fh.write(svg)
            elif rel.lower().endswith((".js", ".css")):
                # Часть файлов сайта в windows-1251 (например, menu.js) — демо требует UTF-8.
                raw = open(os.path.join(ROOT, rel), "rb").read()
                try:
                    text = raw.decode("utf-8")
                except UnicodeDecodeError:
                    text = raw.decode("cp1251")
                with open(dst, "w", encoding="utf-8", newline="") as fh:
                    fh.write(text)
            else:
                shutil.copy2(os.path.join(ROOT, rel), dst)
        # пути для параметра files: относительно out/demo (root)
        files = {k: k for k in files}
    print(json.dumps(files, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
