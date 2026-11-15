<?php
namespace App\Models;

use PDO;

class Product extends Model
{

    public const CATEGORIES = ['Milo Series', 'Snacks', 'Fruit Drinks', 'Mix-n-Match','Bun-Saya', 'Matcha Series', 'Coffee Series'];
    public const FLAVOUR_CATEGORIES = ['Fruit Drinks', 'Mix-n-Match'];
    public const SIDE_SNACKS = ['Fries', 'Cheese stick', 'Kikiam', 'Fishball', 'Siomai', 'Pancake'];
    public const ICE_LEVELS = ['regular' => 'Regular ice', 'less' => 'Less ice', 'hot' => 'Hot coffee'];
    public const FLAVOURS = ['Green Apple', 'Grapes', 'Lychee', 'Mango', 'Blueberry', 'Watermelon', 'Strawberry', 'Orange'];

    // Flow: Validate the unavailable-flavour list before encoding it for storage.
    public static function encodeUnavailableFlavours(mixed $values): ?string
    {
        if (!is_array($values) || count($values) > count(self::FLAVOURS)) {
            throw new \InvalidArgumentException('Select valid unavailable flavours.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, self::FLAVOURS, true)) {
                throw new \InvalidArgumentException('Select valid unavailable flavours.');
            }
        }
        return $values === [] ? null : json_encode(array_values(array_unique($values)), JSON_THROW_ON_ERROR);
    }

    // Flow: Build the eight flavour choices and mark blocked choices unavailable.
    public static function flavourOptions(?string $unavailable): array
    {
        $blocked = $unavailable === null ? [] : json_decode($unavailable, true, 512, JSON_THROW_ON_ERROR);
        return array_map(static fn(string $name): array => [
            'name' => $name, 'available' => !in_array($name, $blocked, true),
        ], self::FLAVOURS);
    }

    // Flow: Reject missing, invalid, or unavailable flavours where a selection is required.
    public static function validateFlavour(array $product, ?string $flavour, bool $required = true): void
    {
        if ($flavour === null) {
            if ($required && in_array($product['category'], self::FLAVOUR_CATEGORIES, true)) {
                throw new \InvalidArgumentException('Please choose an available flavour.');
            }
            return;
        }
        if (!in_array($product['category'], self::FLAVOUR_CATEGORIES, true) || !in_array($flavour, self::FLAVOURS, true)) {
            throw new \InvalidArgumentException('Invalid flavour for this product.');
        }
        foreach (self::flavourOptions($product['unavailable_flavours'] ?? null) as $option) {
            if ($option['name'] === $flavour && !$option['available']) {
                throw new \InvalidArgumentException($flavour . ' is currently unavailable. Please choose another flavour.');
            }
        }
    }

    // Flow: Accept ice and hot-coffee choices only for Coffee Series products.
    public static function validateIceLevel(array $product, ?string $iceLevel): void
    {
        if ($iceLevel === null) {
            return; // Existing cart rows may predate the ice-level chooser.
        }
        if ($product['category'] !== 'Coffee Series' || !array_key_exists($iceLevel, self::ICE_LEVELS)) {
            throw new \InvalidArgumentException('Choose a valid ice level for a Coffee Series product.');
        }
    }

    // Flow: Validate unavailable side snacks before storing their names as JSON.
    public static function encodeUnavailableSideSnacks(mixed $values): ?string
    {
        if (!is_array($values) || count($values) > count(self::SIDE_SNACKS)) {
            throw new \InvalidArgumentException('Select valid unavailable side snacks.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, self::SIDE_SNACKS, true)) {
                throw new \InvalidArgumentException('Select valid unavailable side snacks.');
            }
        }
        return $values === [] ? null : json_encode(array_values(array_unique($values)), JSON_THROW_ON_ERROR);
    }

    public static function sideSnackOptions(?string $unavailable): array
    {
        $blocked = $unavailable === null ? [] : json_decode($unavailable, true, 512, JSON_THROW_ON_ERROR);
        return array_map(static fn(string $name): array => [
            'name' => $name, 'available' => !in_array($name, $blocked, true),
        ], self::SIDE_SNACKS);
    }

