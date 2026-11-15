<?php
namespace App\Controllers;
use App\Models\Product;
use App\Models\Cart;
use Flight;
use InvalidArgumentException;
use Throwable;


class HomeController{
    // Flow: Load the top three products by completed sales for the home page.
    public function index(){
            try {
            $product = new Product();
            $products = $product->getBestSellers();

            Flight::render('home.twig', [
                'products' => $products,
                'cart_count' => (new Cart())->getCount(),
            ]);

            }catch (InvalidArgumentException $error) {
                Flight::halt(400, $error->getMessage());
                return;
            }catch (Throwable $error) {
                error_log('Home display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
                Flight::halt(500, 'Could not load the menu. Please try again.');
                return;
            }
    
    }

    // Flow: Search the full menu and filter by the model's allowed categories.
    public function products(){
        try {
            $search = $_GET['q'] ?? '';
            $category = $_GET['category'] ?? '';
            if (!is_string($search) || !is_string($category)) {
                throw new InvalidArgumentException('Enter a valid search and category.');
            }
            $search = trim($search);
            Flight::render('products.twig', [
                'products' => (new Product())->getProductAll($search, $category),
                'categories' => Product::CATEGORIES,
                'search' => $search,
                'selected_category' => $category,
                'cart_count' => (new Cart())->getCount(),
            ]);
        } catch (InvalidArgumentException $error) {
            Flight::halt(400, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Product listing failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load the menu. Please try again.');
        }
    }

    // Flow: Render the existing development test template.
    public function testing(){
        Flight::render('test.twig');
    }
}
?>
