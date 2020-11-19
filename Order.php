<?php

class Order {
    
    private $db;
    private $environment;

    private $id;
    private $order = [];

    public function __construct(
        DB $db,
        string $environment,
        int $id = null
    ) {
        $this->db = $db;
        $this->environment = $environment;

        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    public function load()
    {
        $this->order['data']     = $this->loadData();
        $this->order['meta']     = $this->loadMeta($this->id);
        $this->order['items']    = $this->loadOrderItems();
        $this->order['comments'] = $this->loadComments();
        $this->order['bookings'] = $this->loadBookings();
        $this->order['vouchers'] = $this->loadVouchers();
    }

    private function loadData()
    {
        return $this->db->getRow(
            $this->environment,
            'SELECT *
            FROM wp_posts
            WHERE ID=?
            AND post_type="shop_order"
            LIMIT 1;',
            [$this->id]
        );
    }

    private function loadMeta($parentId)
    {
        return $this->db->getRows(
            $this->environment,
            'SELECT *
            FROM wp_postmeta
            WHERE post_id=?',
            [$parentId]
        );
    }

    private function loadComments()
    {
        return $this->db->getRows(
            $this->environment,
            'SELECT *
            FROM wp_comments
            WHERE comment_post_ID=?',
            [$this->id]
        );
    }

    private function loadBookings()
    {
        $bookings = $this->db->getRows(
            $this->environment,
            'SELECT *
            FROM wp_posts
            WHERE post_parent=?
            AND post_type="wc_booking"',
            [$this->id]
        );

        if ($bookings) {
            foreach ($bookings as &$booking) {
                $booking['meta'] = $this->loadMeta($booking['ID']);
            }
        }

        return $bookings;
    }

    private function loadOrderItems()
    {
        $orderItems = $this->db->getRows(
            $this->environment,
            'SELECT *
            FROM wp_woocommerce_order_items
            WHERE order_id=?',
            [$this->id]
        );

        foreach ($orderItems as &$orderItem) {
            $orderItem['meta'] = $this->db->getRows(
                $this->environment,
                'SELECT *
                FROM wp_woocommerce_order_itemmeta
                WHERE order_item_id=?',
                [$orderItem['order_item_id']]
            );
        }

        return $orderItems;
    }

    private function loadVouchers(): array
    {
        $vouchers = [];

        // look through all order item meta fields for any voucher codes
        foreach ($this->order['items'] as $item) {
            foreach ($item['meta'] as $meta) {
                if ($meta['meta_key'] == '_woo_vou_codes') {
                    $voucherCodes = explode(', ', $meta['meta_value']);
                    foreach ($voucherCodes as $voucherCode) {
                        $vouchers[] = $this->loadVoucher(trim($voucherCode));
                    }
                }
            }
        }

        return $vouchers;
    }

    private function loadVoucher(string $voucherCode): array
    {
        $voucher = $this->db->getRow(
            $this->environment,
            "SELECT *
            FROM wp_posts
            WHERE ID = (
              SELECT post_id
              FROM wp_postmeta
              WHERE meta_key = '_woo_vou_purchased_codes'
              AND meta_value = :voucher_code
            );",
            [ 'voucher_code' => $voucherCode ]
        );

        $voucher['meta'] = $this->db->getRows(
            $this->environment,
            "SELECT *
            FROM wp_postmeta
            WHERE post_id = :post_id;",
            [ 'post_id' => $voucher['ID'] ]
        );

        return $voucher;
    }

    public function get($dataKey)
    {
        return $this->order['data'][$dataKey];
    }

    public function getData(): array
    {
        return $this->order['data'];
    }

    public function getMeta(): array
    {
        return $this->order['meta'];
    }

    public function getItems(): array
    {
        return $this->order['items'];
    }

    public function hasComments(): bool
    {
        return (is_array($this->order['comments']) && count($this->order['comments']));
    }

    public function getComments(): array
    {
        return $this->order['comments'];
    }

    public function hasBookings(): bool
    {
        return (is_array($this->order['bookings']) && count($this->order['bookings']));
    }

    public function getBookings(): array
    {
        return $this->order['bookings'];
    }

    public function hasVouchers(): bool
    {
        return (is_array($this->order['vouchers']) && count($this->order['vouchers']));
    }

    public function getVouchers(): array
    {
        return $this->order['vouchers'];
    }
}
