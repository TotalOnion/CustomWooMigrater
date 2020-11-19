<?php

class Utilities {

    const VERBOSITY_QUIET = 0;
    const VERBOSITY_VERBOSE = 1;
    const VERBOSITY_VERY_VERBOSE = 2;
    const VERBOSITY_VERY_VERY_VERBOSE = 3;

    private $db;
    private $currentVerbosity;
    
    public function __construct(
        DB $db,
        int $verbosity = Utilities::VERBOSITY_QUIET
    ) {
        $this->db = $db;
        $this->currentVerbosity = $verbosity;
    }

    public function getOtherEnvironmentThan(string $otherEnvironment): string
    {
        switch ($otherEnvironment) {
            case DB::ENVIRONMENT_STAGE:
                return DB::ENVIRONMENT_LIVE;

            case DB::ENVIRONMENT_LIVE:
                return DB::ENVIRONMENT_STAGE;
        }

        throw new \Exception('Unknown environment');
    }

    public function findMaxOrderId(string $environment)
    {
        return $this->db->getValue(
            $environment,
            'SELECT MAX(ID)
            FROM wp_posts
            WHERE post_type="shop_order";'
        );
    }

    public function findOrderIDsAfter(
        string $environment,
        int $afterOrderId
    ) {
        return $this->db->getColumn(
            $environment,
            'SELECT ID
            FROM wp_posts
            WHERE post_type="shop_order"
            AND ID > ?;',
            [$afterOrderId]
        );
    }

    public function addPostLikeObject(
        string $targetEnvironment,
        array $data
    ): ?array
    {
        $postLikeObject = $this->db->insert(
            $targetEnvironment,
            'wp_posts',
            'ID',
            [
                'post_author' => $data['post_author'],
                'post_date' => $data['post_date'],
                'post_date_gmt' => $data['post_date_gmt'],
                'post_content' => $data['post_content'],
                'post_title' => $data['post_title'],
                'post_excerpt' => $data['post_excerpt'],
                'post_status' => $data['post_status'],
                'comment_status' => $data['comment_status'],
                'ping_status' => $data['ping_status'],
                'post_password' => $data['post_password'],
                'post_name' => $data['post_name'],
                'to_ping' => $data['to_ping'],
                'pinged' => $data['pinged'],
                'post_modified' => $data['post_modified'],
                'post_modified_gmt' => $data['post_modified_gmt'],
                'post_content_filtered' => $data['post_content_filtered'],
                'post_parent' => $data['post_parent'],
                'guid' => $data['guid'],
                'menu_order' => $data['menu_order'],
                'post_type' => $data['post_type'],
                'post_mime_type' => $data['post_mime_type'],
                'comment_count' => $data['comment_count'],
            ]
        );

        if (empty($postLikeObject)) {
            throw new \Exception('Failed to add postLikeObject');
        }

        $postLikeObject = $this->db->updateOne(
            $targetEnvironment,
            'wp_posts',
            'ID',
            $postLikeObject['ID'],
            [
                'guid' => preg_replace('/p=[0-9]+$/', 'p='.$postLikeObject['ID'], $postLikeObject['guid'])
            ]
        );

        if (empty($postLikeObject)) {
            throw new \Exception('Failed to update postLikeObject');
        }

        return $postLikeObject;
    }

    public function addPostLikeObjectMeta(
        string $targetEnvironment,
        array $data
    ): array {
        return $this->db->insert(
            $targetEnvironment,
            'wp_postmeta',
            'meta_id',
            $data
        );
    }

    public function addPostLikeObjectComment(
        string $targetEnvironment,
        array $data
    ): array {
        return $this->db->insert(
            $targetEnvironment,
            'wp_comments',
            'comment_ID',
            $data
        );
    }

    public function addOrderItem(
        string $targetEnvironment,
        array $data
    ): array {
        return $this->db->insert(
            $targetEnvironment,
            'wp_woocommerce_order_items',
            'order_item_id',
            $data
        );
    }

