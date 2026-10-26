<?php
/*
 * Проверка сборщика склада (admin/inc/magazin.lib.php): страницы из стартовых данных
 * должны совпадать байт в байт с файлами репозитория (sklad.html, tovar-*.html, feed.xml, sitemap.xml).
 * Запуск: php tools/test_magazin.php
 * Расхождения только в блоке «Другие товары» у старых карточек — к сведению (исторические, см. BOARD.md).
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/admin/inc/magazin.lib.php';
$d = abv_norm(json_decode(file_get_contents($root . '/admin/inc/tovary-start.json'), true));
$bad = 0;
$relOnly = 0;
$cmp = function (string $name, string $got) use ($root, &$bad) {
    $have = file_get_contents("$root/$name");
    if ($have !== $got) {
        $bad++;
        $i = 0;
        while ($i < min(strlen($have), strlen($got)) && $have[$i] === $got[$i]) $i++;
        echo "РАЗЛИЧИЕ: $name с байта $i\n  было:  " . substr($have, max(0, $i - 60), 160) . "\n  стало: " . substr($got, max(0, $i - 60), 160) . "\n";
        return false;
    }
    return true;
};
$cmp('sklad.html', abv_render_sklad($d));
$cmp('feed.xml', abv_render_feed($d));
$cmp('sitemap.xml', abv_render_sitemap($d));
$strip = function (string $s) { return preg_replace('~<div class="abv-rel">.*?</div></div>\r?\n~s', '', $s); };
foreach ($d['tovary'] as $it) {
    $name = 'tovar-' . $it['sku'] . '.html';
    $got = abv_render_page($it, $d);
    $have = file_get_contents("$root/$name");
    if ($got === $have) continue;
    if ($strip($got) === $strip($have)) { $relOnly++; continue; }
    $cmp($name, $got);
}
echo "Товаров: " . count($d['tovary']) . ", расхождений: $bad, карточек с иным блоком «Другие товары»: $relOnly\n";
exit($bad ? 1 : 0);
