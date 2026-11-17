<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Let the PHP development server serve real files, such as images and stylesheets, directly.
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;

    if (is_file($file)) {
        return false;
    }
}
// Step 1: Start or resume the session so login state survives later requests.
session_start();

// Step 2: Load application classes, database setup, and Twig rendering.
require './vendor/autoload.php';
require './app/Database/database.php';
require './conf.php';

// Import the controllers used by the route table; each action handles one kind of request.
use App\Controllers\AuthenController;
use App\Controllers\HomeController;
use App\Controllers\ProducController;
use App\Controllers\CartController;
use App\Controllers\OrderController;
use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AdminAuthController;

// Step 3: Connect incoming URLs and HTTP methods to their controller actions.
// Customer sign-in and registration routes keep the existing form URLs and capitalization.
Flight::route('/login', [new AuthenController(), 'login']);

Flight::route('POST /Authenticate', [new AuthenController(), 'Authent']);
Flight::route('/register', [new AuthenController(), 'register']);
Flight::route('POST /addUser', [new AuthenController(), 'addUser']);
Flight::route('/logout', [new AuthenController(), 'logout']);
// Keep the development test page separate from the home page and searchable menu.
Flight::route('/testthis', [new HomeController(), 'testing']);
Flight::route('/', [new HomeController(), 'index']);
Flight::route('GET /products', [new HomeController(), 'products']);

// The older product-creation URLs now use the administrator controller and its access checks.
Flight::route('/addproduct', [new AdminController(), 'newProduct']);
Flight::route('POST /add', [new AdminController(), 'createProduct']);
// Flight passes the ID in the URL to the action; product HTML and product JSON use separate URLs.
Flight::route('GET /product/@id', [new ProducController(), 'openProduct']);
Flight::route('GET /products/@id', [new ProducController(), 'passdata']);
Flight::route('POST /addToCart/@id', [new ProducController(), 'addToCart']);
// Cart and checkout actions read the customer session before displaying or changing customer data.
Flight::route('GET /cart', [new CartController(), 'index']);
Flight::route('GET /checkout', [new CartController(), 'checkout']);
Flight::route('POST /checkout', [new OrderController(), 'place']);
Flight::route('GET /order-success/@number', [new OrderController(), 'success']);
// Account actions show the signed-in customer profile and handle cancellation requests.
Flight::route('GET /account', [new AccountController(), 'index']);
Flight::route('POST /account/orders/@id/cancel', [new AccountController(), 'cancelOrder']);
// Administrator sign-in has its own session values, form token, and role checks.
Flight::route('GET /admin/login', [new AdminAuthController(), 'login']);
Flight::route('POST /admin/login', [new AdminAuthController(), 'authenticate']);
Flight::route('POST /admin/logout', [new AdminAuthController(), 'logout']);
// Management POST routes delegate authorization, validation, and database changes to their controllers.
Flight::route('GET /admin', [new AdminController(), 'index']);
Flight::route('POST /admin/products', [new AdminController(), 'createProduct']);
Flight::route('POST /admin/products/@id/edit', [new AdminController(), 'editProduct']);
Flight::route('POST /admin/products/@id/stock', [new AdminController(), 'updateStock']);
Flight::route('POST /admin/products/@id/delete', [new AdminController(), 'deleteProduct']);
Flight::route('POST /admin/orders/@id/status', [new AdminController(), 'updateOrderStatus']);
// The status endpoint supplies polling JSON; the other tracking route renders the full page.
Flight::route('GET /tracking/@id/status', [new OrderController(), 'trackingStatus']);
Flight::route('GET /tracking/@id', [new OrderController(), 'tracking']);
// Cart item IDs identify saved lines; the model also checks that each line belongs to the customer.
Flight::route('POST /cart/@id/quantity', [new CartController(), 'updateQuantity']);
Flight::route('POST /cart/@id/remove', [new CartController(), 'remove']);

// Step 4: Start Flight and dispatch the current request.
Flight::set('flight.debug', true);
Flight::start();
?>
