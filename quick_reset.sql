# The below is used to reset the destination tables of a migration
# so we can re-run the migration after fixing bugs

SET @current_max_order_id = 50380;
SET @current_max_booking_id = 50382;
SET @current_max_voucher_id = 50892;
SET @current_max_user_id = 4005;

# order meta
delete
from wp_postmeta
where post_id IN (
  select ID
  from wp_posts
  where post_type='shop_order'
  and ID > @current_max_order_id
);

# order items meta
delete
from wp_woocommerce_order_itemmeta
where order_item_id IN (
    select order_item_id
    from wp_woocommerce_order_items
    where order_id > @current_max_order_id
);

# order items
delete
from wp_woocommerce_order_items
where order_id > @current_max_order_id;

# order comments
delete
from wp_comments
where comment_post_ID IN (
  select ID
  from wp_posts
  where post_type='shop_order'
  and ID > @current_max_order_id
);

# orders themselves
delete
from wp_posts
where post_type='shop_order'
and ID > @current_max_order_id;

# booking meta
delete
from wp_postmeta
where post_id IN (
  SELECT ID
  from wp_posts
  where post_type='wc_booking'
  and ID > @current_max_booking_id
);

# bookings
delete
from wp_posts
where post_type='wc_booking'
and ID > @current_max_booking_id;

# voucher meta
delete
from wp_postmeta
where post_id IN (
  select ID
  from wp_posts
  where post_type='woovouchercodes'
  and ID > @current_max_voucher_id
);

# vouchers
delete
from wp_posts
where post_type='woovouchercodes'
and ID > @current_max_voucher_id;

# usermeta
delete
from wp_usermeta
where user_id in (
  select ID
  from wp_users
  where id > @current_max_user_id
);

# users
delete
from wp_users
where id > @current_max_user_id;