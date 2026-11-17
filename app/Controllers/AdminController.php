<?php

namespace App\Controllers;

use App\Models\Admin;
use App\Models\Product;
use App\Models\User;
use Flight;
use InvalidArgumentException;
use Throwable;

class AdminController
{
    // Recheck the persisted role on every management request, including POSTs.
    // Flow: Recheck the stored account role before allowing any administrator request.
    private function signedInAdmin(): ?array
    {
        // Do not cache private management pages; administrator permissions are checked on every request.
        Flight::response()->header('Cache-Control', 'no-store, private');
        // Require a positive administrator session ID before checking its stored role.
        $id = filter_var($_SESSION['admin_user'] ?? null, FILTER_VALIDATE_INT);
        $user = $id !== false && $id > 0 ? (new User())->getAdministrator($id) : null;
        // Stop when authorization failed; the sign-in check has already sent the redirect.
        if ($user === null) {
            unset($_SESSION['admin_user'], $_SESSION['admin_csrf']);
            Flight::redirect('/admin/login', 303);
        }
        return $user;
    }

    // Flow: Compare the posted admin CSRF token with the value stored in the session.
    private function csrf(): void
    {
        // Read the submitted CSRF token and compare it with the administrator session token.
        $token = $_POST['csrf_token'] ?? null;
        // Reject missing, malformed, or stale tokens before processing management changes.
        if (!is_string($token) || !isset($_SESSION['admin_csrf']) || !hash_equals($_SESSION['admin_csrf'], $token)) {
            throw new InvalidArgumentException('Please reload the admin page and try again.');
        }
    }

    // Flow: Reject missing, non-integer, or out-of-range record IDs.
    private function positiveId(mixed $value): int
    {
        // Validate the submitted record ID before using it in a management operation.
        $id = filter_var($value, FILTER_VALIDATE_INT);
        // Record IDs must fit the supported positive integer range before being used by model queries.
        if ($id === false || $id < 1 || $id > 2147483647) {
            throw new InvalidArgumentException('Invalid record ID.');
        }
        return $id;
    }

