<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

Flight::set('flight.base_url', '/');

// Step 1: Load page templates from the application's views directory.
$loader = new FilesystemLoader(__DIR__ . '/app/views');

// Step 2: Configure Twig; HTML escaping protects printed template values.
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/app/cache',
    'debug' => true,
    'autoescape' => 'html',
]);

$twig->addGlobal('base_url', Flight::get('flight.base_url'));

// Step 3: Let controllers render Twig templates through Flight::render().
Flight::map('render', function (string $template, array $data = []) use ($twig) {

    if (substr($template, -5) !== '.twig') {
        $template .= '.twig';
    }

    echo $twig->render($template, $data);
});

$twig->addFunction(
    new \Twig\TwigFunction('file_exists', 'file_exists')
);