    // Flow: Require an available side snack only for Mix-n-Match products.
    public static function validateSideSnack(array $product, ?string $snack): void
    {
        if ($product['category'] !== 'Mix-n-Match') {
            if ($snack !== null) {
                throw new \InvalidArgumentException('Side snacks are only available for Mix-n-Match products.');
            }
            return;
        }
        if ($snack === null || !in_array($snack, self::SIDE_SNACKS, true)) {
            throw new \InvalidArgumentException('Please choose an available side snack.');
        }
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
        $sql = "INSERT INTO products
                (product_name, category, description,
                 base_price, availability, unavailable_flavours, unavailable_side_snacks)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->connection()->prepare($sql);

        $unavailableFlavours = $data['unavailable_flavours'] ?? null;
        $stmt->execute([$data['product_name'],
            $data['category'],
            $data['description'],
            $data['base_price'],
            $data['availability'],
            $unavailableFlavours,
            $data['unavailable_side_snacks'] ?? null]);


        $productId = (int) $this->connection()->lastInsertId();

        $stmt->closeCursor();

        return $productId;
    }


    // 2. Insert one product image.
    // Flow: Store one validated image path and its display order.
    public function addImage(int $productId,string $imagePath,int $imageOrder): void {
        $sql = "INSERT INTO product_images
                (product_id, image_path, image_order)
                VALUES (?, ?, ?)";

        $stmt = $this->connection()->prepare($sql);

        $stmt->execute([$productId,
            $imagePath,
            $imageOrder]);

        $stmt->closeCursor();
    }


    // 3. Insert one add-on.
    // Flow: Store one add-on name and price for its product.
    public function addAddon(int $productId,string $addonName,float $addonPrice): void {
        $sql = "INSERT INTO product_addons
                (product_id, addon_name, addon_price)
                VALUES (?, ?, ?)";

        $stmt = $this->connection()->prepare($sql);

        $stmt->execute([$productId,
            $addonName,
            $addonPrice]);

        $stmt->closeCursor();
    }


    // 4. Insert one size variant.
    // Flow: Store one product variant and its initial stock.
    public function addVariant(int $productId,string $sizeOz,float $price,int $stock): void {
        $sql = "INSERT INTO product_variants
                (product_id, size_oz, price, stock_quantity)
                VALUES (?, ?, ?, ?)";

        $stmt = $this->connection()->prepare($sql);

        $stmt->execute([$productId,
            $sizeOz,
            $price,
            $stock]);

        $stmt->closeCursor();
    }


