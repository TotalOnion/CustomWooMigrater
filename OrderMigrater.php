<?php

class OrderMigrater {

    private $db;
    private $targetEnvironment;
    private $searchAndReplacePairs;
    private $order;
    private $orderItemIdMap;
    
    public function __construct(
        DB $db,
        int $verbosity
    ) {
        $this->db = $db;
        $this->utilities = new Utilities($db, $verbosity);
        $this->reset();
    }

    private function reset()
    {
        $this->searchAndReplacePairs = [];
        $this->orderItemIdMap = [];
        $this->order = [
            'data' => [],
            'meta' => [],
            'items' => [],
            'comments' => [],
            'bookings' => [],
            'vouchers' => []
        ];
    }

    public function migrate(
        string $targetEnvironment,
        Order $srcOrder
    ) {
        $this->targetEnvironment = $targetEnvironment;
        $this->srcOrder = $srcOrder;
        $this->reset();

        // 1. Add the order, and meta
        $this->order['data'] = $this->migrateOrder();
        $this->order['meta'] = $this->migrateOrderMeta();

        // 2. Add the order items (and meta)
        $this->order['items'] = $this->migrateOrderItems();

        // 3. Add the Bookings (and meta)
        $this->order['bookings'] = $this->migrateBookings();

        // 4. Migrate the Vouchers (and meta, and do search and replace)
        $this->order['vouchers'] = $this->migrateVouchers();

        // 5. Add the order comments (and do search and replace)
        $this->order['comments'] = $this->migrateComments();

        $this->utilities->log(" -- Migration finished", Utilities::VERBOSITY_VERBOSE);
        $this->utilities->log('', Utilities::VERBOSITY_VERBOSE);
    }

    private function addSearchReplacePair(string $search, string $replace)
    {
        $this->searchAndReplacePairs[] = [
            'search' => $search,
            'replace' => $replace
        ];
    }

    private function migrateOrder(): ?array
    {
        $newOrder = $this->utilities->addPostLikeObject(
            $this->targetEnvironment,
            $this->srcOrder->getData()
        );

        $this->addSearchReplacePair($this->srcOrder->get('ID'), $newOrder['ID']);
        $this->utilities->log("Copied Order (old -> new) {$this->srcOrder->get('ID')} -> {$newOrder['ID']}");

        return $newOrder;
    }

    private function migrateOrderMeta(): array
    {
        $this->utilities->log(" -- Migrating order meta", Utilities::VERBOSITY_VERBOSE);
        if ( !is_array( $this->srcOrder->getMeta() ) ) {
            $this->utilities->log('No order_meta, skipping migration.');
            return [];
        }

        $newOrderMetas = [];

        foreach ($this->srcOrder->getMeta() as $orderMetaItem) {
            // check for a _customer_user
            if (
                $orderMetaItem['meta_key'] == '_customer_user'
                && $orderMetaItem['meta_value'] != 0
            ) {
                $user = $this->migrateUserBySrcId($orderMetaItem['meta_value']);
                $orderMetaItem['meta_value'] = $user['ID'];
            }

            if ($orderMetaItem['meta_key'] == '_barcode_image') {
                $this->utilities->log(
                    "Adding order meta key/value: {$orderMetaItem['meta_key']} / [not displayed as it's massive]",
                    Utilities::VERBOSITY_VERY_VERBOSE
                );
            } else {
                $this->utilities->log(
                    "Adding order meta key/value: {$orderMetaItem['meta_key']} / {$orderMetaItem['meta_value']}",
                    Utilities::VERBOSITY_VERY_VERBOSE
                );
            }

            $newOrderMetas[] = $this->utilities->addPostLikeObjectMeta(
                $this->targetEnvironment,
                [
                    'post_id' => $this->order['data']['ID'],
                    'meta_key' => $orderMetaItem['meta_key'],
                    'meta_value' => $orderMetaItem['meta_value'],
                ]
            );
        }

        $this->utilities->log(
            sprintf('Migrated %d order meta values', count($newOrderMetas)),
            Utilities::VERBOSITY_VERBOSE
        );

        return $newOrderMetas;
    }

