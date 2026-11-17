<?php

namespace App\Models;

use PDO;

class Product extends Model
{
    // These allowlists are shared by server validation, product forms, and customer option displays.
    public const CATEGORIES = [
        'Milo Series',
        'Snacks',
        'Fruit Drinks',
        'Mix-n-Match',
        'Bun-Saya',
        'Matcha Series',
        'Coffee Series'
    ];
    public const FLAVOUR_CATEGORIES = ['Fruit Drinks', 'Mix-n-Match'];
    public const SIDE_SNACKS = ['Fries', 'Cheese stick', 'Kikiam', 'Fishball', 'Siomai', 'Pancake'];
    public const ICE_LEVELS = ['regular' => 'Regular ice', 'less' => 'Less ice', 'hot' => 'Hot coffee'];
    public const FLAVOURS = [
        'Green Apple',
        'Grapes',
        'Lychee',
        'Mango',
        'Blueberry',
        'Watermelon',
        'Strawberry',
        'Orange'
    ];

    // Flow: Validate the unavailable-flavour list before encoding it for storage.
    public static function encodeUnavailableFlavours(mixed $values): ?string
    {
        // Require a list within the available choice count before validating each submitted name.
        if (!is_array($values) || count($values) > count(self::FLAVOURS)) {
            throw new \InvalidArgumentException('Select valid unavailable flavours.');
        }
        // Reject any unavailable choice that is not a string from the corresponding allowlist.
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, self::FLAVOURS, true)) {
                throw new \InvalidArgumentException('Select valid unavailable flavours.');
            }
        }
        // Store null when no choices are blocked; otherwise store unique names as a JSON list.
        return $values === [] ? null : json_encode(array_values(array_unique($values)), JSON_THROW_ON_ERROR);
    }

    // Flow: Build the eight flavour choices and mark blocked choices unavailable.
    public static function flavourOptions(?string $unavailable): array
    {
        // Decode the stored unavailable names; a null value means every listed choice is available.
        $blocked = $unavailable === null ? [] : json_decode($unavailable, true, 512, JSON_THROW_ON_ERROR);
        // Build a name/availability pair for each allowed choice so templates can disable blocked options.
        return array_map(static fn (string $name): array => [
            'name' => $name,
            'available' => !in_array($name, $blocked, true),
        ], self::FLAVOURS);
    }

    // Flow: Reject missing, invalid, or unavailable flavours where a selection is required.
    public static function validateFlavour(array $product, ?string $flavour, bool $required = true): void
    {
        // New flavoured products require a choice; checkout can permit older cart rows that have no flavour.
        if ($flavour === null) {
            if ($required && in_array($product['category'], self::FLAVOUR_CATEGORIES, true)) {
                throw new \InvalidArgumentException('Please choose an available flavour.');
            }
            return;
        }
        // Reject a flavour that is unknown or does not apply to this product category.
        if (!in_array($product['category'], self::FLAVOUR_CATEGORIES, true) || !in_array($flavour, self::FLAVOURS, true)) {
            throw new \InvalidArgumentException('Invalid flavour for this product.');
        }
        // Check the selected flavour against the current product availability, not just the global list.
        foreach (self::flavourOptions($product['unavailable_flavours'] ?? null) as $option) {
            if ($option['name'] === $flavour && !$option['available']) {
                throw new \InvalidArgumentException($flavour . ' is currently unavailable. Please choose another flavour.');
            }
        }
    }

    // Flow: Accept ice and hot-coffee choices only for Coffee Series products.
    public static function validateIceLevel(array $product, ?string $iceLevel): void
    {
        // Keep compatibility with older cart rows that were saved before the ice-level option existed.
        if ($iceLevel === null) {
            return; // Existing cart rows may predate the ice-level chooser.
        }
        // Only Coffee Series can use the configured ice or hot-coffee choices.
        if ($product['category'] !== 'Coffee Series' || !array_key_exists($iceLevel, self::ICE_LEVELS)) {
            throw new \InvalidArgumentException('Choose a valid ice level for a Coffee Series product.');
        }
    }

    // Flow: Validate unavailable side snacks before storing their names as JSON.
    public static function encodeUnavailableSideSnacks(mixed $values): ?string
    {
        // Require a list within the available choice count before validating each submitted name.
        if (!is_array($values) || count($values) > count(self::SIDE_SNACKS)) {
            throw new \InvalidArgumentException('Select valid unavailable side snacks.');
        }
        // Reject any unavailable choice that is not a string from the corresponding allowlist.
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, self::SIDE_SNACKS, true)) {
                throw new \InvalidArgumentException('Select valid unavailable side snacks.');
            }
        }
        // Store null when no choices are blocked; otherwise store unique names as a JSON list.
        return $values === [] ? null : json_encode(array_values(array_unique($values)), JSON_THROW_ON_ERROR);
    }

    // Expose side snacks in the same name/availability format used by the flavour chooser.
    public static function sideSnackOptions(?string $unavailable): array
    {
        // Decode the stored unavailable names; a null value means every listed choice is available.
        $blocked = $unavailable === null ? [] : json_decode($unavailable, true, 512, JSON_THROW_ON_ERROR);
        // Build a name/availability pair for each allowed choice so templates can disable blocked options.
        return array_map(static fn (string $name): array => [
            'name' => $name,
            'available' => !in_array($name, $blocked, true),
        ], self::SIDE_SNACKS);
    }

    // Flow: Require an available side snack only for Mix-n-Match products.
    public static function validateSideSnack(array $product, ?string $snack): void
    {
        // Other categories cannot include a side snack; a Mix-n-Match item must include one.
        if ($product['category'] !== 'Mix-n-Match') {
            if ($snack !== null) {
                throw new \InvalidArgumentException('Side snacks are only available for Mix-n-Match products.');
            }
            return;
        }
        // Require a recognized snack before checking whether this product currently offers it.
        if ($snack === null || !in_array($snack, self::SIDE_SNACKS, true)) {
            throw new \InvalidArgumentException('Please choose an available side snack.');
        }
        // Reject a snack that an administrator has marked unavailable for this product.
        foreach (self::sideSnackOptions($product['unavailable_side_snacks'] ?? null) as $option) {
            if ($option['name'] === $snack && !$option['available']) {
                throw new \InvalidArgumentException($snack . ' is currently unavailable. Please choose another side snack.');
            }
        }
    }

    // 1. Insert the main product.
    // Flow: Save the main product fields with PDO parameters and return its numeric ID.
    public function createProduct(array $data): int
    {
        // Insert the main product fields; each placeholder follows the column order shown here.
        $sql = "INSERT INTO products
                (product_name, category, description,
                 base_price, availability, unavailable_flavours, unavailable_side_snacks)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        // Prepare the query structure first; submitted values will be passed separately when it is executed.
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);

        $unavailableFlavours = $data['unavailable_flavours'] ?? null;
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([
            $data['product_name'],
            $data['category'],
            $data['description'],
            $data['base_price'],
            $data['availability'],
            $unavailableFlavours,
            $data['unavailable_side_snacks'] ?? null
        ]);

        // Read the generated product ID on this connection so related records can refer to it.
        $productId = (int) $this->connection()->lastInsertId();

        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();

        return $productId;
    }

    // 2. Insert one product image.
    // Flow: Store one validated image path and its display order.
    public function addImage(int $productId, string $imagePath, int $imageOrder): void
    {
        // Link a relative image path and display position to the parent product ID.
        $sql = "INSERT INTO product_images
                (product_id, image_path, image_order)
                VALUES (?, ?, ?)";

        // Prepare the query structure first; submitted values will be passed separately when it is executed.
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);

        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([
            $productId,
            $imagePath,
            $imageOrder
        ]);

        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
    }

    // 3. Insert one add-on.
    // Flow: Store one add-on name and price for its product.
    public function addAddon(int $productId, string $addonName, float $addonPrice): void
    {
        // Link an optional extra and its additional price to the parent product ID.
        $sql = "INSERT INTO product_addons
                (product_id, addon_name, addon_price)
                VALUES (?, ?, ?)";

        // Prepare the query structure first; submitted values will be passed separately when it is executed.
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);

        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([
            $productId,
            $addonName,
            $addonPrice
        ]);

        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
    }

    // 4. Insert one size variant.
    // Flow: Store one product variant and its initial stock.
    public function addVariant(int $productId, string $sizeOz, float $price, int $stock): void
    {
        // Link a variant label, additional price, and initial stock to the parent product ID.
        $sql = "INSERT INTO product_variants
                (product_id, size_oz, price, stock_quantity)
                VALUES (?, ?, ?, ?)";

        // Prepare the query structure first; submitted values will be passed separately when it is executed.
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);

        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([
            $productId,
            $sizeOz,
            $price,
            $stock
        ]);

        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
    }

    // 5. Coordinate all inserts in one transaction.
    // Flow: Save a product, images, add-ons, and variants together in one transaction.
    public function createComplete(array $data, array $images, array $addons, array $variants): int
    {
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();

        try {
            // Create the main product first.
            $productId = $this->createProduct($data);

            // Save each uploaded image path.
            foreach ($images as $image) {
                $this->addImage(
                    $productId,
                    $image['path'],
                    $image['order']
                );
            }

            // Save each add-on.
            foreach ($addons as $addon) {
                $this->addAddon(
                    $productId,
                    $addon['name'],
                    $addon['price']
                );
            }

            // Save each size variant.
            foreach ($variants as $variant) {
                $this->addVariant(
                    $productId,
                    $variant['size_oz'],
                    $variant['price'],
                    $variant['stock']
                );
            }

            // Commit only after every insert succeeds.
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();

            return $productId;

        } catch (\Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();

            throw $error;
        }
    }
    // Flow: Create or update a complete product while preserving existing variant inventory.
    public function saveForAdmin(?int $id, array $data, array $images, array $addons, array $variants): int
    {
        // A missing ID means creation; use the existing complete-product insert transaction.
        if ($id === null) {
            return $this->createComplete($data, $images, $addons, $variants);
        }
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Lock the product during editing so related option updates use a consistent parent record.
            $stmt = $this->connection()->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$id]);
            // Fetch one matching row by column name, using null when no record matches.
            $exists = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            // Stop and roll back if the product was deleted before this edit was saved.
            if ($exists === null) {
                throw new \InvalidArgumentException('Product not found.');
            }
            // Update product details and blocked-choice JSON only for the requested product ID.
            $stmt = $this->connection()->prepare('UPDATE products 
            SET product_name = ?, category = ?, description = ?, 
            base_price = ?, availability = ?, unavailable_flavours = ?, unavailable_side_snacks = ? 
            WHERE id = ?');
            $unavailableFlavours = $data['unavailable_flavours'] ?? null;
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([
                $data['product_name'],
                $data['category'],
                $data['description'],
                $data['base_price'],
                $data['availability'],
                $unavailableFlavours,
                $data['unavailable_side_snacks'] ?? null,
                $id
            ]);
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();

            // Synchronize the submitted variant and add-on rows with the rows already belonging to this product.
            foreach (['product_variants' => $variants, 'product_addons' => $addons] as $table => $rows) {
                // Table names come only from the fixed map above.
                $stmt = $this->connection()->prepare("SELECT id 
                FROM $table WHERE product_id = ? FOR UPDATE");
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([$id]);
                // Index existing option IDs so submitted IDs can be checked and omitted rows can later be removed.
                // Fetch the result rows as arrays keyed by column name for the following PHP processing.
                $remaining = array_fill_keys(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'), true);
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
                $used = [];
                // Update known option IDs, insert new rows with ID zero, and record which existing IDs were retained.
                foreach ($rows as $row) {
                    $rowId = $row['id'] ?? 0;
                    // Reject foreign or repeated IDs so one product cannot modify another product's options.
                    if ($rowId && (!isset($remaining[$rowId]) || isset($used[$rowId]))) {
                        throw new \InvalidArgumentException('A selected variant or add-on does not belong to this product.');
                    }
                    // Variant edits change labels and prices; existing inventory is handled by the separate stock form.
                    if ($table === 'product_variants') {
                        if ($rowId) {
                            // Existing inventory changes only through the variant stock form.
                            // Restrict the update to this variant and product; keep the existing stock on ordinary product edits.
                            $stmt = $this->connection()->prepare('UPDATE product_variants 
                            SET size_oz = ?, price = ? WHERE id = ? AND product_id = ?');
                            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                            $stmt->execute([$row['size_oz'], $row['price'], $rowId, $id]);
                            // Release this result before reusing the statement or connection.
                            $stmt->closeCursor();
                        } else {
                            $this->addVariant($id, $row['size_oz'], $row['price'], $row['stock']);
                        }
                    } elseif ($rowId) {
                        // Update the add-on name and price only when both its ID and parent product match.
                        $stmt = $this->connection()->prepare('UPDATE product_addons 
                        SET addon_name = ?, addon_price = ? 
                        WHERE id = ? 
                        AND product_id = ?');
                        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                        $stmt->execute([$row['name'], $row['price'], $rowId, $id]);
                        // Release this result before reusing the statement or connection.
                        $stmt->closeCursor();
                    } else {
                        $this->addAddon($id, $row['name'], $row['price']);
                    }
                    if ($rowId) {
                        $used[$rowId] = true;
                    }
                }
                // Delete existing options omitted from the submitted form while retaining the selected IDs.
                foreach (array_diff_key($remaining, $used) as $rowId => $_) {
                    // The table name comes from the fixed map; placeholders restrict deletion to the option and its product.
                    $stmt = $this->connection()->prepare("DELETE FROM $table 
                    WHERE id = ? 
                    AND product_id = ?");
                    // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                    $stmt->execute([$rowId, $id]);
                    // Release this result before reusing the statement or connection.
                    $stmt->closeCursor();
                }
            }
            foreach ($images as $image) {
                // Replace only the database image slot supplied by this upload; other image positions remain unchanged.
                $stmt = $this->connection()->prepare('DELETE FROM product_images 
                WHERE product_id = ? 
                AND image_order = ?');
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([$id, $image['order']]);
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
                $this->addImage($id, $image['path'], $image['order']);
            }
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
            return $id;
        } catch (\Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Check ownership and stale stock values before saving the new inventory count.
    public function setVariantStock(int $productId, int $variantId, int $quantity, int $expected): void
    {
        // Reject invalid IDs and stock amounts before opening the inventory transaction.
        if ($productId < 1 || $variantId < 1 || $quantity < 0 || $quantity > 4294967295 || $expected < 0) {
            throw new \InvalidArgumentException('Enter a valid variant and a non-negative whole stock quantity.');
        }
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Lock the matching variant and product while comparing the form's expected stock with current stock.
            $stmt = $this->connection()->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$variantId, $productId]);
            // Fetch one matching row by column name, using null when no record matches.
            $variant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            // Reject a variant that is missing or does not belong to the selected product.
            if ($variant === null) {
                throw new \InvalidArgumentException('The selected variant does not belong to this product.');
            }
            // Reject a stale stock form instead of overwriting an inventory change made since it was opened.
            if ((int) $variant['stock_quantity'] !== $expected) {
                throw new \InvalidArgumentException('Stock changed since this form was opened. Review the current stock and try again.');
            }
            // Save the requested absolute stock count only for the locked variant and its parent product.
            $stmt = $this->connection()->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ? AND product_id = ?');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$quantity, $variantId, $productId]);
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
        } catch (\Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Delete the requested product and reject an unknown ID.
    public function deleteProduct(int $id): void
    {
        // Delete only the product with the supplied ID; database relationships govern its related records.
        $stmt = $this->connection()->prepare('DELETE FROM products WHERE id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$id]);
        // Check whether a product was actually removed so an unknown ID gets a useful error.
        $deleted = $stmt->rowCount();
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        if ($deleted !== 1) {
            throw new \InvalidArgumentException('Product not found.');
        }
    }

    // Flow: Load menu products and attach their images.
    public function getProductAll(string $search = '', string $category = '')
    {
        // Limit search length before preparing a listing query.
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Search must be 200 characters or fewer.');
        }
        // Allow an empty category for all products, or one of the configured category names.
        if ($category !== '' && !in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Choose a valid product category.');
        }
        $conditions = [];
        $values = [];
        // Add name/description matching only when search text is present; bind the same pattern to both fields.
        if ($search !== '') {
            // Treat LIKE wildcard characters as literal search text.
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $conditions[] = "(p.product_name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!')";
            array_push($values, $pattern, $pattern);
        }
        // Allow an empty category for all products, or one of the configured category names.
        if ($category !== '') {
            $conditions[] = 'p.category = ?';
            $values[] = $category;
        }
        // Join only application-created filter fragments; user values remain separate query parameters.
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        // Retrieve matching products newest first, using the ID to break ties in creation time.
        $sqlmaintable = "SELECT
                p.id,
                p.product_name,
                p.category,
                p.description,
                p.base_price,
                p.availability,
                p.unavailable_flavours,
                p.unavailable_side_snacks,
                p.created_at
            FROM products p
            $where
            ORDER BY p.created_at DESC, p.id DESC";

        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $result = $this->connection()->prepare($sqlmaintable);
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $result->execute($values);

        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rowmain = $result->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $result->closeCursor();
        return $this->withListingImages($rowmain);
    }

    // Flow: Rank sold products by completed-order quantity, then revenue and ID.
    public function getBestSellers(): array
    {
        // The subquery joins order items to completed orders and sums sold units and revenue per product.
        // Join those totals to current products, rank by units then revenue and ID, and return at most three.
        $result = $this->connection()->query(
            "SELECT p.*, sales.sold
             FROM products p
             JOIN (
                 SELECT i.product_id, SUM(i.quantity) AS sold, SUM(i.line_total) AS revenue
                 FROM order_items i JOIN orders o ON o.id = i.order_id
                 WHERE o.status = 'Completed'
                 GROUP BY i.product_id
             ) sales ON sales.product_id = p.id
             ORDER BY sales.sold DESC, sales.revenue DESC, p.id ASC
             LIMIT 3"
        );
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $result->closeCursor();
        return $this->withListingImages($rows);
    }

    // Flow: Attach ordered images while preserving listing order and product ID keys.
    private function withListingImages(array $rowmain): array
    {
        // Skip the image query for an empty product list so no empty IN clause is generated.
        if ($rowmain === []) {
            return [];
        }

        // Create one placeholder per product ID, allowing one image query for the whole listing.
        $placeholders = implode(',', array_fill(0, count($rowmain), '?'));
        // Retrieve images only for listed products, sorted by display position and then image ID.
        $sqlImageTable = "SELECT
                p.id,
                p.product_id,
                p.image_path,
                p.image_order
            FROM product_images p
            WHERE p.product_id IN ($placeholders)
            ORDER BY p.image_order ASC, p.id ASC";
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $resultimage = $this->connection()->prepare($sqlImageTable);
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $resultimage->execute(array_column($rowmain, 'id'));
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rowImage = $resultimage->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $resultimage->closeCursor();
        $products = [];

        // Key products by database ID and initialize their image lists for templates and JSON responses.
        foreach ($rowmain as $valuemain) {
            $productId = $valuemain['id'];

            $products[$productId] = $valuemain;
            $products[$productId]['images'] = [];
        }

        // Attach each image to its parent product without changing the listing order.
        foreach ($rowImage as $valueimg) {
            $productId = $valueimg['product_id'];

            // Only attach image records whose product is part of the requested listing.
            if (isset($products[$productId])) {
                $products[$productId]['images'][] = $valueimg;
            }
        }

        return $products;
    }

    // Flow: Allow only known search columns, then load product images, variants, add-ons, and flavours.
    public function getProduct(string $params, mixed $value)
    {
        // 1. Allow only specific column names.
        // Column names cannot be bound as PDO values, so map supported search names to trusted SQL identifiers.
        $allowedColumns = [
            'id' => 'p.id',
            'product_name' => 'p.product_name',
            'category' => 'p.category',
            'availability' => 'p.availability'
        ];

        // 2. Reject any column that is not on the whitelist.
        if (!isset($allowedColumns[$params])) {
            throw new \InvalidArgumentException(
                'Invalid search column.'
            );
        }

        // 3. Validate the value if searching by ID.
        // ID lookups require a positive integer; the other supported columns use their existing search values.
        if ($params === 'id') {
            $value = filter_var($value, FILTER_VALIDATE_INT);

            if ($value === false || $value < 1) {
                throw new \InvalidArgumentException(
                    'Invalid product ID.'
                );
            }
        }

        // 4. Safely query the main products table.
        $column = $allowedColumns[$params];

        $sql = "SELECT
                    p.id,
                    p.product_name,
                    p.category,
                    p.description,
                    p.base_price,
                    p.availability,
                    p.unavailable_flavours,
                    p.unavailable_side_snacks,
                    p.created_at
                FROM products p
                WHERE $column = ?
                ORDER BY p.created_at DESC";

        // Prepare the query structure first; submitted values will be passed separately when it is executed.
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$value]);

        $result = $stmt;
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rowmain = $result->fetchAll(PDO::FETCH_ASSOC);

        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();

        // 5. Build the associative array.
        $products = [];

        // Key products by database ID and initialize their image lists for templates and JSON responses.
        foreach ($rowmain as $product) {
            $productId = $product['id'];

            $products[$productId] = $product;
            $products[$productId]['images'] = [];
        }

        // 6. Reuse one prepared statement to fetch each product's images.
        // Select this product's images in display order for the gallery.
        $sqlImage = "SELECT
                        id,
                        product_id,
                        image_path,
                        image_order
                    FROM product_images
                    WHERE product_id = ?
                    ORDER BY image_order ASC";

        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $imageStmt = $this->connection()->prepare($sqlImage);

        // Load related data for each returned product while preserving the product-ID keys.
        foreach ($products as $productId => & $product) {
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $imageStmt->execute([$productId]);

            $imageResult = $imageStmt;

            // Fetch the result rows as arrays keyed by column name for the following PHP processing.
            $product['images'] = $imageResult->fetchAll(PDO::FETCH_ASSOC);
        }

        /* Fetch custom sizes and prices for each product */
        // Retrieve variant IDs, labels, additional prices, and stock for each product, ordered by ID.
        $variantSql = "
            SELECT id, size_oz, price, stock_quantity
            FROM product_variants
            WHERE product_id = ?
            ORDER BY id ASC
        ";

        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $variantStmt = $this->connection()->prepare($variantSql);

        /* Fetch custom add-ons for each product */
        // Retrieve the extras belonging to each product, ordered by ID for a stable chooser.
        $addonSql = "
            SELECT id, addon_name, addon_price
            FROM product_addons
            WHERE product_id = ?
            ORDER BY id ASC
        ";

        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $addonStmt = $this->connection()->prepare($addonSql);

        // Load related data for each returned product while preserving the product-ID keys.
        foreach ($products as $productId => & $product) {

            // Get variants belonging to this product
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $variantStmt->execute([$productId]);

            // Fetch the result rows as arrays keyed by column name for the following PHP processing.
            $product['variants'] = $variantStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get add-ons belonging to this product
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $addonStmt->execute([$productId]);

            // Only expose flavour and side-snack choosers for categories that support those options.
            $product['flavours'] = in_array($product['category'], self::FLAVOUR_CATEGORIES, true)
                ? self::flavourOptions($product['unavailable_flavours']) : [];
            $product['side_snacks'] = $product['category'] === 'Mix-n-Match'
                ? self::sideSnackOptions($product['unavailable_side_snacks']) : [];

            // Fetch the result rows as arrays keyed by column name for the following PHP processing.
            $product['addons'] = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        unset($product);

        // Release this result before reusing the statement or connection.
        $variantStmt->closeCursor();
        // Release this result before reusing the statement or connection.
        $addonStmt->closeCursor();

        return $products;
    }
}
?>
