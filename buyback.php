<?php

/**
 * Plugin Name: Buyback Orders
 * Description: Buyback order management bridging Bit Form submissions and WooCommerce — custom admin, authenticated REST intake, coupon generation, post-inspection adjustments, and a customer-facing shortcode.
 * Version:     10
 * Author:      Nourhan
 *
 * Configuration (add to wp-config.php — never commit real secrets):
 *   define('BUYBACK_API_SECRET',        '...');  // shared token the Bit Form webhook must send
 *   define('BUYBACK_BITFORM_API_KEY',   '...');  // Bit Form REST API key (rotate the old leaked one!)
 *   define('BUYBACK_FILE_BASE_URL',     'https://your-site.com'); // host serving Bit Form files (defaults to home_url())
 *
 * Each constant falls back to a wp_option of the same lowercase name if the constant is undefined.
 */

defined('ABSPATH') || exit;

define('BUYBACK_DB_VERSION', '10');

/* -------------------------------------------------------------------------
 * Configuration helpers
 * ---------------------------------------------------------------------- */

function buyback_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'buyback_orders';
}

/**
 * Resolve a config value from a constant first, then a wp_option fallback.
 */
function buyback_config($constant, $option_name, $default = '')
{
    if (defined($constant) && constant($constant) !== '') {
        return constant($constant);
    }
    return get_option($option_name, $default);
}

function buyback_get_api_secret()
{
    return (string) buyback_config('BUYBACK_API_SECRET', 'buyback_api_secret', '');
}

function buyback_get_bitform_api_key()
{
    return (string) buyback_config('BUYBACK_BITFORM_API_KEY', 'buyback_bitform_api_key', '');
}

/**
 * Base URL of the host that serves Bit Form file downloads. Defaults to this site.
 */
function buyback_get_file_base_url()
{
    $base = buyback_config('BUYBACK_FILE_BASE_URL', 'buyback_file_base_url', '');
    return $base ? untrailingslashit($base) : untrailingslashit(home_url());
}

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

register_activation_hook(__FILE__, 'buyback_create_table');

function buyback_create_table()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = buyback_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // Note: `condition` is a reserved MySQL keyword — it must stay backticked.
    // dbDelta is whitespace-sensitive: one field per line, two spaces before PRIMARY KEY.
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20),
        reference_id VARCHAR(50),
        name VARCHAR(100),
        email VARCHAR(100),
        phone VARCHAR(50),
        payment VARCHAR(50),
        country VARCHAR(100),
        city VARCHAR(100),
        address TEXT,
        category VARCHAR(100),
        brand VARCHAR(100),
        product VARCHAR(100),
        model_value VARCHAR(100),
        color_value VARCHAR(100),
        capacity_value VARCHAR(100),
        condition_value VARCHAR(100),
        model VARCHAR(100),
        color VARCHAR(100),
        capacity VARCHAR(100),
        `condition` VARCHAR(100),
        total DECIMAL(10,2),
        old_total DECIMAL(10,2),
        status VARCHAR(20) DEFAULT 'Pending',
        coupon_code VARCHAR(50),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        updated_by VARCHAR(100),
        created_by VARCHAR(20) DEFAULT 'User',
        entry_id BIGINT(20),
        form_id BIGINT(20),
        image_id VARCHAR(100),
        images LONGTEXT,
        PRIMARY KEY  (id),
        KEY reference_id (reference_id),
        KEY user_id (user_id)
    ) $charset_collate;";

    dbDelta($sql);

    update_option('buyback_db_version', BUYBACK_DB_VERSION);
}

/**
 * Run schema upgrades only when the stored version changes — not on every request.
 */
function buyback_maybe_update_table()
{
    if (get_option('buyback_db_version') === BUYBACK_DB_VERSION) {
        return;
    }
    buyback_create_table(); // dbDelta is idempotent and adds any missing columns.
}
add_action('plugins_loaded', 'buyback_maybe_update_table');

/* -------------------------------------------------------------------------
 * Admin menu
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_menu_page('Buyback Orders', 'Buyback Orders', 'manage_options', 'buyback-orders', 'buyback_orders_page', 'dashicons-cart');
    add_submenu_page('buyback-orders', 'Reset Buyback Orders', 'Reset Data', 'manage_options', 'reset-buyback-orders', 'buyback_reset_orders_page');
});

function buyback_reset_orders_page()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.'));
    }

    global $wpdb;
    $table_name = buyback_table_name();

    if (isset($_POST['confirm_reset'])) {
        check_admin_referer('buyback_reset_orders', 'buyback_reset_nonce');
        $wpdb->query("TRUNCATE TABLE `$table_name`");
        echo '<div class="updated"><p>Buyback Orders table has been reset (all data deleted).</p></div>';
    }

    echo '<div class="wrap"><h1>Reset Buyback Orders</h1>';
    echo '<form method="POST" onsubmit="return confirm(\'This permanently deletes ALL buyback orders. Continue?\');">';
    wp_nonce_field('buyback_reset_orders', 'buyback_reset_nonce');
    echo '<p style="color:red;"><strong>Warning:</strong> This will delete <strong>ALL</strong> buyback orders. This action is irreversible.</p>';
    echo '<input type="submit" name="confirm_reset" value="Yes, Reset All Data" class="button button-primary">';
    echo '</form></div>';
}

/* -------------------------------------------------------------------------
 * WooCommerce helpers
 * ---------------------------------------------------------------------- */