    private function migrateOrderItems(): array
    {
        $this->utilities->log(" -- Migrating order items", Utilities::VERBOSITY_VERBOSE);

        $newOrderItems = [];

        foreach ($this->srcOrder->getItems() as $srcOrderItem) {
            $this->utilities->log(
                "Adding order item name/type: {$srcOrderItem['order_item_name']} / {$srcOrderItem['order_item_type']}",
                Utilities::VERBOSITY_VERY_VERBOSE
            );

            $newOrderItem = $this->utilities->addOrderItem(
                $this->targetEnvironment,
                [
                    'order_item_name' => $srcOrderItem['order_item_name'],
                    'order_item_type' => $srcOrderItem['order_item_type'],
                    'order_id' => $this->order['data']['ID']
                ]
            );

            $newOrderItem['meta'] = $this->migrateOrderItemMetas(
                $srcOrderItem,
                $newOrderItem
            );

            $newOrderItems[] = $newOrderItem;
        }

        $this->utilities->log(
            sprintf('Migrated %d order items', count($newOrderItems)),
            Utilities::VERBOSITY_VERBOSE
        );

        return $newOrderItems;
    }

    private function migrateOrderItemMetas(
        array $srcOrderItem,
        array $newOrderItem
    ): array
    {
        if (!is_array($srcOrderItem['meta'])) {
            throw new \Exception('Order item has no meta');
        }

        $newOrderItemMetas = [];

        foreach ($srcOrderItem['meta'] as $srcOrderItemMeta) {

            if (
                $srcOrderItemMeta['meta_key'] == '_product_id'
            ) {
                // Check that that the product_id relates to a product we know about
                if (!$this->utilities->postIdIsProduct($this->targetEnvironment, $srcOrderItemMeta['meta_value'])) {
                    throw new \Exception('Order item meta references a non existent product.');
                }

                $productType = $this->utilities->getProductTypeById(
                    $this->targetEnvironment,
                    $srcOrderItemMeta['meta_value']
                );

                // If this product is a booking, make a note of the order item id so it can later be mapped
                if ($productType == 'booking') {

                    $this->utilities->log(
                        " ** Found an order Item that is a Booking.",
                        Utilities::VERBOSITY_VERBOSE
                    );

                    $this->utilities->log(
                        " ** Logging mapping of order_item_id old -> new of {$srcOrderItem['order_item_id']} -> {$newOrderItem['order_item_id']}",
                        Utilities::VERBOSITY_VERBOSE
                    );
                    
                    $this->orderItemIdMap[$srcOrderItem['order_item_id']] = [
                        'old' => $srcOrderItem['order_item_id'],
                        'new' => $newOrderItem['order_item_id']
                    ];

                    $this->addSearchReplacePair($srcOrderItem['order_item_id'], $newOrderItem['order_item_id']);
                }
            }

            $this->utilities->log(
                "Adding order item meta key/value: {$srcOrderItemMeta['meta_key']} / {$srcOrderItemMeta['meta_value']}",
                Utilities::VERBOSITY_VERY_VERBOSE
            );

            $newOrderItemMetas[] = $this->utilities->addOrderItemMeta(
                $this->targetEnvironment,
                [
                    'order_item_id' => $newOrderItem['order_item_id'],
                    'meta_key' => $srcOrderItemMeta['meta_key'],
                    'meta_value' => $srcOrderItemMeta['meta_value'],
                ]
            );
        }

        return $newOrderItemMetas;
    }

    private function migrateBookings(): array
    {
        $this->utilities->log(" -- Migrating Bookings", Utilities::VERBOSITY_VERBOSE);
        $newBookings = [];

        if ($this->srcOrder->hasBookings()) {
            foreach ($this->srcOrder->getBookings() as $srcBooking) {
                $newBookings[] = $this->migrateBooking($srcBooking);
            }
        }

        $this->utilities->log(
            sprintf('Migrated %d Booking(s)', count($newBookings)),
            Utilities::VERBOSITY_VERBOSE
        );

        return $newBookings;
    }

