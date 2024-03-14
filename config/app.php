<?php

return [
	// app
	'app.name'   => env('APP_NAME', 'Nova'),
	'app.env'    => env('APP_ENV', 'live'),
	'app.debug'  => (bool)env('APP_DEBUG', false),
	'app.key'    => env('APP_KEY'),
	'app.cipher' => 'AES-256-CBC',
	'app.legacy' => true,
	
	// dir
	'dir.root'       => APP_ROOT,
	'dir.resources'  => APP_ROOT . '/resources',
	'dir.tpl'        => APP_ROOT . '/resources/views',
	'dir.file'       => APP_ROOT,
	'dir.data'       => APP_ROOT . '/data',
	'dir.tmp'        => APP_ROOT . '/tmp',
	'dir.log'        => APP_ROOT . '/tmp/log',
	'dir.db_cache'   => APP_ROOT . '/tmp/_schema',// use common dir
	'dir.tpl_cache'  => APP_ROOT . '/tmp/_compile',
	'dir.file_cache' => APP_ROOT . '/tmp/_filecache',

	// debug
	'debug.db'       => true,
	'debug.log'      => false,

	// db
	'db.dsn'          => env('DSN', 'mysql://user:pass@host/db'),
	'db.dsn_slave'    => env('DSN_SLAVE', 'mysql://user:pass@host/db'),
	'db.dsn_test'     => env('DSN_TEST', 'mysql://user:pass@host/db'),
	'db.table_prefix' => '',

	// view
	'view.dev'              => env('APP_DEBUG') && env('APP_ENV') === 'dev',
	'view.ext'              => 'html',
	'view.version'          => 0,
	'view.resource_version' => env('APP_DEBUG') ? time() : 1,
	'view.default_tpl'      => 't_black',
	'view.pre_compiler'     => [],
	'view.use_class'        => [],
];
