<?php
// Minimal, de golyóálló XAMPP beállítás

if (!defined('BASE_URL'))   define('BASE_URL', '/epito3');
if (!defined('ASSETS_URL')) define('ASSETS_URL', BASE_URL . '/assets');

if (!defined('BASE_DIR'))   define('BASE_DIR', str_replace('\\','/', realpath(__DIR__ . '/..')));

if (!defined('UPLOADS_DIR'))    define('UPLOADS_DIR',  BASE_DIR . '/uploads');
if (!defined('UPLOADS_URL'))    define('UPLOADS_URL',  BASE_URL . '/uploads');

if (!defined('SIGNATURES_DIR')) define('SIGNATURES_DIR', BASE_DIR . '/signatures');
if (!defined('SIGNATURES_URL')) define('SIGNATURES_URL', BASE_URL . '/signatures');