    private function migrateBooking(array $srcBooking): array
    {
        // Change the post_parent to that of the order
        $srcBooking['post_parent'] = $this->order['data']['ID'];

        $newBooking = $this->utilities->addPostLikeObject(
            $this->targetEnvironment,
            $srcBooking
        );

        $this->addSearchReplacePair($srcBooking['ID'], $newBooking['ID']);
        $this->utilities->log(" ** Migrated booking (old -> new), {$srcBooking['ID']} -> {$newBooking['ID']}");

        $newBooking['meta'] = [];

        foreach ($srcBooking['meta'] as $srcBookingMetaItem) {

            switch($srcBookingMetaItem['meta_key']) {

                // Check that the user is not missing
                case '_booking_customer_id':
                    if ($srcBookingMetaItem['meta_value']) {
                        $user = $this->migrateUserBySrcId($srcBookingMetaItem['meta_value']);
                        $srcBookingMetaItem['meta_value'] = $user['ID'];
                    }
                    break;

                // Map the old to new order item
                case '_booking_order_item_id':
                    if (!array_key_exists($srcBookingMetaItem['meta_value'], $this->orderItemIdMap)) {
                        $this->utilities->log(' !!! Booking has _booking_order_item_id that can\'t be mapped. Quitting.');
                        exit();
                    }

                    $srcBookingMetaItem['meta_value'] = $this->orderItemIdMap[$srcBookingMetaItem['meta_value']]['new'];
                    break;

                // Check that _booking_parent_id is 0
                case '_booking_parent_id':
                    if ($srcBookingMetaItem['meta_value']) {
                        $this->utilities->log(' !!! Booking has _booking_parent_id that is non-zero. Quitting.');
                        exit();
                    }
                    break;

                // Check that _booking_product_id is a booking product
                case '_booking_product_id':
                    $productType = $this->utilities->getProductTypeById(
                        $this->targetEnvironment,
                        $srcBookingMetaItem['meta_value']
                    );

                    if ($productType != 'booking') {
                        $this->utilities->log(' !!! Booking has _booking_product_id that is not a bookable product. Quitting.');
                        exit();
                    }
                    break;

                // Check that the _booking_resource_id is a bookable resource that exists
                case '_booking_resource_id':
                    $productType = $this->utilities->getPostTypeById(
                        $this->targetEnvironment,
                        $srcBookingMetaItem['meta_value']
                    );

                    if ($productType != 'bookable_resource') {
                        $this->utilities->log(' !!! Booking has _booking_resource_id that is not a bookable resource. Quitting.');
                        exit();
                    }
                    break;
            }

            $this->utilities->log(
                "Adding booking meta key/value: {$srcBookingMetaItem['meta_key']} / {$srcBookingMetaItem['meta_value']}",
                Utilities::VERBOSITY_VERY_VERBOSE
            );

            $newBooking['meta'][] = $this->utilities->addPostLikeObjectMeta(
                $this->targetEnvironment,
                [
                    'post_id' => $newBooking['ID'],
                    'meta_key' => $srcBookingMetaItem['meta_key'],
                    'meta_value' => $srcBookingMetaItem['meta_value'],
                ]
            );
        }

        return $newBooking;
    }

    private function migrateComments(): array
    {
        $this->utilities->log(" -- Migrating Comments", Utilities::VERBOSITY_VERBOSE);
        $newComments = [];

        if ($this->srcOrder->hasComments()) {
            foreach ($this->srcOrder->getComments() as $srcComment) {
                if ($srcComment['comment_parent']) {
                    $this->log(' !!! Comment has a comment_parent. Missing migration. Quitting.');
                    exit();
                }

                if ($srcComment['user_id']) {
                    $this->log(' !!! Comment has a user_id. Missing migration. Quitting.');
                    exit();
                }

                // Copy the comment data, and update it
                $newComment = $srcComment;
                unset($newComment['comment_ID']);
                $newComment['comment_post_ID'] = $this->order['data']['ID'];
                $newComment['comment_content'] = $this->doSearchAndReplace($newComment['comment_content']);

                $this->utilities->log(
                    "Adding order Comment author/type/content: {$newComment['comment_author']} / {$newComment['comment_type']} / {$newComment['comment_content']}",
                    Utilities::VERBOSITY_VERY_VERBOSE
                );

                $newComments[] = $this->utilities->addPostLikeObjectComment(
                    $this->targetEnvironment,
                    $newComment
                );
            }
        }

        $this->utilities->log(
            sprintf('Migrated %d order Comment(s)', count($newComments)),
            Utilities::VERBOSITY_VERBOSE
        );

        return $newComments;
    }

    private function migrateVouchers(): array
    {
        $this->utilities->log(" -- Migrating Vouchers", Utilities::VERBOSITY_VERBOSE);
        $newVouchers = [];

        if ($this->srcOrder->hasVouchers()) {
            foreach ($this->srcOrder->getVouchers() as $srcVoucher) {
                $newVouchers[] = $this->migrateVoucher($srcVoucher);
            }
        }

        $this->utilities->log(
            sprintf('Migrated %d Voucher(s)', count($newVouchers)),
            Utilities::VERBOSITY_VERBOSE
        );

        return $newVouchers;
    }

