#!/usr/bin/env python3
"""Выгрузка товаров склада в стартовый файл админки: admin/inc/tovary-start.json.

Запуск:  python3 tools/export_tovary.py

Берёт каталог из sklad.html (порядок, названия, количество, цены, разделы, фото) и условия заказа
из карточек tovar-<артикул>.html — теми же функциями, что и генератор make_tovary.py.
Админка при первом запуске на сайте берёт товары из этого файла; дальше живые данные хранятся
на хостинге (admin-data/), а не в репозитории. Перезапускать только до первого запуска админки.
"""
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import make_tovary as mt  # noqa: E402

OUT = os.path.join(mt.ROOT, "admin", "inc", "tovary-start.json")


def main():
    s = mt.read(mt.SKLAD)
    _, cards, _ = mt.split_sklad(s)
    items = [mt.parse_card(c) for c in cards]
    for it in items:
        mt.terms_from_page(it)
    m = re.search(r'id="dateStock">Остатки товаров на (.*?)</p>', s)
    if not m:
        mt.die("в sklad.html не найдена дата остатков (id=dateStock)")
    feed = mt.read(mt.FEED)
    fd = re.search(r'<yml_catalog date="([^"]*)"', feed)
    data = {
        "ostatki": m.group(1),
        "feed_date": fd.group(1) if fd else "",
        "feed_cats": {k: int(v) for k, v in mt.feed_categories(feed).items()},
        "tovary": [{"sku": it.sku, "name": it.name, "qty": it.qty, "price": it.price, "cat": it.cat,
                    "img": it.img, "srok": it.srok, "min_zakaz": it.min_zakaz, "dostavka": it.dostavka}
                   for it in items],
    }
    with open(OUT, "w", encoding="utf-8", newline="\n") as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
        f.write("\n")
    print(f"{os.path.relpath(OUT, mt.ROOT)}: {len(items)} товаров, остатки на {data['ostatki']}")


if __name__ == "__main__":
    main()