    public function addOrderItemMeta(
        string $targetEnvironment,
        array $data
    ): array {
        return $this->db->insert(
            $targetEnvironment,
            'wp_woocommerce_order_itemmeta',
            'meta_id',
            $data
        );
    }

    public function postIdIsProduct(
        string $targetEnvironment,
        int $postID
    ): bool {
        return $this->db->getValue(
            $targetEnvironment,
            "SELECT post_type FROM wp_posts WHERE ID = :post_id LIMIT 1;",
            [ 'post_id' => $postID ]
        ) == 'product';
    }

    public function getPostTypeById(
        string $targetEnvironment,
        int $postId
    ): string {
        $postType = $this->db->getValue(
            $targetEnvironment,
            "SELECT post_type
            FROM wp_posts
            WHERE ID = :post_id
            LIMIT 1;",
            [ 'post_id' => $postId ]
        );
        
        return $postType ?? '';
    }

    public function getProductTypeById(
        string $targetEnvironment,
        int $productId
    ): string {
        $productType = $this->db->getValue(
            $targetEnvironment,
            "SELECT wp_terms.slug
            FROM wp_term_taxonomy
            JOIN wp_term_relationships ON
              wp_term_relationships.term_taxonomy_id = wp_term_taxonomy.term_id
            JOIN wp_terms ON
              wp_terms.term_id = wp_term_taxonomy.term_id
            WHERE wp_term_relationships.object_id = :product_id
            AND wp_term_taxonomy.taxonomy = 'product_type'
            LIMIT 1;",
            [ 'product_id' => $productId ]
        );
        
        return $productType ?? '';
    }

    public function getUserById(
        string $targetEnvironment,
        int $userId
    ): ?array {
        $user = $this->db->getRow(
            $targetEnvironment,
            "SELECT *
            FROM wp_users
            WHERE ID = :user_id
            LIMIT 1;",
            [ 'user_id' => $userId ]
        );

        if (!$user) {
            return null;
        }

        $user['meta'] = $this->db->getRows(
            $targetEnvironment,
            "SELECT *
            FROM wp_usermeta
            WHERE user_id = :user_id;",
            [ 'user_id' => $userId ]
        );

        return $user;
    }

    public function getUserByLogin(
        string $targetEnvironment,
        string $userLogin
    ): ?array {
        $user = $this->db->getRow(
            $targetEnvironment,
            "SELECT *
            FROM wp_users
            WHERE user_login = :user_login
            LIMIT 1;",
            [ 'user_login' => $userLogin ]
        );

        if (!$user) {
            return null;
        }

        $user['meta'] = $this->db->getRows(
            $targetEnvironment,
            "SELECT *
            FROM wp_usermeta
            WHERE user_id = :user_id;",
            [ 'user_id' => $user['ID'] ]
        );

        return $user;
    }

    public function addUser(
        string $targetEnvironment,
        array $data
    ): array {
        
        $srcData = $data;
        $srcMeta = $data['meta'];
        unset($srcData['meta']);

        $newUser = $this->db->insert(
            $targetEnvironment,
            'wp_users',
            'ID',
            $srcData
        );

        $newUser['meta'] = [];

        foreach ($srcMeta as $srcUserMeta) {
            $newUserMeta = $srcUserMeta;
            unset($newUserMeta['umeta_id']);

            $newUserMeta['user_id'] = $newUser['ID'];

            $newUser['meta'][] = $this->db->insert(
                $targetEnvironment,
                'wp_usermeta',
                'umeta_id',
                $newUserMeta
            );
        }

        return $newUser;
    }

    public function log(
        string $message,
        int $verbosity = Utilities::VERBOSITY_QUIET
    )
    {
        if ($verbosity <= $this->currentVerbosity) {
            echo $message.PHP_EOL;
        }
    }
}
