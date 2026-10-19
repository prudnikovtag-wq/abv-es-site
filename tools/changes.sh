#!/usr/bin/env bash
# Что изменилось с версии, которая сейчас на сайте (ветка na-saite), по сравнению с HEAD.
# Печатает строки:  "M путь" — загрузить/заменить,  "D путь" — удалить на хостинге.
# Переименование = удалить старое + загрузить новое (--no-renames).
# Служебные файлы проекта (регламент, инструменты, доска, сценарии GitHub) никогда не попадают в список.
set -euo pipefail
cd "$(dirname "$0")/.."
base="${1:-na-saite}"
filter='^(CLAUDE\.md|README\.md|BOARD\.md|\.claude/|\.github/|tools/|out/|\.gitignore|\.gitattributes|package(-lock)?\.json)'
g() { git -c core.quotePath=false diff --no-renames --name-only "$@"; }
{ g --diff-filter=ACMR "$base" HEAD | grep -Ev "$filter" || true; } | sed 's/^/M /'
{ g --diff-filter=D "$base" HEAD | grep -Ev "$filter" || true; } | sed 's/^/D /'