function buyback_generate_coupon($amount)
{
    $code = strtoupper('BUYBACK-' . wp_generate_password(8, false));

    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_discount_type('fixed_cart'); // Whole-cart value, applied once.
    $coupon->set_amount($amount);
    $coupon->set_usage_limit(1);
    $coupon->set_individual_use(true);

    // No product restrictions.
    $coupon->set_product_ids([]);
    $coupon->set_excluded_product_ids([]);
    $coupon->set_product_categories([]);
    $coupon->set_excluded_product_categories([]);

    $coupon->save();

    // Dokan compatibility.
    update_post_meta($coupon->get_id(), '_dokan_enable_for_all_vendors', 'yes');
    update_post_meta($coupon->get_id(), 'dokan_global_discount', 'yes');
    update_option('admin_coupons_enabled_for_vendor', 'on');

    return $code;
}

function buyback_cancel_order_by_coupon($coupon_code)
{
    $orders = wc_get_orders([
        'limit'  => -1,
        'status' => ['processing', 'on-hold'],
        'coupon' => $coupon_code,
    ]);

    foreach ($orders as $order) {
        $order->update_status('cancelled', 'Cancelled by admin after failed device inspection');
        error_log("Buyback Plugin: Order #{$order->get_id()} cancelled due to invalid inspection.");
    }
}

function buyback_format_email($ref_id, $user_name, $status, $final_total, $coupon_code = null)
{
    $message  = "<h2>Buyback Order Update</h2>";
    $message .= "<p>Dear <strong>" . esc_html($user_name) . "</strong>,</p>";
    $message .= "<p>Your buyback request (Reference ID: <strong>" . esc_html($ref_id) . "</strong>) has been <strong>" . esc_html($status) . "</strong>.</p>";

    if (($status === 'Accepted' || $status === 'Approved') && $coupon_code) {
        $message .= "<p>We are pleased to offer you a coupon for your next purchase:</p>";
        $message .= "<h3 style='color:#2d9cdb;'>" . esc_html($coupon_code) . "</h3>";
        $message .= "<p><strong>Coupon Value:</strong> " . esc_html($final_total) . "<br><strong>Usage:</strong> One-time only.</p>";
    }

    if ($status === 'Offer') {
        $message .= "<p>New Offer Price: <strong>" . esc_html($final_total) . "</strong></p>";
    }

    $message .= "<p>Thank you for choosing us.</p>";

    return $message;
}

/**
 * Find an existing buyback-created product by its reference id (idempotency guard).
 */
function buyback_find_product_by_reference($ref_id)
{
    $ids = get_posts([
        'post_type'        => 'product',
        'post_status'      => 'any',
        'numberposts'      => 1,
        'fields'           => 'ids',
        'meta_key'         => 'buyback_reference_id',
        'meta_value'       => $ref_id,
        'suppress_filters' => false,
    ]);

    return $ids ? (int) $ids[0] : 0;
}

/**
 * Create a private WooCommerce product mirroring an accepted buyback order.
 * Idempotent: returns the existing product id if one already exists for this reference.
 */
function buyback_insert_wc_product($ref_id, $price)
{
    global $wpdb;
    $table_name = buyback_table_name();

    $existing = buyback_find_product_by_reference($ref_id);
    if ($existing) {
        update_post_meta($existing, '_price', $price);
        update_post_meta($existing, '_regular_price', $price);
        return $existing;
    }

    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by != 'Admin' LIMIT 1",
        $ref_id
    ));

    if (! $order) {
        error_log("Buyback Plugin: No order found for ref_id $ref_id");
        return 0;
    }

    $product_id = wp_insert_post([
        'post_title'   => $order->model_value ?: 'Buyback Product',
        'post_content' => 'Auto-created from buyback order. Reference ID: ' . $ref_id,
        'post_status'  => 'private',
        'post_type'    => 'product',
    ]);

    if (is_wp_error($product_id)) {
        error_log("Buyback Plugin: Failed to insert product - " . $product_id->get_error_message());
        return 0;
    }

    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_stock_status', 'instock');
    update_post_meta($product_id, 'buyback_reference_id', $ref_id);

    if (! empty($order->category)) {
        wp_set_object_terms($product_id, $order->category, 'product_cat', true);
    }
    if (taxonomy_exists('product_brand') && ! empty($order->brand)) {
        wp_set_object_terms($product_id, $order->brand, 'product_brand', true);
    }
    if (taxonomy_exists('wcpv_product_vendors')) {
        wp_set_object_terms($product_id, 'Buyback', 'wcpv_product_vendors', false);
    }

    error_log("Buyback Plugin: Product $product_id created for ref_id $ref_id");
    return (int) $product_id;
}

/**
 * Locate the "Buyback Adjustment Fee" product by title without the deprecated
 * get_page_by_title(). Result is cached in an option for subsequent calls.
 */