    // 5. Coordinate all inserts in one transaction.
    // Flow: Save a product, images, add-ons, and variants together in one transaction.
    public function createComplete(array $data,array $images,array $addons,array $variants): int {
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
            $this->connection()->commit();

            return $productId;

        } catch (\Throwable $error) {
            $this->connection()->rollBack();

            throw $error;
        }
    }
    // Flow: Create or update a complete product while preserving existing variant inventory.
    public function saveForAdmin(?int $id, array $data, array $images, array $addons, array $variants): int
    {
        if ($id === null) {
            return $this->createComplete($data, $images, $addons, $variants);
        }
        $this->connection()->beginTransaction();
        try {
            $stmt = $this->connection()->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt->closeCursor();
            if ($exists === null) {
                throw new \InvalidArgumentException('Product not found.');
            }
            $stmt = $this->connection()->prepare('UPDATE products SET product_name = ?, category = ?, description = ?, base_price = ?, availability = ?, unavailable_flavours = ?, unavailable_side_snacks = ? WHERE id = ?');
            $unavailableFlavours = $data['unavailable_flavours'] ?? null;
            $stmt->execute([$data['product_name'], $data['category'], $data['description'], $data['base_price'], $data['availability'], $unavailableFlavours, $data['unavailable_side_snacks'] ?? null, $id]);
            $stmt->closeCursor();

            foreach (['product_variants' => $variants, 'product_addons' => $addons] as $table => $rows) {
                // Table names come only from the fixed map above.
                $stmt = $this->connection()->prepare("SELECT id FROM $table WHERE product_id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $remaining = array_fill_keys(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'), true);
                $stmt->closeCursor();
                $used = [];
                foreach ($rows as $row) {
                    $rowId = $row['id'] ?? 0;
                    if ($rowId && (!isset($remaining[$rowId]) || isset($used[$rowId]))) {
                        throw new \InvalidArgumentException('A selected variant or add-on does not belong to this product.');
                    }
                    if ($table === 'product_variants') {
                        if ($rowId) {
                            // Existing inventory changes only through the variant stock form.
                            $stmt = $this->connection()->prepare('UPDATE product_variants SET size_oz = ?, price = ? WHERE id = ? AND product_id = ?');
                            $stmt->execute([$row['size_oz'], $row['price'], $rowId, $id]);
                            $stmt->closeCursor();
                        } else {
                            $this->addVariant($id, $row['size_oz'], $row['price'], $row['stock']);
                        }
                    } elseif ($rowId) {
                        $stmt = $this->connection()->prepare('UPDATE product_addons SET addon_name = ?, addon_price = ? WHERE id = ? AND product_id = ?');
                        $stmt->execute([$row['name'], $row['price'], $rowId, $id]);
                        $stmt->closeCursor();
                    } else {
                        $this->addAddon($id, $row['name'], $row['price']);
                    }
                    if ($rowId) {
                        $used[$rowId] = true;
                    }
                }
                foreach (array_diff_key($remaining, $used) as $rowId => $_) {
                    $stmt = $this->connection()->prepare("DELETE FROM $table WHERE id = ? AND product_id = ?");
                    $stmt->execute([$rowId, $id]);
                    $stmt->closeCursor();
                }
            }
            foreach ($images as $image) {
                $stmt = $this->connection()->prepare('DELETE FROM product_images WHERE product_id = ? AND image_order = ?');
                $stmt->execute([$id, $image['order']]);
                $stmt->closeCursor();
                $this->addImage($id, $image['path'], $image['order']);
            }
            $this->connection()->commit();
            return $id;
        } catch (\Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Check ownership and stale stock values before saving the new inventory count.
    public function setVariantStock(int $productId, int $variantId, int $quantity, int $expected): void
    {
        if ($productId < 1 || $variantId < 1 || $quantity < 0 || $quantity > 4294967295 || $expected < 0) {
            throw new \InvalidArgumentException('Enter a valid variant and a non-negative whole stock quantity.');
        }
        $this->connection()->beginTransaction();
        try {
            $stmt = $this->connection()->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE');
            $stmt->execute([$variantId, $productId]);
            $variant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt->closeCursor();
            if ($variant === null) {
                throw new \InvalidArgumentException('The selected variant does not belong to this product.');
            }
            if ((int) $variant['stock_quantity'] !== $expected) {
                throw new \InvalidArgumentException('Stock changed since this form was opened. Review the current stock and try again.');
            }
            $stmt = $this->connection()->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ? AND product_id = ?');
            $stmt->execute([$quantity, $variantId, $productId]);
            $stmt->closeCursor();
            $this->connection()->commit();
        } catch (\Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Delete the requested product and reject an unknown ID.
    public function deleteProduct(int $id): void
    {
        $stmt = $this->connection()->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $deleted = $stmt->rowCount();
        $stmt->closeCursor();
        if ($deleted !== 1) {
            throw new \InvalidArgumentException('Product not found.');
        }
    }

    // Flow: Load menu products and attach their images.
    public function getProductAll(string $search = '', string $category = '') {
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Search must be 200 characters or fewer.');
        }
        if ($category !== '' && !in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Choose a valid product category.');
        }
        $conditions = [];
        $values = [];
        if ($search !== '') {
            // Treat LIKE wildcard characters as literal search text.
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $conditions[] = "(p.product_name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!')";
            array_push($values, $pattern, $pattern);
        }
        if ($category !== '') {
            $conditions[] = 'p.category = ?';
            $values[] = $category;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
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


        $result = $this->connection()->prepare($sqlmaintable);
        $result->execute($values);

        if (!$result) {
            throw new \RuntimeException(
                'Failed to retrieve products.'
            );
        }

        $rowmain = $result->fetchAll(PDO::FETCH_ASSOC);
        $result->closeCursor();
        return $this->withListingImages($rowmain);
    }

    // Flow: Rank sold products by completed-order quantity, then revenue and ID.
    public function getBestSellers(): array
    {
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
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        $result->closeCursor();
        return $this->withListingImages($rows);
    }

    // Flow: Attach ordered images while preserving listing order and product ID keys.
    private function withListingImages(array $rowmain): array
    {
        if ($rowmain === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($rowmain), '?'));
        $sqlImageTable = "SELECT
                p.id,
                p.product_id,
                p.image_path,
                p.image_order
            FROM product_images p
            WHERE p.product_id IN ($placeholders)
            ORDER BY p.image_order ASC, p.id ASC";
        $resultimage = $this->connection()->prepare($sqlImageTable);
        $resultimage->execute(array_column($rowmain, 'id'));
        $rowImage = $resultimage->fetchAll(PDO::FETCH_ASSOC);
        $resultimage->closeCursor();
        $products = [];

        foreach ($rowmain as $valuemain) {
            $productId = $valuemain['id'];

            $products[$productId] = $valuemain;
            $products[$productId]['images'] = [];
        }

        foreach ($rowImage as $valueimg) {
            $productId = $valueimg['product_id'];

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

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute([$value]);

        $result = $stmt;
        $rowmain = $result->fetchAll(PDO::FETCH_ASSOC);

        $stmt->closeCursor();

        // 5. Build the associative array.
        $products = [];

        foreach ($rowmain as $product) {
            $productId = $product['id'];

            $products[$productId] = $product;
            $products[$productId]['images'] = [];
        }

        // 6. Reuse one prepared statement to fetch each product's images.
        $sqlImage = "SELECT
                        id,
                        product_id,
                        image_path,
                        image_order
                    FROM product_images
                    WHERE product_id = ?
                    ORDER BY image_order ASC";

        $imageStmt = $this->connection()->prepare($sqlImage);

        foreach ($products as $productId => &$product) {
            $imageStmt->execute([$productId]);

            $imageResult = $imageStmt;

            $product['images'] = $imageResult->fetchAll(PDO::FETCH_ASSOC);
        }

        /* Fetch custom sizes and prices for each product */
        $variantSql = "
            SELECT id, size_oz, price, stock_quantity
            FROM product_variants
            WHERE product_id = ?
            ORDER BY id ASC
        ";

        $variantStmt = $this->connection()->prepare($variantSql);

        /* Fetch custom add-ons for each product */
        $addonSql = "
            SELECT id, addon_name, addon_price
            FROM product_addons
            WHERE product_id = ?
            ORDER BY id ASC
        ";

        $addonStmt = $this->connection()->prepare($addonSql);

        foreach ($products as $productId => &$product) {

            // Get variants belonging to this product
            $variantStmt->execute([$productId]);

            $product['variants'] = $variantStmt
                ->fetchAll(PDO::FETCH_ASSOC);

            // Get add-ons belonging to this product
            $addonStmt->execute([$productId]);

            $product['flavours'] = in_array($product['category'], self::FLAVOUR_CATEGORIES, true)
                ? self::flavourOptions($product['unavailable_flavours']) : [];
            $product['side_snacks'] = $product['category'] === 'Mix-n-Match'
                ? self::sideSnackOptions($product['unavailable_side_snacks']) : [];

            $product['addons'] = $addonStmt
                ->fetchAll(PDO::FETCH_ASSOC);
        }

        unset($product);

        $variantStmt->closeCursor();
        $addonStmt->closeCursor();

        return $products;
    }
}

?>
