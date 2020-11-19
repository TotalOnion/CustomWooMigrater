# CustomWooMigrater

## What is it 

This was born of necessity. We had a client with a website running WooCommerce, WooCommerce Bookings, PDF Invoices, Coupons and othe plugins. A copy of the live site was created, and work done on that. This meant that at the end of the work the stage and live site were out of sync; stage had more content, live had more orders.

No off the shelf plugins, or combination of plugins, worked without data corruption. This repo is our workaround.

## How do I use it?

First off, this is super custom, so you will not be able to just run this, you will have to tailor it for your needs, but it may give you a starting point.

1. This script was put into a /migration/ folder on the `stage` environment.
2. The database from `live` was made accessible to stage.
3. Settings were added to the DB.php class
4. We looked at the stage DB to get the most recent order, booking, voucher, and user IDs
5. We added those to the quick_reset.sql so that we could wipe what had been added to stage when things failed
6. We opened index.php in a web browser (for long boring reasons we did not have CLI access)

## Notes

- The verbosity of the outpuit can be changed in the index.php
- The database username and password shouldn't be class constants in the DB.php class but I was lazy and it was running locally