function buyback_get_adjustment_product_id()
{
    $cached = (int) get_option('buyback_adjustment_product_id', 0);
    if ($cached && get_post_status($cached) && get_post_type($cached) === 'product') {
        return $cached;
    }

    $query = new WP_Query([
        'post_type'      => 'product',
        'title'          => 'Buyback Adjustment Fee',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if (empty($query->posts)) {
        return 0;
    }

    $id = (int) $query->posts[0];
    update_option('buyback_adjustment_product_id', $id);
    return $id;
}

function get_readable_payment_label($slug)
{
    $map = [
        'cod'      => 'Cash on Delivery',
        'stripe'   => 'Card (Stripe)',
        'bacs'     => 'Bank Transfer',
        'woo_mpgs' => 'Credit Card (MPGS)',
        'paypal'   => 'PayPal',
    ];

    return $map[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
}

/* -------------------------------------------------------------------------
 * Admin POST handlers (each nonce-verified, then redirect)
 * ---------------------------------------------------------------------- */

/**
 * Add a one-off "Adjustment After Inspection" fee to a COD WooCommerce order.
 * Idempotent: skips if the fee already exists on the order.
 */
function buyback_handle_apply_fee($view_id)
{
    check_admin_referer('buyback_apply_fee', 'buyback_apply_fee_nonce');

    global $wpdb;
    $table_name  = buyback_table_name();
    $wc_order_id = intval($_POST['wc_order_id']);
    $ref_id      = sanitize_text_field(wp_unslash($_POST['ref_id']));

    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by = 'User' LIMIT 1", $ref_id));
    $admin = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by = 'Admin' ORDER BY created_at DESC LIMIT 1", $ref_id));

    if ($order && $admin && $order->coupon_code) {
        $coupon     = new WC_Coupon($order->coupon_code);
        $difference = (float) $coupon->get_amount() - (float) $admin->total;
        $woo_order  = wc_get_order($wc_order_id);

        if ($woo_order && $difference > 0) {
            $already_applied = false;
            foreach ($woo_order->get_fees() as $existing_fee) {
                if ($existing_fee->get_name() === 'Adjustment After Inspection') {
                    $already_applied = true;
                    break;
                }
            }

            if (! $already_applied) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name('Adjustment After Inspection');
                $fee->set_amount($difference);
                $fee->set_total($difference);
                $woo_order->add_item($fee);

                $woo_order->calculate_totals();
                $woo_order->update_status('on-hold', 'Manual adjustment from Buyback plugin');
                $woo_order->add_order_note("Fee of BHD {$difference} added.");
                $woo_order->save();

                WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger($woo_order->get_id());

                add_post_meta($coupon->get_id(), '_buyback_coupon_history', date('Y-m-d H:i:s') . " – Manual fee BHD {$difference} applied to Order #{$woo_order->get_id()}.");
            }
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=buyback-orders&view=' . $view_id . '&updated=1'));
    exit;
}

/**
 * Create a standalone WooCommerce order carrying the "Buyback Adjustment Fee"
 * product for the inspection price difference (non-COD payments).
 */
function buyback_handle_adjustment_order($view_id)
{
    check_admin_referer('buyback_adjustment_order', 'buyback_adjustment_nonce');

    $ref_id     = sanitize_text_field(wp_unslash($_POST['ref_id']));
    $user_name  = sanitize_text_field(wp_unslash($_POST['user_name']));
    $difference = floatval($_POST['difference']);
    $user_id    = intval($_POST['user_id']);

    $user = get_user_by('ID', $user_id);
    if (! $user) {
        echo '<div class="notice notice-error"><p>User not found.</p></div>';
        return;
    }

    $product_id = buyback_get_adjustment_product_id();
    if (! $product_id) {
        echo '<div class="notice notice-error"><p>Adjustment product not found.</p></div>';
        return;
    }

    $wc_product = wc_get_product($product_id);
    if (! $wc_product || $difference <= 0) {
        echo '<div class="notice notice-error"><p>Invalid product or no price difference.</p></div>';
        return;
    }

    $order = wc_create_order();
    $order->set_customer_id($user_id);
    $order->set_status('pending');

    $item_id = $order->add_product($wc_product, 1, [
        'subtotal' => $difference,
        'total'    => $difference,
    ]);

    if ($item_id) {
        $item = $order->get_item($item_id);
        $item->add_meta_data('Reference ID', $ref_id, true);
        $item->add_meta_data('Note', 'This fee was generated from Buyback order ' . $ref_id, true);
        $item->save();
    }

    $order->set_billing_email($user->user_email);
    $order->set_billing_first_name($user_name);
    $order->set_billing_last_name('Adjustment');
    $order->set_created_via('buyback-plugin');
    $order->calculate_totals();
    $order->save();

    update_post_meta($order->get_id(), '_customer_user', $user_id);
    $order->add_order_note("Adjustment Order created for Buyback Reference ID {$ref_id}");

    WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger($order->get_id());

    echo '<div class="updated notice"><p>Adjustment order (Order #' . esc_html($order->get_id()) . ') created successfully for BHD ' . esc_html($difference) . '</p></div>';
}

/**
 * Apply the admin's final decision (approve / reject / accept / decline) across
 * all rows sharing the reference id. Returns the redirect target id.
 */
function buyback_handle_save_order($selected_order, $view_id)
{
    check_admin_referer('buyback_save_order_' . $view_id, 'buyback_save_nonce');

    global $wpdb;
    $table_name   = buyback_table_name();
    $action       = sanitize_text_field(wp_unslash($_POST['final_action']));
    $current_user = wp_get_current_user();
    $ref_id       = $selected_order->reference_id;

    $coupon_code = $selected_order->coupon_code;
    $new_status  = '';
    $new_total   = null;

    if ($action === 'approve') {
        $new_status = 'Approved';

        if ($selected_order->payment === 'voucher' && empty($coupon_code)) {
            $coupon_code = buyback_generate_coupon($selected_order->total);
        }

        $message = buyback_format_email($ref_id, $selected_order->name, 'Approved', $selected_order->total, $coupon_code);
        wp_mail($selected_order->email, '[Buyback] Your request has been approved', $message, ['Content-Type: text/html; charset=UTF-8']);
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';

        $message = buyback_format_email($ref_id, $selected_order->name, 'Rejected', 0);
        wp_mail($selected_order->email, '[Buyback] Your request has been rejected', $message, ['Content-Type: text/html; charset=UTF-8']);
    } elseif ($action === 'received_user_price') {
        $new_status = 'Accepted';

        $user_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by != 'Admin' LIMIT 1",
            $ref_id
        ));

        if ($user_order) {
            $new_total = $user_order->total;
            if ($selected_order->payment === 'voucher' && ! empty($selected_order->coupon_code)) {
                $coupon = new WC_Coupon($selected_order->coupon_code);
                if ($coupon->get_id()) {
                    $old_amount = $coupon->get_amount();
                    if ($old_amount != $new_total) {
                        add_post_meta($coupon->get_id(), '_buyback_coupon_history', date('Y-m-d H:i:s') . " – Amount changed from {$old_amount} to {$new_total}");
                    }
                    $coupon->set_amount($new_total);
                    $coupon->save();
                }
            }
            buyback_insert_wc_product($ref_id, $new_total);
        } else {
            $new_total = 0;
            error_log("Buyback Plugin: No user order found for ref_id $ref_id");
        }
    } elseif (strpos($action, 'accept_admin_offer_') === 0) {
        $offer_number = intval(str_replace('accept_admin_offer_', '', $action));

        $admin_offers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by = 'Admin' ORDER BY created_at ASC",
            $ref_id
        ));

        if (isset($admin_offers[$offer_number - 1])) {
            $new_total  = $admin_offers[$offer_number - 1]->total;
            $new_status = 'Accepted';

            if (! empty($selected_order->coupon_code)) {
                $coupon = new WC_Coupon($selected_order->coupon_code);
                if ($coupon->get_id()) {
                    $old_amount = $coupon->get_amount();
                    if ($old_amount != $new_total) {
                        add_post_meta($coupon->get_id(), '_buyback_coupon_history', date('Y-m-d H:i:s') . " – Amount changed from {$old_amount} to {$new_total}");
                    }
                    $coupon->set_amount($new_total);
                    $coupon->save();
                }
            }
            buyback_insert_wc_product($ref_id, $new_total);
        }
    } elseif ($action === 'decline_order') {
        $new_status = 'Declined';

        if (! empty($selected_order->coupon_code)) {
            buyback_cancel_order_by_coupon($selected_order->coupon_code);
            wp_mail(get_option('admin_email'), 'Buyback Order Cancelled', "WooCommerce order using coupon {$selected_order->coupon_code} has been cancelled due to failed inspection.");

            $coupon = new WC_Coupon($selected_order->coupon_code);
            if ($coupon->get_id()) {
                wp_trash_post($coupon->get_id());

                $revoke_message = "<p>Dear <strong>" . esc_html($selected_order->name) . "</strong>,</p>" .
                    "<p>Your buyback order (Reference ID: <strong>" . esc_html($ref_id) . "</strong>) has been declined after inspection.</p>" .
                    "<p>The issued coupon <strong>" . esc_html($selected_order->coupon_code) . "</strong> is no longer valid and has been revoked.</p>" .
                    "<p>If you have questions, please contact our support.</p><p>Thank you.</p>";

                wp_mail($selected_order->email, '[Buyback] Your coupon has been revoked', $revoke_message, ['Content-Type: text/html; charset=UTF-8']);
                error_log("Buyback Plugin: Coupon {$selected_order->coupon_code} revoked for declined order ID {$selected_order->id}");
            }
        }
    }

    if (! empty($new_status)) {
        $orders = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table_name` WHERE reference_id = %s", $ref_id));
        foreach ($orders as $o) {
            $update_data = [
                'status'      => $new_status,
                'total'       => $new_total !== null ? $new_total : $o->total,
                'updated_at'  => current_time('mysql'),
                'updated_by'  => $current_user->display_name,
                'coupon_code' => $coupon_code ?: $o->coupon_code,
            ];

            // Capture the estimate once, at approval time.
            if ($action === 'approve' && $o->old_total === null) {
                $update_data['old_total'] = $o->total;
            }

            $wpdb->update($table_name, $update_data, ['id' => $o->id]);
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=buyback-orders&view=' . $view_id . '&updated=1'));
    exit;
}

/* -------------------------------------------------------------------------
 * Admin orders screen
 * ---------------------------------------------------------------------- */

function buyback_orders_page()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.'));
    }

    global $wpdb;
    $table_name = buyback_table_name();

    if (isset($_GET['updated'])) {
        echo '<div class="updated notice"><p>Order updated successfully.</p></div>';
    }

    if (isset($_POST['delete_order'])) {
        check_admin_referer('buyback_delete_order', 'buyback_delete_nonce');
        $wpdb->delete($table_name, ['id' => intval($_POST['order_id'])]);
        echo '<div class="updated"><p>Order deleted.</p></div>';
    }

    if (isset($_GET['view'])) {
        $view_id = intval($_GET['view']);

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE id = %d", $view_id));
        if (! $order) {
            echo '<div class="notice notice-error"><p>Order not found.</p></div>';
            return;
        }

        if (isset($_POST['buyback_apply_fee']) && isset($_POST['wc_order_id'])) {
            buyback_handle_apply_fee($view_id);
        }

        if (isset($_POST['create_adjustment_order_full'])) {
            buyback_handle_adjustment_order($view_id);
        }

        if (isset($_POST['save_order'])) {
            buyback_handle_save_order($order, $view_id);
        }

        // All rows for this reference, admin offers first.
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table_name` WHERE reference_id = %s ORDER BY FIELD(created_by, 'Admin', 'User'), created_at ASC",
            $order->reference_id
        ));

        echo '<div class="wrap" style="background:#faf8f5;padding:20px;border-radius:8px;">';
        echo '<h1 class="wp-heading-inline">Buyback Orders for Reference ID: ' . esc_html($order->reference_id ?: '-') . '</h1><hr class="wp-header-end">';

        foreach ($orders as $row) {

            $coupon_display = '-';
            if (! empty($row->coupon_code)) {
                $coupon_obj = new WC_Coupon($row->coupon_code);
                $used       = $coupon_obj->get_usage_count() > 0;
                $coupon_display = esc_html($row->coupon_code) . ' – ' .
                    ($used ? '<span style="color:green;">Used</span>' : '<span style="color:red;">Not Used</span>');
            }

            echo '<div style="margin-bottom: 30px; padding: 15px; background:#fff; border: 1px solid #ddd; border-radius: 6px;">';
            echo '<h2>Order #' . esc_html($row->id) . ' (Created By: <span style="color: ' . ($row->created_by === 'Admin' ? 'green' : 'blue') . ';">' . esc_html($row->created_by ?: '-') . '</span>)</h2>';

            echo '<h3>User Details</h3><ul>';
            echo '<li><strong>Name:</strong> ' . esc_html($row->name) . '</li>';
            if (! empty($row->email)) {
                echo '<li><strong>Email:</strong> ' . esc_html($row->email) . '</li>';
            }
            if (! empty($row->phone)) {
                echo '<li><strong>Phone:</strong> ' . esc_html($row->phone) . '</li>';
            }
            if (! empty($row->country)) {
                echo '<li><strong>Country:</strong> ' . esc_html($row->country) . '</li>';
            }
            if (! empty($row->city)) {
                echo '<li><strong>City:</strong> ' . esc_html($row->city) . '</li>';
            }
            if (! empty($row->address)) {
                echo '<li><strong>Address:</strong> ' . esc_html($row->address) . '</li>';
            }
            if (! empty($row->payment)) {
                echo '<li><strong>Payment:</strong> ' . esc_html($row->payment) . '</li>';
            }
            echo '</ul>';

            echo '<h3>Device Details</h3><ul>';
            echo '<li><strong>Category:</strong> ' . esc_html($row->category) . '</li>';
            echo '<li><strong>Brand:</strong> ' . esc_html($row->brand) . '</li>';
            if (! empty($row->product) && $row->created_by === 'Admin') {
                echo '<li><strong>Product :</strong> ' . esc_html($row->product) . '</li>';
            }
            echo '<li><strong>Model:</strong> ' . esc_html($row->model_value) . '</li>';
            echo '<li><strong>Condition:</strong> ' . esc_html($row->condition_value) . '</li>';
            if (! empty($row->coupon_code)) {
                echo '<li><strong>Coupon Code & usage status:</strong> ' . $coupon_display . '</li>';
            }
            echo '</ul>';

            echo '<h3>Order Info</h3><ul>';
            echo '<li><strong>Status:</strong> <span style="color:' . ($row->status === 'Accepted' ? 'green' : ($row->status === 'Rejected' ? 'red' : 'orange')) . ';">' . esc_html($row->status) . '</span></li>';
            echo '<li><strong>Created At:</strong> ' . esc_html($row->created_at) . '</li>';
            echo '<li><strong>Updated At:</strong> ' . esc_html($row->updated_at ?: '-') . '</li>';
            echo '<li><strong>Updated By:</strong> ' . esc_html($row->updated_by ?: '-') . '</li>';
            echo '</ul>';

            echo '<h3>Uploaded Images</h3>';
            if (! empty($row->images)) {
                $images = json_decode($row->images, true);
                if (is_array($images)) {
                    $form_id  = intval($row->form_id);
                    $entry_id = intval($row->entry_id);
                    $base     = buyback_get_file_base_url();

                    echo '<div style="margin-bottom:10px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">';
                    foreach ($images as $file_name) {
                        $img_url = $base . "/bitforms/bitforms-file/?formID={$form_id}&entryID={$entry_id}&fileID=" . urlencode($file_name);
                        echo '<img src="' . esc_url($img_url) . '" style="max-width:100px;max-height:100px;border:1px solid #ccc;border-radius:4px;">';
                    }
                    echo '</div>';
                }
            }

            if (! empty($row->coupon_code) && $row->created_by != 'Admin') {
                $coupon_obj  = new WC_Coupon($row->coupon_code);
                $usage_count = $coupon_obj->get_usage_count();

                if ($usage_count > 0) {
                    $linked_orders = wc_get_orders([
                        'limit'   => 1,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                        'coupon'  => $row->coupon_code,
                    ]);

                    if (! empty($linked_orders)) {
                        echo '<h4>WooCommerce Orders Using This Coupon</h4><ul>';

                        foreach ($linked_orders as $woo_order) {
                            if ($woo_order->get_user_id() !== intval($row->user_id)) {
                                continue;
                            }

                            $payment_method = $woo_order->get_payment_method();
                            $payment_label  = get_readable_payment_label($payment_method);
                            $difference     = floatval($row->old_total - $row->total);

                            echo '<li>Order #' . esc_html($woo_order->get_id()) .
                                ' – Status: ' . esc_html($woo_order->get_status()) .
                                ' – Payment: ' . esc_html($payment_label) .
                                ' – <a href="' . esc_url(admin_url('post.php?post=' . $woo_order->get_id() . '&action=edit')) . '" target="_blank">View</a></li>';

                            if ($payment_method === 'cod' && $difference > 0) {
                                echo '<form method="post" style="margin-top:10px;">';
                                wp_nonce_field('buyback_apply_fee', 'buyback_apply_fee_nonce');
                                echo '<input type="hidden" name="buyback_apply_fee" value="1">';
                                echo '<input type="hidden" name="wc_order_id" value="' . esc_attr($woo_order->get_id()) . '">';
                                echo '<input type="hidden" name="ref_id" value="' . esc_attr($row->reference_id) . '">';
                                echo '<button type="submit" class="button button-primary">➕ Add Fee</button>';
                                echo '<p>This fee covers the difference between the coupon and final offer after inspection: <strong><span style="color:red;">' . esc_html($difference) . '</span></strong> BHD.</p>';
                                echo '</form>';
                            }

                            if ($payment_method != 'cod' && $difference > 0) {
                                echo '<form method="post" style="margin-top:20px;">';
                                wp_nonce_field('buyback_adjustment_order', 'buyback_adjustment_nonce');
                                echo '<input type="hidden" name="create_adjustment_order_full" value="1">';
                                echo '<input type="hidden" name="ref_id" value="' . esc_attr($row->reference_id) . '">';
                                echo '<input type="hidden" name="user_id" value="' . esc_attr($row->user_id) . '">';
                                echo '<input type="hidden" name="user_name" value="' . esc_attr($row->name) . '">';
                                echo '<input type="hidden" name="difference" value="' . esc_attr($difference) . '">';
                                echo '<button type="submit" class="button button-secondary">⚖️ Create Adjustment Order with Product</button>';
                                echo '<p>This creates a new WooCommerce order with “Buyback Adjustment Fee” for BHD ' . esc_html($difference) . '.</p>';
                                echo '</form>';
                            }

                            $fees = $woo_order->get_fees();
                            if (! empty($fees)) {
                                echo '<li style="margin-left:15px;"><strong>Fee(s):</strong> ';
                                foreach ($fees as $fee) {
                                    echo esc_html($fee->get_name()) . ' – BHD ' . esc_html($fee->get_amount());
                                }
                                echo '</li>';
                            }

                            if ($woo_order->has_status('pending')) {
                                echo '<li style="margin-left:15px; color: #d9534f;"><strong>Awaiting Payment:</strong> User must pay the inspection fee to complete order.</li>';
                            }
                        }

                        echo '</ul>';
                    }
                }
            }

            echo '<h3>Financial Info</h3><ul>';
            echo '<li><strong>Estimated Total:</strong> ' . esc_html($row->old_total ?: '-') . '</li>';
            echo '<li><strong>Final Total:</strong> <strong style="color:#2d9cdb;">' . esc_html($row->total) . '</strong></li>';

            if (! empty($row->coupon_code)) {
                $coupon = new WC_Coupon($row->coupon_code);
                if ($coupon->get_id()) {
                    $history = get_post_meta($coupon->get_id(), '_buyback_coupon_history');
                    if (! empty($history)) {
                        echo '<h4>Coupon Value History</h4><ul style="padding-left:20px;">';
                        foreach ($history as $log_entry) {
                            echo '<li>' . esc_html($log_entry) . '</li>';
                        }
                        echo '</ul>';
                    }
                }
            }
            echo '</ul>';

            // Action form only for the selected, still-actionable order.
            if ($row->id == $view_id && in_array($row->status, ['Pending', 'Approved'], true)) {
                $admin_offers = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM `$table_name` WHERE reference_id = %s AND created_by = 'Admin' ORDER BY created_at ASC",
                    $row->reference_id
                ));

                echo '<form method="POST">';
                wp_nonce_field('buyback_save_order_' . $view_id, 'buyback_save_nonce');
                echo '<p><strong>Final Action:</strong> <select name="final_action">';

                if ($row->status === 'Pending') {
                    echo '<option value="approve">✅ Approve</option>';
                    echo '<option value="reject">❌ Reject</option>';
                } elseif ($row->status === 'Approved') {
                    echo '<option value="received_user_price">✅ Received & Accept User Price</option>';
                    foreach ($admin_offers as $index => $admin_offer) {
                        $offer_number = $index + 1;
                        echo '<option value="accept_admin_offer_' . $offer_number . '">✅ Accept Admin Offer ' . $offer_number . '</option>';
                    }
                    echo '<option value="decline_order">❌ Decline After Inspection</option>';
                }
                echo '</select></p>';
                echo '<button type="submit" name="save_order" class="button button-primary">Save Changes</button>';
                echo '</form>';
            } else {
                echo '<p><strong>Final decision has been made. No further changes allowed.</strong></p>';
            }

            echo '</div>';
        }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=buyback-orders')) . '" class="button">Back to list</a></p>';
        echo '</div>';

        return;
    }

    $orders = $wpdb->get_results("SELECT * FROM `$table_name` ORDER BY created_at DESC");

    echo '<script>
    function toggleGroup(refId) {
        document.querySelectorAll(".group-" + refId).forEach(function (row) {
            row.style.display = row.style.display === "none" ? "" : "none";
        });
    }
    </script>';

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
        <th>ID</th><th>Name</th><th>Product</th><th>Total</th><th>Status</th><th>Coupon</th><th>Actions</th>
    </tr></thead><tbody>';

    $current_ref = '';
    foreach ($orders as $order) {
        if ($order->reference_id !== $current_ref) {
            $current_ref = $order->reference_id;
            echo '<tr style="background:#f0f0f0;">
                <td colspan="7" style="cursor:pointer;" onclick="toggleGroup(\'' . esc_attr($current_ref) . '\')">
                    <strong>Reference ID: ' . esc_html($current_ref ?: '-') . '</strong>
                    <span style="float:right;">⬇️</span>
                </td>
              </tr>';
        }

        $status_label = strtolower($order->status) === 'accepted' ? 'Accepted and Received' : $order->status;

        echo '<tr class="group-' . esc_attr($current_ref) . '">
            <td>' . esc_html($order->id) . '</td>
            <td>' . esc_html($order->name) . '</td>
            <td>' . esc_html($order->model_value) . '</td>
            <td><strong>BHD ' . esc_html($order->total) . '</strong></td>
            <td>' . esc_html($status_label) . '</td>
            <td>' . esc_html($order->coupon_code ?: '-') . '</td>
            <td>';

        if ($order->created_by != 'Admin') {
            echo '<a href="' . esc_url(admin_url('admin.php?page=buyback-orders&view=' . $order->id)) . '" class="button button-secondary">View</a> ';

            if ($order->status === 'Approved' || $order->status === 'Pending') {
                $recheck_url = buyback_get_file_base_url() . '/admin-form/?ref_id=' . urlencode($order->reference_id);
                echo '<a href="' . esc_url($recheck_url) . '" class="button button-secondary" target="_blank" title="Open admin form to review or re-inspect this order">Recheck</a>';
            }
        }

        if ($order->status === 'Approved' || $order->status === 'Pending') {
            echo '<form method="POST" style="display:inline-block;margin-left:10px;">';
            wp_nonce_field('buyback_delete_order', 'buyback_delete_nonce');
            echo '<input type="hidden" name="order_id" value="' . esc_attr($order->id) . '">
                <button type="submit" name="delete_order" class="button button-danger" onclick="return confirm(\'Are you sure?\');">🗑️</button>
                </form>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
}

/* -------------------------------------------------------------------------
 * REST intake (authenticated)
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('buyback/v1', '/create', [
        'methods'             => 'GET,POST',
        'callback'            => 'buyback_create_order',
        'permission_callback' => 'buyback_rest_authenticate',
    ]);
});

/**
 * Gate the intake endpoint with a constant-time shared-secret check.
 * The Bit Form webhook must send the token via the X-Buyback-Token header
 * or a `token` parameter.
 */
function buyback_rest_authenticate(WP_REST_Request $request)
{
    $secret = buyback_get_api_secret();
    if (empty($secret)) {
        return new WP_Error('buyback_no_secret', 'Buyback API secret is not configured on the server.', ['status' => 500]);
    }

    $provided = $request->get_header('X-Buyback-Token');
    if (empty($provided)) {
        $provided = (string) $request->get_param('token');
    }

    if (! is_string($provided) || ! hash_equals($secret, $provided)) {
        return new WP_Error('buyback_forbidden', 'Invalid or missing API token.', ['status' => 401]);
    }

    return true;
}

function buyback_create_order(WP_REST_Request $request)
{
    global $wpdb;
    $table_name = buyback_table_name();

    $reference_id = sanitize_text_field($request->get_param('reference_id'));
    if (empty($reference_id)) {
        return new WP_Error('buyback_missing_reference', 'reference_id is required.', ['status' => 400]);
    }

    $entry_id        = intval($request->get_param('entry_id'));
    $form_id         = intval($request->get_param('form_id'));
    $image_field_key = sanitize_text_field($request->get_param('image_id')); // Bit Form field key, e.g. "b2-93".

    // Pull uploaded image filenames from the Bit Form REST API.
    $images  = [];
    $api_key = buyback_get_bitform_api_key();

    if ($api_key && $form_id) {
        $external_api_url = buyback_get_file_base_url() . "/wp-json/bitform/v1/form/response/$form_id";
        $response         = wp_remote_get($external_api_url, [
            'headers' => ['Bitform-Api-Key' => $api_key],
            'timeout' => 15,
        ]);

        if (! is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (! empty($body['data']['entries'])) {
                foreach ($body['data']['entries'] as $entry) {
                    if ((int) $entry['entry_id'] === $entry_id) {
                        if (! empty($entry[$image_field_key])) {
                            $decoded = json_decode($entry[$image_field_key], true);
                            if (is_array($decoded)) {
                                $images = $decoded;
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    // Never trust the client for trust-sensitive fields.
    $created_by = $request->get_param('created_by') === 'Admin' ? 'Admin' : 'User';

    $allowed_statuses = ['Pending', 'Approved', 'Accepted', 'Rejected', 'Declined', 'Offer'];
    $status           = sanitize_text_field($request->get_param('status'));
    if (! in_array($status, $allowed_statuses, true)) {
        $status = 'Pending';
    }

    $data = [
        'reference_id'    => $reference_id,
        'created_by'      => $created_by,
        'name'            => sanitize_text_field($request->get_param('name')),
        'user_id'         => is_numeric($request->get_param('user_id')) ? intval($request->get_param('user_id')) : null,
        'email'           => sanitize_email($request->get_param('email')),
        'phone'           => sanitize_text_field($request->get_param('phone')),
        'payment'         => sanitize_text_field($request->get_param('payment')),
        'country'         => sanitize_text_field($request->get_param('country')),
        'city'            => sanitize_text_field($request->get_param('city')),
        'address'         => sanitize_textarea_field($request->get_param('address')),
        'category'        => sanitize_text_field($request->get_param('category')),
        'brand'           => sanitize_text_field($request->get_param('brand')),
        'product'         => sanitize_text_field($request->get_param('product')),
        'total'           => floatval($request->get_param('total')),
        'status'          => $status,
        'model_value'     => sanitize_text_field($request->get_param('model_value')),
        'condition_value' => sanitize_text_field($request->get_param('condition_value')),
        'entry_id'        => $entry_id,
        'form_id'         => $form_id,
        'image_id'        => $image_field_key,
        'images'          => ! empty($images) ? wp_json_encode($images) : null,
    ];

    $inserted = $wpdb->insert($table_name, $data);
    if ($inserted === false) {
        return new WP_Error('buyback_db_error', 'Failed to store the buyback order.', ['status' => 500]);
    }

    return rest_ensure_response([
        'success'      => true,
        'order_id'     => $wpdb->insert_id,
        'reference_id' => $reference_id,
    ]);
}

/* -------------------------------------------------------------------------
 * Customer-facing shortcode
 * ---------------------------------------------------------------------- */

add_shortcode('buyback_user_orders', 'buyback_display_user_orders');

function buyback_display_user_orders()
{
    if (! is_user_logged_in()) {
        return '<p>Please <a href="' . esc_url(wp_login_url()) . '">log in</a> to view your buyback orders.</p>';
    }

    global $wpdb;
    $current_user = wp_get_current_user();
    $table_name   = buyback_table_name();

    $user_orders = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM `$table_name` WHERE user_id = %d AND created_by = 'User' ORDER BY created_at DESC", $current_user->ID)
    );

    if (empty($user_orders)) {
        return '<p>No buyback orders found for your account.</p>';
    }

    ob_start();

    echo '<div class="buyback-orders" style="margin-top:20px;">';
    echo '<h2>Your Buyback Orders</h2>';

    foreach ($user_orders as $order) {
        $ref_id   = esc_html($order->reference_id);
        $order_id = esc_attr($order->id);

        echo '<div class="order-wrapper" style="border:1px solid #ccc; margin-bottom:15px; border-radius:6px;">';

        echo '<button class="toggle-order" data-target="order-details-' . $order_id . '" style="width:100%;text-align:left;padding:10px;font-size:16px;background:#000;color:#fff;border:none;cursor:pointer;border-radius:6px 6px 0 0;">
            📦 Order Reference: ' . $ref_id . ' – ' . esc_html($order->model_value) . '
        </button>';

        echo '<div id="order-details-' . $order_id . '" class="order-card" style="display:none; padding:15px;">';

        echo '<p><strong>Order Reference:</strong> ' . $ref_id . '</p>';
        echo '<p><strong>Model:</strong> ' . esc_html($order->model_value) . '</p>';
        echo '<p><strong>Status:</strong> <span style="color:' . ($order->status === 'Accepted' ? 'green' : ($order->status === 'Rejected' ? 'red' : 'orange')) . ';">' . esc_html($order->status) . '</span></p>';

        if (! empty($order->coupon_code)) {
            echo '<p><strong>Coupon Code:</strong> <span style="color:#2d9cdb;font-weight:bold;">' . esc_html($order->coupon_code) . '</span></p>';
            echo '<p><strong>Coupon Value:</strong> BHD ' . esc_html($order->total) . '</p>';
        }

        echo '<p><strong>Final Price:</strong> ' . esc_html($order->total) . '</p>';

        echo '<div style="margin-top:10px;"><strong>🔍 Submitted Answers</strong><ul style="margin-top:10px;">';
        echo '<li><strong>Name:</strong> ' . esc_html($order->name) . '</li>';
        echo '<li><strong>Email:</strong> ' . esc_html($order->email) . '</li>';
        echo '<li><strong>Phone:</strong> ' . esc_html($order->phone) . '</li>';
        echo '<li><strong>Payment Method:</strong> ' . esc_html($order->payment) . '</li>';
        echo '<li><strong>Country:</strong> ' . esc_html($order->country) . '</li>';
        echo '<li><strong>City:</strong> ' . esc_html($order->city) . '</li>';
        echo '<li><strong>Address:</strong> ' . esc_html($order->address) . '</li>';
        echo '<li><strong>Category:</strong> ' . esc_html($order->category) . '</li>';
        echo '<li><strong>Brand:</strong> ' . esc_html($order->brand) . '</li>';
        echo '<li><strong>Model:</strong> ' . esc_html($order->model_value) . '</li>';
        echo '<li><strong>Condition:</strong> ' . esc_html($order->condition_value) . '</li>';
        echo '<li><strong>Estimated Price:</strong> ' . esc_html($order->old_total ?: '-') . '</li>';
        echo '</ul></div>';

        echo '<p><strong>Submitted At:</strong> ' . esc_html($order->created_at) . '</p>';

        if (! empty($order->images)) {
            $images = json_decode($order->images, true);
            if (is_array($images)) {
                $form_id  = intval($order->form_id);
                $entry_id = intval($order->entry_id);
                $base     = buyback_get_file_base_url();
                echo '<div style="margin-top:10px;"><strong>Uploaded Images:</strong><br>';
                foreach ($images as $file_name) {
                    $img_url = $base . "/bitforms/bitforms-file/?formID={$form_id}&entryID={$entry_id}&fileID=" . urlencode($file_name);
                    echo '<img src="' . esc_url($img_url) . '" style="max-width:100px;max-height:100px;margin:5px;border:1px solid #ccc;border-radius:4px;">';
                }
                echo '</div>';
            }
        }

        echo '<hr style="margin:15px 0; border:0; border-top:1px solid #ccc;">';

        $admin_inspection = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table_name` WHERE reference_id = %s AND created_by = 'Admin' LIMIT 1", $order->reference_id)
        );

        if ($admin_inspection) {
            echo '<div style="margin-top:10px;">';
            echo '<p style="color:#d2042d;font-size:16px; font-weight:bold; margin-bottom:10px;">Admin Inspection Result</p>';
            echo '<p><strong>Condition:</strong> ' . esc_html($admin_inspection->condition_value) . '</p>';
            echo '<p><strong>Updated Price:</strong> ' . esc_html($admin_inspection->total) . '</p>';
            echo '<p><strong>Updated At:</strong> ' . esc_html($admin_inspection->created_at) . '</p>';
            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '</div>';

    echo '<script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll(".toggle-order").forEach(function (btn) {
                btn.addEventListener("click", function () {
                    var target = document.getElementById(this.dataset.target);
                    target.style.display = (target.style.display === "none" || target.style.display === "") ? "block" : "none";
                });
            });
        });
    </script>';

    return ob_get_clean();
}
