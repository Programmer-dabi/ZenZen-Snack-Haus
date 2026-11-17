<?php

namespace App\Models;

use InvalidArgumentException;
use PDO;
use Throwable;

class Order extends Model
{
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
        return self::PAYMENT_METHODS[$method] ?? ($method === 'cash_at_counter' ? 'Cash at counter' : $method);
    }

    // Flow: Validate required delivery address fields for delivery payment methods.
    public static function deliveryAddressErrors(array $details): array
    {
        if (!in_array($details['payment_method'] ?? null, ['gcash', 'cash_on_delivery'], true)) {
            return [];
        }
        $errors = [];
        foreach (self::ADDRESS_FIELDS as $field => $settings) {
            $value = is_string($details[$field] ?? null) ? trim($details[$field]) : '';
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
        $normalized = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $status)));
        return in_array($normalized, ['placed order', 'pending', 'confirmed', 'paid', 'payed'], true);
    }

    // Both customer and admin cancellation call this inside a transaction holding the order lock.
    // Flow: Cancel a locked order and restore reserved stock only once.
    public static function cancelLockedOrder(PDO $db, int $id): void
    {
        $stmt = $db->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ? AND status <> 'Cancelled'");
        $stmt->execute([$id]);
        $changed = $stmt->rowCount();
        $stmt->closeCursor();
        if ($changed === 0) {
            return;
        }
        $stmt = $db->prepare('SELECT 
        variant_id, SUM(quantity) 
        AS quantity 
        FROM order_items 
        WHERE order_id = ? 
        AND variant_id 
        IS NOT NULL GROUP BY variant_id 
        ORDER BY variant_id');
        $stmt->execute([$id]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($reservations as $reservation) {
            $variantId = (int) $reservation['variant_id'];
            $quantity = (int) $reservation['quantity'];
            $stmt = $db->prepare('UPDATE product_variants 
            SET stock_quantity = stock_quantity + ? 
            WHERE id = ?');
            $stmt->execute([$quantity, $variantId]);
            $stmt->closeCursor();
        }
    }

    private Cart $cart;

    // Flow: Give the order and its cart the same PDO connection for shared transactions.
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->cart = new Cart($this->connection());
    }

    // Flow: Check the current cart, reserve stock, save order snapshots, and clear the cart.
    public function create(array $details): array
    {
        if ($this->cart->getUserId() === null) {
            throw new InvalidArgumentException('Please sign in to place an order.');
        }

        $paymentMethod = $details['payment_method'] ?? null;
        if (!is_string($paymentMethod) || !array_key_exists($paymentMethod, self::PAYMENT_METHODS)) {
            throw new InvalidArgumentException('Choose a valid mode of payment.');
        }
        $addressErrors = self::deliveryAddressErrors($details);
        if ($addressErrors !== []) {
            throw new InvalidArgumentException(reset($addressErrors));
        }
        $address = ['barangay' => null, 'street' => null, 'house_no' => null, 'city' => null];
        if (in_array($paymentMethod, ['gcash', 'cash_on_delivery'], true)) {
            foreach (self::ADDRESS_FIELDS as $field => $settings) {
                $address[$field] = trim($details[$field]);
            }
            $address['city'] = self::DELIVERY_CITY;
        }
        $pickupAt = $paymentMethod === 'store_pickup' ? ($details['pickup_at'] ?? null) : null;
        if ($paymentMethod === 'store_pickup' && (!is_string($pickupAt) || $pickupAt === '')) {
            throw new InvalidArgumentException('Pickup date and time are required for store pick up.');
        }

        // Step: Group order, inventory, and cart changes in one PDO transaction.
        $this->connection()->beginTransaction();
        try {
            // Step: Lock and read the current customer's cart before creating an order.
            $items = $this->cart->getItems(true);
            $userId = $this->cart->getUserId();
            if ($items === []) {
                throw new InvalidArgumentException('Your cart is empty. Add an item before placing an order.');
            }

            $subtotalCentavos = 0;
            $variantQuantities = [];
            foreach ($items as & $item) {
                $stmt = $this->connection()->prepare('SELECT availability, category, unavailable_flavours, unavailable_side_snacks 
                FROM products 
                WHERE id = ? FOR UPDATE');
                $stmt->execute([$item['product_id']]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $stmt->closeCursor();
                if ($product === null || $product['availability'] !== 'Available') {
                    throw new InvalidArgumentException($item['product_name'] . ' is currently unavailable. Please update your cart.');
                }
                // Legacy cart rows may have no flavour; new Fruit Drinks require it when added.
                Product::validateFlavour($product, $item['flavour'], false);
                Product::validateSideSnack($product, $item['side_snack']);
                Product::validateIceLevel($product, $item['ice_level']);
                if ($item['variant_id'] === null && $item['variant_label'] !== null) {
                    throw new InvalidArgumentException('A selected variant is no longer available. Please update your cart.');
                }
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
            ksort($variantQuantities);
            foreach ($variantQuantities as $variantId => $quantity) {
                $stmt = $this->connection()->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
                $stmt->execute([$quantity, $variantId, $quantity]);
                $updated = $stmt->rowCount();
                $stmt->closeCursor();
                if ($updated !== 1) {
                    throw new InvalidArgumentException('Not enough stock for your selected items. Please update your cart.');
                }
            }

            // Step: Generate the order reference and save server-calculated order totals.
            $orderNumber = 'KP-' . strtoupper(bin2hex(random_bytes(6)));
            $total = number_format($subtotalCentavos / 100, 2, '.', '');
            $initialStatus = self::INITIAL_STATUS;
            $stmt = $this->connection()->prepare(
                'INSERT INTO orders
                    (order_number, user_id, customer_name, phone_number,
                     email, pickup_at, notes, payment_method, status, subtotal, total,
                     barangay, street, house_no, city)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
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
            $orderId = (int) $this->connection()->lastInsertId();
            $stmt->closeCursor();

            // Step: Snapshot item names, options, flavours, and prices for order history.
            foreach ($items as $item) {
                $addons = json_encode($item['addons'], JSON_THROW_ON_ERROR);
                $unitPrice = number_format($item['unit_price'], 2, '.', '');
                $stmt = $this->connection()->prepare(
                    'INSERT INTO order_items
                        (order_id, product_id, variant_id, product_name, variant_label, image_path,
                         addons, milk_preference, sugar_level, unit_price, quantity, line_total, flavour, side_snack, ice_level)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
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
                $stmt->closeCursor();
            }

            // Step: Clear the purchased cart and commit the completed order transaction.
            $this->cart->clear();
            $order = $this->getByNumber($orderNumber);
            $this->connection()->commit();
            return $order;
        } catch (Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Find an order number only within the current customer's account.
    public function getByNumber(string $number): ?array
    {
        $userId = $this->cart->getUserId();
        if ($userId === null) {
            return null;
        }
        $stmt = $this->connection()->prepare('SELECT * FROM orders 
        WHERE order_number = ? AND user_id = ?');
        $stmt->execute([$number, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
        if ($userId === null) {
            return null;
        }
        $stmt = $this->connection()->prepare('SELECT * FROM orders 
        WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();

        return $order === null ? null : $this->withItems($order);
    }

    // Flow: Return tracking data only for an order owned by the current customer.
    public function getTrackingStatus(int $id): ?array
    {
        $userId = $this->cart->getUserId();
        if ($userId === null) {
            return null;
        }
        $stmt = $this->connection()->prepare('SELECT status, 
        payment_method FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();

        return $order;
    }

    // Flow: List the signed-in customer's orders and their cancellation eligibility.
    public function getForCurrentUser(): array
    {
        $userId = $this->cart->getUserId();
        if ($userId === null) {
            return [];
        }
        $stmt = $this->connection()->prepare(
            'SELECT o.id, o.order_number, o.created_at, o.pickup_at, o.status, o.total,
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS item_count
             FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC'
        );
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
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
        if ($userId === null) {
            throw new InvalidArgumentException('Please sign in to cancel your order.');
        }
        // Step: Group order, inventory, and cart changes in one PDO transaction.
        $this->connection()->beginTransaction();
        try {
            $stmt = $this->connection()->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$id, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt->closeCursor();
            if ($order === null) {
                throw new InvalidArgumentException('Order not found.');
            }
            if (strtolower($order['status']) !== 'cancelled') {
                if (!self::canCustomerCancel($order['status'])) {
                    throw new InvalidArgumentException('This order can no longer be cancelled because preparation has started or the order is complete.');
                }
                self::cancelLockedOrder($this->connection(), $id);
            }
            $this->connection()->commit();
        } catch (Throwable $error) {
            $this->connection()->rollBack();
            throw $error;
        }
    }

    // Flow: Attach saved order items and decode their saved add-ons.
    private function withItems(array $order): array
    {
        $order['payment_label'] = self::paymentLabel($order['payment_method']);
        $stmt = $this->connection()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        foreach ($order['items'] as & $item) {
            $item['addons'] = json_decode($item['addons'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($item);
        return $order;
    }
}
