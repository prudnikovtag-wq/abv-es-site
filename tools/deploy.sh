#!/usr/bin/env bash
# Выкатка сайта abv-es.ru на хостинг reg.ru по FTPS. Запускается ТОЛЬКО из GitHub Actions
# (сценарий .github/workflows/deploy.yml), который запускает владелец кнопкой «Run workflow».
#
#   bash tools/deploy.sh proverka   — только смотрит: подключается, сверяет сервер с репозиторием,
#                                     показывает, что было бы загружено/удалено. Ничего не меняет.
#   bash tools/deploy.sh vykatka    — то же + резервная копия → загрузка → проверка → откат при ошибке.
#
# Нужны переменные окружения FTP_HOST, FTP_USER, FTP_PASSWORD (секреты GitHub).
# FTP-пользователь ограничен папкой сайта, поэтому корень FTP = корень сайта.
set -euo pipefail
cd "$(dirname "$0")/.."

MODE="${1:-proverka}"
SITE="${SITE:-https://abv-es.ru}"   # переопределяется только в тестах
WORK="out/deploy"
rm -rf "$WORK"; mkdir -p "$WORK/backup" "$WORK/server-now"
REPORT="$WORK/ОТЧЁТ.md"
: > "$REPORT"
say() { echo "$*"; echo "$*" >> "$REPORT"; }
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

say "# Выкатка abv-es.ru — режим: **$MODE**"
say ""
say "Коммит: \`$(git rev-parse --short HEAD)\` — $(git log -1 --format=%s | head -1)"
say "На сайте сейчас (по данным репозитория): \`$(git rev-parse --short na-saite)\`"
say ""

# ---------- 1. Подключение и полный список файлов на сервере ----------
echo 'find -l .' > "$WORK/cmd-list"
if ! ftp_run "$WORK/cmd-list" > "$WORK/server-list.txt" 2> "$WORK/ftp-err.txt"; then
  cat "$WORK/ftp-err.txt" >&2
  fail "не удалось подключиться к хостингу по FTPS ($(tail -1 "$WORK/ftp-err.txt"))"
fi
say "✅ Подключение к хостингу по FTPS работает."

# Сверка: какие файлы есть на сервере, но нет в репозитории (на сайте na-saite) — и наоборот.
python3 - "$WORK/server-list.txt" > "$WORK/sync.txt" <<'PY'
import sys, subprocess, re
server = {}
# строка `find -l`: права  владелец/группа  размер  дата  время  ./путь (путь может содержать пробелы)
row = re.compile(r'^(\S+)\s+(\S+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(.*)$')
for line in open(sys.argv[1], encoding="utf-8", errors="replace"):
    m = row.match(line.rstrip("\n"))
    if not m or m.group(1).startswith("d"):
        continue
    path = m.group(6)
    if path.startswith("./"): path = path[2:]
    server[path] = int(m.group(3))
repo = {}
out = subprocess.run(["git", "ls-tree", "-r", "-l", "na-saite"], capture_output=True, text=True).stdout
skip = re.compile(r'^(CLAUDE\.md|README\.md|BOARD\.md|\.claude/|\.github/|tools/|out/|\.gitignore|\.gitattributes|package(-lock)?\.json)')
for line in out.splitlines():
    meta, path = line.split("\t", 1)
    if skip.match(path): continue
    repo[path] = int(meta.split()[3])
only_server = sorted(set(server) - set(repo))
only_repo = sorted(set(repo) - set(server))
diff_size = sorted(p for p in set(server) & set(repo) if server[p] != repo[p])
print(f"SERVER {len(server)} REPO {len(repo)}")
for p in only_server: print("ONLY_SERVER", p)
for p in only_repo: print("ONLY_REPO", p)
for p in diff_size: print("DIFF_SIZE", p, server[p], repo[p])
PY
read -r _ n_server _ n_repo < <(head -1 "$WORK/sync.txt")
say "Файлов на сервере: $n_server, в репозитории (версия na-saite): $n_repo."
if grep -q -E '^(ONLY_|DIFF_)' "$WORK/sync.txt"; then
  say ""
  say "⚠️ **Сервер и репозиторий расходятся** (кто-то менял файлы на хостинге вручную, или это файлы хостинга):"
  grep -E '^ONLY_SERVER' "$WORK/sync.txt" | sed 's/^ONLY_SERVER /- только на сервере: /' | head -40 | tee -a "$REPORT"
  grep -E '^ONLY_REPO'   "$WORK/sync.txt" | sed 's/^ONLY_REPO /- только в репозитории: /' | head -40 | tee -a "$REPORT"
  grep -E '^DIFF_SIZE'   "$WORK/sync.txt" | awk '{print "- другой размер: "$2" (сервер "$3" б, репозиторий "$4" б)"}' | head -40 | tee -a "$REPORT"
else
  say "✅ Сервер полностью совпадает с репозиторием."
fi
say ""

# ---------- 2. План ----------
bash tools/changes.sh > "$WORK/plan.txt"
n_m=$(grep -c '^M ' "$WORK/plan.txt" || true); n_d=$(grep -c '^D ' "$WORK/plan.txt" || true)
say "## План: загрузить $n_m, удалить $n_d"
sed 's/^M /- загрузить: /; s/^D /- удалить: /' "$WORK/plan.txt" | tee -a "$REPORT"
if [ "$n_m" = 0 ] && [ "$n_d" = 0 ]; then
  say "Нечего выкатывать: на сайте уже последняя версия."
  exit 0
