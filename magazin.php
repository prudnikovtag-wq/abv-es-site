<?php
/*
 * Выдача страниц склада из данных админки. Сюда ведут правила в .htaccess:
 *   /sklad.html → ?r=sklad, /tovar-<артикул>.html → ?r=tovar&sku=…, /feed.xml → ?r=feed, /sitemap.xml → ?r=sitemap
 * Сама страница ничего не меняет — только читает данные (admin/inc/magazin.lib.php).
 */
declare(strict_types=1);
require __DIR__ . '/admin/inc/magazin.lib.php';

function abv_send(string $type, string $body, array $d): void
{
    // Last-Modified — по последней правке данных или шаблонов, чтобы поисковики могли получать «не изменилось».
    $m = max(@filemtime(abv_data_file()) ?: 0, @filemtime(__DIR__ . '/' . ABV_SKLAD) ?: 0,
        @filemtime(__DIR__ . '/' . ABV_REFERENCE) ?: 0, @filemtime(__DIR__ . '/' . ABV_FEED) ?: 0,
        @filemtime(__DIR__ . '/admin/inc/magazin.lib.php') ?: 0);
    $etag = '"' . substr(md5($body), 0, 20) . '"';
    header('Content-Type: ' . $type . '; charset=UTF-8');
    if ($m) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $m) . ' GMT');
    }
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
}

try {
    $d = abv_data();
    $r = (string)($_GET['r'] ?? '');
    if ($r === 'sklad') {
        abv_send('text/html', abv_render_sklad($d), $d);
    } elseif ($r === 'tovar') {
        $sku = (string)($_GET['sku'] ?? '');
        $i = preg_match(ABV_SKU_RE, $sku) ? abv_find($d['tovary'], $sku) : null;
        if ($i === null) {
            // товара нет (снят с продажи) — как раньше, постоянная переадресация в каталог
            header('Location: ' . ABV_SITE . 'sklad.html', true, 301);
            exit;
        }
        abv_send('text/html', abv_render_page($d['tovary'][$i], $d), $d);
    } elseif ($r === 'feed') {
        abv_send('text/xml', abv_render_feed($d), $d);
    } elseif ($r === 'sitemap') {
        abv_send('text/xml', abv_render_sitemap($d), $d);
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Страница не найдена\n";
    }
} catch (Throwable $e) {
    error_log('magazin.php: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Склад временно недоступен</title>'
        . '<p style="font:16px sans-serif;margin:40px">Склад временно недоступен. Попробуйте через несколько минут '
        . 'или позвоните 8 (800) 250 33 92, напишите на <a href="mailto:info@abv-es.ru">info@abv-es.ru</a>.</p>';
}
