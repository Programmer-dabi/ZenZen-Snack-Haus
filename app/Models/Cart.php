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
        // Start a session when the model is used outside the normal entry point, then read its customer ID.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Reuse a supplied PDO connection when this cart participates in an order transaction.
        parent::__construct($db);
        $userId = filter_var($_SESSION['user'] ?? null, FILTER_VALIDATE_INT);

        // Remember only a positive customer ID; guest carts return no saved items.
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
        // Guests may read an empty cart, but attempts to save or change items must be rejected.
        if ($this->userId === null) {
            throw new InvalidArgumentException('Please sign in to add items to your cart.');
        }

        // Apply the same 1-to-99 rule before saving a new amount or combining identical selections.
        $this->assertQuantity($quantity);
        // Check product and optional variant IDs before any database lookup.
        if ($productId < 1 || ($variantId !== null && $variantId < 1)) {
            throw new InvalidArgumentException('Invalid product or variant ID.');
        }
        // Require positive integer add-on IDs before checking product ownership in the database.
        foreach ($addonIds as $addonId) {
            if (!is_int($addonId) || $addonId < 1) {
                throw new InvalidArgumentException('Add-on IDs must be positive integers.');
            }
        }
        // Accept only the supported sugar percentages or no sugar preference.
        if (!in_array($sugarLevel, [null, 100, 75, 50, 0], true)) {
            throw new InvalidArgumentException('Invalid sugar level.');
        }

        // Remove duplicate add-ons and sort their IDs so identical selections compare consistently.
        $addonIds = array_values(array_unique($addonIds));
        sort($addonIds);

        // Step: Start a transaction so a failed cart change cannot save partial data.
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Serialize additions for this product, including concurrent duplicates.
            // Read current product settings under a lock; competing additions for this product wait for this transaction.
            $product = $this->fetchProduct($productId);
            // Reject a product that no longer exists before calculating its cart price.
            if ($product === null) {
                throw new InvalidArgumentException('Product not found.');
            }
            // A product marked unavailable cannot be added even if its page was opened earlier.
            if ($product['availability'] !== 'Available') {
                throw new InvalidArgumentException('This product is currently unavailable.');
            }
            // Step: Reject unavailable flavours even if a browser bypasses disabled controls.
            Product::validateFlavour($product, $flavour);
            Product::validateSideSnack($product, $sideSnack);
            // New Coffee Series selections default to regular ice when no ice choice was sent.
            $iceLevel ??= $product['category'] === 'Coffee Series' ? 'regular' : null;
            Product::validateIceLevel($product, $iceLevel);
            // A product with saved variants requires the customer to choose one.
            if ($variantId === null && $product['has_variants']) {
                throw new InvalidArgumentException('Please choose an in-stock variant.');
            }

            $variant = null;
            // Start with the product base price in centavos, then add variant and add-on prices.
            $unitCentavos = $this->toCentavos($product['base_price']);
            // Only add a variant price after confirming that the selected variant belongs to this product.
            if ($variantId !== null) {
                $variant = $this->fetchVariant($productId, $variantId);
                // Reject a variant from another product or one that has been removed.
                if ($variant === null) {
                    throw new InvalidArgumentException('Invalid variant for this product.');
                }
                $unitCentavos += $this->toCentavos($variant['price']);
            }

            // Fetch requested add-ons from this product so client-supplied prices are never trusted.
            $addons = $this->fetchAddons($productId, $addonIds);
            // Every distinct submitted add-on must match an add-on owned by the product.
            if (count($addons) !== count($addonIds)) {
                throw new InvalidArgumentException('Invalid add-on for this product.');
            }
            // Add each selected extra's database price to the per-unit total.
            foreach ($addons as $addon) {
                $unitCentavos += $this->toCentavos($addon['addon_price']);
            }

            // Step: Combine only selections with the same product options and flavour.
            $existing = $this->findSelection($productId, $variantId, $addonIds, $sideSnack, $sugarLevel, $flavour, $iceLevel);
            // Combine the existing line quantity with the requested units before checking limits and stock.
            $newQuantity = (int)($existing['quantity'] ?? 0) + $quantity;
            $this->assertQuantity($newQuantity);
            // Reject the combined quantity if the selected variant does not have enough stock.
            if ($variant !== null && $newQuantity > (int) $variant['stock_quantity']) {
                throw new InvalidArgumentException('Not enough stock for the selected variant.');
            }

            $variantLabel = $variant['size_oz'] ?? null;
            // Snapshot add-on IDs, names, and prices as JSON so the saved selection can be displayed and compared.
            $addonJson = json_encode(array_map(
                static fn (array $addon): array => [
                    'id' => (int) $addon['id'],
                    'name' => $addon['addon_name'],
                    'price' => (float) $addon['addon_price'],
                ],
                $addons
            ), JSON_THROW_ON_ERROR);
            // Convert the integer-centavo calculation to the two-decimal amount stored by the database.
            $unitPrice = number_format($unitCentavos / 100, 2, '.', '');
            // An identical selection updates its existing line; a different selection inserts a new line.
            if ($existing !== null) {
                $itemId = (int) $existing['id'];
                $stmt = $this->connection()->prepare(
                    // Refresh the saved display details, price, and quantity only for this customer's existing line.
                    'UPDATE cart_items 
                    SET product_name = ?, variant_label = ?, addons = ?,
                    unit_price = ?, quantity = ? 
                    WHERE id = ? 
                    AND user_id = ?'
                );
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
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
                    // Store the selected product/options with the session owner, trusted price, and combined quantity.
                    'INSERT INTO cart_items
                        (user_id, product_id, product_name, variant_id, variant_label, addons,
                         side_snack, sugar_level, unit_price, quantity, flavour, ice_level)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
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
            // Return the existing line ID after an update or the generated ID after an insert.
            $itemId = $existing !== null ? $itemId : (int) $this->connection()->lastInsertId();
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            // Step: Commit only after validation and the cart write succeed.
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();

            return $itemId;
        } catch (Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Setting quantity to zero removes the line.
    // Flow: Change only the current user's item after checking quantity and stock.
    public function updateQuantity(int $itemId, int $quantity): void
    {
        $this->requireUser();
        // The model supports zero as removal even though the quantity form endpoint accepts only 1 to 99.
        if ($quantity === 0) {
            $this->deleteFromCart($itemId);
            return;
        }
        // Apply the same 1-to-99 rule before saving a new amount or combining identical selections.
        $this->assertQuantity($quantity);
        // Step: Start a transaction so a failed cart change cannot save partial data.
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Lock the customer-owned line before checking its current variant and applying the new quantity.
            $line = $this->fetchLine($itemId, true);
            // Reject an unknown line or one belonging to another customer.
            if ($line === null) {
                throw new InvalidArgumentException('Cart item not found.');
            }
            // Recheck variant stock because inventory may have changed since the item was added.
            if ($line['variant_id'] !== null) {
                $variant = $this->fetchVariant((int) $line['product_id'], (int) $line['variant_id']);
                if ($variant === null || $quantity > (int) $variant['stock_quantity']) {
                    throw new InvalidArgumentException('Not enough stock for the selected variant.');
                }
            }
            // Update the quantity only where both the saved line ID and current customer ID match.
            $stmt = $this->connection()->prepare('UPDATE cart_items SET quantity = ? WHERE user_id = ? AND id = ?');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$quantity, $this->userId, $itemId]);
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            // Step: Commit only after validation and the cart write succeed.
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
        } catch (Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Remove only the item owned by the signed-in customer.
    public function deleteFromCart(int $itemId): void
    {
        $this->requireUser();
        // Restrict deletion to the current owner; an item ID alone is not enough to remove another cart.
        $stmt = $this->connection()->prepare('DELETE FROM cart_items WHERE user_id = ? AND id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$this->userId, $itemId]);
        // Use the affected row count to detect a missing line rather than claiming it was removed.
        $deleted = $stmt->rowCount();
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        if ($deleted === 0) {
            throw new InvalidArgumentException('Cart item not found.');
        }
    }

    // Key items by their database ID for cart forms and JSON responses.
    // Flow: Load the current user's cart, or return an empty cart when signed out.
    public function getItems(bool $forUpdate = false): array
    {
        // Guests may read an empty cart, but attempts to save or change items must be rejected.
        if ($this->userId === null) {
            return [];
        }

        $stmt = $this->connection()->prepare(
            // Join cart_items to products to include the current category.
            // The image subquery chooses the first product image; customer filtering prevents reading another cart.
            'SELECT c.id, c.product_id, c.product_name, c.variant_id, c.variant_label,
                    c.addons, c.flavour, c.side_snack, c.ice_level, c.milk_preference, c.sugar_level, c.unit_price, c.quantity,
                    p.category,
                    (SELECT i.image_path FROM product_images i
                     WHERE i.product_id = c.product_id ORDER BY i.image_order ASC LIMIT 1) AS image_path
             FROM cart_items c JOIN products p ON p.id = c.product_id
             WHERE c.user_id = ? ORDER BY c.created_at, c.id'
                // Checkout adds FOR UPDATE so the cart cannot change while its order is being saved.
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$this->userId]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();

        $items = [];
        // Convert database values to the types expected by PHP/JSON and decode saved add-on snapshots.
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['variant_id'] = $row['variant_id'] === null ? null : (int) $row['variant_id'];
            $row['sugar_level'] = $row['sugar_level'] === null ? null : (int) $row['sugar_level'];
            $row['unit_price'] = (float) $row['unit_price'];
            $row['quantity'] = (int) $row['quantity'];
            $row['addons'] = json_decode($row['addons'], true, 512, JSON_THROW_ON_ERROR);
            // Keep database IDs as keys so cart forms and JSON updates can locate the same saved line.
            $items[$row['id']] = $row;
        }
        return $items;
    }

    // Flow: Count all units in the current cart.
    public function getCount(): int
    {
        // The badge counts purchased units across all lines, including quantities greater than one.
        return array_sum(array_column($this->getItems(), 'quantity'));
    }

    // Flow: Expose the cart owner ID without allowing callers to change it.
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    // Flow: Remove the signed-in customer's cart items after checkout or an explicit clear.
    public function clear(): void
    {
        $this->requireUser();
        // Restrict deletion to the current owner; an item ID alone is not enough to remove another cart.
        $stmt = $this->connection()->prepare('DELETE FROM cart_items WHERE user_id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$this->userId]);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
    }

    // Flow: Reject cart mutations when there is no signed-in customer.
    private function requireUser(): void
    {
        // Guests may read an empty cart, but attempts to save or change items must be rejected.
        if ($this->userId === null) {
            throw new InvalidArgumentException('Please sign in to manage your cart.');
        }
    }

    // Product locking in addToCart serializes duplicate additions without a hash column.
    // Flow: Find an identical selection so quantities combine without a hash key.
    private function findSelection(int $productId, ?int $variantId, array $addonIds, ?string $sideSnack, ?int $sugar, ?string $flavour, ?string $iceLevel): ?array
    {
        $stmt = $this->connection()->prepare(
            // Find lines with matching options for this owner; MySQL <=> compares nullable choices safely.
            // Lock matches and compare decoded add-on IDs below before merging quantities.
            'SELECT id, quantity, addons FROM cart_items
             WHERE user_id = ? AND product_id = ? AND variant_id <=> ?
               AND side_snack <=> ? AND milk_preference IS NULL AND sugar_level <=> ? AND flavour <=> ? AND ice_level <=> ? FOR UPDATE'
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$this->userId, $productId, $variantId, $sideSnack, $sugar, $flavour, $iceLevel]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        // Convert database values to the types expected by PHP/JSON and decode saved add-on snapshots.
        foreach ($rows as $row) {
            // Normalize saved add-on IDs so order differences in the JSON do not create duplicate selections.
            $savedIds = array_map('intval', array_column(json_decode($row['addons'], true, 512, JSON_THROW_ON_ERROR), 'id'));
            sort($savedIds);
            // Matching sorted add-on IDs complete the identical-selection check.
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
        // Restrict the lookup to the session owner and requested line; optionally lock it during quantity changes.
        $sql = 'SELECT product_id, variant_id, quantity FROM cart_items WHERE user_id = ? AND id = ?';
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$this->userId, $itemId]);
        // Fetch one matching row by column name, using null when no record matches.
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Lock the product while checking its current price and availability.
    private function fetchProduct(int $productId): ?array
    {
        $stmt = $this->connection()->prepare(
            // EXISTS reports whether the product has any variants without loading them all.
            // FOR UPDATE locks the product while checking price/availability and combining cart selections.
            'SELECT p.id, p.product_name, p.base_price, p.availability, p.category, p.unavailable_flavours, p.unavailable_side_snacks,
                    EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id = p.id) AS has_variants
             FROM products p WHERE p.id = ? FOR UPDATE'
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$productId]);
        // Fetch one matching row by column name, using null when no record matches.
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Verify that a selected variant belongs to the requested product.
    private function fetchVariant(int $productId, int $variantId): ?array
    {
        $stmt = $this->connection()->prepare(
            // Require both variant ID and product ID to match; return the price and stock used by validation.
            'SELECT id, size_oz, price, stock_quantity FROM product_variants WHERE id = ? AND product_id = ?'
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$variantId, $productId]);
        // Fetch one matching row by column name, using null when no record matches.
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        return $row ?: null;
    }

    // Flow: Retrieve only the requested add-ons belonging to this product.
    private function fetchAddons(int $productId, array $addonIds): array
    {
        // Skip the database lookup when no extras were chosen and avoid an empty IN clause.
        if ($addonIds === []) {
            return [];
        }
        // Build one placeholder per add-on ID, with the parent product ID supplied first at execution.
        $placeholders = implode(',', array_fill(0, count($addonIds), '?'));
        $stmt = $this->connection()->prepare(
            // Return only requested add-ons owned by this product, in a stable ID order.
            "SELECT id, addon_name, addon_price FROM product_addons
             WHERE product_id = ? AND id IN ($placeholders) ORDER BY id"
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$productId, ... $addonIds]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        return $rows;
    }
}
