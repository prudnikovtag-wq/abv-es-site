<?php
/*
 * Админка склада abv-es.ru: вход по паролю, защита форм, оформление страниц, правка товаров и фото.
 */
declare(strict_types=1);
require __DIR__ . '/magazin.lib.php';
require __DIR__ . '/config.php';

const ABV_MAX_FAILS = 5;            // неверных паролей подряд с одного адреса…
const ABV_BLOCK_MIN = 15;           // …и вход с него закрыт на столько минут
const ABV_IDLE_HOURS = 8;           // без действий дольше — нужно войти заново
const ABV_FOTO_MAX_MB = 15;

// ---------- сессия и вход ----------

function abv_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function abv_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('abv_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => abv_https(),
        'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (!empty($_SESSION['ok']) && time() - (int)($_SESSION['seen'] ?? 0) > ABV_IDLE_HOURS * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
}

function abv_logged_in(): bool { return !empty($_SESSION['ok']); }

function abv_password_hash(): string
{
    $p = abv_read_guarded(abv_dir() . '/parol.php');
    return (string)($p['hash'] ?? ABV_ADMIN_HASH);
}

function abv_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '?'); }

/** Счётчик неудачных входов по адресам (файл admin-data/vhod.php). */
function abv_fails(?array $set = null): array
{
    $f = abv_dir() . '/vhod.php';
    if ($set !== null) {
        abv_prepare_dirs();
        $now = time();
        $set = array_filter($set, function ($v) use ($now) { return ($v['until'] ?? 0) > $now || ($v['last'] ?? 0) > $now - 86400; });
        abv_write_guarded($f, $set);
        return $set;
    }
    return abv_read_guarded($f) ?? [];
}

/** null — вошёл; иначе текст ошибки. */
function abv_try_login(string $password): ?string
{
    $all = abv_fails();
    $ip = abv_ip();
    $me = $all[$ip] ?? ['n' => 0, 'until' => 0, 'last' => 0];
    if (($me['until'] ?? 0) > time()) {
        $min = (int)ceil(($me['until'] - time()) / 60);
        return "Слишком много неверных попыток. Вход с этого устройства закрыт ещё на $min мин.";
    }
    if ($password !== '' && password_verify($password, abv_password_hash())) {
        unset($all[$ip]);
        abv_fails($all);
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return null;
    }
    $me['n'] = (int)($me['n'] ?? 0) + 1;
    $me['last'] = time();
    if ($me['n'] >= ABV_MAX_FAILS) {
        $me = ['n' => 0, 'until' => time() + ABV_BLOCK_MIN * 60, 'last' => time()];
    }
    $all[$ip] = $me;
    abv_fails($all);
    usleep(800000);
    if (!empty($me['until'])) {
        return 'Слишком много неверных попыток. Вход с этого устройства закрыт на ' . ABV_BLOCK_MIN . ' мин.';
    }
    $left = ABV_MAX_FAILS - $me['n'];
    return "Неверный пароль. Осталось попыток: $left.";
}

function abv_set_password(string $new): void
{
    abv_prepare_dirs();
    abv_write_guarded(abv_dir() . '/parol.php', ['hash' => password_hash($new, PASSWORD_DEFAULT), 'izmenen' => date('c')]);
}

function abv_csrf_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals((string)($_SESSION['csrf'] ?? ''), $_POST['csrf']);
}

function abv_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function abv_flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$kind, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function abv_redirect(string $q = ''): void
{
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/') . '/';
    header('Location: ' . $base . ($q !== '' ? '?' . $q : ''), true, 303);
    exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- проверка введённых данных ----------

/** Убирает невидимые и управляющие символы, лишние пробелы. */
function abv_clean(string $s): string
{
    $s = preg_replace('~[\x{0000}-\x{001F}\x{007F}\x{2028}\x{2029}]~u', ' ', $s) ?? '';
    $s = preg_replace('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]~u', '', $s) ?? '';
    return trim(preg_replace('~\s+~u', ' ', $s) ?? '');
}

function abv_lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }

