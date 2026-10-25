<?php

/*
 * Controlador frontal de fastore-api publicado en https://shop.juarezcampos.net/fastore-api.
 * Vive en <raíz de shop>/fastore-api; el código de Laravel está FUERA de la carpeta
 * pública (/home/juarezc/fastore-api-app), así .env, logs y código nunca son accesibles desde la web.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// La raíz de shop.juarezcampos.net puede ser /home/juarezc/<carpeta> o /home/juarezc/public_html/<carpeta>.
$app = getenv('FASTORE_API_PATH') ?: (is_dir(dirname(__DIR__, 2).'/fastore-api-app') ? dirname(__DIR__, 2) : dirname(__DIR__, 3)).'/fastore-api-app';

if (! is_file($app.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'El servicio no está disponible en este momento.']);
    exit;
}

if (is_file($mantenimiento = $app.'/storage/framework/maintenance.php')) {
    require $mantenimiento;
}

require $app.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $laravel */
$laravel = require_once $app.'/bootstrap/app.php';
$laravel->usePublicPath(__DIR__);
$laravel->handleRequest(Request::capture());
