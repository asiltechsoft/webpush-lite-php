# webpush-lite-php

Send **Web Push notifications from PHP with zero dependencies**: one file, no Composer packages, no extra libraries.

- 🔐 Payload encryption per **RFC 8291** (`aes128gcm`)
- 🪪 Server identification with **VAPID** (RFC 8292, ES256)
- 📦 Just `ext-openssl` and `ext-curl`, available on almost every shared host
- ✅ Works with Chrome, Edge, Firefox, Samsung Internet, and Safari (iOS 16.4+ home-screen apps)

It was extracted from the notification system of [asiltechsoft.com](https://asiltechsoft.com), where it delivers new quote, booking and message alerts to the admin's phone.

> 🇹🇷 Türkçe açıklama aşağıda.

## Why?

Most PHP Web Push libraries pull in several packages (JWT, big-integer math, HTTP clients). On shared hosting or in small projects, that is a lot of weight for one feature. This library does the same job with PHP's built-in OpenSSL functions in about 200 lines.

## Requirements

- PHP 8.1+
- `ext-openssl` with elliptic-curve support (standard in PHP builds)
- `ext-curl`
- Your site must be served over **HTTPS** (browsers require it for push)

## Quick start

**1. Create VAPID keys once and keep them.**

```php
require 'WebPush.php';
use AsilTechSoft\WebPush\WebPush;

$keys = WebPush::generateVapidKeys();
// $keys['public']  → used in the browser and on the server
// $keys['private'] → PEM, keep it secret
```

**2. Subscribe in the browser.** Copy `sw.js` to your site and see `example-subscribe.html`. Send the JSON from `PushSubscription.toJSON()` to your server and store it.

**3. Send.**

```php
$push = new WebPush($publicKey, $privatePem, 'mailto:you@example.com');

$result = $push->send($subscription, json_encode([
    'title' => 'New order',
    'body'  => 'Order #1042 has been placed.',
    'url'   => '/admin/orders',
]));

if ($result->expired()) {
    // 404/410: the user unsubscribed or the browser dropped it, so delete it
}
```

`send()` options: `ttl` (seconds the push service keeps the message for an offline device, default 86400) and `urgency` (`very-low`, `low`, `normal`, `high`).

## Files

| File | What it is |
|---|---|
| `WebPush.php` | The library |
| `sw.js` | Minimal service worker: shows the notification, opens `url` on click |
| `example-subscribe.html` | Browser side: asks permission, prints the subscription JSON |
| `example-send.php` | Server side: sends a notification |

## Notes

- Payload limit is ~4 KB. Send a short title, body and URL, not data.
- Keep the private key out of your public web folder.
- Each device has its own subscription; store one row per device and remove rows when `expired()` returns true.

## License

MIT © Ünal Kaya, [Asil Tech Soft](https://asiltechsoft.com)

---

## 🇹🇷 Türkçe

**PHP'den tarayıcı bildirimi göndermenin bağımlılıksız yolu.** Tek dosya; Composer paketi ya da ek kütüphane gerekmez. Yalnızca PHP'nin kendi OpenSSL ve cURL eklentilerini kullanır, bu yüzden paylaşımlı hostinglerde de çalışır.


**Kullanım:**
1. `WebPush::generateVapidKeys()` ile anahtarları **bir kez** üret ve sakla.
2. `sw.js` dosyasını sitene koy. `example-subscribe.html`'deki gibi tarayıcıdan izin alıp aboneliği sunucuna kaydet.
3. `$push->send($abonelik, $mesaj)` ile gönder. `expired()` true dönerse o aboneliği sil.

Site HTTPS olmalı. Mesaj içeriği en fazla ~4 KB olabilir.
