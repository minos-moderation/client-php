<?php

declare(strict_types=1);

/*
 * The mock runs without `composer install`: this maps its two namespaces onto the tree.
 * Composer's autoloader (tests) maps the same ones, from `composer.json`.
 */
spl_autoload_register(static function (string $class): void {
    $roots = [
        'Minos\\Client\\' => __DIR__ . '/../src/',
        'Minos\\Mock\\'   => __DIR__ . '/src/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
