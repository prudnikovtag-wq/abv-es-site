<?php
/*
 * Склад abv-es.ru: данные товаров и сборка страниц из них.
 *
 * Живые данные лежат на хостинге в admin-data/tovary.php (JSON, перед ним строка-заглушка <?php exit; ?>,
 * чтобы файл нельзя было скачать, даже если .htaccess не сработает). В репозитории их НЕТ — их меняет админка.
 * Если файла ещё нет (первый запуск) — товары берутся из admin/inc/tovary-start.json.
 *
 * Страницы собираются по шаблонам — обычным файлам сайта из репозитория:
 *   sklad.html               — каталог: подставляются плитки, кнопки-фильтры и дата остатков;
 *   tovar-1SDA073725R1.html  — эталон карточки: подставляются данные товара (как в tools/make_tovary.py);
 *   feed.xml                 — фид Яндекса: подставляются дата, категории и товары.
 * Вывод совпадает байт в байт с тем, что делает tools/make_tovary.py (проверка: tools/test_magazin.php).
 * Меняете вёрстку склада или карточки — меняйте эти файлы и генератор вместе.
 */
declare(strict_types=1);

const ABV_SITE = 'https://abv-es.ru/';
const ABV_NBSP = "\u{00A0}";
const ABV_GUARD = "<?php exit; ?>\n";
const ABV_SKLAD = 'sklad.html';
const ABV_FEED = 'feed.xml';
const ABV_REFERENCE = 'tovar-1SDA073725R1.html';
const ABV_RELATED = 4;
const ABV_KOPII_MAX = 150;          // сколько резервных копий хранить
const ABV_SKU_RE = '/^[A-Z0-9][A-Z0-9-]{0,39}$/';

const ABV_DESC_1 = '{name} (артикул {sku}) — оригинальное оборудование ABB. АБВ Электросервис — сервисный центр '
    . 'низковольтного и средневольтного электрооборудования АББ: поставляем запасные части и комплектующие '
    . 'со склада в Москве и под заказ, помогаем с подбором и идентификацией аппаратов, выполняем монтаж, '
    . 'ремонт и обслуживание.';
const ABV_DESC_2 = 'Чтобы уточнить наличие, сроки поставки и совместимость, оставьте запрос, напишите на info@abv-es.ru '
    . 'или позвоните 8 (800) 250 33 92.';

const ABV_GO_SVG = '<span class="abv-go"><svg fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" '
    . 'stroke-width="2.4" viewbox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></span>';

const ABV_TERMS_CSS = "/* условия заказа */\n"
    . ".abv-product .abv-terms{list-style:none;margin:16px 0 0;padding:14px 0 0;border-top:1px dashed #ffd3ad;"
    . "display:flex;flex-direction:column;gap:6px}\n"
    . ".abv-product .abv-terms li{position:relative;margin:0;padding:0 0 0 17px;font-size:14px;line-height:1.45;color:var(--t)}\n"
    . ".abv-product .abv-terms li:before{content:\"\";position:absolute;left:0;top:.5em;width:7px;height:7px;"
    . "border-radius:50%;background:var(--o)}\n"
    . ".abv-product .abv-terms b{color:var(--h);font-weight:700;white-space:nowrap}\n"
    . ".abv-side{display:grid;gap:28px;min-width:0}\n"
    . ".abv-product .abv-side .abv-spec dt{flex:0 1 auto}\n";

const ABV_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября',
    'октября', 'ноября', 'декабря'];

date_default_timezone_set('Europe/Moscow');

// ---------- пути и файлы ----------

function abv_root(): string { return dirname(__DIR__, 2); }
function abv_dir(): string { return abv_root() . '/admin-data'; }
function abv_data_file(): string { return abv_dir() . '/tovary.php'; }
function abv_kopii_dir(): string { return abv_dir() . '/kopii'; }
function abv_foto_dir(): string { return abv_root() . '/foto'; }

function abv_file(string $name): string
{
    $s = @file_get_contents(abv_root() . '/' . $name);
    if ($s === false) {
        throw new RuntimeException("нет файла $name");
    }
    return $s;
}

