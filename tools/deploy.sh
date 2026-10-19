#!/usr/bin/env bash
# Выкатка сайта abv-es.ru на хостинг reg.ru по FTPS. Запускается ТОЛЬКО из GitHub Actions
# (сценарий .github/workflows/deploy.yml), который запускает владелец кнопкой «Run workflow».
#
#   bash tools/deploy.sh proverka   — только смотрит: подключается, сверяет сервер с репозиторием,
#                                     показывает, что было бы загружено/удалено. Ничего не меняет.
#   bash tools/deploy.sh vykatka    — то же + резервная копия → загрузка → проверка → откат при ошибке.
#
# Нужны переменные окружения FTP_HOST (= server247.hosting.reg.ru — на это имя выписан сертификат),
# FTP_USER, FTP_PASSWORD (секреты GitHub). FTP-пользователь ограничен папкой сайта: корень FTP = корень сайта.
set -euo pipefail
cd "$(dirname "$0")/.."
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.quotePath GIT_CONFIG_VALUE_0=false  # кириллица в путях без кавычек

MODE="${1:-proverka}"
SITE="${SITE:-https://abv-es.ru}"   # переопределяется только в тестах
WORK="out/deploy"
rm -rf "$WORK"; mkdir -p "$WORK/backup" "$WORK/verify"
REPORT="$WORK/ОТЧЁТ.md"
: > "$REPORT"
say() { echo "$*"; echo "$*" >> "$REPORT"; }
STARTED=0; FINISHED=0
fail() { say ""; say "## ❌ ОСТАНОВЛЕНО: $*"; exit 1; }

for v in FTP_HOST FTP_USER FTP_PASSWORD; do
  [ -n "${!v:-}" ] || fail "нет секрета $v в настройках GitHub"
done
export LFTP_PASSWORD="$FTP_PASSWORD"

# Все подключения — только шифрованные (FTPS) и с проверкой сертификата сервера.
LFTP_SETTINGS="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; \
set net:max-retries 2; set net:timeout 20; set net:reconnect-interval-base 5; set cmd:fail-exit true"
ftp_run() { # $1 — файл с командами lftp
  lftp --env-password -u "$FTP_USER" -e "$LFTP_SETTINGS; source $1; bye" "ftp://$FTP_HOST"
}
ftp_list() { # полный список файлов сервера → $1
  echo 'find -l .' > "$WORK/cmd-list"
  ftp_run "$WORK/cmd-list" > "$1" 2> "$WORK/ftp-err.txt"
}
# Разбор `find -l` в «путь<TAB>размер» (путь может содержать пробелы).
parse_list() {
  python3 - "$1" <<'PY'
import sys, re
row = re.compile(r'^(\S+)\s+(\S+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(.*)$')
for line in open(sys.argv[1], encoding="utf-8", errors="replace"):
    m = row.match(line.rstrip("\n"))
    if not m or m.group(1).startswith("d"):
        continue
    p = m.group(6)
    if not p.startswith("./"):   # формат строки не тот — не угадываем путь (отсечёт проверка доли)
        continue
    print(f"{p[2:]}\t{m.group(3)}")
PY
}
# Без пайпов: `cmd | grep -q` при pipefail даёт ложный «нет» (SIGPIPE у cmd).
on_server() { grep -qxF -- "$1" <(cut -f1 "$WORK/server-files.tsv"); }
git ls-tree -r --name-only na-saite > "$WORK/live-files.txt"
in_live()   { grep -qxF -- "$1" "$WORK/live-files.txt"; }

say "# Выкатка abv-es.ru — режим: **$MODE**"
say ""
say "Коммит: \`$(git rev-parse --short HEAD)\` — $(git log -1 --format=%s | head -1)"
say "На сайте сейчас (по данным репозитория): \`$(git rev-parse --short na-saite)\`"
say ""

