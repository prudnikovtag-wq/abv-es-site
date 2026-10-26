<?php
/*
 * Роутер для локальной проверки админки и склада без хостинга — повторяет правила .htaccess.
 * Запускать на КОПИИ сайта (админка пишет admin-data/ и foto/):
 *   rsync -a --exclude .git ./ /путь/к/копии/ && cd /путь/к/копии && php -S 127.0.0.1:8090 tools/dev_server.php
 * Переменная ABV_REWRITE=0 — без переадресации склада в magazin.php (как до включения, шаг 1 выкатки).
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$root = dirname(__DIR__);
// закрытые папки (как .htaccess в admin/inc, admin-data) и скрипты в foto/
if (preg_match('~^/(admin/inc|admin-data)(/|$)~', $path) || preg_match('~^/foto/.*\.(php\d?|phtml|phar|html?)$~i', $path)
    || preg_match('~^/(tools|\.git|\.github|\.claude)(/|$)~', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (getenv('ABV_REWRITE') !== '0') {
    $map = null;
    if ($path === '/sklad.html') $map = ['r' => 'sklad'];
    elseif (preg_match('~^/tovar-([0-9A-Z][0-9A-Z-]*)\.html$~', $path, $m)) $map = ['r' => 'tovar', 'sku' => $m[1]];
    elseif ($path === '/feed.xml') $map = ['r' => 'feed'];
    elseif ($path === '/sitemap.xml') $map = ['r' => 'sitemap'];
    if ($map) {
        $_GET = $map;
        $_SERVER['SCRIPT_NAME'] = '/magazin.php';
        chdir($root);
        require $root . '/magazin.php';
        return true;
    }
}
if ($path === '/admin' ) { header('Location: /admin/'); return true; }
if (substr($path, -1) === '/' && is_file($root . $path . 'index.php')) {
    chdir($root . $path);
    $_SERVER['SCRIPT_NAME'] = $path . 'index.php';
    require $root . $path . 'index.php';
    return true;
}
return false;   // остальное — обычные файлы