/** Читает JSON из файла с защитной строкой <?php exit; ?>. null — нет файла или он испорчен. */
function abv_read_guarded(string $file): ?array
{
    $s = @file_get_contents($file);
    if ($s === false) {
        return null;
    }
    if (strncmp($s, ABV_GUARD, strlen(ABV_GUARD)) === 0) {
        $s = substr($s, strlen(ABV_GUARD));
    }
    $d = json_decode($s, true);
    return is_array($d) ? $d : null;
}

/** Записывает JSON атомарно: во временный файл, затем переименование (страница никогда не увидит полфайла). */
function abv_write_guarded(string $file, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('не удалось подготовить данные к записи');
    }
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, ABV_GUARD . $json . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('не удалось записать файл ' . basename($file) . ' (нет прав на запись?)');
    }
}

/** Служебные папки и защита от скачивания (создаются при первой записи). */
function abv_prepare_dirs(): void
{
    foreach ([abv_dir(), abv_kopii_dir()] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
            throw new RuntimeException('не удалось создать папку ' . basename($d));
        }
    }
    $deny = "# Служебные данные админки — снаружи недоступны\n"
        . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
    if (!is_file(abv_dir() . '/.htaccess')) {
        @file_put_contents(abv_dir() . '/.htaccess', $deny);
    }
}

// ---------- данные ----------

/** Приводит запись товара к нужным типам. */
function abv_norm_item(array $t): array
{
    $mz = $t['min_zakaz'] ?? null;
    $srok = $t['srok'] ?? null;
    return [
        'sku' => (string)($t['sku'] ?? ''),
        'name' => (string)($t['name'] ?? ''),
        'qty' => (int)($t['qty'] ?? 0),
        'price' => (int)($t['price'] ?? 0),
        'cat' => (string)($t['cat'] ?? ''),
        'img' => (string)($t['img'] ?? ''),
        'srok' => ($srok === null || $srok === '') ? null : (string)$srok,
        'min_zakaz' => ($mz === null || (int)$mz <= 0) ? null : (int)$mz,
        'dostavka' => (bool)($t['dostavka'] ?? false),
    ];
}

function abv_norm(array $d): array
{
    $d['tovary'] = array_values(array_map('abv_norm_item', is_array($d['tovary'] ?? null) ? $d['tovary'] : []));
    $d['ostatki'] = (string)($d['ostatki'] ?? abv_date_ru());
    $d['feed_date'] = (string)($d['feed_date'] ?? date('c'));
    $d['feed_cats'] = is_array($d['feed_cats'] ?? null) ? $d['feed_cats'] : [];
    $d['izmeneno'] = (string)($d['izmeneno'] ?? '');
    return $d;
}

/** Самая свежая резервная копия (на случай, если основной файл повреждён). */
function abv_latest_kopiya(): ?array
{
    $files = glob(abv_kopii_dir() . '/tovary-*.php') ?: [];
    rsort($files, SORT_STRING);
    foreach ($files as $f) {
        $d = abv_read_guarded($f);
        if ($d && isset($d['tovary'])) {
            return $d;
        }
    }
    return null;
}

/** Текущие данные склада. $fresh — перечитать с диска (после записи). */
function abv_data(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    clearstatcache();
    $d = null;
    if (is_file(abv_data_file())) {
        $d = abv_read_guarded(abv_data_file()) ?? abv_latest_kopiya();
    }
    if (!$d) {
        $d = json_decode(abv_file('admin/inc/tovary-start.json'), true);
        if (!is_array($d)) {
            throw new RuntimeException('стартовый файл товаров повреждён');
        }
    }
    return $cache = abv_norm($d);
}

/** Отпечаток данных — чтобы не затереть правку, сделанную в другой вкладке. */
function abv_version(array $d): string
{
    return substr(hash('sha256', json_encode($d['tovary'], JSON_UNESCAPED_UNICODE) . $d['ostatki']), 0, 16);
}

/**
 * Сохраняет данные: блокировка, запись, резервная копия нового состояния и строка в журнал.
 * $expected — отпечаток данных, которые видел пользователь (null — не проверять).
 */
