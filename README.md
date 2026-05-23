# Buyback Orders

WordPress plugin bridging Bit Form submissions and WooCommerce for a device buyback flow.

## Requirements

- WordPress 5.6+
- WooCommerce
- Bit Form

## Configuration

Add to `wp-config.php`:

```php
define('BUYBACK_API_SECRET', '...');
define('BUYBACK_BITFORM_API_KEY', '...');
define('BUYBACK_FILE_BASE_URL', 'https://your-site.com');
```

## Usage

- REST intake: `GET|POST /wp-json/buyback/v1/create` (requires `X-Buyback-Token` header or `token` param)
- Shortcode: `[buyback_user_orders]`
- Admin: **Buyback Orders** menu in wp-admin