    private function migrateVoucher(array $srcVoucher): array
    {
        // Change the title and name to that of the order
        $srcVoucher['post_title'] = $this->order['data']['ID'];
        $srcVoucher['post_name'] = $this->order['data']['ID'];

        // Check the parnet is a product
        $productType = $this->utilities->getProductTypeById(
            $this->targetEnvironment,
            $srcVoucher['post_parent']
        );
        if ($productType != 'simple') {
            $this->utilities->log(' !!! Voucher has a post_parent that is not a simple product. Quitting.');
            exit();
        }

        $newVoucher = $this->utilities->addPostLikeObject(
            $this->targetEnvironment,
            $srcVoucher
        );

        $this->addSearchReplacePair($srcVoucher['ID'], $newVoucher['ID']);
        $this->utilities->log(" ** Migrated Voucher (old -> new), {$srcVoucher['ID']} -> {$newVoucher['ID']}");

        $newVoucher['meta'] = [];

        foreach ($srcVoucher['meta'] as $srcVoucherMetaItem) {

            switch($srcVoucherMetaItem['meta_key']) {

                // Check that the user is not missing
                case '_woo_vou_customer_user':
                    if ($srcVoucherMetaItem['meta_value']) {
                        $this->utilities->log(' !!! Voucher has a non-zero _woo_vou_customer_user number. Missing migration. Quitting.');
                        exit();
                    }
                    break;

                // Map the old to new order item
                case '_woo_vou_order_id':
                    $srcVoucherMetaItem['meta_value'] = $this->order['data']['ID'];
                    break;

                // Check that _woo_vou_sec_vendor_users is empty
                case '_woo_vou_sec_vendor_users':
                    if ($srcVoucherMetaItem['meta_value']) {
                        $this->utilities->log(' !!! Voucher has _woo_vou_sec_vendor_users that is non-zero. Quitting.');
                        exit();
                    }
                    break;
            }

            $this->utilities->log(
                "Adding voucher meta key/value: {$srcVoucherMetaItem['meta_key']} / {$srcVoucherMetaItem['meta_value']}",
                Utilities::VERBOSITY_VERY_VERBOSE
            );

            $newVoucher['meta'][] = $this->utilities->addPostLikeObjectMeta(
                $this->targetEnvironment,
                [
                    'post_id' => $newVoucher['ID'],
                    'meta_key' => $srcVoucherMetaItem['meta_key'],
                    'meta_value' => $srcVoucherMetaItem['meta_value'],
                ]
            );
        }

        return $newVoucher;
    }

    private function doSearchAndReplace(string $string): string
    {
        foreach ($this->searchAndReplacePairs as $searchAndReplacePair) {
            $string = str_replace(
                [
                    '='.$searchAndReplacePair['search'],
                    '#'.$searchAndReplacePair['search'],
                ],
                [
                    '='.$searchAndReplacePair['replace'],
                    '#'.$searchAndReplacePair['replace'],
                ],
                $string
            );
        }

        return $string;
    }

    private function migrateUserBySrcId(int $srcUserId): array
    {
        $this->utilities->log(
            " ** Found an reference to a User (src user.ID = $srcUserId)",
            Utilities::VERBOSITY_VERBOSE
        );

        // Get the user info from the src database
        $srcUser = $this->utilities->getUserById(
            $this->utilities->getOtherEnvironmentThan($this->targetEnvironment),
            $srcUserId
        );

        if (!$srcUser) {
            throw new \Exception("Unable to find src user with ID of $srcUserId");
        }
        
        // Now see if we have that user on the destination database
        $dstUser = $this->utilities->getUserByLogin(
            $this->targetEnvironment,
            $srcUser['user_login']
        );

        // If we have it (it's already been migrated), return it
        if ($dstUser) {
            $this->utilities->log(
                " ** User had already been migrated. Mapping (old -> new) {$srcUser['ID']} -> {$dstUser['ID']}",
                Utilities::VERBOSITY_VERBOSE
            );
            return $dstUser;
        }

        // OK, so we need to add the user
        $newUserData = $srcUser;
        unset($newUserData['ID']);
        $newUser = $this->utilities->addUser(
            $this->targetEnvironment,
            $newUserData
        );

        $this->utilities->log(
            " ** User has now been migrated. Mapping (old -> new) {$srcUser['ID']} -> {$newUser['ID']}",
            Utilities::VERBOSITY_VERBOSE
        );

        return $newUser;
    }
}
