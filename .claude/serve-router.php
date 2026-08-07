<?php

/**
 * Router para `php -S` equivalente al de Laravel, pero con la ruta
 * pública fija en vez de getcwd(): permite levantar este proyecto
 * desde el directorio de trabajo del otro (Claude Code arranca el
 * server siempre en la raíz del proyecto primario).
 */
$publicPath = __DIR__.'/../public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
