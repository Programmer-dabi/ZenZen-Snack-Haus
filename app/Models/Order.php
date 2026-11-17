<?php

namespace App\Models;

use InvalidArgumentException;
use PDO;
use Throwable;

class Order extends Model
{
    // Shared checkout settings define new-order status, supported address fields, and payment labels.
    public const INITIAL_STATUS = 'Placed order';
    public const DELIVERY_CITY = 'Nueva Ecija';
    public const ADDRESS_FIELDS = [
        'barangay' => ['label' => 'Barangay', 'max' => 100],
        'street' => ['label' => 'Street', 'max' => 150],
        'house_no' => ['label' => 'House No.', 'max' => 30],
    ];

    public const PAYMENT_METHODS = [
        'gcash' => 'Gcash',
        'cash_on_delivery' => 'Cash on Delivery',
        'store_pickup' => 'Store pick up',
    ];

    // Flow: Translate a stored payment method into its display label.
    public static function paymentLabel(string $method): string
    {
        // Keep a readable label for historical cash-at-counter orders and preserve unknown stored values.
        return self::PAYMENT_METHODS[$method] ?? ($method === 'cash_at_counter' ? 'Cash at counter' : $method);
    }

    // Flow: Validate required delivery address fields for delivery payment methods.
    public static function deliveryAddressErrors(array $details): array
    {
        // Only delivery payment methods require address validation; pickup does not.
        if (!in_array($details['payment_method'] ?? null, ['gcash', 'cash_on_delivery'], true)) {
            return [];
        }
        $errors = [];
        // Read each configured address field and apply its length limit and input rules.
        foreach (self::ADDRESS_FIELDS as $field => $settings) {
            $value = is_string($details[$field] ?? null) ? trim($details[$field]) : '';
            // Report missing fields, oversized text, or control characters using field-specific messages.
            if ($value === '') {
                $errors[$field] = $settings['label'] . ' is required for delivery.';
            } elseif (mb_strlen($value) > $settings['max']) {
                $errors[$field] = $settings['label'] . ' must be ' . $settings['max'] . ' characters or fewer.';
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                $errors[$field] = 'Enter a valid ' . $settings['label'];
            }
        }
        return $errors;
    }