function abv_save(array $d, string $note, ?string $expected = null): void
{
    abv_prepare_dirs();
    $lock = fopen(abv_dir() . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('не удалось заблокировать данные для записи');
    }
    try {
        $cur = abv_data(true);
        if ($expected !== null && abv_version($cur) !== $expected) {
            throw new AbvConflict('Пока вы редактировали, данные изменились (другая вкладка или другой человек). '
                . 'Откройте страницу заново и повторите правку.');
        }
        if (!is_file(abv_data_file())) {          // первый запуск: сохранить исходное состояние как копию
            abv_snapshot($cur, 'Исходное состояние склада (до первой правки в админке)');
        }
        $d = abv_norm($d);
        $d['feed_date'] = date('c');
        $d['izmeneno'] = date('c');
        foreach ($d['tovary'] as $t) {           // новые категории — новые id в фиде (старые не меняются)
            if (!isset($d['feed_cats'][$t['cat']])) {
                $d['feed_cats'][$t['cat']] = ($d['feed_cats'] ? max(array_map('intval', $d['feed_cats'])) : 0) + 1;
            }
        }
        abv_write_guarded(abv_data_file(), $d);
        abv_snapshot($d, $note);
        abv_data(true);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

class AbvConflict extends RuntimeException {}

/** Резервная копия состояния + строка журнала; старые копии сверх ABV_KOPII_MAX удаляются. */
function abv_snapshot(array $d, string $note): void
{
    $t = microtime(true);   // в имени — время до микросекунд, чтобы копии сортировались по порядку
    $name = 'tovary-' . date('Ymd-His', (int)$t) . '-' . sprintf('%06d', (int)(($t - floor($t)) * 1e6)) . '.php';
    $d['kopiya_note'] = $note;
    abv_write_guarded(abv_kopii_dir() . '/' . $name, $d);
    $files = glob(abv_kopii_dir() . '/tovary-*.php') ?: [];
    sort($files, SORT_STRING);
    while (count($files) > ABV_KOPII_MAX) {
        @unlink(array_shift($files));
    }
}

/** Список резервных копий, новые сверху: [file, time, note, count]. */
function abv_kopii(): array
{
    $files = glob(abv_kopii_dir() . '/tovary-*.php') ?: [];
    rsort($files, SORT_STRING);
    $out = [];
    foreach ($files as $f) {
        $d = abv_read_guarded($f);
        if (!$d) {
            continue;
        }
        $out[] = ['file' => basename($f), 'time' => (string)($d['izmeneno'] ?? ''), 'note' => (string)($d['kopiya_note'] ?? ''),
            'count' => count($d['tovary'] ?? []), 'mtime' => filemtime($f)];
    }
    return $out;
}

function abv_date_ru(?int $ts = null): string
{
    $ts = $ts ?? time();
    return (int)date('j', $ts) . ' ' . ABV_MONTHS[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) . ' г.';
}

function abv_find(array $tovary, string $sku): ?int
{
    foreach ($tovary as $i => $t) {
        if ($t['sku'] === $sku) {
            return $i;
        }
    }
    return null;
}

// ---------- мелочи вывода (как в make_tovary.py) ----------

function abv_t(string $s): string { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s); }
function abv_a(string $s): string { return str_replace('"', '&quot;', abv_t($s)); }
function abv_money(int $n, string $sep = ABV_NBSP): string { return number_format($n, 0, '', $sep); }

/** urllib.parse.quote(s, safe="/") */
function abv_quote(string $s): string { return str_replace('%2F', '/', rawurlencode($s)); }

/** json.dumps(v, ensure_ascii=False) из Python — с пробелами после «,» и «:». */
function abv_pyjson($v): string
{
    if (is_array($v)) {
        if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
            return '[' . implode(', ', array_map('abv_pyjson', $v)) . ']';
        }
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = abv_pyjson((string)$k) . ': ' . abv_pyjson($x);
        }
        return '{' . implode(', ', $parts) . '}';
    }
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
}

