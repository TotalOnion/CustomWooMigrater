<?php

/*
 port 10003 == stage
 port 10008 == live
*/

require 'DB.php';
require 'Order.php';
require 'OrderMigrater.php';
require 'Utilities.php';

$verbosity = Utilities::VERBOSITY_VERY_VERY_VERBOSE;

function dump($stuff) {
    echo '<pre>'.PHP_EOL;
    print_r($stuff);
    echo '</pre>'.PHP_EOL;
}

$db = new DB();
$utilities = new Utilities($db, $verbosity);
$orderMigrater = new OrderMigrater($db, $verbosity);

// What's the most recent order we have on stage?
$fromOrderId = $utilities->findMaxOrderId(DB::ENVIRONMENT_STAGE);

// Load all the order IDs that happened after that on live
$orderIds = $utilities->findOrderIDsAfter(DB::ENVIRONMENT_LIVE, $fromOrderId);

foreach ($orderIds as $orderId) {
    $order = new Order($db, DB::ENVIRONMENT_LIVE, $orderId);
    $orderMigrater->migrate(DB::ENVIRONMENT_STAGE, $order);
}

$totalMigrated = count($orderIds);
$utilities->log("");
$utilities->log("Finished! Migrated $totalMigrated Orders.");