<?php
require __DIR__ . '/WebPush.php';

use AsilTechSoft\WebPush\WebPush;

// Run once, then save both keys (e.g. in your config or database):
// $keys = WebPush::generateVapidKeys();

$push = new WebPush(
    publicKey:  'YOUR_PUBLIC_KEY',
    privateKey: file_get_contents(__DIR__ . '/vapid-private.pem'),
    subject:    'mailto:you@example.com',
);

// The JSON you got from PushSubscription.toJSON() in the browser
$subscription = json_decode(file_get_contents(__DIR__ . '/subscription.json'), true);

$result = $push->send($subscription, json_encode([
    'title' => 'New order',
    'body'  => 'Order #1042 has been placed.',
    'url'   => '/admin/orders',
]), ttl: 3600, urgency: 'high');

if ($result->ok()) {
    echo "Sent\n";
} elseif ($result->expired()) {
    echo "Subscription expired: delete it from your database\n";
} else {
    echo "Failed ({$result->status}): {$result->body}\n";
}