function abv_sub_once(string $s, string $rx, string $repl, string $what): string
{
    $out = preg_replace_callback($rx, function () use ($repl) { return $repl; }, $s, -1, $cnt);
    if ($out === null || $cnt !== 1) {
        throw new RuntimeException("в шаблоне блок «{$what}» найден " . (int)$cnt . ' раз (ожидался 1)');
    }
    return $out;
}

// ---------- склад ----------

function abv_render_tile(array $it, bool $catalog = true): string
{
    $attrs = $catalog ? ' data-cat="' . abv_a($it['cat']) . '" data-q="' . abv_a($it['sku'] . ' ' . $it['name'] . ' ' . $it['cat']) . '"' : '';
    $alt = $catalog ? abv_a($it['name']) : '';
    $badge = $it['qty'] > 0 ? $it['qty'] . ' шт.' : 'под заказ';
    return '<div><a class="abv-pcard"' . $attrs . ' href="tovar-' . $it['sku'] . '.html"><div class="abv-pcard-img">'
        . '<span class="abv-badge">' . $badge . '</span><img alt="' . $alt . '" loading="lazy" src="' . abv_a($it['img']) . '" '
        . 'style="view-transition-name:p-' . $it['sku'] . '"/></div><div class="abv-pcard-body">'
        . '<p class="abv-pcard-cat">' . abv_t($it['cat']) . '</p>'
        . '<p class="abv-pcard-name" style="view-transition-name:t-' . $it['sku'] . '">' . abv_t($it['name']) . '</p>'
        . '<p class="abv-pcard-code">Арт. ' . $it['sku'] . '</p><div class="abv-pcard-foot">'
        . '<p class="abv-price">' . abv_money($it['price']) . ABV_NBSP . '₽ <small>с НДС</small></p>' . ABV_GO_SVG . '</div></div></a></div>';
}

function abv_categories(array $items): array
{
    $order = [];
    foreach ($items as $it) {
        $order[$it['cat']] = ($order[$it['cat']] ?? 0) + 1;
    }
    return $order;    // раздел => число товаров, в порядке каталога
}

function abv_render_chips(array $items): string
{
    $out = '<button class="abv-chip on" data-cat="" type="button">Все<span>' . count($items) . '</span></button>';
    foreach (abv_categories($items) as $c => $n) {
        $out .= '<button class="abv-chip" data-cat="' . abv_a((string)$c) . '" type="button">' . abv_t((string)$c) . '<span>' . $n . '</span></button>';
    }
    return $out;
}