# ---------- 0. История: версия на сайте должна входить в то, что выкатываем ----------
git merge-base --is-ancestor na-saite HEAD || fail "ветка na-saite (что на сайте) не входит в историю main — \
выкатка откатила бы чьи-то правки. Сначала влейте na-saite в main."

# ---------- 1. Подключение и полный список файлов на сервере ----------
# Диагностика, если подключиться не вышло. Пароль вырезается из любого вывода.
diagnose() {
  local D="$WORK/diag.txt"
  {
    echo "### Диагностика подключения"
    echo '```'
    echo "Хост: $FTP_HOST   пользователь: $FTP_USER"
    echo "-- DNS:"; getent hosts "$FTP_HOST" || echo "имя не находится"
    echo "-- порт 21:"; timeout 10 bash -c "</dev/tcp/$FTP_HOST/21" 2>&1 && echo "открыт" || echo "НЕ открыт (закрыт или фильтруется)"
    echo "-- приветствие и TLS-сертификат (STARTTLS):"
    timeout 15 openssl s_client -connect "$FTP_HOST:21" -starttls ftp -servername "$FTP_HOST" </dev/null 2>&1 \
      | grep -E '^(CONNECTED|depth|verify|subject=|issuer=)|Verify return code|error|errno' | head -12
    echo "-- вход с подробным журналом lftp (пароль скрыт):"
    timeout 40 lftp --env-password -u "$FTP_USER" -e "debug 3; set ftp:ssl-force true; set ssl:verify-certificate true; \
      set net:max-retries 1; set net:timeout 15; pwd; ls -a | head -5; bye" "ftp://$FTP_HOST" 2>&1 | head -60
    echo '```'
  } > "$D" 2>&1
  python3 - "$D" <<'PY'
import os, sys
p = sys.argv[1]; t = open(p, encoding="utf-8", errors="replace").read()
pw = os.environ.get("FTP_PASSWORD", "")
if pw: t = t.replace(pw, "***")
open(p, "w", encoding="utf-8").write(t)
PY
  cat "$D" | tee -a "$REPORT"
}
if ! ftp_list "$WORK/server-list.txt"; then
  cat "$WORK/ftp-err.txt" >&2
  diagnose || true
  fail "не удалось подключиться к хостингу по FTPS ($(tail -1 "$WORK/ftp-err.txt"))"
fi
parse_list "$WORK/server-list.txt" > "$WORK/server-files.tsv"
n_lines=$(grep -vc '^d' "$WORK/server-list.txt" || true)
n_server=$(wc -l < "$WORK/server-files.tsv")
[ "$n_server" -gt 0 ] || fail "не удалось разобрать список файлов сервера (формат ответа FTP неожиданный)"
[ "$n_server" -ge $(( n_lines * 9 / 10 )) ] || fail "разобрано только $n_server из $n_lines строк списка файлов сервера"
say "✅ Подключение к хостингу по FTPS работает."

# Сверка всего сервера с версией na-saite (для отчёта; сама по себе выкатку не останавливает).
python3 - "$WORK/server-files.tsv" > "$WORK/sync.txt" <<'PY'
import sys, subprocess, re
server = {}
for line in open(sys.argv[1], encoding="utf-8"):
    p, s = line.rstrip("\n").split("\t"); server[p] = int(s)
skip = re.compile(r'^(CLAUDE\.md|README\.md|BOARD\.md|\.claude/|\.github/|tools/|out/|\.gitignore|\.gitattributes|package(-lock)?\.json)')
repo = {}
out = subprocess.run(["git", "-c", "core.quotePath=false", "ls-tree", "-r", "-l", "na-saite"], capture_output=True, text=True).stdout
for line in out.splitlines():
    meta, path = line.split("\t", 1)
    if not skip.match(path): repo[path] = int(meta.split()[3])