    // Flow: Load the dashboard and requested management dialog for a verified administrator.
    public function index(): void
    {
        try {
            $user = $this->signedInAdmin();
            // Stop when authorization failed; the sign-in check has already sent the redirect.
            if ($user === null) {
                return;
            }
            $admin = new Admin();
            // Load the product, inventory, order, customer, and sales arrays used by the dashboard.
            $data = $admin->dashboard();
            // Read the requested dialog name from the query string; only known dialog types are allowed.
            $modal = $_GET['modal'] ?? '';
            // Return not found for an unknown dialog instead of treating input as a template filename.
            if (!is_string($modal) || !in_array($modal, [
                '',
                'add-product',
                'edit-product',
                'stock',
                'order',
                'customer',
                'delete-product'
            ], true)) {
                Flight::halt(404, 'Admin form not found.');
                return;
            }
            // Initialize selected records to null so the template can distinguish an unopened dialog from a loaded record.
            $data['modal'] = $modal;
            $data['selected_product'] = null;
            $data['selected_variant'] = null;
            $data['selected_order'] = null;
            $data['selected_customer'] = null;
            // Product-related dialogs must select a product from the dashboard data using a validated ID.
            if (in_array($modal, ['edit-product', 'stock', 'delete-product'], true)) {
                $id = $this->positiveId($_GET['product_id'] ?? null);
                $product = $data['products'][$id] ?? null;
                // Reject a missing product rather than opening a form that could modify the wrong record.
                if ($product === null) {
                    Flight::halt(404, 'Product not found.');
                    return;
                }
                $data['selected_product'] = $product;
                // For stock management, choose the requested variant or the first available variant of this product.
                if ($modal === 'stock') {
                    $variantId = isset($_GET['variant_id']) ? $this->positiveId($_GET['variant_id']) : null;
                    // Search this product's variants only, so a foreign variant ID cannot populate the stock form.
                    foreach ($product['variants'] as $variant) {
                        if ($variantId === null || (int) $variant['id'] === $variantId) {
                            $data['selected_variant'] = $variant;
                            break;
                        }
                    }
                    // A product without a matching variant cannot open the inventory form.
                    if ($data['selected_variant'] === null) {
                        Flight::halt(404, 'Variant not found for this product.');
                        return;
                    }
                }
            // Order and customer dialogs load their records separately after validating the requested ID.
            } elseif ($modal === 'order' || $modal === 'customer') {
                $id = $this->positiveId($_GET['id'] ?? null);
                $record = $modal === 'order' ? $admin->order($id) : $admin->customer($id);
                // Reject an order or customer that no longer exists.
                if ($record === null) {
                    Flight::halt(404, 'Record not found.');
                    return;
                }
                $data[$modal === 'order' ? 'selected_order' : 'selected_customer'] = $record;
            }
            $product = $data['selected_product'];
            // Restore a failed submission first; otherwise prefill from the selected product or new-product defaults.
            // Map database variant/add-on field names to the names used by the form.
            $data['product_form'] = $_SESSION['admin_product_values'] ?? [
                'name' => $product['product_name'] ?? '',
                'category' => $product['category'] ?? Product::CATEGORIES[0],
                'description' => $product['description'] ?? '',
                'price' => $product['base_price'] ?? '0.00',
                'availability' => $product['availability'] ?? 'Available',
                'unavailable_flavours' => json_decode($product['unavailable_flavours'] ?? '[]', true),
                'unavailable_side_snacks' => json_decode($product['unavailable_side_snacks'] ?? '[]', true),
                'variants' => array_map(static fn ($v) => [
                    'id' => $v['id'],
                    'size_oz' => $v['size_oz'],
                    'price' => $v['price'],
                    'stock' => $v['stock_quantity']
                ], $product['variants'] ?? []),
                'addons' => array_map(static fn ($a) => ['id' => $a['id'], 'name' => $a['addon_name'], 'price' => $a['addon_price']], $product['addons'] ?? []),
            ];
            // Create a session token shared by the administrator mutation forms.
            $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
            // Render the dashboard with selected records, one-time messages, and payment-aware allowed statuses.
            Flight::render('admin.twig', array_merge($data, [
                'current_user' => $user,
                'csrf_token' => $_SESSION['admin_csrf'],
                'admin_error' => $_SESSION['admin_error'] ?? null,
                'admin_success' => $_SESSION['admin_success'] ?? null,
                'allowed_categories' => Admin::CATEGORIES,
                'allowed_flavours' => Product::FLAVOURS,
                'allowed_statuses' => $data['selected_order'] !== null
                    ? Admin::availableOrderStatuses($data['selected_order']['payment_method'], $data['selected_order']['status'])
                    : Admin::STATUSES,
            ]));
            // Consume feedback and restored product fields once so they do not leak into later forms.
            unset($_SESSION['admin_error'], $_SESSION['admin_success'], $_SESSION['admin_product_values']);
        // Handle a known validation failure using this action's existing error response or form feedback.
        } catch (InvalidArgumentException $error) {
            Flight::halt(404, 'Admin record not found.');
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Admin display failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load the admin page. Please try again.');
        }
    }