function abv_render_sklad(?array $d = null): string
{
    $d = $d ?? abv_data();
    $s = abv_file(ABV_SKLAD);
    $open = '<div class="abv-cards">';
    $i = strpos($s, $open);
    if ($i === false) {
        throw new RuntimeException('в sklad.html не найден список плиток');
    }
    $i += strlen($open);
    $j = $i;
    $tile = '<div><a class="abv-pcard" ';
    while (substr_compare($s, $tile, $j, strlen($tile)) === 0) {
        $e = strpos($s, '</a></div>', $j);
        if ($e === false) {
            throw new RuntimeException('в sklad.html не разобрана плитка');
        }
        $j = $e + strlen('</a></div>');
    }
    if (substr_compare($s, '</div>', $j, 6) !== 0) {
        throw new RuntimeException('в sklad.html не найден конец списка плиток');
    }
    $tiles = '';
    foreach ($d['tovary'] as $it) {
        $tiles .= abv_render_tile($it);
    }
    $s = substr($s, 0, $i) . $tiles . substr($s, $j);
    $s = abv_sub_once($s, '~(?<=<div class="abv-chips">).*?(?=</div>)~s', abv_render_chips($d['tovary']), 'кнопки-фильтры');
    // дата остатков: «Остатки товаров на 2 октября 2026 г.» и «Остатки на 2 октября 2026 г.» в описаниях
    $s = preg_replace('~(Остатки (?:товаров )?на )\d{1,2} [а-яё]+ \d{4} г\.~u', '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $d['ostatki']), $s);
    return $s;
}

// ---------- карточка товара ----------

function abv_related(array $it, array $items): array
{
    $same = array_values(array_filter($items, function ($c) use ($it) { return $c['cat'] === $it['cat']; }));
    $i = 0;
    foreach ($same as $k => $c) {
        if ($c['sku'] === $it['sku']) {
            $i = $k;
            break;
        }
    }
    $out = [];
    for ($dd = 1; $dd < count($same); $dd++) {
        foreach ([$i - $dd, $i + $dd] as $j) {
            if ($j >= 0 && $j < count($same)) {
                $out[] = $same[$j];
            }
        }
    }
    $out = array_slice($out, 0, ABV_RELATED);
    if (count($out) < ABV_RELATED) {
        $last = 0;
        foreach ($items as $k => $c) {
            if ($c['cat'] === $it['cat']) {
                $last = $k;
            }
        }
        $n = count($items);
        $inOut = function ($c) use (&$out) {
            foreach ($out as $o) {
                if ($o['sku'] === $c['sku']) {
                    return true;
                }
            }
            return false;
        };
        for ($dd = 1; $dd < $n; $dd++) {
            $c = $items[($last + $dd) % $n];
            if ($c['cat'] !== $it['cat'] && !$inOut($c)) {
                $out[] = $c;
            }
            if (count($out) === ABV_RELATED) {
                break;
            }
        }
    }
    return $out;
}

function abv_reference(): array
{
    static $ref = null;
    if ($ref === null) {
        $s = abv_file(ABV_REFERENCE);
        if (!preg_match('~onclick="abvRequest\(event\)">ЗАПРОСИТЬ</a>\r?\n(.*?)\r?\n<div class="abv-info">~s', $s, $m)
            || !preg_match('~</dl>\r?\n(<p class="abv-note">.*?</p></div>)\r?\n~s', $s, $n)) {
            throw new RuntimeException('эталон карточки ' . ABV_REFERENCE . ' не похож на карточку товара');
        }
        $ref = ['html' => $s, 'actions_tail' => $m[1], 'note' => $n[1]];
    }
    return $ref;
}

function abv_desc1(array $it): string
{
    return str_replace(['{name}', '{sku}'], [$it['name'], $it['sku']], ABV_DESC_1);
}

function abv_render_page(array $it, ?array $d = null): string
{
    $d = $d ?? abv_data();
    $items = $d['tovary'];
    $ref = abv_reference();
    $nl = strpos($ref['html'], "\r\n") !== false ? "\r\n" : "\n";
    $sku = $it['sku'];
    $url = ABV_SITE . 'tovar-' . $sku . '.html';
    $imgAbs = ABV_SITE . explode('?', $it['img'])[0];
    $est = $it['qty'] > 0;
    $title = $it['name'] . ' (' . $sku . ') — купить | АБВ Электросервис';
    $descr = $it['name'] . ', артикул ' . $sku . '. '
        . ($est ? 'В наличии ' . $it['qty'] . ' шт. ' : 'Под заказ. ')
        . 'Цена ' . abv_money($it['price']) . ' ₽ с НДС. Оригинал ABB, склад в Москве.';
    $d1 = abv_desc1($it);
    $d2 = ABV_DESC_2;
    $offer = ['@type' => 'Offer', 'price' => $it['price'], 'priceCurrency' => 'RUB',
        'availability' => $est ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
        'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url,
        'seller' => ['@type' => 'Organization', 'name' => 'ООО «АБВ Электросервис»']];
    if ($it['min_zakaz']) {
        $offer['eligibleTransactionVolume'] = ['@type' => 'PriceSpecification', 'minPrice' => $it['min_zakaz'],
            'priceCurrency' => 'RUB', 'valueAddedTaxIncluded' => true];
    }
    $ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $it['name'], 'sku' => $sku, 'mpn' => $sku,
        'brand' => ['@type' => 'Brand', 'name' => 'ABB'], 'category' => $it['cat'], 'url' => $url,
        'description' => $d1 . ' ' . $d2, 'image' => [$imgAbs], 'offers' => $offer];
    $ldJson = str_replace('<', '\\' . 'u003c', abv_pyjson($ld));   // «<» внутри <script> — всегда экранировать
    $headLine = '<meta content="product" property="og:type"/><meta content="' . abv_a($title) . '" property="og:title"/>'
        . '<meta content="' . abv_a($descr) . '" property="og:description"/><meta content="' . $url . '" property="og:url"/>'
        . '<meta content="АБВ Электросервис" property="og:site_name"/><meta content="ru_RU" property="og:locale"/>'
        . '<link href="' . $url . '" rel="canonical"/><meta content="' . abv_a($imgAbs) . '" property="og:image"/>'
        . '<script type="application/ld+json">' . $ldJson . '</script></head>';

    $subj = abv_quote('Запрос: ' . $sku . ' — ' . $it['name']);
    $body = abv_quote("Прошу уточнить наличие и цену:\n" . $sku . ' — ' . $it['name'] . "\nКоличество: \n");
    $priceTxt = abv_money($it['price']);
    $minTxt = $it['min_zakaz'] ? 'от ' . abv_money($it['min_zakaz']) . ABV_NBSP . '₽ с' . ABV_NBSP . 'НДС' : '';
    $terms = [];
    if ($it['min_zakaz']) {
        $terms[] = '<li>Минимальная сумма заказа — <b>' . $minTxt . '</b></li>';
    }
    if ($it['srok']) {
        $terms[] = '<li>Срок поставки: <b>' . abv_t($it['srok']) . '</b></li>';
    }
    $spec = function (string $k, string $v, string $cls = ''): string {
        return '<div class="abv-spec"><dt>' . $k . '</dt><span class="abv-lead"></span><dd class="' . $cls . '">' . $v . '</dd></div>';
    };
    $shield = '<svg fill="none" stroke="#2fa652" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" '
        . 'viewbox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"></path>'
        . '<path d="M9 12l2 2 4-4"></path></svg>';
    $copySvg = '<svg fill="none" stroke="#F5821E" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" '
        . 'viewbox="0 0 24 24"><rect height="11" rx="2" width="11" x="9" y="9"></rect>'
        . '<path d="M5 15V5a2 2 0 0 1 2-2h8"></path></svg>';
    $stock = $est ? '<p class="abv-stock"><i></i>В наличии ' . $it['qty'] . ' шт. · склад в Москве</p>'
        : '<p class="abv-stock" style="color:#c8650f;background:#fff4e9"><i style="background:#F5821E;box-shadow:0 0 0 4px rgba(245,130,30,.18)"></i>'
          . 'Под заказ · срок уточняйте у менеджера</p>';
    $lines = [
        '<nav class="abv-crumbs"><a href="index.html">Главная</a><span>/</span><a href="sklad.html">Склад</a>'
        . '<span>/</span><span>' . $sku . '</span></nav>',
        '<div class="abv-hero">',
        '<div class="abv-gal"><div class="abv-main-img"><span class="abv-chip-orig">' . $shield . 'Оригинал ABB</span>'
        . '<img alt="' . abv_a($it['name']) . '" src="' . abv_a($it['img']) . '" style="view-transition-name:p-' . $sku . '"/></div></div>',
        '<div>',
        '<p class="abv-eyebrow">' . abv_t($it['cat']) . '</p>',
        '<h1 class="abv-title" style="view-transition-name:t-' . $sku . '">' . abv_t($it['name']) . '</h1>',
        '<div class="abv-meta">',
        '<span class="abv-sku">Артикул <b>' . $sku . '</b><span class="abv-copy" data-copy="' . $sku . '" role="button" '
        . 'title="Скопировать артикул">' . $copySvg . '</span></span>',
        '<span class="abv-brand">ABB</span>',
        '</div>',
        $stock,
        '<div class="abv-pricebox">',
        '<p class="abv-price-lbl">Цена за 1 шт.</p><p class="abv-bigprice">' . $priceTxt . ABV_NBSP . '<span>₽</span>'
        . '<small>с НДС</small></p>',
    ];
    if ($terms) {
        $lines[] = '<ul class="abv-terms">' . implode('', $terms) . '</ul>';
    }
    $lines[] = '<div class="abv-actions">';
    $lines[] = '<a class="abv-btn abv-btn-main" data-code="' . $sku . '" data-name="' . abv_a($it['name']) . '" '
        . 'href="mailto:info@abv-es.ru?subject=' . $subj . '&amp;body=' . $body . '" onclick="abvRequest(event)">ЗАПРОСИТЬ</a>';
    $lines[] = $ref['actions_tail'];
    $desc = '<div class="abv-panel"><h2 class="abv-h2">Описание</h2><div class="abv-desc"><p>' . abv_t($d1) . '</p>'
        . '<p>' . abv_t($d2) . '</p></div></div>';
    if ($it['dostavka']) {
        $dost = [['По Москве и Московской области', 'бесплатно при заказе от 50' . ABV_NBSP . '000' . ABV_NBSP . '₽ с' . ABV_NBSP . 'НДС', 'ok'],
            ['В другие регионы', 'бесплатно при заказе от 50' . ABV_NBSP . '000' . ABV_NBSP . '₽ с' . ABV_NBSP . 'НДС', 'ok'],
            ['Самовывоз из офиса', 'Москва, ул. Профсоюзная, 57', '']];
        $dl = '';
        foreach ($dost as $row) {
            $dl .= $spec($row[0], $row[1], $row[2]);
        }
        $desc = implode($nl, ['<div class="abv-side">', $desc,
            '<div class="abv-panel"><h2 class="abv-h2">Доставка и самовывоз</h2><dl class="abv-specs">' . $dl . '</dl></div>',
            '</div>']);
    }
    $rel = '';
    foreach (abv_related($it, $items) as $r) {
        $rel .= abv_render_tile($r, false);
    }
    $lines[] = '<div class="abv-info">';
    $lines[] = '<div class="abv-panel"><h2 class="abv-h2">Характеристики</h2><dl class="abv-specs">'
        . $spec('Артикул', $sku) . $spec('Производитель', 'ABB') . $spec('Категория', abv_t($it['cat']))
        . ($est ? $spec('Наличие', $it['qty'] . ' шт.', 'ok') : $spec('Наличие', 'под заказ'))
        . $spec('Склад', 'Москва, ул. Профсоюзная, 57')
        . ($it['srok'] ? $spec('Срок поставки', abv_t($it['srok'])) : '')
        . $spec('Цена', $priceTxt . ABV_NBSP . '₽ с НДС', 'pr')
        . ($it['min_zakaz'] ? $spec('Минимальный заказ', $minTxt, 'pr') : '') . '</dl>';
    $lines[] = $ref['note'];
    $lines[] = $desc;
    $lines[] = '</div>';
    $lines[] = '<div class="abv-psec"><h2 class="abv-h2">Другие товары со склада</h2><div class="abv-rel">' . $rel . '</div></div>';
    $block = implode($nl, $lines);

    $out = $ref['html'];
    $out = abv_sub_once($out, '~<title>.*?</title>~s', '<title>' . abv_t($title) . '</title>', 'title');
    $out = abv_sub_once($out, '~<meta content="[^"]*" name="description"/>~s', '<meta content="' . abv_a($descr) . '" name="description"/>', 'meta description');
    $out = abv_sub_once($out, '~<meta content="product" property="og:type"/>.*?</head>~s', $headLine, 'og/ld+json');
    $out = abv_sub_once($out, '~<nav class="abv-crumbs">.*?(?=\r?\n</div>\r?\n<script>\r?\ndocument\.querySelectorAll)~s', $block, 'тело карточки');
    if ($it['srok'] || $it['min_zakaz'] || $it['dostavka']) {
        $out = abv_sub_once($out, '~</style>~', str_replace("\n", $nl, ABV_TERMS_CSS) . '</style>', '</style> карточки');
    }
    return $out;
}