fi
say ""

# ---------- 3. Резервная копия + защита от затирания ручных правок ----------
: > "$WORK/cmd-backup"
while read -r op path; do
  if grep -qxF "$path" <(git ls-tree -r --name-only na-saite); then
    mkdir -p "$WORK/server-now/$(dirname "$path")"
    echo "get \"$path\" -o \"$WORK/server-now/$path\"" >> "$WORK/cmd-backup"
  fi
done < "$WORK/plan.txt"
if [ -s "$WORK/cmd-backup" ]; then
  # Файлы, которых на сервере уже нет, не ошибка — поэтому без fail-exit.
  sed -i 's/^get /set cmd:fail-exit false; get /' "$WORK/cmd-backup"
  ftp_run "$WORK/cmd-backup" > /dev/null 2> "$WORK/ftp-err.txt" || true
fi
conflicts=0
while read -r op path; do
  if grep -qxF "$path" <(git ls-tree -r --name-only na-saite); then
    if [ -f "$WORK/server-now/$path" ]; then
      if ! cmp -s "$WORK/server-now/$path" <(git show "na-saite:$path"); then
        say "❌ На сервере \`$path\` отличается от версии в репозитории — его меняли вручную."
        conflicts=$((conflicts+1))
      fi
    else
      say "⚠️ \`$path\` уже нет на сервере."
    fi
  fi
done < "$WORK/plan.txt"
cp -r "$WORK/server-now/." "$WORK/backup/" 2>/dev/null || true
[ "$conflicts" = 0 ] || fail "найдены ручные правки на сервере ($conflicts). Выкатка их затёрла бы. Сначала синхронизируйте репозиторий с сайтом."
say "✅ Затираемые файлы на сервере совпадают с известной версией. Резервная копия снята."
say ""

if [ "$MODE" != "vykatka" ]; then
  say "Режим проверки: **ничего не изменено.** Для выкатки запустите сценарий в режиме vykatka."
  exit 0
fi

# ---------- 4. Выкатка ----------
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

rollback() {
  say ""
  say "## ↩️ Откат"
  : > "$WORK/cmd-rollback"
  while read -r op path; do
    if [ -f "$WORK/backup/$path" ]; then
      echo "put \"$WORK/backup/$path\" -o \"$path\"" >> "$WORK/cmd-rollback"
    elif [ "$op" = M ]; then
      echo "rm -f \"$path\"" >> "$WORK/cmd-rollback"   # файла раньше не было — убираем новый
    fi
  done < "$WORK/plan.txt"
  if ftp_run "$WORK/cmd-rollback" > /dev/null 2>&1; then say "✅ Сайт возвращён к прежней версии."
  else say "‼️ Откат не удался — нужна ручная проверка! Резервная копия — в артефакте этого запуска."; fi
}

if ! ftp_run "$WORK/cmd-deploy" > /dev/null 2> "$WORK/ftp-err.txt"; then
  say "❌ Ошибка при загрузке: $(tail -1 "$WORK/ftp-err.txt")"
  rollback; exit 1
fi
say "✅ Файлы загружены."

# ---------- 5. Проверка ----------
ok=1
# 5а. Сверяем загруженное с тем, что лежит на сервере (скачиваем обратно).
mkdir -p "$WORK/verify"; : > "$WORK/cmd-verify"
grep '^M ' "$WORK/plan.txt" | while read -r op path; do
  mkdir -p "$WORK/verify/$(dirname "$path")"; echo "get \"$path\" -o \"$WORK/verify/$path\"" >> "$WORK/cmd-verify"
done
if ftp_run "$WORK/cmd-verify" > /dev/null 2>&1; then
  while read -r op path; do
    [ "$op" = M ] || continue
    cmp -s "$path" "$WORK/verify/$path" || { say "❌ На сервере не та версия: $path"; ok=0; }
  done < "$WORK/plan.txt"
else
  say "❌ Не удалось скачать загруженные файлы для проверки"; ok=0
fi
# 5б. Сайт отвечает.
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/?deploy=$(date +%s)")
[ "$code" = 200 ] || { say "❌ Главная страница отвечает кодом $code"; ok=0; }
# 5в. Изменённые страницы открываются (кеш хостинга может отдать старую версию — это только замечание).
while read -r op path; do
  [ "$op" = M ] || continue
  case "$path" in *.html|*.css|*.js|*.xml)
    c=$(curl -s -o "$WORK/live.tmp" -w '%{http_code}' --max-time 20 "$SITE/$path?deploy=$(date +%s)")
    if [ "$c" != 200 ]; then say "❌ $path отвечает кодом $c"; ok=0
    elif ! cmp -s "$path" "$WORK/live.tmp"; then say "⚠️ $path: сайт пока отдаёт другую версию (вероятно, кеш)"; fi;;
  esac
done < "$WORK/plan.txt"

if [ "$ok" != 1 ]; then rollback; exit 1; fi
say "✅ Проверка пройдена: главная отвечает 200, на сервере ровно загруженные файлы."

# ---------- 6. Отметить версию как текущую на сайте ----------
git push -f origin HEAD:refs/heads/na-saite
say "✅ Ветка na-saite → \`$(git rev-parse --short HEAD)\`. Выкатка завершена."
