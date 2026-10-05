<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Running a shop day to day: the orders that came in, what the month sold, and
 * the subscriptions that bill on their own.
 *
 * Written for the site owner's own WhatsApp bot, so the reads carry what an
 * owner needs to act — who ordered and how to reach them — which the
 * diagnostic order tools deliberately leave out. Nothing here is stored by the
 * platform beyond the conversation it is answered in.
 *
 * What is NOT here, on purpose: refunds, payment changes and deleting orders.
 * A refund moves money through the payment gateway, and the only acceptable
 * way to do that is a person in wp-admin looking at the transaction. The bot
 * can tell the owner where an order stands and move it along the ordinary
 * fulfilment path; it cannot give money back or make an order disappear.
 */
class Multioto_Agent_Store_Admin
{
    /** How many orders, subscriptions or leads one read may return. */
    const MAX_LIMIT = 50;

    /** How many recent orders a name/phone search looks through. */
    const SEARCH_WINDOW = 300;

    /** The ceiling on orders one report adds up, so a big shop cannot exhaust memory. */
    const REPORT_CAP = 5000;

    /**
     * The statuses an order may be moved to from the bot.
     *
     * `refunded` is absent because only a refund should produce it — setting
     * the status by hand marks money as returned that never was. `failed` and
     * `checkout-draft` are states the payment flow reaches on its own.
     */
    const ORDER_STATUSES = ['pending', 'processing', 'on-hold', 'completed', 'cancelled'];

    /** The subscription statuses the bot may set. */
    const SUBSCRIPTION_STATUSES = ['active', 'on-hold', 'cancelled', 'pending-cancel'];

    public static function requireWoo(): void
    {
        if (! function_exists('wc_get_orders') || ! class_exists('WooCommerce')) {
            throw new Multioto_Agent_Rpc_Error(-32601, 'WooCommerce אינו מותקן או אינו פעיל באתר זה.');
        }
    }

    public static function subscriptionsActive(): bool
    {
        return function_exists('wcs_get_subscriptions') && function_exists('wcs_get_subscription');
    }

    // --- Orders --------------------------------------------------------------

