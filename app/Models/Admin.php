<?php

namespace App\Models;

use App\Orders\OrderWorkflowFactory;
use App\Orders\StorePickupWorkflow;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

class Admin extends Model
{
    public const CATEGORIES = Product::CATEGORIES;
    public const STATUSES = StorePickupWorkflow::STATUSES;

    // Flow: Ask the selected payment workflow for its allowed statuses.
    public static function statusesForPayment(string $method): array
    {
        return OrderWorkflowFactory::forPayment($method)->statuses();
    }

    // Flow: Ask the workflow which status changes are still permitted.
    public static function availableOrderStatuses(string $method, string $currentStatus): array
    {
        return OrderWorkflowFactory::forPayment($method)->availableStatuses($currentStatus);
    }

    // Flow: Share the PDO connection and use the store's database timezone.
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        // Use the store's UTC+8 offset for database timestamp operations on this connection.
        $this->connection()->query("SET time_zone = '+08:00'");
    }

    // Flow: Execute a prepared PDO query with its separate parameter values.
    private function rows(string $sql, array $values = []): array
    {
        // Prepare the SQL structure; values will be supplied separately to its placeholders.
        $stmt = $this->connection()->prepare($sql);
        // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
        $stmt->execute($values);
        // Fetch the result rows as arrays keyed by column name for the following PHP processing.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Release this result before reusing the statement or connection.
        $stmt->closeCursor();
        return $rows;
    }

    // Flow: Load products and attach their variants and add-ons for administration.
    public function products(): array
    {
        // Reuse the connection to load the product listing and its images before attaching management options.
        $products = (new Product($this->connection()))->getProductAll();
        // Initialize empty option lists before assigning variants and add-ons by parent product ID.
        foreach ($products as & $product) {
            $product['variants'] = [];
            $product['addons'] = [];
        }
        unset($product);
        // Load variants in ID order and group each row under its parent product.
        foreach ($this->rows('SELECT * FROM product_variants ORDER BY id') as $variant) {
            $products[$variant['product_id']]['variants'][] = $variant;
        }
        // Load add-ons in ID order and group each row under its parent product.
        foreach ($this->rows('SELECT * FROM product_addons ORDER BY id') as $addon) {
            $products[$addon['product_id']]['addons'][] = $addon;
        }
        return $products;
    }

    // Flow: Assemble inventory, sales reports, recent orders, and customer summaries.
    public function dashboard(): array
    {
        // Build store-local day/month boundaries; the exclusive end is midnight tomorrow.
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day')->format('Y-m-d H:i:s');
        $month = $today->modify('first day of this month')->format('Y-m-d H:i:s');
        $products = $this->products();
        $inventory = [];
        // Flatten each product's variants into inventory rows that also carry the product name and image.
        foreach ($products as $product) {
            foreach ($product['variants'] as $variant) {
                $inventory[] = array_merge($variant, [
                    'product_name' => $product['product_name'],
                    'image_path' => $product['images'][0]['image_path'] ?? null,
                ]);
            }
        }
        // Highlight variants with at most ten units and sort the lowest stock first.
        $lowStock = array_values(array_filter($inventory, static fn ($variant) => (int) $variant['stock_quantity'] <= 10));
        usort($lowStock, static fn ($a, $b) => (int) $a['stock_quantity'] <=> (int) $b['stock_quantity']);
        $reports = [];
        // Calculate completed sales for daily, rolling seven-day, and current-month date ranges.
        foreach ([
            'daily' => $today->format('Y-m-d H:i:s'),
            'weekly' => $today->modify('-6 days')->format('Y-m-d H:i:s'),
            'monthly' => $month
        ] as $period => $start) {
            // SUM adds order totals within the date range; COALESCE returns zero when there are no completed orders.
            // The placeholders are the inclusive start and exclusive end timestamps.
            $reports[$period] = $this->rows("SELECT COALESCE(SUM(total), 0) 
            AS sales FROM orders WHERE status = 'Completed' 
            AND created_at >= ? AND created_at < ?", [$start, $tomorrow])[0]['sales'];
        }
        
        //sales
        // Group completed orders by calendar date and sum their totals for the seven-day chart.
        $sales = $this->rows("SELECT DATE(created_at) 
        AS day, SUM(total) 
        AS sales 
        FROM orders 
        WHERE status = 'Completed' 
        AND created_at >= ? 
        AND created_at < ? 
        GROUP BY DATE(created_at)", [$today->modify('-6 days')->format('Y-m-d H:i:s'), $tomorrow]);
        $byDay = array_column($sales, 'sales', 'day');
        $chart = [];
        // Create all seven chart days, using zero sales when the grouped query had no row for that date.
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->modify("-$i days");
            $chart[] = [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'sales' => (float)($byDay[$date->format('Y-m-d')] ?? 0)
            ];
        }
        // Scale bar heights against the largest day; a minimum divisor of one avoids division by zero.
        $maximum = max(1, ... array_column($chart, 'sales'));
        // Store each bar height as a percentage for the Twig chart style.
        foreach ($chart as & $bar) {
            $bar['height'] = round($bar['sales'] / $maximum * 100);
        }
        unset($bar);
        //sales end

        // COUNT groups products by category; merge stored names with the configured categories below.
        $categoryCounts = array_column($this->rows('SELECT category, COUNT(*) 
        AS product_count 
        FROM products 
        GROUP BY category'), 'product_count', 'category');
        $categories = [];
        // Include configured empty categories and any historical categories still present in the database.
        foreach (array_unique(array_merge(self::CATEGORIES, array_keys($categoryCounts))) as $category) {
            $categories[] = [
                'name' => $category,
                'product_count' => (int)($categoryCounts[$category] ?? 0)
            ];
        }
        return [
            'products' => $products,
            'inventory' => $inventory,
            'low_stock' => $lowStock,
            'reports' => $reports,
            'sales_chart' => $chart,
            'categories' => $categories,
            'stats' => [
                // Count orders in today's half-open date range, regardless of completion status.
                'orders_today' => $this->rows('SELECT COUNT(*) 
                AS count 
                FROM orders 
                WHERE created_at >= ? 
                AND created_at < ?', [$today->format('Y-m-d H:i:s'), $tomorrow])[0]['count'],
                // Count orders from the start of this month through today.
                'orders_month' => $this->rows('SELECT COUNT(*) 

                AS count 
                FROM orders 
                WHERE created_at >= ? 
                AND created_at < ?', [$month, $tomorrow])[0]['count'],
                // Count all user records for the dashboard statistic.
                'customers' => $this->rows('SELECT COUNT(*) 

                AS count 
                FROM users')[0]['count'],
                'products' => count($products),
            ],
            // LEFT JOIN keeps orders even without items; SUM counts their units and GROUP BY returns one row per order.
            // List the twenty newest orders, using ID to break equal creation times.
            'recent_orders' => $this->rows(' SELECT o.*,
                    COALESCE(
                        SUM(i.quantity), 0)
                    AS item_count
                FROM orders o
                LEFT JOIN order_items i
                    ON o.id = i.order_id
                GROUP BY o.id
                ORDER BY
                    o.created_at DESC,
                    o.id DESC
                LIMIT 20'),

            // Count today's orders separately for each stored status, then sort the status labels.
            'status_counts' => $this->rows('SELECT status, 
            COUNT(*) 
            AS count 
            FROM orders 
            WHERE created_at >= ? 
            AND created_at < ? 
            GROUP BY status 
            ORDER BY status', [$today->format('Y-m-d H:i:s'), $tomorrow]),

            // Join item snapshots to completed orders this month; group by saved product ID/name and sum units/revenue.
            // Choose a representative saved image and return the five highest-selling groups.
            'best_sellers' => $this->rows("SELECT i.product_name, 
            MAX(i.image_path) 
            AS image_path, SUM(i.quantity) 
            AS sold, SUM(i.line_total) 
            AS sales 
            FROM order_items i 
            JOIN orders o 
            ON o.id = i.order_id 
            WHERE o.status = 'Completed' 
            AND o.created_at >= ? 
            AND o.created_at < ? 
            GROUP BY i.product_id, i.product_name 
            ORDER BY sold DESC, sales DESC LIMIT 5", [$month, $tomorrow]),

            // The correlated subquery counts each user's orders without duplicating profile rows.
            // List the fifty most recently created users.
            'customers' => $this->rows('SELECT u.id, u.name, u.email, u.phone_number, u.created_at, 
            (SELECT COUNT(*) 
            FROM orders 
            WHERE user_id = u.id) 
            AS order_count 
            FROM users u 
            ORDER BY u.created_at DESC, 
            u.id DESC LIMIT 50'),
            'today' => $now,
        ];
    }

    // Flow: Load the selected order and its saved item details for admin review.
    public function order(int $id): ?array
    {
        // Find the requested order for an administrator; this management lookup is not restricted to a customer owner.
        $order = $this->rows('SELECT * 
        FROM orders 
        WHERE id = ?', [$id])[0] ?? null;
        // Stop this lookup or status change when the requested order no longer exists.
        if ($order === null) {
            return null;
        }
        // Prepare a store-local date object and readable payment label for the administrator dialog.
        $order['pickup_time'] = $order['pickup_at'] !== null ? new DateTimeImmutable($order['pickup_at'], new DateTimeZone('Asia/Manila')) : null;
        $order['payment_label'] = Order::paymentLabel($order['payment_method']);

        // Retrieve this order's saved item snapshots in ID order.
        $order['items'] = $this->rows('SELECT * 
        FROM order_items 
        WHERE order_id = ? 
        ORDER BY id', [$id]);
        // Decode saved extras so the reusable order-items template can display them.
        foreach ($order['items'] as & $item) {
            $item['addons'] = json_decode($item['addons'], true, 512, JSON_THROW_ON_ERROR);
        }

        unset($item);
        return $order;
    }

    // Flow: Load a customer summary and recent orders for admin review.
    public function customer(int $id): ?array
    {
        // Find one user and use correlated subqueries for their order count and completed spending.
        // COALESCE makes spending zero when no completed orders exist.
        $customer = $this->rows("SELECT u.id, u.name, u.email, u.phone_number, u.created_at, 
        (SELECT COUNT(*) 
        FROM orders 
        WHERE user_id = u.id) 
        AS order_count, (SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE user_id = u.id 
        AND status = 'Completed') 
        AS amount_spent FROM users u 
        WHERE u.id = ?", [$id])[0] ?? null;
        // Return no result instead of querying recent orders for a missing customer.
        if ($customer === null) {
            return null;
        }
        
        // Retrieve this customer's ten newest orders for the management dialog.
        $customer['orders'] = $this->rows('SELECT id, order_number, total, status, created_at 
        FROM orders 
        WHERE user_id = ? 
        ORDER BY created_at 
        DESC, id 
        DESC LIMIT 10', [$id]);
        return $customer;
    }

    // Flow: Lock the order and enforce the payment workflow before changing status.
    public function updateOrderStatus(int $id, string $status): void
    {
        // Start one transaction so all related changes succeed together or can be undone together.
        $this->connection()->beginTransaction();
        try {
            // Lock the latest status so simultaneous or stale forms cannot move it backward.
            $order = $this->rows('SELECT status, payment_method 
            FROM orders 
            WHERE id = ? 
            FOR UPDATE', [$id])[0] ?? null;
            // Stop this lookup or status change when the requested order no longer exists.
            if ($order === null) {
                throw new InvalidArgumentException('Order not found.');
            }
            // Reject statuses that do not belong to this order's payment method.
            if (!in_array($status, self::statusesForPayment($order['payment_method']), true)) {
                throw new InvalidArgumentException('Choose a valid status for this order\'s mode of payment.');
            }
            // Reject backward changes and changes out of terminal states, using the locked current status.
            if (!in_array($status, self::availableOrderStatuses($order['payment_method'], $order['status']), true)) {
                throw new InvalidArgumentException('Order status cannot move backward. Completed or cancelled orders cannot be changed. Reload the order to see its current status.');
            }
            // Cancellation also restores reserved stock; other status changes update only the order status.
            if ($status === 'Cancelled') {
                Order::cancelLockedOrder($this->connection(), $id);
            } else {
                // Bind the chosen status and order ID separately while updating the already-locked order.
                $stmt = $this->connection()->prepare('UPDATE orders 
                SET status = ? 
                WHERE id = ?');
                // Execute with values in placeholder order; PDO keeps these values separate from SQL syntax.
                $stmt->execute([$status, $id]);
                // Release this result before reusing the statement or connection.
                $stmt->closeCursor();
            }    
            // Make all successful changes in this transaction permanent.
            $this->connection()->commit();
        } catch (Throwable $error) {
            // Undo every database change made by this transaction before passing the error to the caller.
            $this->connection()->rollBack();
            throw $error;
        }
    }
}
