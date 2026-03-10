<?php

// Force PostgreSQL connection for tests — must be set before Laravel bootstraps
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = 'pgsql';
$_SERVER['DB_CONNECTION'] = 'pgsql';

require __DIR__ . '/../vendor/autoload.php';
