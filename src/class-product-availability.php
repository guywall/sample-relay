<?php
if (!defined('ABSPATH')) exit;

class PBSR_Product_Availability {

    const FIELD_NAME = 'product_availability';
    const STATUS_AVAILABLE = 'available';
    const STATUS_UNAVAILABLE = 'unavailable';
    const STATUS_DISCONTINUED = 'discontinued';

    private static $notice_rendered = [];

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
        add_filter('the_content', [__CLASS__, 'prepend_content_notice']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_notice'], 6);
    }

    public static function enqueue_styles() {
        $css = <<<'CSS'
.pbsr-product-availability-notice{
    margin:0 0 1rem;
    padding:.9rem 1rem;
    border-left:4px solid #c26a00;
    border-radius:8px;
    background:#fff4e3;
    color:#5d3a00;
    font-weight:700;
}
.pbsr-product-availability-notice--discontinued{
    border-left-color:#8a1f11;
    background:#fff0ed;
    color:#6f1d12;
}
CSS;
        wp_register_style('pbsr-product-availability', false, [], PBSR_VER);
        wp_add_inline_style('pbsr-product-availability', $css);
        wp_enqueue_style('pbsr-product-availability');
    }

    public static function get_status($product_id) {
        $status = '';

        if (function_exists('get_field')) {
            $status = get_field(self::FIELD_NAME, $product_id, false);
        }

        if (is_array($status)) {
            $status = isset($status['value']) ? $status['value'] : reset($status);
        }

        $status = sanitize_key((string) $status);

        if ('' === $status) {
            $status = sanitize_key((string) get_post_meta($product_id, self::FIELD_NAME, true));
        }

        $allowed = [
            self::STATUS_AVAILABLE,
            self::STATUS_UNAVAILABLE,
            self::STATUS_DISCONTINUED,
        ];

        return in_array($status, $allowed, true) ? $status : self::STATUS_AVAILABLE;
    }

    public static function is_available($product_id) {
        return self::STATUS_AVAILABLE === self::get_status($product_id);
    }

    public static function is_unavailable($product_id) {
        return self::STATUS_UNAVAILABLE === self::get_status($product_id);
    }

    public static function is_discontinued($product_id) {
        return self::STATUS_DISCONTINUED === self::get_status($product_id);
    }

    public static function is_visible_in_grid($product_id) {
        return !self::is_discontinued($product_id);
    }

    public static function is_selectable_in_grid($product_id) {
        return self::is_available($product_id);
    }

    public static function get_notice_message($product_id) {
        $status = self::get_status($product_id);

        if (self::STATUS_UNAVAILABLE === $status) {
            return 'Product is currently unavailable';
        }

        if (self::STATUS_DISCONTINUED === $status) {
            return 'Product has been discontinued';
        }

        return '';
    }

    public static function prepend_content_notice($content) {
        if (!self::should_render_on_current_product()) {
            return $content;
        }

        $product_id = get_the_ID();
        if (!$product_id || !empty(self::$notice_rendered[$product_id])) {
            return $content;
        }

        $notice = self::get_notice_html($product_id);
        if ('' === $notice) {
            return $content;
        }

        self::$notice_rendered[$product_id] = true;

        return $notice . $content;
    }

    public static function render_product_notice() {
        if (!self::should_render_on_current_product()) {
            return;
        }

        $product_id = get_the_ID();
        if (!$product_id || !empty(self::$notice_rendered[$product_id])) {
            return;
        }

        $notice = self::get_notice_html($product_id);
        if ('' === $notice) {
            return;
        }

        self::$notice_rendered[$product_id] = true;
        echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function get_notice_html($product_id) {
        $message = self::get_notice_message($product_id);
        if ('' === $message) {
            return '';
        }

        $status = self::get_status($product_id);
        $classes = 'pbsr-product-availability-notice pbsr-product-availability-notice--' . $status;

        return '<div class="' . esc_attr($classes) . '" role="status">' . esc_html($message) . '</div>';
    }

    private static function should_render_on_current_product() {
        if (!is_singular(['product', 'products'])) {
            return false;
        }

        $post_type = get_post_type();

        return in_array($post_type, ['product', 'products'], true);
    }
}

PBSR_Product_Availability::init();
