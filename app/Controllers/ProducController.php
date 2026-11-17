<?php

namespace App\Controllers;

use App\Models\Product;
use App\Models\Cart;
use App\Middleware\AuthMiddleware;
use Flight;
use InvalidArgumentException;
use Throwable;

class ProducController
{
    // Flow: Load a product and render its available choices for the customer.
    public function openProduct($id): void
    {
        try {
            $productModel = new Product();

            // Load product details and their images, variants, add-ons, and availability choices.
            $products = $productModel->getProduct('id', $id);

            // Check whether the product exists.
            if (empty($products)) {
                Flight::halt(404, 'Product not found.');
                return;
            }

            // Twig receives products keyed by ID, plus sign-in state and the cart badge count.
            Flight::render('product.twig', [
                'products' => $products,
                'is_logged_in' => AuthMiddleware::isLoggedIn(),
                'cart_count' => (new Cart())->getCount(),
            ]);

        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            Flight::halt(400, $error->getMessage());
            return;

        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log($error->__toString());

            Flight::halt(500, $error->getMessage());
            return;
        }
    }

    // Flow: Return product details using the existing Products JSON response key.
    public function passData($id)
    {
        // Mark this response as JSON for clients requesting product data.
        header('Content-Type: application/json');

        try {
            $productModel = new Product();

            // Load product details and their images, variants, add-ons, and availability choices.
            $products = $productModel->getProduct('id', $id);

            // Check whether the product exists.
            if (empty($products)) {
                Flight::halt(404, 'Product not found.');
                return;
            }

            // Keep the existing Products response key and product-ID keys expected by JSON callers.
            echo json_encode([
                'Products' => $products,
            ]);

        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            Flight::halt(400, $error->getMessage());
            return;

        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log($error->__toString());

            Flight::halt(500, $error->getMessage());
            return;
        }
    }

    // Flow: Require login and validate posted JSON before asking Cart to save the selection.
    public function addToCart($id): void
    {
        // Stop protected work for guests; the shared guard has already sent the sign-in response.
        if (!AuthMiddleware::requireLogin(true)) {
            return;
        }

        // Accept only JSON cart submissions, allowing a charset parameter after the MIME type.
        $contentType = explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0];
        if (strtolower(trim($contentType)) !== 'application/json') {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Send an application/json request.'], 415);
            return;
        }

        try {
            // Decode into an object so a JSON object can be distinguished from a list or scalar.
            $data = json_decode(Flight::request()->getBody(), false, 512, JSON_THROW_ON_ERROR);
        // Return a client error when the request body is not valid JSON.
        } catch (\JsonException $error) {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Invalid JSON request body.'], 400);
            return;
        }

        // Validate the URL ID before the cart model accesses product records.
        $productId = filter_var($id, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Invalid product ID.'], 422);
            return;
        }

        // A cart request must be a JSON object with named option fields.
        if (!$data instanceof \stdClass) {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Expected a JSON object.'], 422);
            return;
        }

        // Require the core option fields even when the chosen value is null or an empty list.
        foreach (['variant_id', 'sugar_level', 'addon_ids'] as $field) {
            if (!property_exists($data, $field)) {
                // Return the existing JSON response shape and HTTP status for the JavaScript caller.
                Flight::json(['success' => false, 'message' => 'Missing field: ' . $field], 422);
                return;
            }
        }

        // Read optional choices and use one unit when quantity is omitted.
        $flavour = $data->flavour ?? null;
        $sideSnack = $data->side_snack ?? null;
        $iceLevel = $data->ice_level ?? null;
        $quantity = property_exists($data, 'quantity') ? $data->quantity : 1;
        // Check JSON value types and allowed option values; non-null milk preferences are not accepted for new items.
        if (
            ($data->variant_id !== null && (!is_int($data->variant_id) || $data->variant_id < 1)) ||
            ($data->milk_preference ?? null) !== null ||
            ($sideSnack !== null && !is_string($sideSnack)) ||
            ($iceLevel !== null && !is_string($iceLevel)) ||
            !in_array($data->sugar_level, [null, 100, 75, 50, 0], true) ||
            ($flavour !== null && !is_string($flavour)) ||
            !is_array($data->addon_ids) ||
            !is_int($quantity) || $quantity < 1 || $quantity > 99
        ) {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Invalid product options.'], 422);
            return;
        }

        // Reject malformed add-on IDs before the model checks whether those add-ons belong to the product.
        foreach ($data->addon_ids as $addonId) {
            if (!is_int($addonId) || $addonId < 1) {
                // Return the existing JSON response shape and HTTP status for the JavaScript caller.
                Flight::json(['success' => false, 'message' => 'Add-on IDs must be positive integers.'], 422);
                return;
            }
        }

        try {
            $cartModel = new Cart();
            // Pass option IDs rather than posted prices; the model calculates the trusted unit price and checks stock.
            $itemId = $cartModel->addToCart(
                $productId,
                $data->variant_id,
                $data->addon_ids,
                $quantity,
                $sideSnack,
                $data->sugar_level,
                $flavour,
                $iceLevel
            );
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json([
                'success' => true,
                'message' => 'Product added to your cart.',
                'cart_item_id' => $itemId,
                'redirect_url' => '/cart',
            ]);
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => $error->getMessage()], 422);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Cart save failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            // Return the existing JSON response shape and HTTP status for the JavaScript caller.
            Flight::json(['success' => false, 'message' => 'Could not save your cart. Please try again.'], 500);
        }
    }
}
?>
