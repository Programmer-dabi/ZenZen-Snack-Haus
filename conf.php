<?php
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Templates use this URL prefix when linking to shared assets.
Flight::set('flight.base_url', '/');

// Step 1: Load page templates from the application's views directory.
$loader = new FilesystemLoader(__DIR__ . '/app/views');

// Step 2: Configure Twig; HTML escaping protects printed template values.
$twig = new Environment($loader, [
    'cache' => __DIR__ . '/app/cache',
    'debug' => true,
    'autoescape' => 'html',
]);

// Make the asset prefix available in every Twig template without repeating it in controllers.
$twig->addGlobal('base_url', Flight::get('flight.base_url'));

// Step 3: Let controllers render Twig templates through Flight::render().
Flight::map('render', function (string $template, array $data = []) use($twig) {
    // Accept either a short template name or a complete .twig filename from controllers.
    if (substr($template, -5) !== '.twig') {
        $template .= '.twig';
    }

    // Pass the controller data to Twig and print the resulting HTML response.
    echo $twig->render($template, $data);
});