    // Flow: Allow customer cancellation only before preparation or delivery.
    public static function canCustomerCancel(string $status): bool
    {
        // Accept historical capitalization and separators when deciding if customer cancellation is still allowed.
        $normalized = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $status)));
        // Customers may cancel before preparation starts, including older pending and paid status spellings.
        return in_array($normalized, ['placed order', 'pending', 'confirmed', 'paid', 'payed'], true);
    }

    // Both customer and admin cancellation call this inside a transaction holding the order lock.
    // Flow: Cancel a locked order and restore reserved stock only once.
    public static function cancelLockedOrder(PDO $db, int $id): void
    {
        // Change the status only if it is not already Cancelled, so inventory is not restored twice.
        $stmt = $db->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND status <> 'Cancelled'");
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$id]);
        // The affected-row count tells whether this call performed a new cancellation.
        $changed = $stmt->rowCount();
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        // A repeated cancellation does nothing and must not add stock again.
        if ($changed === 0) {
            return;
        }
        // Sum reserved units per non-null variant across this order's items.
        // Grouping avoids separate restores for duplicate variants; ordering keeps inventory updates consistent.
        $stmt = $db->prepare('SELECT 
        variant_id, SUM(quantity) 
        AS quantity 
        FROM order_items 
        WHERE order_id = ? 
        AND variant_id 
        IS NOT NULL GROUP BY variant_id 
        ORDER BY variant_id');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$id]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        // Restore the total reserved quantity for each variant, using the transaction already opened by the caller.
        foreach ($reservations as $reservation) {
            $variantId = (int) $reservation['variant_id'];
            $quantity = (int) $reservation['quantity'];
            // Add the cancelled reservation back to the matching variant using quantity and variant-ID placeholders.
            $stmt = $db->prepare('UPDATE product_variants 
            SET stock_quantity = stock_quantity + ? 
            WHERE id = ?');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$quantity, $variantId]);
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
        }
    }

    private Cart $cart;

    // Flow: Give the order and its cart the same PDO connection for shared transactions.
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        // The cart uses this exact PDO connection, so its reads and clearing share the order transaction.
        $this->cart = new Cart($this->connection());
    }

    // Flow: Check the current cart, reserve stock, save order snapshots, and clear the cart.
    public function create(array $details): array
    {
        // Reject order creation without a signed-in cart owner.
        if ($this->cart->getUserId() === null) {
            throw new InvalidArgumentException('Please sign in to place an order.');
        }

        // Recheck payment and address business rules in the model even when the controller already validated the form.
        $paymentMethod = $details['payment_method'] ?? null;
        if (!is_string($paymentMethod) || !array_key_exists($paymentMethod, self::PAYMENT_METHODS)) {
            throw new InvalidArgumentException('Choose a valid mode of payment.');
        }
        $addressErrors = self::deliveryAddressErrors($details);
        // Stop creation if delivery details are incomplete; the first address error becomes the cart-level message.
        if ($addressErrors !== []) {
            throw new InvalidArgumentException(reset($addressErrors));
        }
        // Pickup keeps address columns null; delivery copies validated address fields and the fixed delivery location.
        $address = ['barangay' => null, 'street' => null, 'house_no' => null, 'city' => null];
        if (in_array($paymentMethod, ['gcash', 'cash_on_delivery'], true)) {
            // Copy only configured address fields after the address validation above has succeeded.
            foreach (self::ADDRESS_FIELDS as $field => $settings) {
                $address[$field] = trim($details[$field]);
            }
            $address['city'] = self::DELIVERY_CITY;
        }
        // Store a pickup timestamp only for pickup orders and reject a missing pickup value.
        $pickupAt = $paymentMethod === 'store_pickup' ? ($details['pickup_at'] ?? null) : null;
        if ($paymentMethod === 'store_pickup' && (!is_string($pickupAt) || $pickupAt === '')) {
            throw new InvalidArgumentException('Pickup date and time are required for store pick up.');
        }

        // Step: Group order, inventory, and cart changes in one PDO transaction.
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Step: Lock and read the current customer's cart before creating an order.
            $items = $this->cart->getItems(true);
            $userId = $this->cart->getUserId();
            // Do not create an order header when the locked cart has no items.
            if ($items === []) {
                throw new InvalidArgumentException('Your cart is empty. Add an item before placing an order.');
            }

            $subtotalCentavos = 0;
            $variantQuantities = [];
            // Recheck each saved selection against current product availability and collect totals and reserved quantities.
            foreach ($items as & $item) {
                // Lock this item's product while checking current category and blocked choices.
                $stmt = $this->connection()->prepare('SELECT availability, category, unavailable_flavours, unavailable_side_snacks 
                FROM products 
                WHERE id = ? FOR UPDATE');
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([$item['product_id']]);
                // Fetch one matching row by column name, using null when no record matches.
                $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
                // A removed or unavailable product prevents checkout until the customer updates the cart.
                if ($product === null || $product['availability'] !== 'Available') {
                    throw new InvalidArgumentException($item['product_name'] . ' is currently unavailable. Please update your cart.');
                }
                // Legacy cart rows may have no flavour; new Fruit Drinks require it when added.
                Product::validateFlavour($product, $item['flavour'], false);
                Product::validateSideSnack($product, $item['side_snack']);
                Product::validateIceLevel($product, $item['ice_level']);
                // A saved variant label without a variant ID means the original option was removed.
                if ($item['variant_id'] === null && $item['variant_label'] !== null) {
                    throw new InvalidArgumentException('A selected variant is no longer available. Please update your cart.');
                }
                // Accumulate the quantity across all cart lines using this variant before reserving inventory.
                if ($item['variant_id'] !== null) {
                    $variantQuantities[$item['variant_id']] = ($variantQuantities[$item['variant_id']] ?? 0) + $item['quantity'];
                }
                // Prices are saved by Cart from product data; never accept posted totals.
                $lineCentavos = (int) round($item['unit_price'] * 100) * $item['quantity'];
                $item['line_total'] = number_format($lineCentavos / 100, 2, '.', '');
                $subtotalCentavos += $lineCentavos;
            }
            unset($item);

            // Step: Reserve variants in a consistent order and reject insufficient stock.
            // Reserve variants in ID order to reduce conflicting lock orders between concurrent checkouts.
            ksort($variantQuantities);
            // Subtract all required units for each variant with one guarded database update.
            foreach ($variantQuantities as $variantId => $quantity) {
                // The WHERE stock_quantity >= ? condition prevents negative stock even with simultaneous orders.
                // The placeholders are required units, variant ID, and required units again.
                $stmt = $this->connection()->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([$quantity, $variantId, $quantity]);
                $updated = $stmt->rowCount();
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
                // An unmatched update means the variant is missing or stock is insufficient; roll back the entire order.
                if ($updated !== 1) {
                    throw new InvalidArgumentException('Not enough stock for your selected items. Please update your cart.');
                }
            }

            // Step: Generate the order reference and save server-calculated order totals.
            $orderNumber = 'KP-' . strtoupper(bin2hex(random_bytes(6)));
            $total = number_format($subtotalCentavos / 100, 2, '.', '');
            $initialStatus = self::INITIAL_STATUS;
            $stmt = $this->connection()->prepare(
                // Save the header, session owner, contact details, payment method, status, and trusted totals in column order.
                'INSERT INTO orders
                    (order_number, user_id, customer_name, phone_number,
                     email, pickup_at, notes, payment_method, status, subtotal, total,
                     barangay, street, house_no, city)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([
                $orderNumber,
                $userId,
                $details['name'],
                $details['phone_number'],
                $details['email'],
                $pickupAt,
                $details['notes'],
                $paymentMethod,
                $initialStatus,
                $total,
                $total,
                $address['barangay'],
                $address['street'],
                $address['house_no'],
                $address['city']
            ]);
            // Use the generated order ID as the parent key for every saved item snapshot.
            $orderId = (int) $this->connection()->lastInsertId();
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();

            // Step: Snapshot item names, options, flavours, and prices for order history.
            // Save each cart line as an order-history snapshot, retaining its original names, options, prices, and image.
            foreach ($items as $item) {
                $addons = json_encode($item['addons'], JSON_THROW_ON_ERROR);
                $unitPrice = number_format($item['unit_price'], 2, '.', '');
                $stmt = $this->connection()->prepare(
                    // Insert the item snapshot linked to this order; the placeholders match the listed columns in order.
                    'INSERT INTO order_items
                        (order_id, product_id, variant_id, product_name, variant_label, image_path,
                         addons, milk_preference, sugar_level, unit_price, quantity, line_total, flavour, side_snack, ice_level)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([
                    $orderId,
                    $item['product_id'],
                    $item['variant_id'],
                    $item['product_name'],
                    $item['variant_label'],
                    $item['image_path'],
                    $addons,
                    $item['milk_preference'],
                    $item['sugar_level'],
                    $unitPrice,
                    $item['quantity'],
                    $item['line_total'],
                    $item['flavour'],
                    $item['side_snack'],
                    $item['ice_level']
                ]);
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
            }

            // Step: Clear the purchased cart and commit the completed order transaction.
            $this->cart->clear();
            $order = $this->getByNumber($orderNumber);
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
            return $order;
        } catch (Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Find an order number only within the current customer's account.
    public function getByNumber(string $number): ?array
    {
        $userId = $this->cart->getUserId();
        // Without a customer session, private order lookups return no records and mutations are rejected.
        if ($userId === null) {
            return null;
        }
        // Restrict the lookup by order reference or ID and by the session owner to protect private order details.
        $stmt = $this->connection()->prepare('SELECT * FROM orders 
        WHERE order_number = ? AND user_id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$number, $userId]);
        // Fetch one matching row by column name, using null when no record matches.
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        if ($order === null) {
            return null;
        }
        return $this->withItems($order);
    }

    // Flow: Find an order ID only within the current customer's account.
    public function getById(int $id): ?array
    {
        $userId = $this->cart->getUserId();
        // Without a customer session, private order lookups return no records and mutations are rejected.
        if ($userId === null) {
            return null;
        }
        // Restrict the lookup by order reference or ID and by the session owner to protect private order details.
        $stmt = $this->connection()->prepare('SELECT * FROM orders 
        WHERE id = ? AND user_id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$id, $userId]);
        // Fetch one matching row by column name, using null when no record matches.
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();

        return $order === null ? null : $this->withItems($order);
    }

    // Flow: Return tracking data only for an order owned by the current customer.
    public function getTrackingStatus(int $id): ?array
    {
        $userId = $this->cart->getUserId();
        // Without a customer session, private order lookups return no records and mutations are rejected.
        if ($userId === null) {
            return null;
        }
        // Polling needs only status and payment method, filtered by both order ID and owner.
        $stmt = $this->connection()->prepare('SELECT status, 
        payment_method FROM orders WHERE id = ? AND user_id = ?');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$id, $userId]);
        // Fetch one matching row by column name, using null when no record matches.
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();

        return $order;
    }

    // Flow: List the signed-in customer's orders and their cancellation eligibility.
    public function getForCurrentUser(): array
    {
        $userId = $this->cart->getUserId();
        // Without a customer session, private order lookups return no records and mutations are rejected.
        if ($userId === null) {
            return [];
        }
        $stmt = $this->connection()->prepare(
            // The item subquery sums quantities per order, using zero for no items.
            // Filter orders by the session owner and list newest first, breaking timestamp ties by ID.
            'SELECT o.id, o.order_number, o.created_at, o.pickup_at, o.status, o.total,
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS item_count
             FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC'
        );
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$userId]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        // Attach cancellation eligibility to each order so Twig shows the action only at supported stages.
        foreach ($orders as & $order) {
            $order['can_cancel'] = self::canCustomerCancel($order['status']);
        }
        unset($order);

        return $orders;
    }

    // Flow: Verify ownership and current status before cancelling the customer's order.
    public function cancelForCurrentUser(int $id): void
    {
        $userId = $this->cart->getUserId();
        // Without a customer session, private order lookups return no records and mutations are rejected.
        if ($userId === null) {
            throw new InvalidArgumentException('Please sign in to cancel your order.');
        }
        // Step: Group order, inventory, and cart changes in one PDO transaction.
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Lock the order owned by this customer before checking its latest cancellation eligibility.
            $stmt = $this->connection()->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
            // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
            $stmt->execute([$id, $userId]);
            // Fetch one matching row by column name, using null when no record matches.
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            // Release this result before reusing the statement or connection.
            $stmt->closeCursor();
            if ($order === null) {
                throw new InvalidArgumentException('Order not found.');
            }
            // Already-cancelled orders are left alone; other orders must still be in a cancellable stage.
            if (strtolower($order['status']) !== 'cancelled') {
                // Reject cancellation once preparation, delivery, or completion has started.
                if (!self::canCustomerCancel($order['status'])) {
                    throw new InvalidArgumentException('This order can no longer be cancelled because preparation has started or the order is complete.');
                }
                self::cancelLockedOrder($this->connection(), $id);
            }
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
        } catch (Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Attach saved order items and decode their saved add-ons.
    private function withItems(array $order): array
    {
        $order['payment_label'] = self::paymentLabel($order['payment_method']);
        // Retrieve the saved item snapshots for this order in insertion order.
        $stmt = $this->connection()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute([$order['id']]);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        // Decode each saved add-on JSON list so Twig can display its names and prices.
        foreach ($order['items'] as & $item) {
            $item['addons'] = json_decode($item['addons'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($item);
        return $order;
    }
}
