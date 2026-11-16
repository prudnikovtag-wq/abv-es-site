<?php
/*
 * Начальный пароль админки (хранится только хеш — сам пароль из него не восстановить).
 * Пароль, сменённый в админке («Пароль»), хранится на хостинге в admin-data/parol.php и действует вместо этого.
 * Забыли пароль и нет доступа к хостингу — новый хеш: php -r 'echo password_hash("НОВЫЙ-ПАРОЛЬ", PASSWORD_DEFAULT);'
 * (и удалить admin-data/parol.php на хостинге через файловый менеджер reg.ru).
 */
const ABV_ADMIN_HASH = '$2y$12$8fzq77obepPMHNv6WIYZp.3Ownc9zAQb5VBLlde82BoAds0rqtkiO';
