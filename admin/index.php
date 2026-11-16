<?php
/*
 * Админка склада abv-es.ru — https://abv-es.ru/admin/
 * Товары: список с поиском, быстрая правка количества и цены, карточка товара (добавить / изменить / удалить, фото),
 * история изменений с возвратом, выгрузка в Excel (CSV), смена пароля.
 */
declare(strict_types=1);
require __DIR__ . '/inc/admin.lib.php';

abv_session();
$a = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// Запрос больше post_max_size: PHP отбрасывает всю форму целиком — говорим про размер фото, а не «страница устарела»
if ($post && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    abv_flash('Файл слишком большой для хостинга (больше ' . h(ini_get('post_max_size')) . '). '
        . 'Уменьшите фото и повторите — введённое в форму придётся заполнить заново.', 'err');
    abv_redirect($a === 'save' ? 'a=' . (is_string($_GET['sku'] ?? null) ? 'edit&sku=' . rawurlencode($_GET['sku']) : 'new') : '');
}
if ($post && !abv_csrf_ok()) {
    http_response_code(400);
    abv_page_start('Ошибка', false);
    echo '<div class="box"><h1>Страница устарела</h1><p>Форма была открыта слишком давно или в другой вкладке. '
        . '<a href="./">Откройте админку заново</a> и повторите действие.</p></div>';
    abv_page_end();
    exit;
}

// ---------- вход ----------
if (!abv_logged_in()) {
    $err = null;
    if ($post && $a === 'login') {
        try {
            $err = abv_try_login((string)($_POST['password'] ?? ''));
        } catch (Throwable $e) {
            $err = 'Не удалось проверить пароль: ' . $e->getMessage();
        }
        if ($err === null) {
            abv_redirect();
        }
    }
    abv_page_start('Вход', false);
    echo '<form class="box login" method="post" action="./?a=login">' . abv_csrf_field()
        . '<h1>Вход в админку склада</h1>'
        . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<label>Пароль<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button class="btn">Войти</button></form>';
    abv_page_end();
    exit;
}

