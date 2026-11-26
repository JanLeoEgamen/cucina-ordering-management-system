<?php

// Router for `php -S -t public` while the browser tests run (see serve.mjs).

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// A real file under public/ (the built assets): the server sends it itself.
if ($path !== '/' && is_file(__DIR__ . '/../../../public' . $path)) {
    return false;
}

define('LARAVEL_START', microtime(true));

$app = require __DIR__ . '/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