function abv_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('~.~us', $s); }

/** Целое число из поля формы: «12 500», «12500 ₽» → 12500; пусто → null; не число → false. */
function abv_int_field($v)
{
    $s = preg_replace('~[\s\x{00A0}₽рРуб\.]+~u', '', (string)$v) ?? '';
    if ($s === '') {
        return null;
    }
    return preg_match('~^\d{1,10}$~', $s) ? (int)$s : false;
}

/** Артикул: пробелы убираются, буквы — заглавные латинские. */
function abv_sku_field(string $s): string
{
    $s = strtoupper(preg_replace('~\s+~u', '', abv_clean($s)) ?? '');
    return $s;
}

/**
 * Проверяет данные товара из формы. Возвращает [товар, ошибки].
 * $old — товар до правки (null — новый).
 */
function abv_item_from_post(array $p, ?array $old, array $tovary): array
{
    $err = [];
    $sku = $old ? $old['sku'] : abv_sku_field((string)($p['sku'] ?? ''));
    if (!$old) {
        if ($sku === '') {
            $err['sku'] = 'Укажите артикул.';
        } elseif (!preg_match(ABV_SKU_RE, $sku)) {
            $err['sku'] = 'Артикул — только латинские буквы, цифры и дефис (например, 1SDA073725R1).';
        } elseif (abv_find($tovary, $sku) !== null) {
            $err['sku'] = 'Товар с таким артикулом уже есть на складе.';
        }
    }
    $name = abv_clean((string)($p['name'] ?? ''));
    if ($name === '') {
        $err['name'] = 'Укажите название.';
    } elseif (abv_len($name) > 200) {
        $err['name'] = 'Слишком длинное название (больше 200 знаков).';
    }
    $cat = abv_clean((string)(($p['cat'] ?? '') === '__new' ? ($p['cat_new'] ?? '') : ($p['cat'] ?? '')));
    if ($cat === '') {
        $err['cat'] = 'Выберите раздел или впишите новый.';
    } elseif (abv_len($cat) > 80) {
        $err['cat'] = 'Слишком длинное название раздела.';
    }
    $qty = abv_int_field($p['qty'] ?? '');
    if ($qty === null || $qty === false || $qty > 10000000) {
        $err['qty'] = 'Количество — целое число штук (0 — «под заказ»).';
    }
    $price = abv_int_field($p['price'] ?? '');
    if ($price === null || $price === false || $price < 1 || $price > 1000000000) {
        $err['price'] = 'Цена — целое число рублей с НДС, больше нуля.';
    }
    $srok = abv_clean((string)($p['srok'] ?? ''));
    if (abv_len($srok) > 60) {
        $err['srok'] = 'Слишком длинный срок поставки.';
    }
    $mz = abv_int_field($p['min_zakaz'] ?? '');
    if ($mz === false || ($mz !== null && $mz > 1000000000)) {
        $err['min_zakaz'] = 'Минимальная сумма — целое число рублей или пусто.';
    }
    $it = ['sku' => $sku, 'name' => $name, 'qty' => is_int($qty) ? $qty : 0, 'price' => is_int($price) ? $price : 0,
        'cat' => $cat, 'img' => $old ? $old['img'] : 'images/net-foto.jpg', 'srok' => $srok === '' ? null : $srok,
        'min_zakaz' => is_int($mz) && $mz > 0 ? $mz : null, 'dostavka' => !empty($p['dostavka'])];
    return [$it, $err];
}

/** Вставляет/обновляет товар: на своём месте; при смене раздела или новый — в конец своего раздела. */
function abv_upsert(array $tovary, array $it): array
{
    $idx = abv_find($tovary, $it['sku']);
    if ($idx !== null) {
        if ($tovary[$idx]['cat'] === $it['cat']) {
            $tovary[$idx] = $it;
            return $tovary;
        }
        array_splice($tovary, $idx, 1);
    }
    $last = null;
    foreach ($tovary as $k => $t) {
        if ($t['cat'] === $it['cat']) {
            $last = $k;
        }
    }
    if ($last === null) {
        $tovary[] = $it;
    } else {
        array_splice($tovary, $last + 1, 0, [$it]);
    }
    return array_values($tovary);
}