// ---------- фид Яндекса ----------

function abv_x(string $s): string { return abv_t($s); }

function abv_render_offer(array $it, int $cid, string $nl): string
{
    $desc = abv_desc1($it) . ' ' . ABV_DESC_2;
    $rows = [
        '      <offer id="' . $it['sku'] . '" available="' . ($it['qty'] > 0 ? 'true' : 'false') . '">',
        '        <name>' . abv_x($it['name']) . '</name>',
        '        <vendor>ABB</vendor>',
        '        <vendorCode>' . $it['sku'] . '</vendorCode>',
        '        <url>' . ABV_SITE . 'tovar-' . $it['sku'] . '.html</url>',
        '        <price>' . $it['price'] . '</price>',
        '        <currencyId>RUR</currencyId>',
        '        <categoryId>' . $cid . '</categoryId>',
        '        <picture>' . abv_x(ABV_SITE . explode('?', $it['img'])[0]) . '</picture>',
        '        <description>' . abv_x($desc) . '</description>',
        '        <count>' . $it['qty'] . '</count>',
        '        <manufacturer_warranty>true</manufacturer_warranty>',
        '        <param name="Артикул">' . $it['sku'] . '</param>',
        '      </offer>',
    ];
    return implode($nl, $rows) . $nl;
}

