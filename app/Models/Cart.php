<?php

namespace App\Models;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Database-backed cart. Prices always come from product data and server settings.
 * Carts belong to signed-in users and items are identified by their database ID.
 */
class Cart extends Model
{
    private const MAX_QUANTITY = 99;

    private ?int $userId = null;

    // Flow: Read the signed-in customer ID from the session and share the PDO connection.
    public function __construct(?PDO $db = null)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        parent::__construct($db);
        $userId = filter_var($_SESSION['user'] ?? null, FILTER_VALIDATE_INT);

        if ($userId !== false && $userId > 0) {
            $this->userId = $userId;
        }
    }

    /**
     * Save a product, or increase the quantity of an identical selection.
     *
     * @param int[] $addonIds
     * @return int The cart item ID.
     */
    // Flow: Validate the selection, calculate its price, and save or combine the cart item.
    public function addToCart(
        int $productId,
            ?int $variantId = null,
        array $addonIds = [],
        int $quantity = 1,
            ?string $sideSnack = null,
            ?int $sugarLevel = null,
            ?string $flavour = null,
            ?string $iceLevel = null
    ): int
    {
        if ($this->userId === null) {
            throw new InvalidArgumentException('Please sign in to add items to your cart.');
        }

        $this->assertQuantity($quantity);
        if ($productId < 1 || ($variantId !== null && $variantId < 1)) {
            throw new InvalidArgumentException('Invalid product or variant ID.');
        }
        foreach ($addonIds as $addonId) {
            if (!is_int($addonId) || $addonId < 1) {
                throw new InvalidArgumentException('Add-on IDs must be positive integers.');
            }
        }
        if (!in_array($sugarLevel, [null, 100, 75, 50, 0], true)) {
            throw new InvalidArgumentException('Invalid sugar level.');
        }

        $addonIds = array_values(array_unique($addonIds));
        sort($addonIds);

        // Step: Start a transaction so a failed cart change cannot save partial data.
        $this->connection()->beginTransaction();
        try {
            // Serialize additions for this product, including concurrent duplicates.
            $product = $this->fetchProduct($productId);
            if ($product === null) {
                throw new InvalidArgumentException('Product not found.');
            }
            if ($product['availability'] !== 'Available') {
                throw new InvalidArgumentException('This product is currently unavailable.');
            }
            // Step: Reject unavailable flavours even if a browser bypasses disabled controls.
            Product::validateFlavour($product, $flavour);
            Product::validateSideSnack($product, $sideSnack);
            $iceLevel ??= $product['category'] === 'Coffee Series' ? 'regular' : null;
            Product::validateIceLevel($product, $iceLevel);
            if ($variantId === null && $product['has_variants']) {
                throw new InvalidArgumentException('Please choose an in-stock variant.');
            }

            $variant = null;
            $unitCentavos = $this->toCentavos($product['base_price']);
            if ($variantId !== null) {
                $variant = $this->fetchVariant($productId, $variantId);
                if ($variant === null) {
                    throw new InvalidArgumentException('Invalid variant for this product.');
                }
                $unitCentavos += $this->toCentavos($variant['price']);
            }

            $addons = $this->fetchAddons($productId, $addonIds);
            if (count($addons) !== count($addonIds)) {
                throw new InvalidArgumentException('Invalid add-on for this product.');
            }
            foreach ($addons as $addon) {
                $unitCentavos += $this->toCentavos($addon['addon_price']);
            }

            // Step: Combine only selections with the same product options and flavour.
            $existing = $this->findSelection($productId, $variantId, $addonIds, $sideSnack, $sugarLevel, $flavour, $iceLevel);
            $newQuantity = (int)($existing['quantity'] ?? 0) + $quantity;
            $this->assertQuantity($newQuantity);
            if ($variant !== null && $newQuantity > (int) $variant['stock_quantity']) {
                throw new InvalidArgumentException('Not enough stock for the selected variant.');
            }

            $variantLabel = $variant['size_oz'] ?? null;
            $addonJson = json_encode(array_map(
                static fn (array $addon): array => [
                    'id' => (int) $addon['id'],
                    'name' => $addon['addon_name'],
                    'price' => (float) $addon['addon_price'],
                ],
                $addons
            ), JSON_THROW_ON_ERROR);
            $unitPrice = number_format($unitCentavos / 100, 2, '.', '');
            if ($existing !== null) {
                $itemId = (int) $existing['id'];
                $stmt = $this->connection()->prepare(
                    'UPDATE cart_items 
                    SET product_name = ?, variant_label = ?, addons = ?,
                    unit_price = ?, quantity = ? 
                    WHERE id = ? 
                    AND user_id = ?'
                );
                $stmt->execute([
                    $product['product_name'],
                    $variantLabel,
                    $addonJson,
                    $unitPrice,
                    $newQuantity,
                    $itemId,
                    $this->userId
                ]);
            } else {
                $stmt = $this->connection()->prepare(
                    'INSERT INTO cart_items
                        (user_id, product_id, product_name, variant_id, variant_label, addons,
                         side_snack, sugar_level, unit_price, quantity, flavour, ice_level)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $this->userId,
                    $productId,
                    $product['product_name'],
                    $variantId,
                    $variantLabel,
                    $addonJson,
                    $sideSnack,
                    $sugarLevel,
                    $unitPrice,
                    $newQuantity,
                    $flavour,
                    $iceLevel
                ]);
            }
            $itemId = $existing !== null ? $itemId : (int) $this->connection()->lastInsertId();
            $stmt->closeCursor();
            // Step: Commit only after validation and the cart write succeed.
            $this->connection()->commit();

            return $itemId;
        } catch (Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Setting quantity to zero removes the line.
    // Flow: Change only the current user's item after checking quantity and stock.
    public function updateQuantity(int $itemId, int $quantity): void
    {
        $this->requireUser();
        if ($quantity === 0) {
            $this->deleteFromCart($itemId);
            return;
        }
        $this->assertQuantity($quantity);
        // Step: Start a transaction so a failed cart change cannot save partial data.
        $this->connection()->beginTransaction();
        try {
            $line = $this->fetchLine($itemId, true);
            if ($line === null) {
                throw new InvalidArgumentException('Cart item not found.');
            }
            if ($line['variant_id'] !== null) {
                $variant = $this->fetchVariant((int) $line['product_id'], (int) $line['variant_id']);
                if ($variant === null || $quantity > (int) $variant['stock_quantity']) {
                    throw new InvalidArgumentException('Not enough stock for the selected variant.');
                }
            }
            $stmt = $this->connection()->prepare('UPDATE cart_items SET quantity = ? WHERE user_id = ? AND id = ?');
            $stmt->execute([$quantity, $this->userId, $itemId]);
            $stmt->closeCursor();
            // Step: Commit only after validation and the cart write succeed.
            $this->connection()->commit();
        } catch (Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Remove only the item owned by the signed-in customer.
    public function deleteFromCart(int $itemId): void
    {
        $this->requireUser();
        $stmt = $this->connection()->prepare('DELETE FROM cart_items WHERE user_id = ? AND id = ?');
        $stmt->execute([$this->userId, $itemId]);
        $deleted = $stmt->rowCount();
        $stmt->closeCursor();
        if ($deleted === 0) {
            throw new InvalidArgumentException('Cart item not found.');
        }
    }

    // Key items by their database ID for cart forms and JSON responses.
    // Flow: Load the current user's cart, or return an empty cart when signed out.
    public function getItems(bool $forUpdate = false): array
    {
        if ($this->userId === null) {
            return [];
        }

        $stmt = $this->connection()->prepare(
            'SELECT c.id, c.product_id, c.product_name, c.variant_id, c.variant_label,
                    c.addons, c.flavour, c.side_snack, c.ice_level, c.milk_preference, c.sugar_level, c.unit_price, c.quantity,
                    p.category,
                    (SELECT i.image_path FROM product_images i
                     WHERE i.product_id = c.product_id ORDER BY i.image_order ASC LIMIT 1) AS image_path
             FROM cart_items c JOIN products p ON p.id = c.product_id
             WHERE c.user_id = ? ORDER BY c.created_at, c.id'
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$this->userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $items = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['variant_id'] = $row['variant_id'] === null ? null : (int) $row['variant_id'];
            $row['sugar_level'] = $row['sugar_level'] === null ? null : (int) $row['sugar_level'];
            $row['unit_price'] = (float) $row['unit_price'];
            $row['quantity'] = (int) $row['quantity'];
            $row['addons'] = json_decode($row['addons'], true, 512, JSON_THROW_ON_ERROR);
            $items[$row['id']] = $row;
        }
        return $items;
    }

    // Flow: Count all units in the current cart.
    public function getCount(): int
    {
        return array_sum(array_column($this->getItems(), 'quantity'));
    }

    // Flow: Expose the cart owner ID without allowing callers to change it.
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    // Flow: Calculate the cart total in centavos before returning pesos.
    public function getTotal(): float
    {
        $totalCentavos = 0;
        foreach ($this->getItems() as $line) {
            $totalCentavos += $this->toCentavos($line['unit_price']) * $line['quantity'];
        }
        return $totalCentavos / 100;
    }

    // Flow: Remove the signed-in customer's cart items after checkout or an explicit clear.
    public function clear(): void
    {
        $this->requireUser();
        $stmt = $this->connection()->prepare('DELETE FROM cart_items WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        $stmt->closeCursor();
    }

    // Flow: Reject cart mutations when there is no signed-in customer.
    private function requireUser(): void
    {
        if ($this->userId === null) {
            throw new InvalidArgumentException('Please sign in to manage your cart.');
        }
    }

    // Product locking in addToCart serializes duplicate additions without a hash column.
    // Flow: Find an identical selection so quantities combine without a hash key.
    private function findSelection(int $productId, ?int $variantId, array $addonIds, ?string $sideSnack, ?int $sugar, ?string $flavour, ?string $iceLevel): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, quantity, addons FROM cart_items
             WHERE user_id = ? AND product_id = ? AND variant_id <=> ?
               AND side_snack <=> ? AND milk_preference IS NULL AND sugar_level <=> ? AND flavour <=> ? AND ice_level <=> ? FOR UPDATE'
        );
        $stmt->execute([$this->userId, $productId, $variantId, $sideSnack, $sugar, $flavour, $iceLevel]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($rows as $row) {
            $savedIds = array_map('intval', array_column(json_decode($row['addons'], true, 512, JSON_THROW_ON_ERROR), 'id'));
            sort($savedIds);
            if ($savedIds === $addonIds) {
                return $row;
            }
        }
        return null;
    }

    // Flow: Convert a price to integer centavos for calculations.
    private function toCentavos(string | int | float $price): int
    {
        return (int) round((float) $price * 100);
    }

    // Flow: Reject quantities outside the allowed range of 1 to 99.
    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('Quantity must be between 1 and ' . self::MAX_QUANTITY . '.');
        }
    }

    // Flow: Find an item by both its numeric ID and its current owner.
    private function fetchLine(int $itemId, bool $lock = false): ?array
    {
        $sql = 'SELECT product_id, variant_id, quantity FROM cart_items WHERE user_id = ? AND id = ?';
        $stmt = $this->connection()->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$this->userId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Lock the product while checking its current price and availability.
    private function fetchProduct(int $productId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT p.id, p.product_name, p.base_price, p.availability, p.category, p.unavailable_flavours, p.unavailable_side_snacks,
                    EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id = p.id) AS has_variants
             FROM products p WHERE p.id = ? FOR UPDATE'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Verify that a selected variant belongs to the requested product.
    private function fetchVariant(int $productId, int $variantId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, size_oz, price, stock_quantity FROM product_variants WHERE id = ? AND product_id = ?'
        );
        $stmt->execute([$variantId, $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Retrieve only the requested add-ons belonging to this product.
    private function fetchAddons(int $productId, array $addonIds): array
    {
        if ($addonIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($addonIds), '?'));
        $stmt = $this->connection()->prepare(
            "SELECT id, addon_name, addon_price FROM product_addons
             WHERE product_id = ? AND id IN ($placeholders) ORDER BY id"
        );
        $stmt->execute([$productId, ... $addonIds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }
}
