#!/usr/bin/env bash
# Готовит пакет «ОТДАТЬ» — только те файлы, которые изменились с версии,
# которая сейчас лежит на сайте (ветка git `na-saite`).
#
# Запуск:  bash tools/package.sh
# Результат: out/otdat-ГГГГ-ММ-ДД/  — файлы в тех же папках, что и на хостинге,
#            out/otdat-ГГГГ-ММ-ДД.zip и СПИСОК.txt (что куда загрузить, что удалить).
set -euo pipefail
cd "$(dirname "$0")/.."

if ! git rev-parse -q --verify na-saite >/dev/null; then
  echo "Нет ветки na-saite — непонятно, что сейчас на сайте. Выполните: git fetch origin na-saite:na-saite" >&2
  exit 1
fi

stamp=$(date +%Y-%m-%d)
dir="out/otdat-$stamp"
rm -rf "$dir" "$dir.zip"
mkdir -p "$dir"

# Служебные файлы проекта на сайт не уходят.
filter='^(CLAUDE\.md|README\.md|BOARD\.md|\.claude/|\.github/|tools/|out/|\.gitignore|\.gitattributes|package(-lock)?\.json)'

changed=$(git -c core.quotePath=false diff --no-renames --name-only --diff-filter=ACMR na-saite HEAD | grep -Ev "$filter" || true)
deleted=$(git -c core.quotePath=false diff --no-renames --name-only --diff-filter=D na-saite HEAD | grep -Ev "$filter" || true)

if [ -z "$changed" ] && [ -z "$deleted" ]; then
  echo "С момента последней загрузки на сайт ничего не изменилось."
  exit 0
fi

{
  echo "Пакет для загрузки на abv-es.ru — $stamp"
  echo "Загрузите файлы в корень сайта на хостинге, сохраняя папки."
  echo
  if [ -n "$changed" ]; then
    echo "ЗАГРУЗИТЬ (заменить, если файл уже есть):"
    echo "$changed" | sed 's/^/  /'
  fi
  if [ -n "$deleted" ]; then
    echo
    echo "УДАЛИТЬ НА ХОСТИНГЕ:"
    echo "$deleted" | sed 's/^/  /'
  fi
  echo
  echo "После загрузки напишите Claude «залил» — он отметит эту версию как текущую."
} > "$dir/СПИСОК.txt"

if [ -n "$changed" ]; then
  echo "$changed" | while IFS= read -r f; do
    mkdir -p "$dir/$(dirname "$f")"
    cp "$f" "$dir/$f"
  done
fi

(cd out && zip -qr "otdat-$stamp.zip" "otdat-$stamp")
cat "$dir/СПИСОК.txt"
echo
echo "Готово: $dir.zip"
