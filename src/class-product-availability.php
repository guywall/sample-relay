<?php
if (!defined('ABSPATH')) exit;

class PBSR_Product_Availability {

    const META_KEY = '_pbsr_sample_unavailable';
    const NONCE_ACTION = 'pbsr_sample_availability_save';
    const NONCE_NAME = 'pbsr_sample_availability_nonce';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
        add_action('save_post', [__CLASS__, 'save_meta_box']);
    }

    public static function register_meta_box() {
        foreach (['product', 'products'] as $post_type) {
            if (!post_type_exists($post_type)) {
                continue;
            }

            add_meta_box(
                'pbsr-sample-availability',
                'Sample Availability',
                [__CLASS__, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $is_unavailable = get_post_meta($post->ID, self::META_KEY, true) === '1';
        ?>
        <p>
            <label>
                <input type="checkbox" name="pbsr_sample_unavailable" value="1" <?php checked($is_unavailable); ?> />
                Mark this sample as unavailable (hide from sample request form)
            </label>
        </p>
        <?php
    }

    public static function save_meta_box($post_id) {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $post_type = get_post_type($post_id);
        if (!in_array($post_type, ['product', 'products'], true)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $is_unavailable = !empty($_POST['pbsr_sample_unavailable']) ? '1' : '0';
        update_post_meta($post_id, self::META_KEY, $is_unavailable);
    }

    public static function is_unavailable($product_id) {
        return get_post_meta($product_id, self::META_KEY, true) === '1';
    }
}

PBSR_Product_Availability::init();