print(f"SERVER {len(server)} REPO {len(repo)}")
for p in sorted(set(server) - set(repo)): print("ONLY_SERVER", p)
for p in sorted(set(repo) - set(server)): print("ONLY_REPO", p)
for p in sorted(p for p in set(server) & set(repo) if server[p] != repo[p]): print("DIFF_SIZE", p, server[p], repo[p])
PY
read -r _ n_srv _ n_repo < <(head -1 "$WORK/sync.txt")
say "Файлов на сервере: $n_srv, в репозитории (версия na-saite): $n_repo."
if grep -q -E '^(ONLY_|DIFF_)' "$WORK/sync.txt"; then
  say ""
  say "⚠️ **Сервер и репозиторий расходятся** (файлы меняли на хостинге вручную, или это служебные файлы хостинга):"
  { grep -E '^ONLY_SERVER' "$WORK/sync.txt" | sed 's/^ONLY_SERVER /- только на сервере: /' | head -40 || true; } | tee -a "$REPORT"
  { grep -E '^ONLY_REPO'   "$WORK/sync.txt" | sed 's/^ONLY_REPO /- только в репозитории: /' | head -40 || true; } | tee -a "$REPORT"
  { grep -E '^DIFF_SIZE'   "$WORK/sync.txt" | awk '{print "- другой размер: "$2" (сервер "$3" б, репозиторий "$4" б)"}' | head -40 || true; } | tee -a "$REPORT"
else
  say "✅ Сервер полностью совпадает с репозиторием."
fi
say ""

# ---------- 2. План ----------
bash tools/changes.sh > "$WORK/plan.txt"
n_m=$(grep -c '^M ' "$WORK/plan.txt" || true); n_d=$(grep -c '^D ' "$WORK/plan.txt" || true)
say "## План: загрузить $n_m, удалить $n_d"
{ sed 's/^M /- загрузить: /; s/^D /- удалить: /' "$WORK/plan.txt" || true; } | tee -a "$REPORT"
if [ "$n_m" = 0 ] && [ "$n_d" = 0 ]; then
  say "Нечего выкатывать: на сайте уже последняя версия."
  exit 0
fi
say ""

# ---------- 3. Резервная копия + защита от затирания ручных правок ----------
# Каждый затрагиваемый файл, который ЕСТЬ на сервере, обязан скачаться. Не скачался — стоп, ничего не меняем.
: > "$WORK/cmd-backup"
while read -r op path; do
  if on_server "$path"; then
    mkdir -p "$WORK/backup/$(dirname "$path")"
    echo "get \"$path\" -o \"$WORK/backup/$path\"" >> "$WORK/cmd-backup"
  fi
done < "$WORK/plan.txt"
# Страховка: файл по данным репозитория на сервере есть, но в списке его не видно — пробуем скачать (без ошибки).
: > "$WORK/cmd-backup2"
while read -r op path; do
  if ! on_server "$path" && in_live "$path"; then
    [ -s "$WORK/cmd-backup2" ] || echo "set cmd:fail-exit false" > "$WORK/cmd-backup2"
    mkdir -p "$WORK/backup/$(dirname "$path")"
    echo "get \"$path\" -o \"$WORK/backup/$path\"" >> "$WORK/cmd-backup2"
  fi
done < "$WORK/plan.txt"
[ -s "$WORK/cmd-backup2" ] && { ftp_run "$WORK/cmd-backup2" > /dev/null 2>&1 || true; }
find "$WORK/backup" -type f -empty -delete   # пустышки от неудачных get не должны считаться копиями
if [ -s "$WORK/cmd-backup" ] && ! ftp_run "$WORK/cmd-backup" > /dev/null 2> "$WORK/ftp-err.txt"; then
  fail "не удалось снять резервную копию ($(tail -1 "$WORK/ftp-err.txt")). Ничего не изменено."
