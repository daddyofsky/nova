<?php

use App\WebApp;
use Nova\App;
use Nova\Autoloader;

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/Nova/Autoloader.php';
Autoloader::register();

$app = App::alias(new WebApp(), 'app');
$app->boot();