function abv_render_feed(?array $d = null): string
{
    $d = $d ?? abv_data();
    $f = abv_file(ABV_FEED);
    $nl = strpos($f, "\r\n") !== false ? "\r\n" : "\n";
    $cats = $d['feed_cats'];
    $next = $cats ? max(array_map('intval', $cats)) + 1 : 1;
    foreach ($d['tovary'] as $t) {
        if (!isset($cats[$t['cat']])) {
            $cats[$t['cat']] = $next++;
        }
    }
    $used = abv_categories($d['tovary']);
    $byId = [];
    foreach ($cats as $name => $id) {
        if (isset($used[$name])) {
            $byId[(int)$id] = (string)$name;
        }
    }
    ksort($byId);
    $catXml = '';
    foreach ($byId as $id => $name) {
        $catXml .= '      <category id="' . $id . '">' . abv_x($name) . '</category>' . $nl;
    }
    $offers = '';
    foreach ($d['tovary'] as $t) {
        $offers .= abv_render_offer($t, (int)$cats[$t['cat']], $nl);
    }
    $f = abv_sub_once($f, '~(?<=<yml_catalog date=")[^"]*(?=")~', $d['feed_date'], 'дата фида');
    $f = abv_sub_once($f, '~(?<=<categories>' . preg_quote($nl) . ').*?(?=    </categories>)~s', $catXml, 'категории фида');
    $f = abv_sub_once($f, '~(?<=<offers>' . preg_quote($nl) . ').*?(?=    </offers>)~s', $offers, 'товары фида');
    return $f;
}