try {
    // ---------- действия (POST) ----------
    if ($post) {
        $d = abv_data(true);
        $ver = (string)($_POST['ver'] ?? '');
        if ($a === 'logout') {
            $_SESSION = [];
            session_destroy();
            abv_redirect();
        }
        if ($a === 'bulk') {                       // быстрая правка количества и цены из списка
            $qty = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
            $price = is_array($_POST['price'] ?? null) ? $_POST['price'] : [];
            $notes = [];
            $errs = [];
            $qtyChanged = false;
            foreach ($d['tovary'] as $i => $t) {
                $sku = $t['sku'];
                if (array_key_exists($sku, $qty)) {
                    $q = abv_int_field($qty[$sku]);
                    if (!is_int($q) || $q > 10000000) {
                        $errs[] = "$sku: количество «" . abv_clean((string)$qty[$sku]) . '» — не целое число';
                    } elseif ($q !== $t['qty']) {
                        $notes[] = "$sku кол-во {$t['qty']} → $q";
                        $d['tovary'][$i]['qty'] = $q;
                        $qtyChanged = true;
                    }
                }
                if (array_key_exists($sku, $price)) {
                    $p = abv_int_field($price[$sku]);
                    if (!is_int($p) || $p < 1 || $p > 1000000000) {
                        $errs[] = "$sku: цена «" . abv_clean((string)$price[$sku]) . '» — нужно целое число рублей больше нуля';
                    } elseif ($p !== $t['price']) {
                        $notes[] = "$sku цена {$t['price']} → $p";
                        $d['tovary'][$i]['price'] = $p;
                    }
                }
            }
            if ($errs) {
                abv_flash('Ничего не сохранено, исправьте: ' . implode('; ', array_slice($errs, 0, 5))
                    . (count($errs) > 5 ? ' и ещё ' . (count($errs) - 5) : ''), 'err');
                abv_redirect();
            }
            if (!$notes) {
                abv_flash('Изменений нет.', 'info');
                abv_redirect();
            }
            if ($qtyChanged) {
                $d['ostatki'] = abv_date_ru();
            }
            $n = count($notes);
            abv_save($d, 'Быстрая правка: ' . implode('; ', array_slice($notes, 0, 12)) . ($n > 12 ? '; и ещё ' . ($n - 12) : ''), $ver);
            abv_flash("Сохранено ($n " . ($n === 1 ? 'изменение' : ($n < 5 ? 'изменения' : 'изменений')) . '). ' . abv_live_note());
            abv_redirect();
        }
        if ($a === 'save') {                       // карточка товара: новый или правка
            $sku = (string)($_POST['old_sku'] ?? '');
            $idx = $sku !== '' ? abv_find($d['tovary'], $sku) : null;
            if ($sku !== '' && $idx === null) {
                abv_flash("Товар $sku не найден — возможно, его уже удалили.", 'err');
                abv_redirect();
            }
            $old = $idx !== null ? $d['tovary'][$idx] : null;
            [$it, $err] = abv_item_from_post($_POST, $old, $d['tovary']);
            $foto = $_FILES['foto'] ?? null;
            if (!$err && $foto && ($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $it['img'] = abv_save_foto($foto, $it['sku']);
                } catch (InvalidArgumentException $e) {
                    $err['foto'] = $e->getMessage();
                }
            }
            if ($err) {
                $_SESSION['form'] = ['post' => $_POST, 'err' => $err];
                abv_redirect($old ? 'a=edit&sku=' . rawurlencode($old['sku']) : 'a=new');
            }
            if ($old && $old === $it) {
                abv_flash('Изменений нет.', 'info');
                abv_redirect('a=edit&sku=' . rawurlencode($it['sku']));
            }
            if (!$old || $old['qty'] !== $it['qty']) {
                $d['ostatki'] = abv_date_ru();
            }
            $d['tovary'] = abv_upsert($d['tovary'], $it);
            $note = $old ? 'Изменён товар ' . $it['sku'] . ': ' . abv_diff_note($old, $it)
                : 'Добавлен товар ' . $it['sku'] . ' — ' . $it['name'];
            try {
                abv_save($d, $note, $ver);
            } catch (AbvConflict $e) {
                // данные поменялись в другой вкладке: новое фото не нужно, введённое сохраняем для повтора
                if ($it['img'] !== ($old['img'] ?? '') && strpos($it['img'], 'foto/') === 0) {
                    @unlink(abv_root() . '/' . $it['img']);
                }
                $_SESSION['form'] = ['post' => $_POST, 'err' => ['conflict' => $e->getMessage()]];
                abv_redirect($old ? 'a=edit&sku=' . rawurlencode($old['sku']) : 'a=new');
            }
            abv_flash(($old ? 'Товар сохранён' : 'Товар добавлен') . '. ' . abv_live_note());
            abv_redirect('a=edit&sku=' . rawurlencode($it['sku']));
        }
        if ($a === 'delete') {
            $sku = (string)($_POST['sku'] ?? '');
            $idx = abv_find($d['tovary'], $sku);
            if ($idx === null) {
                abv_flash("Товар $sku не найден.", 'err');
                abv_redirect();
            }
            $t = $d['tovary'][$idx];
            array_splice($d['tovary'], $idx, 1);
            $d['ostatki'] = abv_date_ru();
            abv_save($d, 'Удалён товар ' . $t['sku'] . ' — ' . $t['name'], $ver);
            abv_flash('Товар ' . $t['sku'] . ' удалён с сайта. Если ошиблись — его можно вернуть в «Истории».');
            abv_redirect();
        }
        if ($a === 'restore') {
            $file = basename((string)($_POST['file'] ?? ''));
            $k = preg_match('~^tovary-\d{8}-\d{6}-\d{6}\.php$~', $file) ? abv_read_guarded(abv_kopii_dir() . '/' . $file) : null;
            if (!$k || !isset($k['tovary'])) {
                abv_flash('Копия не найдена.', 'err');
                abv_redirect('a=history');
            }
            $when = $k['izmeneno'] ? date('d.m.Y H:i', strtotime($k['izmeneno'])) : 'начала работы админки';
            $was = (string)($k['kopiya_note'] ?? '');
            unset($k['kopiya_note']);
            abv_save($k, 'Возврат к состоянию от ' . $when . ($was !== '' ? ' («' . (abv_len($was) > 90 ? (function_exists('mb_substr') ? mb_substr($was, 0, 90, 'UTF-8') : substr($was, 0, 90)) . '…' : $was) . '»)' : ''), null);
            abv_flash('Склад возвращён к состоянию от ' . $when . '.');
            abv_redirect('a=history');
        }
        if ($a === 'password') {
            $cur = (string)($_POST['cur'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $rep = (string)($_POST['rep'] ?? '');
            if (!password_verify($cur, abv_password_hash())) {
                abv_flash('Текущий пароль неверный.', 'err');
            } elseif (abv_len($new) < 10) {
                abv_flash('Новый пароль слишком короткий — нужно не меньше 10 знаков.', 'err');
            } elseif ($new !== $rep) {
                abv_flash('Новый пароль и повтор не совпадают.', 'err');
            } else {
                abv_set_password($new);
                session_regenerate_id(true);
                $_SESSION['pw'] = abv_pw_mark();   // этот вход остаётся, остальные — нет
                abv_flash('Пароль изменён. Запомните или запишите его.');
                abv_redirect();
            }
            abv_redirect('a=password');
        }
        abv_redirect();
    }

    // ---------- страницы (GET) ----------
    $d = abv_data(true);
    $ver = abv_version($d);

    if ($a === 'csv') {                            // выгрузка для Excel
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sklad-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $row = function (array $r) use ($out) {
            fwrite($out, implode(';', array_map(function ($v) {
                $v = (string)$v;
                if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
                    $v = "'" . $v;                 // чтобы Excel не принял текст за формулу
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, $r)) . "\r\n");
        };
        $row(['Артикул', 'Название', 'Раздел', 'Количество, шт.', 'Цена с НДС, ₽', 'Срок поставки', 'Мин. сумма заказа, ₽', 'Доставка', 'Страница']);
        foreach ($d['tovary'] as $t) {
            $row([$t['sku'], $t['name'], $t['cat'], $t['qty'], $t['price'], $t['srok'] ?? '', $t['min_zakaz'] ?? '',
                $t['dostavka'] ? 'да' : 'нет', ABV_SITE . 'tovar-' . $t['sku'] . '.html']);
        }
        exit;
    }

    if ($a === 'new' || $a === 'edit') {
        $old = null;
        if ($a === 'edit') {
            $idx = abv_find($d['tovary'], (is_string($_GET['sku'] ?? null) ? $_GET['sku'] : ''));
            if ($idx === null) {
                abv_flash('Товар не найден.', 'err');
                abv_redirect();
            }
            $old = $d['tovary'][$idx];
        }
        $form = $_SESSION['form'] ?? null;
        unset($_SESSION['form']);
        $err = $form['err'] ?? [];
        $v = $old ?? ['sku' => '', 'name' => '', 'qty' => '', 'price' => '', 'cat' => '', 'img' => 'images/net-foto.jpg',
            'srok' => '1–2 недели', 'min_zakaz' => 40000, 'dostavka' => true];
        if ($form) {                               // показать то, что ввели, вместе с ошибками
            $p = $form['post'];
            foreach (['name', 'qty', 'price', 'srok', 'min_zakaz'] as $k) {
                $v[$k] = (string)($p[$k] ?? '');
            }
            if (!$old) {
                $v['sku'] = (string)($p['sku'] ?? '');
            }
            $v['cat'] = ($p['cat'] ?? '') === '__new' ? (string)($p['cat_new'] ?? '') : (string)($p['cat'] ?? '');
            $v['dostavka'] = !empty($p['dostavka']);
        }
        $cats = array_keys(abv_categories($d['tovary']));
        $isNewCat = $v['cat'] !== '' && !in_array($v['cat'], $cats, true);
        $e = function (string $k) use ($err): string { return isset($err[$k]) ? '<span class="ferr">' . h($err[$k]) . '</span>' : ''; };
        abv_page_start($old ? $old['sku'] : 'Новый товар');
        echo '<p class="crumbs"><a href="./">← Все товары</a></p>';
        echo '<h1>' . ($old ? h($old['name']) : 'Новый товар') . '</h1>';
        if (isset($err['conflict'])) {
            echo '<div class="flash err">' . h($err['conflict']) . ' Введённое ниже сохранилось (кроме фото) — '
                . 'проверьте и нажмите кнопку ещё раз.</div>';
        } elseif ($err) {
            echo '<div class="flash err">Товар не сохранён — исправьте поля, отмеченные красным.</div>';
        }
        echo '<form class="box item" method="post" action="./?a=save" enctype="multipart/form-data">' . abv_csrf_field()
            . '<input type="hidden" name="ver" value="' . h($ver) . '">'
            . '<input type="hidden" name="old_sku" value="' . h($old['sku'] ?? '') . '">'
            . '<div class="grid"><div class="fields">';
        if ($old) {
            echo '<div class="row"><span class="lbl">Артикул</span><div class="ro"><span><b>' . h($old['sku']) . '</b>'
                . ' · <a href="../tovar-' . h(rawurlencode($old['sku'])) . '.html" target="_blank" rel="noopener">страница на сайте ↗</a></span>'
                . '<small>Артикул не меняется (по нему адрес страницы). Нужен другой — добавьте новый товар, а этот удалите.</small></div></div>';
        } else {
            echo '<label class="row' . (isset($err['sku']) ? ' bad' : '') . '"><span class="lbl">Артикул</span>'
                . '<input name="sku" value="' . h($v['sku']) . '" required maxlength="40" placeholder="например, 1SDA073725R1" autocapitalize="characters" spellcheck="false">'
                . $e('sku') . '</label>';
        }
        echo '<label class="row' . (isset($err['name']) ? ' bad' : '') . '"><span class="lbl">Название</span>'
            . '<input name="name" value="' . h($v['name']) . '" required maxlength="200">' . $e('name') . '</label>';
        echo '<label class="row' . (isset($err['cat']) ? ' bad' : '') . '"><span class="lbl">Раздел склада</span><select name="cat" id="cat">';
        if (!$old) {
            echo '<option value="">— выберите —</option>';
        }
        foreach ($cats as $c) {
            echo '<option' . ($c === $v['cat'] ? ' selected' : '') . ' value="' . h($c) . '">' . h($c) . '</option>';
        }
        echo '<option value="__new"' . ($isNewCat ? ' selected' : '') . '>+ Новый раздел…</option></select>'
            . '<input name="cat_new" id="cat_new" maxlength="80" placeholder="Название нового раздела" value="' . h($isNewCat ? $v['cat'] : '') . '"'
            . ($isNewCat ? '' : ' hidden') . '>' . $e('cat') . '</label>';
        echo '<div class="two"><label class="row' . (isset($err['qty']) ? ' bad' : '') . '"><span class="lbl">Количество, шт.</span>'
            . '<input name="qty" inputmode="numeric" value="' . h($v['qty']) . '" required><small>0 — товар показывается как «под заказ»</small>' . $e('qty') . '</label>'
            . '<label class="row' . (isset($err['price']) ? ' bad' : '') . '"><span class="lbl">Цена за 1 шт., ₽ с НДС</span>'
            . '<input name="price" id="price" inputmode="numeric" value="' . h(is_int($v['price']) ? abv_money($v['price'], ' ') : $v['price']) . '" required>' . $e('price') . '</label></div>';
        echo '<details class="more"' . ($err && array_intersect_key($err, ['srok' => 1, 'min_zakaz' => 1]) ? ' open' : '') . '><summary>Условия заказа</summary>'
            . '<label class="row' . (isset($err['srok']) ? ' bad' : '') . '"><span class="lbl">Срок поставки</span>'
            . '<input name="srok" maxlength="60" value="' . h($v['srok'] ?? '') . '" placeholder="например, 1–2 недели"><small>Пусто — срок не показывается</small>' . $e('srok') . '</label>'
            . '<label class="row' . (isset($err['min_zakaz']) ? ' bad' : '') . '"><span class="lbl">Минимальная сумма заказа, ₽ с НДС</span>'
            . '<input name="min_zakaz" inputmode="numeric" value="' . h(is_int($v['min_zakaz'] ?? null) ? abv_money($v['min_zakaz'], ' ') : ($v['min_zakaz'] ?? '')) . '"><small>Обычно 40 000 для товаров дешевле 40 000 ₽. Пусто — не показывается</small>' . $e('min_zakaz') . '</label>'
            . '<label class="chk"><input type="checkbox" name="dostavka" value="1"' . ($v['dostavka'] ? ' checked' : '') . '> Показывать блок «Доставка и самовывоз»</label>'
            . '</details>';
        echo '</div><div class="fotobox' . (isset($err['foto']) ? ' bad' : '') . '"><span class="lbl">Фото</span>'
            . '<img id="preview" src="' . abv_thumb($v['img']) . '" alt="">'
            . '<label class="btn ghost file">Загрузить фото<input type="file" name="foto" id="foto" accept="image/jpeg,image/png,image/webp"></label>'
            . '<small>JPG, PNG или WEBP. Фон станет белым, фото впишется в рамку как у остальных товаров.</small>' . $e('foto') . '</div></div>'
            . '<div class="actions"><button class="btn">' . ($old ? 'Сохранить' : 'Добавить товар') . '</button><a class="btn ghost" href="./">Отмена</a></div></form>';
        if ($old) {
            echo '<form class="danger" method="post" action="./?a=delete" id="delform">' . abv_csrf_field()
                . '<input type="hidden" name="ver" value="' . h($ver) . '"><input type="hidden" name="sku" value="' . h($old['sku']) . '">'
                . '<button class="btn red">Удалить товар с сайта</button><small>Страница товара будет вести в каталог склада. Вернуть можно в «Истории».</small></form>';
        }
        abv_page_end(<<<'JS'
var c=document.getElementById('cat'),n=document.getElementById('cat_new');
c.addEventListener('change',function(){n.hidden=c.value!=='__new';if(!n.hidden)n.focus();});
var f=document.getElementById('foto'),p=document.getElementById('preview');
f.addEventListener('change',function(){if(f.files&&f.files[0])p.src=URL.createObjectURL(f.files[0]);});
var del=document.getElementById('delform');
if(del)del.addEventListener('submit',function(e){if(!confirm('Удалить этот товар с сайта?'))e.preventDefault();});
var dirty=false;document.querySelector('form.item').addEventListener('input',function(){dirty=true;});
document.querySelector('form.item').addEventListener('submit',function(){dirty=false;});
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue='';}});
JS);
        exit;
    }

    if ($a === 'history') {
        abv_page_start('История');
        echo '<h1>История изменений</h1><p class="hint">После каждого сохранения хранится копия всего склада (последние '
            . ABV_KOPII_MAX . '). Если что-то пошло не так — верните склад к нужному моменту: сайт сразу станет таким, '
            . 'каким был тогда. Сам возврат тоже попадает в историю, так что его можно отменить.</p>';
        $k = abv_kopii();
        if (!$k) {
            echo '<div class="box"><p>Изменений ещё не было.</p></div>';
        } else {
            echo '<div class="box hist"><table><thead><tr><th>Когда</th><th>Что сделано</th><th>Товаров</th><th></th></tr></thead><tbody>';
            foreach ($k as $i => $row) {
                $when = $row['time'] ? date('d.m.Y H:i', strtotime($row['time'])) : date('d.m.Y H:i', $row['mtime']);
                echo '<tr><td class="nw">' . h($when) . '</td><td>' . h($row['note']) . '</td><td>' . (int)$row['count'] . '</td><td>'
                    . ($i === 0 ? '<span class="cur">сейчас на сайте</span>'
                        : '<form method="post" action="./?a=restore" class="restore">' . abv_csrf_field()
                        . '<input type="hidden" name="file" value="' . h($row['file']) . '"><button class="btn small ghost">Вернуть это состояние</button></form>')
                    . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        abv_page_end(<<<'JS'
document.querySelectorAll('form.restore').forEach(function(f){f.addEventListener('submit',function(e){
if(!confirm('Вернуть весь склад к этому состоянию? Изменения, сделанные позже, пропадут с сайта (но останутся в истории).'))e.preventDefault();});});
JS);
        exit;
    }

    if ($a === 'password') {
        abv_page_start('Пароль');
        echo '<p class="crumbs"><a href="./">← Все товары</a></p><h1>Смена пароля</h1>'
            . '<form class="box narrow" method="post" action="./?a=password">' . abv_csrf_field()
            . '<label class="row"><span class="lbl">Текущий пароль</span><input type="password" name="cur" autocomplete="current-password" required></label>'
            . '<label class="row"><span class="lbl">Новый пароль</span><input type="password" name="new" autocomplete="new-password" minlength="10" required><small>Не меньше 10 знаков</small></label>'
            . '<label class="row"><span class="lbl">Новый пароль ещё раз</span><input type="password" name="rep" autocomplete="new-password" minlength="10" required></label>'
            . '<div class="actions"><button class="btn">Сменить пароль</button></div></form>';
        abv_page_end();
        exit;
    }

    // ---------- список товаров ----------
    $cats = abv_categories($d['tovary']);
    $zero = count(array_filter($d['tovary'], function ($t) { return $t['qty'] === 0; }));
    $sht = array_sum(array_map(function ($t) { return $t['qty']; }, $d['tovary']));
    $summa = array_sum(array_map(function ($t) { return $t['qty'] * $t['price']; }, $d['tovary']));
    abv_page_start('Товары');
    if (!abv_live()) {
        echo '<div class="flash info">Админка работает, но склад на сайте пока показывает прежние страницы. '
            . 'Правки отсюда станут видны клиентам после включения (шаг 2 выкатки).</div>';
    }
    echo '<section class="head"><div><h1>Товары склада</h1><p class="hint">Остатки на ' . h($d['ostatki'])
        . ($d['izmeneno'] ? ' · последнее изменение ' . h(date('d.m.Y в H:i', strtotime($d['izmeneno']))) : '') . '</p></div>'
        . '<div class="headbtns"><a class="btn" href="./?a=new">Добавить товар</a><a class="btn ghost" href="./?a=csv">Скачать для Excel</a></div></section>';
    echo '<dl class="stats"><div><dt>Позиций</dt><dd>' . count($d['tovary']) . '</dd></div>'
        . '<div><dt>Штук на складе</dt><dd>' . abv_money($sht, ' ') . '</dd></div>'
        . '<div><dt>Под заказ</dt><dd' . ($zero ? ' class="acc"' : '') . '>' . $zero . '</dd></div>'
        . '<div><dt>На сумму, ₽ с НДС</dt><dd>' . abv_money($summa, ' ') . '</dd></div></dl>';
    echo '<div class="filters"><div class="search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>'
        . '<input type="search" id="q" placeholder="Артикул, название или раздел" autocomplete="off" aria-label="Поиск"></div>'
        . '<span id="found" class="hint"></span></div>';
    echo '<div class="chips" id="chips"><button type="button" class="chip on" data-cat="">Все<span>' . count($d['tovary']) . '</span></button>';
    foreach ($cats as $c => $n) {
        echo '<button type="button" class="chip" data-cat="' . h($c) . '">' . h($c) . '<span>' . $n . '</span></button>';
    }
    echo '</div>';
    echo '<form method="post" action="./?a=bulk" id="bulk">' . abv_csrf_field() . '<input type="hidden" name="ver" value="' . h($ver) . '">'
        . '<div class="list"><table><thead><tr><th class="ph"><span class="sr">Фото</span></th><th class="sku">Артикул</th><th>Название</th>'
        . '<th class="cq">Штук</th><th class="cp">Цена, ₽</th><th class="ed"><span class="sr">Действие</span></th></tr></thead><tbody>';
    foreach ($d['tovary'] as $t) {
        $skuEnc = rawurlencode($t['sku']);
        echo '<tr' . ($t['qty'] === 0 ? ' class="zero"' : '') . ' data-cat="' . h($t['cat']) . '" data-q="' . h(abv_lower($t['sku'] . ' ' . $t['name'] . ' ' . $t['cat'])) . '">'
            . '<td class="ph"><img loading="lazy" src="' . abv_thumb($t['img']) . '" alt=""></td>'
            . '<td class="sku"><a href="./?a=edit&amp;sku=' . h($skuEnc) . '">' . h($t['sku']) . '</a></td>'
            . '<td class="nm">' . h($t['name']) . '<small>' . h($t['cat']) . ($t['qty'] === 0 ? ' · <b>под заказ</b>' : '') . '</small></td>'
            . '<td class="cq"><label><span>Штук</span><input class="num" name="qty[' . h($t['sku']) . ']" value="' . $t['qty'] . '" data-v="' . $t['qty'] . '" inputmode="numeric" aria-label="Количество ' . h($t['sku']) . '"></label></td>'
            . '<td class="cp"><label><span>Цена, ₽</span><input class="num" name="price[' . h($t['sku']) . ']" value="' . abv_money($t['price'], ' ') . '" data-v="' . $t['price'] . '" inputmode="numeric" aria-label="Цена ' . h($t['sku']) . '"></label></td>'
            . '<td class="ed"><a class="btn small ghost" href="./?a=edit&amp;sku=' . h($skuEnc) . '">Изменить</a></td></tr>';
    }
    echo '</tbody></table><p class="empty" id="empty" hidden>Ничего не найдено. Проверьте артикул или <a href="./?a=new">добавьте товар</a>.</p></div>'
        . '<p class="hint foot">Количество и цену меняйте прямо в таблице и нажмите «Сохранить». Название, раздел, фото и удаление — кнопка «Изменить».</p>'
        . '<div class="savebar" id="savebar" hidden><span id="chg"></span><button class="btn">Сохранить</button>'
        . '<button type="button" class="btn ghost" id="undo">Отменить</button></div></form>';
    abv_page_end(<<<'JS'
var rows=[].slice.call(document.querySelectorAll('.list tbody tr')),q=document.getElementById('q'),cat='',
 chips=[].slice.call(document.querySelectorAll('#chips .chip')),
 found=document.getElementById('found'),empty=document.getElementById('empty'),bar=document.getElementById('savebar'),
 chg=document.getElementById('chg'),form=document.getElementById('bulk'),nums=[].slice.call(document.querySelectorAll('input.num'));
function filt(){var w=q.value.toLowerCase().trim().split(/\s+/).filter(Boolean),n=0;
 rows.forEach(function(r){var ok=(!cat||r.getAttribute('data-cat')===cat)&&w.every(function(x){return r.getAttribute('data-q').indexOf(x)>-1});
  r.hidden=!ok;if(ok)n++;});found.textContent=(w.length||cat)?'Найдено: '+n:'';empty.hidden=n>0;}
chips.forEach(function(b){b.addEventListener('click',function(){cat=b.getAttribute('data-cat');
 chips.forEach(function(x){x.classList.toggle('on',x===b)});filt();});});
q.addEventListener('input',filt);
function val(i){return i.value.replace(/[\s\u00a0]/g,'');}
function changed(){return nums.filter(function(i){return val(i)!==i.getAttribute('data-v')});}
function upd(){var c=changed(),k={};c.forEach(function(i){k[i.closest('tr').querySelector('.sku').textContent]=1;i.classList.add('chg')});
 nums.forEach(function(i){if(c.indexOf(i)<0)i.classList.remove('chg')});var n=Object.keys(k).length;
 bar.hidden=!n;chg.textContent='Изменено: '+n;}
form.addEventListener('input',upd);
document.getElementById('undo').addEventListener('click',function(){nums.forEach(function(i){i.value=i.defaultValue});upd();});
form.addEventListener('submit',function(){nums.forEach(function(i){if(val(i)===i.getAttribute('data-v'))i.disabled=true});bar.hidden=true;});
window.addEventListener('beforeunload',function(e){if(!bar.hidden){e.preventDefault();e.returnValue='';}});
window.addEventListener('pageshow',function(){nums.forEach(function(i){i.disabled=false});upd();});
JS);
} catch (AbvConflict $e) {
    abv_flash($e->getMessage(), 'err');
    abv_redirect();
} catch (Throwable $e) {
    error_log('admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (!headers_sent()) {
        abv_page_start('Ошибка', false);
    }
    echo '<div class="box"><h1>Не получилось</h1><p>' . h($e->getMessage()) . '</p><p><a href="./">Вернуться к товарам</a></p></div>';
    abv_page_end();
}