fi
conflicts=0
while read -r op path; do
  if on_server "$path"; then
    [ -f "$WORK/backup/$path" ] || fail "резервная копия $path не скачалась. Ничего не изменено."
    if in_live "$path"; then
      if ! cmp -s "$WORK/backup/$path" <(git show "na-saite:$path"); then
        say "❌ На сервере \`$path\` отличается от версии в репозитории — его меняли вручную."
        conflicts=$((conflicts+1))
      fi
    else
      say "❌ \`$path\` — новый для репозитория файл, но на сервере такой уже есть (залит вручную?)."
      conflicts=$((conflicts+1))
    fi
  elif in_live "$path"; then
    if [ -s "$WORK/backup/$path" ]; then   # пустой файл = lftp создал его при ошибке 550, не считаем
      fail "\`$path\` есть на сервере, но не виден в списке файлов — список сервера разобран неверно. Ничего не изменено."
    fi
    say "⚠️ \`$path\` по данным репозитория должен быть на сервере, но его там нет."
  fi
done < "$WORK/plan.txt"
[ "$conflicts" = 0 ] || fail "найдены ручные правки на сервере ($conflicts). Выкатка их затёрла бы. Сначала синхронизируйте репозиторий с сайтом."
say "✅ Затрагиваемые файлы на сервере совпадают с известной версией. Резервная копия снята."
say ""

if [ "$MODE" != "vykatka" ]; then
  say "Режим проверки: **ничего не изменено.** Для выкатки запустите сценарий в режиме vykatka."
  exit 0
fi

# ---------- 4. Выкатка ----------
rollback() {
  say ""
  say "## ↩️ Откат"
  # Откат идёт до конца, даже если отдельные команды не прошли.
  echo "set cmd:fail-exit false" > "$WORK/cmd-rollback"
  while read -r op path; do
    if [ -f "$WORK/backup/$path" ]; then
      d=$(dirname "$path"); [ "$d" != "." ] && echo "mkdir -p -f \"$d\"" >> "$WORK/cmd-rollback"
      echo "put \"$WORK/backup/$path\" -o \"$path\"" >> "$WORK/cmd-rollback"
    elif [ "$op" = M ]; then
      echo "rm -f \"$path\"" >> "$WORK/cmd-rollback"   # файла на сервере не было — убираем новый
    fi
  done < "$WORK/plan.txt"
  ftp_run "$WORK/cmd-rollback" > /dev/null 2>&1 || true
  # Сверяем, что вернулось ровно то, что было.
  local bad=0
  if ftp_list "$WORK/after-rollback.txt"; then
    parse_list "$WORK/after-rollback.txt" > "$WORK/server-files.tsv"
    echo "set cmd:fail-exit false" > "$WORK/cmd-rbcheck"; rm -rf "$WORK/rbcheck"; mkdir -p "$WORK/rbcheck"
    while read -r op path; do
      [ -f "$WORK/backup/$path" ] || continue
      mkdir -p "$WORK/rbcheck/$(dirname "$path")"; echo "get \"$path\" -o \"$WORK/rbcheck/$path\"" >> "$WORK/cmd-rbcheck"
    done < "$WORK/plan.txt"
    ftp_run "$WORK/cmd-rbcheck" > /dev/null 2>&1 || true
    while read -r op path; do
      if [ -f "$WORK/backup/$path" ]; then
        cmp -s "$WORK/backup/$path" "$WORK/rbcheck/$path" 2>/dev/null || { say "‼️ не восстановлен: $path"; bad=1; }
      elif [ "$op" = M ] && on_server "$path"; then
        say "‼️ не удалён новый файл: $path"; bad=1
      fi
    done < "$WORK/plan.txt"
  else
    bad=1
  fi
  if [ "$bad" = 0 ]; then say "✅ Сайт возвращён к прежней версии (проверено)."
  else say "‼️ Откат не подтверждён — нужна ручная проверка! Резервная копия — в артефакте этого запуска (vykatka-N)."; fi
}
# Любой неожиданный выход после начала загрузки → откат.
on_exit() {
  local rc=$?
  if [ "$STARTED" = 1 ] && [ "$FINISHED" = 0 ]; then
    trap - EXIT
    say ""; say "❌ Выкатка прервалась (код $rc)."
    set +e; rollback; exit 1
  fi
}
trap on_exit EXIT