// ---------- sitemap.xml (как tools/make_sitemap.py, товары — из данных) ----------

function abv_render_sitemap(?array $d = null): string
{
    $d = $d ?? abv_data();
    $root = abv_root();
    $pages = [];
    foreach (scandir($root) ?: [] as $f) {
        if (substr($f, -5) !== '.html' || !is_file("$root/$f") || $f === 'source.html' || strpos($f, 'tovar-') === 0
            || fnmatch('yandex_*.html', $f) || fnmatch('google*.html', $f)) {
            continue;
        }
        $txt = (string)@file_get_contents("$root/$f");
        $noindex = false;
        if (preg_match_all('~<meta\b[^>]*>~i', $txt, $mm)) {
            foreach ($mm[0] as $tag) {
                if (preg_match('~\bname\s*=\s*["\']?robots\b~i', $tag) && preg_match('~noindex|\bnone\b~i', $tag)) {
                    $noindex = true;
                }
            }
        }
        if (!$noindex) {
            $pages[] = $f;
        }
    }
    sort($pages, SORT_STRING);
    $tov = array_map(function ($t) { return 'tovar-' . $t['sku'] . '.html'; }, $d['tovary']);
    sort($tov, SORT_STRING);
    $urls = [];
    if (in_array('index.html', $pages, true)) {
        $urls[] = ABV_SITE;
    }
    foreach ($pages as $p) {
        if ($p !== 'index.html') {
            $urls[] = ABV_SITE . $p;
        }
    }
    foreach ($tov as $p) {
        $urls[] = ABV_SITE . $p;
    }
    $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
    foreach ($urls as $u) {
        $lines[] = '  <url><loc>' . abv_t($u) . '</loc></url>';
    }
    $lines[] = '</urlset>';
    return implode("\n", $lines) . "\n";
}