/** Что изменилось в товаре — для журнала. */
function abv_diff_note(array $a, array $b): string
{
    $names = ['name' => 'название', 'cat' => 'раздел', 'qty' => 'кол-во', 'price' => 'цена', 'srok' => 'срок',
        'min_zakaz' => 'мин. заказ', 'dostavka' => 'доставка', 'img' => 'фото'];
    $out = [];
    foreach ($names as $k => $n) {
        if ($a[$k] !== $b[$k]) {
            if ($k === 'qty' || $k === 'price') {
                $out[] = "$n {$a[$k]} → {$b[$k]}";
            } else {
                $out[] = $n;
            }
        }
    }
    return implode(', ', $out);
}

// ---------- фото ----------

/**
 * Принимает загруженное фото и делает картинку как у остальных товаров: белый фон, обрезка полей,
 * вписать в 600×460 по центру холста 720×540, JPEG. Возвращает путь от корня сайта (foto/…).
 */
function abv_save_foto(array $file, string $sku): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Фото слишком большое для хостинга. Уменьшите его (до 5–10 МБ) и загрузите снова.');
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new InvalidArgumentException('Фото не загрузилось. Попробуйте ещё раз.');
    }
    if ((int)$file['size'] > ABV_FOTO_MAX_MB * 1024 * 1024) {
        throw new InvalidArgumentException('Фото больше ' . ABV_FOTO_MAX_MB . ' МБ — уменьшите его.');
    }
    $info = @getimagesize((string)$file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new InvalidArgumentException('Это не фото. Подходят файлы JPG, PNG или WEBP.');
    }
    if ($info[0] * $info[1] > 40000000) {
        throw new InvalidArgumentException('Слишком большое разрешение фото — уменьшите его.');
    }
    $dir = abv_foto_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('не удалось создать папку для фото');
    }
    $name = 'tovar-' . $sku . '-' . bin2hex(random_bytes(3)) . '.jpg';
    $dest = $dir . '/' . $name;
    $fn = 'imagecreatefrom' . $types[$info[2]];
    if (!function_exists('imagecreatetruecolor') || !function_exists($fn)) {
        // нет обработки картинок на хостинге — JPEG кладём как есть, остальное не принимаем
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('На хостинге нет обработки картинок — загрузите фото в формате JPG.');
        }
        if (!@move_uploaded_file((string)$file['tmp_name'], $dest)) {
            throw new RuntimeException('не удалось сохранить фото');
        }
        @chmod($dest, 0644);
        return 'foto/' . $name;
    }
    $src = @$fn((string)$file['tmp_name']);
    if (!$src) {
        throw new InvalidArgumentException('Не удалось прочитать фото. Сохраните его как JPG и попробуйте снова.');
    }
    $w = imagesx($src);
    $hh = imagesy($src);
    // на белый фон (прозрачные PNG/WEBP)
    $flat = imagecreatetruecolor($w, $hh);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $src, 0, 0, 0, 0, $w, $hh);
    imagedestroy($src);
    // обрезка почти белых полей
    [$x0, $y0, $x1, $y1] = abv_trim_box($flat, $w, $hh);
    $cw = $x1 - $x0 + 1;
    $ch = $y1 - $y0 + 1;
    $scale = min(600 / $cw, 460 / $ch, 3.0);
    $nw = max(1, (int)round($cw * $scale));
    $nh = max(1, (int)round($ch * $scale));
    $out = imagecreatetruecolor(720, 540);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $flat, (int)((720 - $nw) / 2), (int)((540 - $nh) / 2), $x0, $y0, $nw, $nh, $cw, $ch);
    imagedestroy($flat);
    imageinterlace($out, true);
    $ok = imagejpeg($out, $dest, 85);
    imagedestroy($out);
    if (!$ok) {
        throw new RuntimeException('не удалось сохранить фото');
    }
    @chmod($dest, 0644);
    return 'foto/' . $name;
}