    // Flow: Open the existing add-product dialog.
    public function newProduct(): void
    {
        try {
            // Stop this action when the administrator role check has redirected to sign-in.
            if ($this->signedInAdmin() === null) {
                return;
            }
            Flight::redirect('/admin?modal=add-product', 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            error_log('Admin product form failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            Flight::halt(500, 'Could not load the product form. Please try again.');
        }
    }

    // Flow: Send product creation through the shared administrator save flow.
    public function createProduct(): void
    {
        $this->saveProduct(null);
    }
    // Flow: Send product editing through the shared administrator save flow.
    public function editProduct(string $id): void
    {
        $this->saveProduct($id);
    }

    // Flow: Accept a string input and trim surrounding whitespace.
    private function text(mixed $value): string
    {
        // Malformed non-string form values become empty text for the existing validation to reject.
        return is_string($value) ? trim($value) : '';
    }

    // Flow: Normalize posted product fields and verify the option arrays have matching rows.
    private function formValues(): array
    {
        $form = [];
        // Collect the basic product text fields before building the option rows.
        // Read each option group as parallel form columns: label/name, existing ID, price, and variant stock.
        foreach (['name', 'category', 'description', 'price', 'availability'] as $field) {
            $form[$field] = $this->text($_POST[$field] ?? null);
        }
        $unavailable = $_POST['unavailable_flavours'] ?? [];
        // Validate the submitted flavour allowlist now, while retaining an array for form restoration.
        Product::encodeUnavailableFlavours($unavailable);
        $form['unavailable_flavours'] = array_values($unavailable);
        $unavailableSnacks = $_POST['unavailable_side_snacks'] ?? [];
        // Apply the corresponding side-snack allowlist before retaining the submitted choices.
        Product::encodeUnavailableSideSnacks($unavailableSnacks);
        $form['unavailable_side_snacks'] = array_values($unavailableSnacks);
        $form['variants'] = [];
        $form['addons'] = [];
        // Read each option group as parallel form columns: label/name, existing ID, price, and variant stock.
        foreach ([
            'variants' => ['variant_oz', 'variant_id', 'variant_price', 'variant_stock'],
            'addons' => ['addon_name', 'addon_id', 'addon_price']
        ] as $group => $fields) {
            $columns = [];
            // Normalize each submitted column into numeric row order and reject oversized or non-array input.
            foreach ($fields as $field) {
                $values = $_POST[$field] ?? [];
                if (!is_array($values) || count($values) > 100) {
                    throw new InvalidArgumentException('Check the product options (maximum 100 rows).');
                }
                $columns[] = array_values($values);
            }
            // Require labels, prices, and variant stock to have matching row counts before combining them.
            if (count($columns[0]) !== count($columns[2]) || ($group === 'variants' && count($columns[0]) !== count($columns[3]))) {
                throw new InvalidArgumentException('Complete every product option row.');
            }
            // Combine matching positions into one row; a missing ID defaults to zero for a new option.
            foreach ($columns[0] as $i => $name) {
                $row = [
                    'id' => $this->text($columns[1][$i] ?? '0'),
                    'price' => $this->text($columns[2][$i] ?? null)
                ];
                // Variants carry a size label and stock; add-ons carry a name and price.
                if ($group === 'variants') {
                    $row['size_oz'] = $this->text($name);
                    $row['stock'] = $this->text($columns[3][$i] ?? null);
                } else {
                    $row['name'] = $this->text($name);
                }
                $form[$group][] = $row;
            }
        }
        return $form;
    }

    // Flow: Validate a non-negative price with at most two decimal places.
    private function price(string $value): float
    {
        // Accept only non-negative decimal prices with at most two fractional digits.
        if (!preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $value)) {
            throw new InvalidArgumentException('Prices must be non-negative amounts with at most two decimal places.');
        }
        return (float) $value;
    }

    // Flow: Validate a non-negative whole stock quantity within the database range.
    private function stock(mixed $value): int
    {
        // Require whole stock values that fit the database's supported unsigned quantity range.
        $quantity = filter_var($value, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0 || $quantity > 4294967295) {
            throw new InvalidArgumentException('Stock must be a non-negative whole number.');
        }
        return $quantity;
    }

    // Flow: Validate product details, category, availability, variants, add-ons, and flavours.
    private function validateProduct(array $form): array
    {
        // Reject missing or oversized product text before any file upload or database write.
        if ($form['name'] === '' || mb_strlen($form['name']) > 150 || strlen($form['description']) > 65535) {
            throw new InvalidArgumentException('Enter a product name (up to 150 characters) and a valid description.');
        }
        // Require configured category and availability values rather than accepting arbitrary labels.
        if (!in_array($form['category'], Admin::CATEGORIES, true) || !in_array($form['availability'], ['Available', 'Unavailable'], true)) {
            throw new InvalidArgumentException('Select a valid category and availability.');
        }
        // Translate form field names into the product column names expected by the model.
        $data = [
            'product_name' => $form['name'],
            'category' => $form['category'],
            'description' => $form['description'],
            'base_price' => $this->price($form['price']),
            'availability' => $form['availability']
        ];
        // Store blocked choices only for categories that actually support those choosers.
        $data['unavailable_flavours'] = in_array($form['category'], Product::FLAVOUR_CATEGORIES, true)
            ? Product::encodeUnavailableFlavours($form['unavailable_flavours'] ?? []) : null;
        $data['unavailable_side_snacks'] = $form['category'] === 'Mix-n-Match'
            ? Product::encodeUnavailableSideSnacks($form['unavailable_side_snacks'] ?? []) : null;
        // Every product created through this form must include at least one variant.
        if ($form['variants'] === []) {
            throw new InvalidArgumentException('Add at least one variant with its price and initial stock.');
        }
        $variants = [];
        $names = [];
        // Validate each variant's ID, label, price, and stock before assembling the model input.
        foreach ($form['variants'] as $row) {
            $name = $row['size_oz'];
            // Reject empty, oversized, or case-insensitively repeated variant labels.
            if ($name === '' || mb_strlen($name) > 50 || isset($names[mb_strtolower($name)])) {
                throw new InvalidArgumentException('Each variant needs a different label of up to 50 characters.');
            }
            $names[mb_strtolower($name)] = true;
            $variants[] = [
                'id' => $row['id'] === '0' ? 0 : $this->positiveId($row['id']),
                'size_oz' => $name,
                'price' => $this->price($row['price']),
                'stock' => $this->stock($row['stock'])
            ];
        }
        $addons = [];
        // Ignore a completely blank new add-on row; validate every other row before saving.
        foreach ($form['addons'] as $row) {
            // An untouched new add-on is optional and does not create an empty database record.
            if ($row['name'] === '' && $row['price'] === '' && $row['id'] === '0') {
                continue;
            }
            // Partially filled or oversized add-on names are errors instead of silently discarded options.
            if ($row['name'] === '' || mb_strlen($row['name']) > 100) {
                throw new InvalidArgumentException('Each add-on needs a name of up to 100 characters.');
            }
            $addons[] = [
                'id' => $row['id'] === '0' ? 0 : $this->positiveId($row['id']),
                'name' => $row['name'],
                'price' => $this->price($row['price'])
            ];
        }
        return [$data, $variants, $addons];
    }

    // Flow: Validate uploaded image content and size before assigning random filenames.
    private function uploads(bool $required, array & $uploaded): array
    {
        $images = [];
        $directory = dirname(__DIR__, 2) . '/public/images/products';
        // Read each option group as parallel form columns: label/name, existing ID, price, and variant stock.
        // Check the three image slots in display order; creation requires all three and edits may replace selected slots.
        foreach ([1, 2, 3] as $order) {
            $file = $_FILES['image' . $order] ?? null;
            // An empty image slot during editing keeps the existing image record.
            if (!$required && ($file === null || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE)) {
                continue;
            }
            // Require a successful genuine HTTP upload rather than trusting a submitted temporary path.
            if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Please upload all three product images when adding a product.');
            }
            // Inspect actual file content to select a safe extension; the browser-provided MIME type is not trusted.
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            // Require an allowed image type, a nonzero size up to 5 MiB, and recognizable image content.
            if (!isset($extensions[$mime]) || !is_int($file['size']) || $file['size'] < 1 || $file['size'] > 5 * 1024 * 1024 || @ getimagesize($file['tmp_name']) === false) {
                throw new InvalidArgumentException('Each image must be a valid JPG, PNG, or WebP up to 5 MiB.');
            }
            // Create the upload directory if needed and stop safely when it cannot be created.
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Image directory unavailable.');
            }
            // Use an unpredictable filename; keep a relative database path and a separate absolute filesystem destination.
            $path = 'public/images/products/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            $destination = dirname(__DIR__, 2) . '/' . $path;
            // Stop if PHP cannot move the validated upload into the product image directory.
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new \RuntimeException('Image upload failed.');
            }
            // Track moved files for failure cleanup and return their relative paths with display positions.
            $uploaded[] = $destination;
            $images[] = ['path' => $path, 'order' => $order];
        }
        return $images;
    }

    // Flow: Check admin access and CSRF, validate input, and save the complete product.
    private function saveProduct(?string $rawId): void
    {
        // Track files separately from database changes because rolling back SQL does not delete uploaded files.
        $uploaded = [];
        $id = null;
        $form = null;
        try {
            // Stop this action when the administrator role check has redirected to sign-in.
            if ($this->signedInAdmin() === null) {
                return;
            }
            // Step: Validate the form token after checking administrator access.
            $this->csrf();
            $id = $rawId === null ? null : $this->positiveId($rawId);
            $form = $this->formValues();
            // Step: Validate every field and option before uploading or writing records.
            [$data, $variants, $addons] = $this->validateProduct($form);
            // Move only validated images after product fields pass validation, tracking paths in case saving fails.
            $images = $this->uploads($id === null, $uploaded);
            // Step: Save the product and its related records in the model transaction.
            (new Product())->saveForAdmin($id, $data, $images, $addons, $variants);
            unset($_SESSION['admin_product_values']);
            $_SESSION['admin_success'] = $id === null ? 'Product added.' : 'Product updated. Existing variant stock was kept.';
            Flight::redirect('/admin#admin-products', 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            // Remove files moved by this failed save; database rollback alone would leave them on disk.
            foreach ($uploaded as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            // Keep normalized product fields so the administrator can correct a failed submission without retyping.
            if ($form !== null) {
                $_SESSION['admin_product_values'] = $form;
            }
            $this->failure($error, $id === null ? '/admin?modal=add-product' : '/admin?modal=edit-product&product_id=' . $id);
        }
    }

    // Flow: Validate an administrator stock request before updating a product variant.
    public function updateStock(string $id): void
    {
        $productId = null;
        try {
            // Stop this action when the administrator role check has redirected to sign-in.
            if ($this->signedInAdmin() === null) {
                return;
            }
            // Step: Validate the form token after checking administrator access.
            $this->csrf();
            $productId = $this->positiveId($id);
            $variantId = $this->positiveId($_POST['variant_id'] ?? null);
            // Pass both requested and expected stock; the model rejects stale forms while holding the variant lock.
            (new Product())->setVariantStock($productId, $variantId, $this->stock($_POST['quantity'] ?? null), $this->stock($_POST['expected_stock'] ?? null));
            $_SESSION['admin_success'] = 'Stock updated for the selected variant.';
            Flight::redirect('/admin#admin-inventory', 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            $this->failure($error, $productId === null ? '/admin' : '/admin?modal=stock&product_id=' . $productId);
        }
    }

    // Flow: Check administrator access, CSRF, and product ID before deletion.
    public function deleteProduct(string $id): void
    {
        try {
            // Stop this action when the administrator role check has redirected to sign-in.
            if ($this->signedInAdmin() === null) {
                return;
            }
            // Step: Validate the form token after checking administrator access.
            $this->csrf();
            // Delete only after authorization, CSRF, and positive-ID checks have succeeded.
            (new Product())->deleteProduct($this->positiveId($id));
            $_SESSION['admin_success'] = 'Product deleted.';
            Flight::redirect('/admin#admin-products', 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            $this->failure($error, '/admin#admin-products');
        }
    }

    // Flow: Check administrator access and delegate status rules to the order workflow.
    public function updateOrderStatus(string $id): void
    {
        $orderId = null;
        try {
            // Stop this action when the administrator role check has redirected to sign-in.
            if ($this->signedInAdmin() === null) {
                return;
            }
            // Step: Validate the form token after checking administrator access.
            $this->csrf();
            $orderId = $this->positiveId($id);
            // The model locks the order, enforces payment-specific progression, and restores stock on cancellation.
            (new Admin())->updateOrderStatus($orderId, $this->text($_POST['status'] ?? null));
            $_SESSION['admin_success'] = 'Order status updated.';
            Flight::redirect('/admin?modal=order&id=' . $orderId, 303);
        // Handle unexpected failures here so the request follows its existing error path.
        } catch (Throwable $error) {
            $this->failure($error, $orderId === null ? '/admin' : '/admin?modal=order&id=' . $orderId);
        }
    }

    // Flow: Store a safe validation message or generic failure before redirecting.
    private function failure(Throwable $error, string $url): void
    {
        // Show known validation messages; unexpected failures get a generic message and a server-side log entry.
        if ($error instanceof InvalidArgumentException) {
            $_SESSION['admin_error'] = $error->getMessage();
        } else {
            error_log('Admin update failed (' . get_class($error) . ', code ' . $error->getCode() . ').');
            $_SESSION['admin_error'] = 'Could not save your changes. Check the details and try again.';
        }
        // Return to the relevant form or section where the one-time feedback can be shown.
        Flight::redirect($url, 303);
    }
}