    /**
     * Recent orders, newest first.
     *
     * A search that is not an order number is matched here, against the name,
     * email and phone on the order, rather than handed to WooCommerce: its own
     * search differs between the legacy posts table and HPOS, and an owner
     * typing "דנה" should get the same answer on either.
     *
     * @param  array<string, mixed>  $args
     * @return array{count: int, orders: list<array<string, mixed>>}
     */
    public static function orders(array $args): array
    {
        self::requireWoo();

        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 10)));
        $search = trim(sanitize_text_field((string) ($args['search'] ?? '')));

        $query = [
            'limit' => $search !== '' ? self::SEARCH_WINDOW : $limit,
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ];

        $status = self::orderStatusFilter((string) ($args['status'] ?? ''));

        if ($status !== null) {
            $query['status'] = [$status];
        }

        $days = (int) ($args['days'] ?? 0);

        if ($days > 0) {
            $query['date_created'] = '>'.(time() - min(366, $days) * DAY_IN_SECONDS);
        }

        $rows = [];

        foreach ((array) wc_get_orders($query) as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            $row = self::orderRow($order);

            if ($search !== '' && ! self::matches($search, [$row['number'], $row['customer'], $row['email'], $row['phone']])) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['count' => count($rows), 'orders' => $rows];
    }

    /**
     * Who to call about an order: the billing name, phone and email.
     *
     * Kept apart from wc_order_get, which the team's diagnostics read without
     * any personal details at all.
     *
     * @return array{name: string, phone: string, email: string}
     */
    public static function contact(WC_Order $order): array
    {
        return [
            'name' => trim($order->get_formatted_billing_full_name()),
            'phone' => (string) $order->get_billing_phone(),
            'email' => (string) $order->get_billing_email(),
        ];
    }

    /**
     * Move an order along — and only if it is still where the owner saw it.
     *
     * `expected_status` is what the owner was shown when they approved. An
     * order that moved since (the warehouse completed it, the customer
     * cancelled) is left alone and reported, rather than dragged back to a
     * status somebody already moved it out of.
     *
     * Completing an order emails the customer, and cancelling one returns its
     * items to stock: both are WooCommerce's own behaviour, and the platform's
     * preview says so before the owner approves.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function setOrderStatus(int $orderId, array $args): array
    {
        self::requireWoo();

        $order = self::order($orderId);
        $status = self::bareStatus((string) ($args['status'] ?? ''));

        if (! in_array($status, self::ORDER_STATUSES, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602,
                'status חייב להיות אחד מ: '.implode(', ', self::ORDER_STATUSES).'. החזר כספי אינו נעשה דרך הבוט.');
        }

        $current = $order->get_status();

        // A refunded order is closed money. Reopening it from a phone would make
        // the shop believe it is owed — or owes — something it is not.
        if ($current === 'refunded') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'ההזמנה הוחזרה כספית (refunded) ולכן אינה משנה סטטוס מכאן.');
        }

        $expected = self::bareStatus((string) ($args['expected_status'] ?? ''));

        if (($expected !== '' && $expected !== $current) || $current === $status) {
            return [
                'changed' => false,
                'order_id' => $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'status' => $current,
                'previous' => $current,
            ];
        }

        $note = sanitize_textarea_field((string) ($args['note'] ?? ''));
        $order->update_status($status, $note !== '' ? $note : 'עודכן דרך בוט ניהול האתר.', true);

        return [
            'changed' => true,
            'order_id' => $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => $order->get_status(),
            'previous' => $current,
        ];
    }

    /**
     * Add a note to an order.
     *
     * A customer note is emailed to the buyer by WooCommerce the moment it is
     * saved; a private note is seen only in wp-admin. The flag is explicit and
     * defaults to private, because a note meant for the warehouse arriving in a
     * customer's inbox is not something an undo can take back.
     *
     * @param  array<string, mixed>  $args
     * @return array{note_id: int, order_id: int, number: string, customer_note: bool}
     */
    public static function addOrderNote(int $orderId, array $args): array
    {
        self::requireWoo();

        $order = self::order($orderId);
        $note = trim(sanitize_textarea_field((string) ($args['note'] ?? '')));

        if ($note === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר תוכן להערה (note).');
        }

        $toCustomer = filter_var($args['customer_note'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $noteId = (int) $order->add_order_note($note, $toCustomer ? 1 : 0, false);

        if ($noteId <= 0) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'ההערה לא נשמרה.');
        }

        return [
            'note_id' => $noteId,
            'order_id' => $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'customer_note' => $toCustomer,
        ];
    }

    // --- Reports -------------------------------------------------------------

    /**
     * What the shop sold over the last N days, against the N days before.
     *
     * Only PAID orders count as sales — WooCommerce's own list of paid
     * statuses, so a gateway plugin that adds one is respected. Refunds are
     * reported beside the gross rather than silently netted out, because "we
     * sold 12,000 and returned 3,000" is a different month from "we sold 9,000".
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function salesReport(array $args): array
    {
        self::requireWoo();

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $range = self::dateRange($args, $timezone);

        if ($range !== null) {
            // An explicit period — "yesterday", "last month" — for a report
            // that has to cover whole days that are already over.
            list($from, $to) = $range;
            $days = (int) $from->diff($to)->days + 1;
            $end = $to->modify('+1 day')->getTimestamp() - 1;
            $today = $to;
        } else {
            $days = max(1, min(366, (int) ($args['days'] ?? 30)));

            // Whole days in the SHOP's calendar: "the last 7 days" ending at
            // midnight UTC would cut an Israeli Saturday night in half.
            $today = new DateTimeImmutable('today', $timezone);
            $from = $today->modify('-'.($days - 1).' days');
            $end = time();
        }

        $previousFrom = $from->modify('-'.$days.' days');

        $current = self::periodTotals($from->getTimestamp(), $end, true);
        $previous = self::periodTotals($previousFrom->getTimestamp(), $from->getTimestamp() - 1, false);

        $waiting = wc_get_orders([
            'limit' => 500,
            'type' => 'shop_order',
            'status' => ['pending', 'on-hold'],
            'date_created' => $from->getTimestamp().'...'.$end,
            'return' => 'ids',
        ]);

        return [
            'days' => $days,
            'from' => $from->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'currency' => get_woocommerce_currency(),
            'paid_orders' => $current['orders'],
            'gross_sales' => $current['gross'],
            'refunded' => $current['refunded'],
            'net_sales' => wc_format_decimal((float) $current['gross'] - (float) $current['refunded'], 2),
            'average_order' => $current['orders'] > 0
                ? wc_format_decimal((float) $current['gross'] / $current['orders'], 2)
                : '0.00',
            'awaiting_payment_or_hold' => count((array) $waiting),
            'top_products' => $current['top'],
            'previous_period' => [
                'from' => $previousFrom->format('Y-m-d'),
                'paid_orders' => $previous['orders'],
                'gross_sales' => $previous['gross'],
            ],
            'truncated' => $current['truncated'],
        ];
    }

    /**
     * Paid orders created between two instants, added up.
     *
     * @return array{orders: int, gross: string, refunded: string, top: list<array<string, mixed>>, truncated: bool}
     */
    private static function periodTotals(int $from, int $to, bool $withProducts): array
    {
        $paid = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : ['processing', 'completed'];

        // A fully refunded order leaves the paid statuses for `refunded`. It was
        // still a sale, and its refund still happened — left out, both would
        // vanish from the report and the month would look smaller and cleaner
        // than it was.
        $orders = (array) wc_get_orders([
            'limit' => self::REPORT_CAP,
            'type' => 'shop_order',
            'status' => array_values(array_unique(array_merge($paid, ['refunded']))),
            'date_created' => $from.'...'.$to,
            'return' => 'objects',
        ]);

        $gross = 0.0;
        $refunded = 0.0;
        $products = [];

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            $gross += (float) $order->get_total();
            $refunded += (float) $order->get_total_refunded();

            if (! $withProducts) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $name = $item->get_name();

                if (! isset($products[$name])) {
                    $products[$name] = ['name' => $name, 'quantity' => 0, 'total' => 0.0];
                }

                $products[$name]['quantity'] += (int) $item->get_quantity();
                $products[$name]['total'] += (float) $order->get_line_total($item, true);
            }
        }

        usort($products, static function (array $a, array $b): int {
            return $b['total'] <=> $a['total'];
        });

        $top = array_map(static function (array $product): array {
            return [
                'name' => $product['name'],
                'quantity' => $product['quantity'],
                'total' => wc_format_decimal($product['total'], 2),
            ];
        }, array_slice($products, 0, 5));

        return [
            'orders' => count($orders),
            'gross' => wc_format_decimal($gross, 2),
            'refunded' => wc_format_decimal($refunded, 2),
            'top' => $top,
            'truncated' => count($orders) >= self::REPORT_CAP,
        ];
    }

    /**
     * `from` and `to` as whole days in the shop's calendar, or null when the
     * caller asked by `days` instead. Strict Y-m-d, at most a year, never
     * backwards — a report is not the place for a lenient date parser.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
     */
    public static function dateRange(array $args, DateTimeZone $timezone): ?array
    {
        $rawFrom = trim((string) ($args['from'] ?? ''));
        $rawTo = trim((string) ($args['to'] ?? ''));

        if ($rawFrom === '' && $rawTo === '') {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $rawFrom, $timezone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $rawTo, $timezone);

        if (! $from || ! $to || $from->format('Y-m-d') !== $rawFrom || $to->format('Y-m-d') !== $rawTo
            || $to < $from || (int) $from->diff($to)->days > 366) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'from ו-to חייבים להיות תאריכים בפורמט YYYY-MM-DD, from לא אחרי to, ועד שנה.');
        }

        return [$from, $to];
    }

    // --- Subscriptions (WooCommerce Subscriptions) ---------------------------

    /**
     * Subscriptions, newest first.
     *
     * @param  array<string, mixed>  $args
     * @return array{count: int, subscriptions: list<array<string, mixed>>}
     */
    public static function subscriptions(array $args): array
    {
        self::requireSubscriptions();

        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 10)));
        $search = trim(sanitize_text_field((string) ($args['search'] ?? '')));
        $status = self::bareStatus((string) ($args['status'] ?? ''));

        $found = wcs_get_subscriptions([
            'subscriptions_per_page' => $search !== '' ? self::SEARCH_WINDOW : $limit,
            'subscription_status' => $status !== '' ? $status : 'any',
            'orderby' => 'start_date',
            'order' => 'DESC',
        ]);

        $rows = [];

        foreach ((array) $found as $subscription) {
            $row = self::subscriptionRow($subscription);

            if ($search !== '' && ! self::matches($search, [(string) $row['id'], $row['customer'], $row['email'], $row['phone'], implode(' ', $row['items'])])) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['count' => count($rows), 'subscriptions' => $rows];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function subscription(array $args): array
    {
        self::requireSubscriptions();

        $subscription = self::subscriptionById((int) ($args['subscription_id'] ?? 0));

        return self::subscriptionRow($subscription) + [
            'start_date' => self::subscriptionDate($subscription, 'start'),
            'end_date' => self::subscriptionDate($subscription, 'end'),
            'last_payment' => self::subscriptionDate($subscription, 'last_order_date_created'),
            'parent_order' => (int) $subscription->get_parent_id(),
            'payment_method' => (string) $subscription->get_payment_method_title(),
        ];
    }

    /**
     * Pause, resume or cancel a subscription — if it is still as the owner saw it.
     *
     * Goes through WooCommerce Subscriptions' own transition rules
     * (`can_be_updated_to`), so a state it would refuse from wp-admin is
     * refused here too. A cancellation cannot be reversed by anyone, so the
     * platform says so in the preview before the owner approves it.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function setSubscriptionStatus(array $args): array
    {
        self::requireSubscriptions();

        $subscription = self::subscriptionById((int) ($args['subscription_id'] ?? 0));
        $status = self::bareStatus((string) ($args['status'] ?? ''));

        if (! in_array($status, self::SUBSCRIPTION_STATUSES, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'status חייב להיות אחד מ: '.implode(', ', self::SUBSCRIPTION_STATUSES).'.');
        }

        $current = $subscription->get_status();
        $expected = self::bareStatus((string) ($args['expected_status'] ?? ''));

        if (($expected !== '' && $expected !== $current) || $current === $status) {
            return ['changed' => false, 'subscription_id' => $subscription->get_id(), 'status' => $current, 'previous' => $current];
        }

        if (! $subscription->can_be_updated_to($status)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "לא ניתן להעביר את המנוי מ-{$current} ל-{$status}.");
        }

        $subscription->update_status($status, 'עודכן דרך בוט ניהול האתר.', true);

        return [
            'changed' => true,
            'subscription_id' => $subscription->get_id(),
            'status' => $subscription->get_status(),
            'previous' => $current,
        ];
    }

    // --- Helpers -------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function orderRow(WC_Order $order): array
    {
        $created = $order->get_date_created();
        $contact = self::contact($order);
        $items = [];

        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name().' ×'.(int) $item->get_quantity();
        }

        return [
            'id' => $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => $order->get_status(),
            'status_label' => wc_get_order_status_name($order->get_status()),
            'date' => $created ? wp_date('Y-m-d H:i', $created->getTimestamp()) : null,
            'total' => (string) $order->get_total(),
            'currency' => $order->get_currency(),
            'customer' => $contact['name'],
            'phone' => $contact['phone'],
            'email' => $contact['email'],
            'items' => array_slice($items, 0, 5),
            'payment_method' => (string) $order->get_payment_method_title(),
        ];
    }

    /** @return array<string, mixed> */
    private static function subscriptionRow($subscription): array
    {
        $items = [];

        foreach ($subscription->get_items() as $item) {
            $items[] = $item->get_name();
        }

        return [
            'id' => $subscription->get_id(),
            'status' => $subscription->get_status(),
            'status_label' => function_exists('wcs_get_subscription_status_name')
                ? wcs_get_subscription_status_name($subscription->get_status())
                : $subscription->get_status(),
            'customer' => trim($subscription->get_formatted_billing_full_name()),
            'phone' => (string) $subscription->get_billing_phone(),
            'email' => (string) $subscription->get_billing_email(),
            'total' => (string) $subscription->get_total(),
            'currency' => $subscription->get_currency(),
            'billing' => 'every '.(int) $subscription->get_billing_interval().' '.$subscription->get_billing_period(),
            'next_payment' => self::subscriptionDate($subscription, 'next_payment'),
            'items' => array_slice($items, 0, 5),
        ];
    }

    /** A subscription date in the site's own calendar, or null when it has none. */
    private static function subscriptionDate($subscription, string $type): ?string
    {
        $timestamp = (int) $subscription->get_time($type);

        return $timestamp > 0 ? wp_date('Y-m-d', $timestamp) : null;
    }

    private static function requireSubscriptions(): void
    {
        self::requireWoo();

        if (! self::subscriptionsActive()) {
            throw new Multioto_Agent_Rpc_Error(-32601, 'WooCommerce Subscriptions אינו מותקן באתר זה, ולכן אין בו מנויים מתחדשים.');
        }
    }

    private static function subscriptionById(int $id)
    {
        $subscription = $id > 0 ? wcs_get_subscription($id) : false;

        if (! $subscription) {
            throw new Multioto_Agent_Rpc_Error(-32602, "מנוי {$id} לא נמצא.");
        }

        return $subscription;
    }

    private static function order(int $orderId): WC_Order
    {
        $order = $orderId > 0 ? wc_get_order($orderId) : false;

        if (! $order instanceof WC_Order) {
            throw new Multioto_Agent_Rpc_Error(-32602, "הזמנה {$orderId} לא נמצאה.");
        }

        return $order;
    }

    /**
     * A status filter WooCommerce knows, or null for "any".
     *
     * An unknown one is refused by name, not dropped: an owner who asks for
     * "shipped" orders on a shop with no such status should hear that, rather
     * than receive every order and think they all shipped.
     */
    private static function orderStatusFilter(string $raw): ?string
    {
        $status = self::bareStatus($raw);

        if ($status === '' || $status === 'any') {
            return null;
        }

        $known = array_map(array(__CLASS__, 'bareStatus'), array_keys(wc_get_order_statuses()));

        if (! in_array($status, $known, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "סטטוס {$status} אינו קיים בחנות. הקיימים: ".implode(', ', $known).'.');
        }

        return $status;
    }

    /** "wc-processing" and "processing" are the same status. */
    public static function bareStatus(string $status): string
    {
        $status = sanitize_key($status);

        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }

    /**
     * Does any of these values contain the search, ignoring case and the
     * dashes and spaces people put into phone numbers?
     *
     * @param  list<string>  $values
     */
    private static function matches(string $search, array $values): bool
    {
        $needle = self::fold($search);

        if ($needle === '') {
            return true;
        }

        foreach ($values as $value) {
            if ($value !== '' && strpos(self::fold($value), $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);

        if (preg_match('/^[\d\s\-+()]+$/', $value) !== 1) {
            return trim($value);
        }

        // A phone typed as 050-123 4567 and stored as +972501234567 is one
        // person: compared on the last nine digits, past any country prefix.
        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }
}
