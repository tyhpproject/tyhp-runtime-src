<?php

declare(strict_types=1);

$path = \parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$path = \is_string($path) ? $path : '/';

switch (true) {
    case $path === '/hello':
        \header('Content-Type: text/plain');
        \header('X-Test: one');
        \header('X-Test: two', false);
        echo 'hello';
        break;

    case $path === '/json':
        \header('Content-Type: application/json');
        echo \json_encode(['name' => 'tyhp', 'count' => 3], \JSON_THROW_ON_ERROR);
        break;

    case \str_starts_with($path, '/status/'):
        \http_response_code((int) \substr($path, 8));
        echo 'status';
        break;

    case $path === '/slow':
        $ms = (int) ($_GET['ms'] ?? 500);
        \usleep($ms * 1000);
        echo 'slow:' . $ms;
        break;

    case $path === '/echo':
        \header('Content-Type: application/json');
        echo \json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'body' => \file_get_contents('php://input'),
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
            'custom' => $_SERVER['HTTP_X_CUSTOM'] ?? '',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
        ], \JSON_THROW_ON_ERROR);
        break;

    case $path === '/redirect':
        \header('Location: /hello', true, 302);
        break;

    default:
        \http_response_code(404);
        echo 'not found';
}
