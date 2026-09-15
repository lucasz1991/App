<?php

use Illuminate\Http\Request;

// PHP's loopback-only development server routes real application requests.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$public = realpath(__DIR__.'/../../public');
$candidate = realpath($public.$path);
if ($candidate && str_starts_with($candidate, $public.DIRECTORY_SEPARATOR) && is_file($candidate) && pathinfo($candidate, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
$app = require __DIR__.'/operations-bootstrap.php';
$app->handleRequest(Request::capture());