: > "$WORK/cmd-deploy"
while read -r op path; do
  d=$(dirname "$path")
  if [ "$op" = M ]; then
    [ "$d" != "." ] && echo "mkdir -p -f \"$d\"" >> "$WORK/cmd-deploy"
    echo "put \"$path\" -o \"$path\"" >> "$WORK/cmd-deploy"
  else
    echo "rm -f \"$path\"" >> "$WORK/cmd-deploy"
  fi
done < "$WORK/plan.txt"

STARTED=1
trap '' INT   # при отмене задания даём lftp доработать загрузку, дальше сработает проверка/откат
ftp_run "$WORK/cmd-deploy" > /dev/null 2> "$WORK/ftp-err.txt" || {
  say "❌ Ошибка при загрузке: $(tail -1 "$WORK/ftp-err.txt")"; false; }
say "✅ Файлы загружены."

# ---------- 5. Проверка ----------
ok=1
# 5а. Список сервера: новые файлы есть, удалённых нет.
ftp_list "$WORK/after.txt" || { say "❌ Не удалось получить список файлов после загрузки"; false; }
parse_list "$WORK/after.txt" > "$WORK/server-files.tsv"
while read -r op path; do
  if [ "$op" = M ]; then on_server "$path" || { say "❌ Нет на сервере: $path"; ok=0; }
  else if on_server "$path"; then say "❌ Не удалился: $path"; ok=0; fi; fi
done < "$WORK/plan.txt"
# 5б. Загруженное совпадает байт в байт (скачиваем обратно).
: > "$WORK/cmd-verify"
while read -r op path; do
  [ "$op" = M ] || continue
  mkdir -p "$WORK/verify/$(dirname "$path")"; echo "get \"$path\" -o \"$WORK/verify/$path\"" >> "$WORK/cmd-verify"
done < "$WORK/plan.txt"
if [ -s "$WORK/cmd-verify" ]; then
  ftp_run "$WORK/cmd-verify" > /dev/null 2>&1 || { say "❌ Не удалось скачать загруженные файлы для проверки"; ok=0; }
  while read -r op path; do
    [ "$op" = M ] || continue
    cmp -s "$path" "$WORK/verify/$path" 2>/dev/null || { say "❌ На сервере не та версия: $path"; ok=0; }
  done < "$WORK/plan.txt"
fi
# 5в. Сайт отвечает.
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/?deploy=$(date +%s)" || true)
[ "$code" = 200 ] || { say "❌ Главная страница отвечает кодом ${code:-нет ответа}"; ok=0; }
# 5г. Изменённые страницы открываются. Несовпадение содержимого — только замечание (кеш хостинга).
while read -r op path; do
  [ "$op" = M ] || continue
  case "$path" in *.html|*.css|*.js|*.xml)
    url="$SITE/$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$path")?deploy=$(date +%s)"
    c=$(curl -s -o "$WORK/live.tmp" -w '%{http_code}' --max-time 20 "$url" || true)
    if [ "$c" != 200 ]; then say "❌ $path отвечает кодом ${c:-нет ответа}"; ok=0
    elif ! cmp -s "$path" "$WORK/live.tmp"; then say "⚠️ $path: сайт пока отдаёт другую версию (вероятно, кеш)"; fi;;
  esac
done < "$WORK/plan.txt"

[ "$ok" = 1 ] || false   # → on_exit → откат
FINISHED=1
say "✅ Проверка пройдена: главная отвечает 200, на сервере ровно загруженные файлы, удалённых нет."

# ---------- 6. Отметить версию как текущую на сайте ----------
if git push -f origin HEAD:refs/heads/na-saite; then
  say "✅ Ветка na-saite → \`$(git rev-parse --short HEAD)\`. Выкатка завершена."
else
  say "⚠️ Файлы выложены и проверены, но ветку na-saite обновить не удалось. \
Сдвиньте вручную: git push -f origin $(git rev-parse HEAD):refs/heads/na-saite"
  exit 1
fi
