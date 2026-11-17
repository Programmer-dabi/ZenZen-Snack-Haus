<?php

namespace App\Controllers;

use App\Models\Product;
use App\Models\Cart;
use Flight;
use InvalidArgumentException;
use Throwable;

class HomeController
{
    // Flow: Load the top three products by completed sales for the home page.
    public function index()
    {
        try {
            $product = new Product();
            // Ask the model for up to three products ranked using completed sales.
            $products = $product->getBestSellers();

            // Give Twig the products and current cart count; the template builds the home page.
            Flight::render('home.twig', [
                'products' => $products,
                'cart_count' => (new Cart())->getCount(),
            ]);

        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            Flight::halt(400, $error->getMessage());
            return;
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Home display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load the menu. Please try again.');
            return;
        }

    }

    // Flow: Search the full menu and filter by the model's allowed categories.
    public function products()
    {
        try {
            // Read the search text and category from query parameters, retaining them for the menu form.
            $search = $_GET['q'] ?? '';
            $category = $_GET['category'] ?? '';
            // Reject arrays or other unexpected values before trimming text or querying products.
            if (!is_string($search) || !is_string($category)) {
                throw new InvalidArgumentException('Enter a valid search and category.');
            }
            $search = trim($search);
            // The model validates the category and performs the search; Twig receives results and current filters.
            Flight::render('products.twig', [
                'products' => (new Product())->getProductAll($search, $category),
                'categories' => Product::CATEGORIES,
                'search' => $search,
                'selected_category' => $category,
                'cart_count' => (new Cart())->getCount(),
            ]);
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            Flight::halt(400, $error->getMessage());
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Product listing failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load the menu. Please try again.');
        }
    }

    // Flow: Render the existing development test template.
    public function testing()
    {
        // This registered development route renders a static page without loading products.
        Flight::render('test.twig');
    }
}
?>