/** Рамка без белых полей (порог 245). Если всё белое — вся картинка. */
function abv_trim_box($im, int $w, int $h): array
{
    $step = max(1, (int)floor(max($w, $h) / 1200));     // большие фото проверяем через пиксель-другой
    $white = function (int $x, int $y) use ($im): bool {
        $c = imagecolorat($im, $x, $y);
        return (($c >> 16) & 255) > 244 && (($c >> 8) & 255) > 244 && ($c & 255) > 244;
    };
    $rowWhite = function (int $y) use ($w, $step, $white): bool {
        for ($x = 0; $x < $w; $x += $step) {
            if (!$white($x, $y)) {
                return false;
            }
        }
        return true;
    };
    $colWhite = function (int $x, int $y0, int $y1) use ($step, $white): bool {
        for ($y = $y0; $y <= $y1; $y += $step) {
            if (!$white($x, $y)) {
                return false;
            }
        }
        return true;
    };
    $y0 = 0;
    while ($y0 < $h - 1 && $rowWhite($y0)) $y0++;
    if ($y0 >= $h - 1) {
        return [0, 0, $w - 1, $h - 1];
    }
    $y1 = $h - 1;
    while ($y1 > $y0 && $rowWhite($y1)) $y1--;
    $x0 = 0;
    while ($x0 < $w - 1 && $colWhite($x0, $y0, $y1)) $x0++;
    $x1 = $w - 1;
    while ($x1 > $x0 && $colWhite($x1, $y0, $y1)) $x1--;
    $pad = (int)round(max($x1 - $x0, $y1 - $y0) * 0.02);   // небольшой отступ, чтобы не резать впритык
    return [max(0, $x0 - $pad), max(0, $y0 - $pad), min($w - 1, $x1 + $pad), min($h - 1, $y1 + $pad)];
}

// ---------- оформление ----------

function abv_page_start(string $title, bool $nav = true): void
{
    $nonce = base64_encode(random_bytes(12));
    $GLOBALS['abv_nonce'] = $nonce;
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' "
        . "https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'nonce-$nonce'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'");
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<meta name="color-scheme" content="dark"><meta name="theme-color" content="#121110">'
        . '<title>' . h($title) . ' — админка склада</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="admin.css?v=20261026b"></head><body' . ($nav ? '' : ' class="bare"') . '>';
    echo '<header class="top"><a class="logo" href="./"><b>АБВ</b><span>Склад</span></a>';
    if ($nav) {
        $a = (string)($_GET['a'] ?? '');
        $on = function (string $k) use ($a): string { return ($a === $k || ($k === '' && $a === 'edit')) ? ' class="on" aria-current="page"' : ''; };
        echo '<nav><a href="./"' . $on('') . '>Товары</a><a href="./?a=new"' . $on('new') . '>Добавить</a>'
            . '<a href="./?a=history"' . $on('history') . '>История</a><a href="./?a=password"' . $on('password') . '>Пароль</a>'
            . '<a class="ext" href="../sklad.html" target="_blank" rel="noopener">Склад на сайте ↗</a>'
            . '<form method="post" action="./?a=logout">' . abv_csrf_field() . '<button class="linkbtn">Выйти</button></form></nav>';
    }
    echo '</header><main>';
    $f = abv_flash();
    if ($f) {
        echo '<div class="flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
}

function abv_page_end(string $js = ''): void
{
    echo '</main>';
    if ($js !== '') {
        echo '<script nonce="' . h($GLOBALS['abv_nonce']) . '">' . $js . '</script>';
    }
    echo '</body></html>';
}

function abv_thumb(string $img): string
{
    return '../' . h($img);
}
