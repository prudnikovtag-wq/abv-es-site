#!/usr/bin/env bash
# Что изменилось с версии, которая сейчас на сайте (ветка na-saite), по сравнению с HEAD.
# Печатает строки:  "M путь" — загрузить/заменить,  "D путь" — удалить на хостинге.
# Служебные файлы проекта (регламент, инструменты, доска) никогда не попадают в список.
set -euo pipefail
cd "$(dirname "$0")/.."
base="${1:-na-saite}"
filter='^(CLAUDE\.md|README\.md|BOARD\.md|\.claude/|\.github/|tools/|out/|\.gitignore|\.gitattributes|package(-lock)?\.json)'
git diff --name-only --diff-filter=ACMR "$base" HEAD | grep -Ev "$filter" | sed 's/^/M /' || true
git diff --name-only --diff-filter=D "$base" HEAD | grep -Ev "$filter" | sed 's/^/D /' || true
