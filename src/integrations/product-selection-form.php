<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Product_Selection_Form {
    const AJAX_ACTION = 'pb_submit_samples';

    public static function init() {
        add_action('init', [__CLASS__, 'register_hooks'], 999);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function register_hooks() {
        remove_shortcode('product_selection_form');
        remove_shortcode('permabound_sample_request');

        add_shortcode('product_selection_form', [__CLASS__, 'render_shortcode']);
        add_shortcode('permabound_sample_request', [__CLASS__, 'render_shortcode']);

        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
    }

    private static function get_product_thumbnail_url($product_id) {
        $thumbnail_id = get_post_thumbnail_id($product_id);
        if (!$thumbnail_id) {
            return '';
        }

        $url = self::get_attachment_image_url_from_metadata($thumbnail_id, [
            'medium',
            'woocommerce_thumbnail',
            'medium_large',
            'thumbnail',
        ]);

        if ($url) {
            return $url;
        }

        foreach (['medium', 'woocommerce_thumbnail', 'medium_large', 'thumbnail', 'full'] as $size) {
            $candidate = (string) wp_get_attachment_image_url($thumbnail_id, $size);
            if ($candidate && !self::is_elementor_thumb_url($candidate)) {
                return $candidate;
            }
        }

        return (string) wp_get_attachment_url($thumbnail_id);
    }

    private static function get_attachment_image_url_from_metadata($attachment_id, array $preferred_sizes) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($meta) || empty($meta['file'])) {
            return '';
        }

        $base_dir = wp_normalize_path((string) dirname($meta['file']));
        if ('.' === $base_dir) {
            $base_dir = '';
        }

        $sizes = !empty($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : [];
        foreach ($preferred_sizes as $size) {
            if (empty($sizes[$size]['file'])) {
                continue;
            }

            return self::build_upload_url($base_dir, (string) $sizes[$size]['file']);
        }

        return self::build_upload_url('', (string) $meta['file']);
    }

    private static function build_upload_url($dir, $file) {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl'])) {
            return '';
        }

        $parts = [];
        foreach ([$dir, $file] as $segment) {
            $segment = trim((string) wp_normalize_path($segment), '/');
            if ('' !== $segment) {
                $parts[] = $segment;
            }
        }

        if (!$parts) {
            return '';
        }

        $encoded = [];
        foreach (explode('/', implode('/', $parts)) as $segment) {
            $encoded[] = rawurlencode($segment);
        }

        return trailingslashit((string) $uploads['baseurl']) . implode('/', $encoded);
    }

    private static function is_elementor_thumb_url($url) {
        return false !== strpos((string) $url, '/elementor/thumbs/');
    }

    private static function form_fields() {
        $settings = PBSR_Settings::get();

        return PBSR_Settings::normalize_form_fields($settings['form_fields'] ?? []);
    }

    private static function field_is_required(array $fields, $key) {
        return !empty($fields[$key]['required']);
    }

    private static function field_width_class(array $fields, $key) {
        $width = $fields[$key]['width'] ?? 'half';

        return 'full' === $width ? 'pb-field--full' : 'pb-field--half';
    }

    private static function field_class(array $fields, $key, $extra = '') {
        $classes = trim('pb-field ' . self::field_width_class($fields, $key) . ' ' . $extra);

        return esc_attr($classes);
    }

    private static function field_required_attr(array $fields, $key) {
        return self::field_is_required($fields, $key) ? ' required data-pb-required="1"' : ' data-pb-required="0"';
    }

    private static function fieldset_required_attrs(array $fields, $key) {
        return self::field_is_required($fields, $key) ? ' data-pb-required="1"' : ' data-pb-required="0"';
    }

    public static function render_shortcode($atts) {
        $atts = shortcode_atts([
            'categories' => 'resin-bound-stone-blends,rubber-mulch,soft-gravel,colourbound',
            'max' => 4,
        ], $atts);

        $nonce = wp_create_nonce('pbsamples_nonce');
        $cats = array_filter(array_map('trim', explode(',', $atts['categories'])));
        $max = (int) $atts['max'];
        $auto_check = '';
        $form_fields = self::form_fields();
        $settings = PBSR_Settings::get();

        if (is_singular('product')) {
            $auto_check = get_the_title(get_the_ID());
        }

        ob_start();
        ?>
        <form id="pb-samples-form" class="pb-form" novalidate>
            <input type="hidden" name="action" value="pb_submit_samples">
            <input type="hidden" name="pbsamples_nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" id="pb-max" value="<?php echo esc_attr($max); ?>">
            <input type="hidden" name="max" value="<?php echo esc_attr($max); ?>">
            <input type="text" name="website" class="hp" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;">
            <input type="hidden" name="page_url" value="">
            <input type="hidden" name="referrer" value="">
            <input type="hidden" name="current_product" value="<?php echo esc_attr($auto_check); ?>">
            <input type="hidden" name="pbsr_enable" value="1">

            <section class="pb-step" data-step="1">
                <h3>Select up to <span id="pb-max-count"><?php echo esc_html($max); ?></span> samples</h3>
                <p class="pb-intro">Choose up to four blends you&rsquo;d like to receive as samples. Once you&rsquo;ve made your selections, click &lsquo;Next&rsquo; to enter your delivery details.</p>

                <div id="pb-sticky-bar" class="pb-sticky-bar">
                    <div class="pb-sticky-inner">
                        <div id="pb-picked-top" class="pb-picked-top" aria-live="polite"></div>
                        <div class="pb-sticky-meta">
                            <div id="pb-step-indicator" class="pb-step-indicator">0/<?php echo esc_html($max); ?> selected</div>
                            <button type="button" class="button button-primary pb-next-top" data-next>Next</button>
                        </div>
                    </div>
                </div>

                <div class="pb-filter">
                    <input type="search" id="pb-search" placeholder="Search products...">
                    <?php
                    $types = get_terms(['taxonomy' => 'product_type', 'hide_empty' => false]);
                    if (!is_wp_error($types) && $types) {
                        echo '<select id="pb-type"><option value="">All types</option>';
                        foreach ($types as $type) {
                            echo '<option value="' . esc_attr($type->slug) . '">' . esc_html($type->name) . '</option>';
                        }
                        echo '</select>';
                    }
                    ?>
                </div>

                <?php
                $hidden = PBSR_Mapper::parseHiddenSamples($settings['hidden_samples'] ?? '');

                foreach ($cats as $slug) {
                    $term = get_term_by('slug', $slug, 'product_category');
                    if (!$term || is_wp_error($term)) {
                        continue;
                    }

                    $query = new WP_Query([
                        'post_type' => ['product', 'products'],
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'tax_query' => [[
                            'taxonomy' => 'product_category',
                            'field' => 'slug',
                            'terms' => $slug,
                        ]],
                        'fields' => 'ids',
                    ]);

                    if (!$query->have_posts()) {
                        continue;
                    }

                    $open = $slug === 'resin-bound-stone-blends' ? ' open' : '';
                    echo '<details class="pb-accordion"' . $open . '>';
                    echo '<summary class="pb-cat-title" data-cat="' . esc_attr($slug) . '">' . esc_html($term->name) . '</summary>';
                    echo '<div class="pb-products" data-cat="' . esc_attr($slug) . '">';

                    foreach ($query->posts as $product_id) {
                        if (class_exists('PBSR_Product_Availability') && PBSR_Product_Availability::is_unavailable($product_id)) {
                            continue;
                        }

                        $name = get_the_title($product_id);
                        $thumb = self::get_product_thumbnail_url($product_id);
                        $sku = self::get_product_sku($product_id);

                        if (PBSR_Mapper::isSampleHidden($name, $sku, $hidden)) {
                            continue;
                        }

                        $ptype = wp_get_post_terms($product_id, 'product_type', ['fields' => 'slugs']);
                        $ptype_class = (!is_wp_error($ptype) && $ptype) ? implode(' ', $ptype) : '';
                        $checked = ($auto_check && $auto_check === $name) ? 'checked' : '';
                        ?>
                        <label class="pb-card <?php echo esc_attr($ptype_class); ?>">
                            <input
                                type="checkbox"
                                class="pb-choice"
                                name="product_selection[]"
                                value="<?php echo esc_attr($name); ?>"
                                data-name="<?php echo esc_attr($name); ?>"
                                data-sku="<?php echo esc_attr($sku); ?>"
                                data-thumb="<?php echo esc_url($thumb ?: ''); ?>"
                                <?php echo $checked; ?>
                            >
                            <?php if ($thumb): ?>
                                <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($name); ?>">
                            <?php endif; ?>
                            <span class="pb-name"><?php echo esc_html($name); ?></span>
                        </label>
                        <?php
                    }

                    echo '</div></details>';
                    wp_reset_postdata();
                }
                ?>
            </section>

            <section class="pb-step" data-step="2" hidden>
                <h3>Your details</h3>

                <div class="pb-error-banner" role="alert" aria-live="polite"></div>

                <div class="pb-grid">
                    <div class="<?php echo self::field_class($form_fields, 'first_name'); ?>">
                        <label for="pb-first">First name</label>
                        <input id="pb-first" name="first_name" type="text"<?php echo self::field_required_attr($form_fields, 'first_name'); ?>>
                    </div>
                    <div class="<?php echo self::field_class($form_fields, 'surname'); ?>">
                        <label for="pb-last">Surname</label>
                        <input id="pb-last" name="surname" type="text"<?php echo self::field_required_attr($form_fields, 'surname'); ?>>
                    </div>
                </div>
                <div class="pb-grid">
                    <div class="<?php echo self::field_class($form_fields, 'email'); ?>">
                        <label for="pb-email">Email</label>
                        <input id="pb-email" name="email" type="email"<?php echo self::field_required_attr($form_fields, 'email'); ?>>
                    </div>
                    <div class="<?php echo self::field_class($form_fields, 'phone'); ?>">
                        <label for="pb-phone">Phone</label>
                        <input id="pb-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"<?php echo self::field_required_attr($form_fields, 'phone'); ?>>
                    </div>
                </div>

                <div class="pb-grid pb-project-grid">
                    <div class="<?php echo self::field_class($form_fields, 'enquiry_type'); ?>">
                        <label for="pb-enquiry">Enquiry type</label>
                        <select id="pb-enquiry" name="enquiry_type"<?php echo self::field_required_attr($form_fields, 'enquiry_type'); ?>>
                            <option value="">Please select</option>
                            <option value="homeowner">Homeowner</option>
                            <option value="contractor_installer">Contractor/Installer</option>
                            <option value="merchant_reseller">Merchant/Reseller</option>
                            <option value="local_authority">Local Authority</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="<?php echo self::field_class($form_fields, 'organisation_name'); ?>" id="pb-org-wrap" style="display:none;">
                        <label for="pb-org">Organisation name</label>
                        <input id="pb-org" name="organisation_name" type="text"<?php echo self::field_required_attr($form_fields, 'organisation_name'); ?>>
                    </div>
                </div>

                <div class="pb-grid">
                    <fieldset class="<?php echo self::field_class($form_fields, 'project_type', 'pb-project-type'); ?>"<?php echo self::fieldset_required_attrs($form_fields, 'project_type'); ?>>
                        <legend>Project Type</legend>
                        <div class="pb-check-group">
                            <label class="pb-check-option" for="pb-project-type-path-patio">
                                <input id="pb-project-type-path-patio" type="checkbox" name="project_type[]" value="Path/Patio">
                                <span>Path/Patio</span>
                            </label>
                            <label class="pb-check-option" for="pb-project-type-driveway">
                                <input id="pb-project-type-driveway" type="checkbox" name="project_type[]" value="Driveway">
                                <span>Driveway</span>
                            </label>
                            <label class="pb-check-option" for="pb-project-type-other">
                                <input id="pb-project-type-other" type="checkbox" name="project_type[]" value="Other">
                                <span>Other</span>
                            </label>
                        </div>
                        <input type="hidden" name="project_type_serialized" value="">
                    </fieldset>
                    <div class="<?php echo self::field_class($form_fields, 'project_size_m2'); ?>">
                        <label for="pb-project-size">Project Size in m&sup2;</label>
                        <input id="pb-project-size" name="project_size_m2" type="number" inputmode="numeric" min="0" step="1"<?php echo self::field_required_attr($form_fields, 'project_size_m2'); ?>>
                        <input type="hidden" name="project_size_value" value="">
                    </div>
                </div>

                <fieldset class="pb-address">
                    <legend>Shipping address</legend>
                    <div class="pb-address-fields">
                        <div class="<?php echo self::field_class($form_fields, 'street'); ?>">
                            <label for="pb-street">Street</label>
                            <input id="pb-street" name="street" type="text"<?php echo self::field_required_attr($form_fields, 'street'); ?>>
                        </div>
                        <div class="<?php echo self::field_class($form_fields, 'address_2'); ?>">
                            <label for="pb-address2">Address 2</label>
                            <input id="pb-address2" name="address_2" type="text"<?php echo self::field_required_attr($form_fields, 'address_2'); ?>>
                        </div>
                        <div class="<?php echo self::field_class($form_fields, 'city'); ?>">
                            <label for="pb-city">Town/City</label>
                            <input id="pb-city" name="city" type="text"<?php echo self::field_required_attr($form_fields, 'city'); ?>>
                        </div>
                        <div class="<?php echo self::field_class($form_fields, 'county'); ?>">
                            <label for="pb-county">County</label>
                            <input id="pb-county" name="county" type="text"<?php echo self::field_required_attr($form_fields, 'county'); ?>>
                        </div>
                        <div class="<?php echo self::field_class($form_fields, 'country'); ?>">
                            <label for="pb-country">Country</label>
                            <input id="pb-country" name="country" type="text" value="United Kingdom"<?php echo self::field_required_attr($form_fields, 'country'); ?>>
                        </div>
                        <div class="<?php echo self::field_class($form_fields, 'postcode'); ?>">
                            <label for="pb-postcode">Postcode</label>
                            <input id="pb-postcode" name="postcode" type="text"<?php echo self::field_required_attr($form_fields, 'postcode'); ?>>
                        </div>
                    </div>
                </fieldset>

                <div class="pb-grid">
                    <div class="<?php echo self::field_class($form_fields, 'gdpr_consent', 'pb-consent'); ?>">
                        <label>
                            <input type="checkbox" name="gdpr_consent" id="pb-gdpr"<?php echo self::field_required_attr($form_fields, 'gdpr_consent'); ?>>
                            I agree to be contacted about my sample request and understand how my data will be used.
                        </label>
                    </div>
                </div>

                <div class="pb-nav">
                    <button type="button" class="button" data-prev>Previous</button>
                    <button type="button" class="button button-primary" data-next>Next</button>
                </div>
            </section>

            <section class="pb-step" data-step="3" hidden>
                <h3>Review and submit</h3>
                <div id="pb-review" class="pb-review"></div>
                <div id="pb-review-grid" class="pb-review-grid" aria-live="polite"></div>
                <div id="pb-status" class="pb-status" aria-live="polite"></div>
                <div class="pb-nav">
                    <button type="button" class="button" data-prev>Previous</button>
                    <button type="button" class="button button-primary" id="pb-submit">Submit</button>
                    <button type="button" class="button" id="pb-finish" hidden>Close</button>
                </div>
            </section>
        </form>
        <?php

        return ob_get_clean();
    }

    public static function enqueue_assets() {
        $settings = PBSR_Settings::get();

        $css = <<<'CSS'
.pb-no-postcode{margin-top:4px;color:#555}
.pb-no-postcode input[type="checkbox"]{accent-color:var(--pb-brand,#ff9f23)}
@import url("https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700&display=swap");
#pb-samples-form{
    --pb-brand:#ff9f23;
    --pb-brand-700:#e68f20;
    --pb-ring:rgba(255,159,35,.28);
    --pb-border:#cfd3da;
    --pb-border-soft:#e5e7eb;
    --pb-error:#c62828;
    --pb-error-bg:#fff0f0;
    --pb-error-ring:rgba(198,40,40,.18);
    --pb-shadow:0 6px 20px rgba(0,0,0,.08);
    font-family:"Montserrat",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    font-size:1em;
    font-weight:400;
}
#pb-samples-form *{box-sizing:border-box}
.pb-form{max-width:880px;margin:0 auto;color:#4e4e4e}
.pb-step{background:#fff;animation:pbFadeIn .2s ease both}
@keyframes pbFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.pb-step>h3{font-size:2em;font-weight:700;text-transform:uppercase;letter-spacing:.02em;margin:0 0 .25em 0}
.pb-intro{margin:-.25em 0 1em 0;color:#4e4e4e}
.pb-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;row-gap:20px}
.pb-address-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px;row-gap:20px}
.pb-field--full{grid-column:1/-1}
.pb-field--half{grid-column:auto}
.pb-field{margin-bottom:8px}
.pb-field label{display:block;margin-bottom:6px;font-weight:700}
.pb-field input,.pb-field select,fieldset.pb-address input,.pb-filter input,.pb-filter select{
    width:100%;
    padding:10px;
    border:1px solid var(--pb-border)!important;
    border-radius:10px;
    background:#fff!important;
    box-shadow:inset 0 1px 2px rgba(0,0,0,.02);
    transition:border-color .15s,box-shadow .15s;
}
.pb-field input:focus,.pb-field select:focus,fieldset.pb-address input:focus,.pb-filter input:focus,.pb-filter select:focus{
    border-color:var(--pb-brand)!important;
    box-shadow:0 0 0 3px var(--pb-ring)!important;
    outline:0;
}
fieldset.pb-address{border:1px solid var(--pb-border-soft);padding:16px;border-radius:12px;margin-top:18px}
fieldset.pb-address legend{padding:0 6px;color:#4e4e4e;font-weight:700}
.pb-error-banner{display:none;padding:10px 12px;border:1px solid var(--pb-error);background:var(--pb-error-bg);color:#7f1d1d;border-radius:10px;margin:6px 0 12px}
.pb-error-banner.show{display:block}
.is-invalid{border-color:var(--pb-error)!important;box-shadow:0 0 0 3px var(--pb-error-ring)!important;background:#fff7f7!important}
input[type="checkbox"].is-invalid{outline:2px solid var(--pb-error);outline-offset:3px}
.pb-nav{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
.pb-form .button{border-radius:10px;padding:10px 14px;border:1px solid var(--pb-border);background:#fafbfc;transition:transform .05s,background .15s}
.pb-form .button:hover{background:#f3f4f6}
.pb-form .button:active{transform:scale(.99)}
.pb-form .button.button-primary{background:var(--pb-brand)!important;border-color:var(--pb-brand)!important;color:#111!important;font-weight:700;box-shadow:0 1px 0 rgba(0,0,0,.06)}
.pb-form .button.button-primary:hover{background:var(--pb-brand-700)!important;border-color:var(--pb-brand-700)!important}
.pb-form input[type="checkbox"]{accent-color:var(--pb-brand)}
#pb-samples-form .pb-products{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}
.pb-card{display:flex;flex-direction:column;align-items:center;gap:8px;border:1px solid var(--pb-border-soft);border-radius:14px;padding:12px;cursor:pointer;position:relative;transition:border-color .15s,box-shadow .15s,transform .05s,background .15s;background:#fff;text-align:center}
.pb-card input{position:absolute;opacity:0;pointer-events:none}
.pb-card img{width:120px;height:auto;border-radius:10px}
.pb-card:hover{box-shadow:0 1px 0 0 rgba(0,0,0,.06)}
.pb-card:active{transform:scale(.99)}
.pb-card.is-selected{border-color:var(--pb-brand);box-shadow:0 0 0 2px var(--pb-ring)}
.pb-card.is-selected .pb-name{font-weight:700}
.pb-card.is-selected::after{content:"\2713";position:absolute;top:8px;right:8px;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--pb-brand);color:#4e4e4e;font-weight:800;font-size:14px;line-height:1}
.pb-cat-title{margin:8px 0;font-size:1.1rem;list-style:none}
.pb-accordion{border-top:1px solid var(--pb-border-soft);padding:8px 0}
.pb-accordion summary{cursor:pointer;padding:8px 0;font-weight:800;display:flex;align-items:center;gap:8px;transition:color .15s}
.pb-accordion summary::marker,.pb-accordion summary::-webkit-details-marker{display:none}
.pb-accordion summary:before{content:"\25b8";display:inline-block;transform:rotate(0deg);transition:transform .15s}
.pb-accordion[open] summary:before{transform:rotate(90deg)}
.pb-accordion[open] summary{color:var(--pb-brand)}
.pb-accordion>.pb-products{animation:pbAccordion .18s ease both}
@keyframes pbAccordion{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.pb-filter{display:flex;gap:10px;margin:10px 0}
.pb-chip{padding:6px 14px 6px 10px;border:1px solid var(--pb-border-soft);border-radius:9999px;background:#f6f7f8;font-size:1rem;display:inline-flex;align-items:center;gap:10px;transition:background .15s,border-color .15s;line-height:1}
.pb-chip img{width:34px;height:34px;flex:0 0 34px;aspect-ratio:1/1;border-radius:50%!important;object-fit:cover;display:block;border:1px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.05)}
.pb-chip .remove{border:none;background:none;cursor:pointer;margin-left:2px;font-weight:700;line-height:1;color:#c2185b}
.pb-chip:hover{background:#eef0f2;border-color:#d7dbe2}
#pb-sticky-bar{position:sticky;top:0;z-index:40;background:#fff;border-bottom:1px solid var(--pb-border-soft);padding:10px 0 8px;transition:box-shadow .2s}
#pb-sticky-bar.pb-shadow{box-shadow:var(--pb-shadow)}
.pb-sticky-inner{display:flex;flex-direction:column;gap:8px}
.pb-picked-top{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:2px}
.pb-sticky-meta{display:flex;justify-content:space-between;align-items:center;gap:10px}
.pb-step-indicator{font-weight:800;font-size:.95rem}
.pb-next-top{padding:6px 14px;font-weight:700;border-radius:8px}
#pb-samples-form .pb-review-grid{margin:14px 0 4px 0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.pb-review-tile{position:relative;border-radius:12px;overflow:hidden;border:1px solid var(--pb-border-soft);background:#fafafa;box-shadow:inset 0 1px 2px rgba(0,0,0,.02);aspect-ratio:1/1;display:flex;align-items:flex-end;justify-content:flex-start}
.pb-review-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.pb-review-name{position:relative;color:#fff;font-weight:800;font-size:.95rem;padding:8px;z-index:1;letter-spacing:.01em}
.pb-step[hidden]{display:none!important}
.pb-status .ok{color:#4e4e4e}
.pb-status .warn{color:#9a3412}
.pb-status .err{color:#9f2f2f}
.hp{display:none!important}
.pb-review p{margin:.3rem 0}
#pb-samples-form .pb-project-grid{align-items:start}
#pb-samples-form .pb-project-type{margin:0;padding:0;border:0;min-inline-size:0}
#pb-samples-form .pb-project-type legend{margin:0 0 6px;padding:0;font-weight:700;color:#4e4e4e}
#pb-samples-form .pb-check-group{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#pb-samples-form .pb-check-option{display:flex!important;align-items:center!important;gap:10px;padding:10px 12px;margin:0;border:1px solid var(--pb-border)!important;border-radius:10px;background:#fff;cursor:pointer;transition:border-color .15s,box-shadow .15s,background .15s}
#pb-samples-form .pb-check-option.is-selected{border-color:var(--pb-brand)!important;box-shadow:0 0 0 3px var(--pb-ring)!important;background:#fffaf3}
#pb-samples-form .pb-project-type.is-invalid .pb-check-option{border-color:var(--pb-error)!important;box-shadow:0 0 0 3px var(--pb-error-ring)!important}
#pb-samples-form .pb-check-option:hover{border-color:var(--pb-brand)!important}
#pb-samples-form .pb-check-option input[type="checkbox"]{width:auto!important;min-width:16px;max-width:16px;height:16px;margin:0!important;flex:0 0 16px}
#pb-samples-form .pb-check-option span{display:block;flex:1 1 auto;font-weight:700}
#pb-samples-form .pb-consent{margin-top:18px}
@media (max-width:1100px){
    #pb-samples-form .pb-products{grid-template-columns:repeat(3,minmax(0,1fr))}
    #pb-samples-form .pb-review-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media (max-width:820px){
    #pb-samples-form .pb-products{grid-template-columns:repeat(2,minmax(0,1fr))}
    #pb-samples-form .pb-review-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:640px){
    .pb-grid{grid-template-columns:1fr}
    .pb-address-fields{grid-template-columns:1fr}
    .pb-field--half,.pb-field--full{grid-column:1/-1}
    .pb-filter{flex-direction:column}
    #pb-samples-form .pb-products{grid-template-columns:1fr}
    #pb-samples-form .pb-review-grid{grid-template-columns:1fr}
    .pb-sticky-inner{gap:6px}
    .pb-sticky-meta{flex-direction:column;align-items:flex-start}
    .pb-next-top{align-self:flex-end}
    #pb-samples-form .pb-check-group{grid-template-columns:1fr}
}
CSS;
        wp_register_style('pb-samples', false);
        wp_add_inline_style('pb-samples', $css);
        wp_enqueue_style('pb-samples');

        wp_register_script('pb-samples-js', '', [], PBSR_VER, true);
        wp_enqueue_script('pb-samples-js');
        wp_localize_script('pb-samples-js', 'PBSAMPLES', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'google_places_api_key' => (string) ($settings['google_places_api_key'] ?? ''),
        ]);

    $js = '
    (function(){
        // --- Utilities ---
        function esc(s){
            var str = String(s||"");
            var map = {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"};
            return str.replace(/[&<>"]/g,function(ch){return map[ch];}).replace(/\\x27/g,"&#39;");
        }
        function steps(form){ return form.querySelectorAll(".pb-step"); }
        function currentStepIndex(form){
            var ss = steps(form);
            for (var i=0;i<ss.length;i++){ if(!ss[i].hasAttribute("hidden")) return i; }
            return 0;
        }
        function showStep(form, i){
            var ss = steps(form);
            for (var k=0;k<ss.length;k++){ ss[k].hidden = (k!==i); }
            if (i===2){ buildReview(form); renderReviewGrid(form); }
            try{ window.scrollTo({top: form.getBoundingClientRect().top + window.scrollY - 40, behavior:"smooth"});}catch(e){}
        }

        // Maintain a hidden bar for internal mapping (not shown)
        function ensurePickedBar(form){
            var bar = form.querySelector(".pb-picked");
            if (!bar){
                var after = form.querySelector(".pb-count");
                bar = document.createElement("div");
                bar.className = "pb-picked";
                if (after && after.parentNode){
                    after.parentNode.insertBefore(bar, after.nextSibling);
                } else {
                    var s1 = form.querySelector(\'[data-step="1"]\');
                    if (s1) s1.appendChild(bar);
                }
            }
            return bar;
        }

        function setContext(form){
            var page = form.querySelector(\'input[name="page_url"]\');
            var ref = form.querySelector(\'input[name="referrer"]\');
            var attr = {};
            if (typeof tracking === "function") {
                try {
                    attr = tracking() || {};
                } catch (e) {
                    attr = {};
                }
            }
            if (page) {
                page.value = window.location.href;
            }
            if (ref) {
                ref.value = attr.referrer || document.referrer || "";
            }
        }

        function syncProjectFields(form){
            var projectTypes = Array.prototype.map.call(
                form.querySelectorAll(\'input[name="project_type[]"]:checked\'),
                function(input){ return input.value || ""; }
            ).filter(Boolean);
            var projectSize = (form.querySelector("#pb-project-size") || {}).value || "";
            var projectTypeHidden = form.querySelector(\'input[name="project_type_serialized"]\');
            var projectSizeHidden = form.querySelector(\'input[name="project_size_value"]\');

            if (projectTypeHidden) {
                projectTypeHidden.value = projectTypes.join(", ");
            }
            if (projectSizeHidden) {
                projectSizeHidden.value = projectSize;
            }

            Array.prototype.forEach.call(form.querySelectorAll(".pb-check-option"), function(label){
                var checkbox = label.querySelector(\'input[type="checkbox"]\');
                label.classList.toggle("is-selected", !!(checkbox && checkbox.checked));
            });
        }

        function updateSelectedVisual(form){
            var cards = form.querySelectorAll(".pb-card");
            for (var i=0;i<cards.length;i++){
                var cb = cards[i].querySelector(".pb-choice");
                if (cb && cb.checked){ cards[i].classList.add("is-selected"); }
                else { cards[i].classList.remove("is-selected"); }
            }
            syncProjectFields(form);
        }

        // Bottom (hidden) chips list stays for internal state
        function updateSelectedList(form){
            var bar = ensurePickedBar(form);
            var sels = Array.prototype.map.call(
                form.querySelectorAll(".pb-choice:checked"),
                function(c){
                    return {
                        name:c.getAttribute("data-name")||c.value,
                        sku:c.getAttribute("data-sku")||"",
                        thumb:c.getAttribute("data-thumb")||""
                    };
                }
            );
            if (!sels.length){ bar.innerHTML = ""; return; }
            var html = sels.map(function(o){
                var img = o.thumb ? \'<img src="\' + esc(o.thumb) + \'" alt="">\' : "";
                return \'<span class="pb-chip" data-value="\' + esc(o.name) + \'">\' +
                       img + esc(o.name) +
                       \' <button type="button" class="remove" aria-label="Remove \' + esc(o.name) + \'">&times;</button></span>\';
            }).join("");
            bar.innerHTML = html;
        }

        function updateCount(form){
            var countEl = form.querySelector("#pb-count");
            var selected = form.querySelectorAll(".pb-choice:checked");
            if (countEl) countEl.textContent = String(selected.length);
        }
        function selectedCount(form){ return form.querySelectorAll(".pb-choice:checked").length; }
        function maxAllowed(form){
            var maxEl = form.querySelector("#pb-max");
            return parseInt(maxEl ? (maxEl.value||"4") : "4", 10);
        }

        function filterProducts(form){
            var qEl = form.querySelector("#pb-search");
            var tEl = form.querySelector("#pb-type");
            var q = (qEl && qEl.value || "").toLowerCase();
            var t = tEl ? tEl.value : "";
            var cards = form.querySelectorAll(".pb-card");
            for (var i=0;i<cards.length;i++){
                var card = cards[i];
                var nameEl = card.querySelector(".pb-name");
                var name = nameEl ? nameEl.textContent.toLowerCase() : "";
                var classes = card.className || "";
                var ok = (!q || name.indexOf(q)!==-1) && (!t || classes.indexOf(t)!==-1);
                card.style.display = ok ? "" : "none";
            }
            updateSelectedVisual(form);
        }

        // Review text summary (unchanged info but selections no longer comma-only)
        function buildReview(form){
            var out = [];
            function get(id){ var el=form.querySelector("#"+id); return el && el.value ? el.value : ""; }
            function getCheckedValues(name){
                return Array.prototype.map.call(
                    form.querySelectorAll(\'input[name="\' + name + \'"]:checked\'),
                    function(input){ return input.value || ""; }
                ).filter(Boolean);
            }
            var sels = Array.prototype.map.call(
                form.querySelectorAll(".pb-choice:checked"),
                function(c){ return c.getAttribute("data-name")||c.value; }
            );
            out.push("<p><strong>Name:</strong> " + esc(get("pb-first")) + " " + esc(get("pb-last")) + "</p>");
            out.push("<p><strong>Email:</strong> " + esc(get("pb-email")) + "</p>");
            out.push("<p><strong>Phone:</strong> " + esc(get("pb-phone")) + "</p>");
            var enquEl = form.querySelector("#pb-enquiry");
            var enquText = enquEl && enquEl.selectedOptions && enquEl.selectedOptions[0] ? enquEl.selectedOptions[0].textContent : (enquEl ? enquEl.value : "");
            out.push("<p><strong>Enquiry type:</strong> " + esc(enquText) + "</p>");
            var orgEl = form.querySelector("#pb-org");
            if (orgEl && orgEl.value) out.push("<p><strong>Organisation:</strong> " + esc(orgEl.value) + "</p>");
            var projectTypes = getCheckedValues("project_type[]");
            if (projectTypes.length) out.push("<p><strong>Project type:</strong> " + esc(projectTypes.join(", ")) + "</p>");
            if (get("pb-project-size")) out.push("<p><strong>Project size:</strong> " + esc(get("pb-project-size")) + " m&sup2;</p>");
            out.push("<p><strong>Address:</strong> " + esc(get("pb-street")) + (get("pb-address2") ? ", " + esc(get("pb-address2")) : "") + ", " + esc(get("pb-city")) + ", " + esc(get("pb-county")) + ", " + esc(get("pb-country")) + " " + esc(get("pb-postcode")) + "</p>");
            // Keep a simple list line as well
            out.push("<p><strong>Selected products:</strong> " + esc(sels.join(", ")) + "</p>");
            var review = form.querySelector("#pb-review");
            if (review) review.innerHTML = out.join("");
        }

        // Review grid (4 cols desktop/tablet, 2 cols mobile)
        function renderReviewGrid(form){
            var grid = form.querySelector("#pb-review-grid");
            if(!grid) return;
            var items = Array.prototype.map.call(
                form.querySelectorAll(".pb-choice:checked"),
                function(c){
                    return {
                        name: c.getAttribute("data-name")||c.value,
                        thumb: c.getAttribute("data-thumb")||""
                    };
                }
            );
            if(!items.length){ grid.innerHTML = ""; return; }
            grid.innerHTML = items.map(function(it){
                var img = it.thumb ? \'<img src="\' + esc(it.thumb) + \'" alt="\' + esc(it.name) + \'">\'
                                   : \'<div style="position:absolute;inset:0;background:#eee"></div>\';
                return \'<div class="pb-review-tile">\' + img + \'<div class="pb-review-name">\' + esc(it.name) + \'</div></div>\';
            }).join("");
        }

        // Sticky bar chips + indicator (with thumbs)
        function updateTopBar(form){
            var topWrap = form.querySelector("#pb-picked-top");
            var indicator = form.querySelector("#pb-step-indicator");
            if(!topWrap || !indicator) return;

            var sels = Array.prototype.map.call(
                form.querySelectorAll(".pb-choice:checked"),
                function(c){ return { name:c.getAttribute("data-name")||c.value, thumb:c.getAttribute("data-thumb")||"" }; }
            );
            if (!sels.length){
                topWrap.innerHTML = "";
            } else {
                topWrap.innerHTML = sels.map(function(o){
                    var img = o.thumb ? \'<img src="\' + esc(o.thumb) + \'" alt="">\' : "";
                    return \'<span class="pb-chip" data-value="\' + esc(o.name) + \'">\' + img + esc(o.name) + \'<button type="button" class="remove" aria-label="Remove \' + esc(o.name) + \'">&times;</button></span>\';
                }).join("");
            }
            var max = maxAllowed(form);
            var sel = selectedCount(form);
            indicator.textContent = sel + "/" + max + " selected";
        }

        // Sticky shadow when bar reaches top
        (function(){
            window.addEventListener("scroll", ()=>{
                const bar = document.querySelector("#pb-sticky-bar");
                if(!bar) return;
                const rect = bar.getBoundingClientRect();
                if(rect.top <= 0){ bar.classList.add("pb-shadow"); }
                else{ bar.classList.remove("pb-shadow"); }
            }, {passive:true});
        })();

        // Error helpers (for details step)
        function banner(form){ return form.querySelector(".pb-error-banner"); }
        function clearErrors(form){
            var bad = form.querySelectorAll(".is-invalid");
            for (var i=0;i<bad.length;i++){ bad[i].classList.remove("is-invalid"); bad[i].removeAttribute("aria-invalid"); }
            var b = banner(form);
            if (b){ b.classList.remove("show"); b.textContent=""; }
        }
        function showError(form, fields){
            var b = banner(form);
            if (b){ b.textContent = "Please check the highlighted fields."; b.classList.add("show"); }
            if (fields && fields.length){ try{ fields[0].focus(); }catch(e){} }
        }

        function initForm(form){
            if (!form || form.dataset.pbInit) return;
            form.dataset.pbInit = "1";
            var maxEl   = form.querySelector("#pb-max");
            var max = parseInt(maxEl ? (maxEl.value||"4") : "4", 10);
            var maxCount = form.querySelector("#pb-max-count");
            var pbLimit = form.querySelector("#pb-limit");
            if (maxCount) maxCount.textContent = String(max);
            if (pbLimit) pbLimit.textContent = String(max);

            // Start on Step 1 (selection)
            showStep(form, 0);
            setContext(form);
            toggleOrg(form);
            updateCount(form);
            updateSelectedVisual(form);
            updateSelectedList(form);
            updateTopBar(form);
        }
        function scan(){ var forms = document.querySelectorAll("#pb-samples-form"); for (var i=0;i<forms.length;i++) initForm(forms[i]); }

        function toggleOrg(form){
            var enquiry = form.querySelector("#pb-enquiry");
            var wrap = form.querySelector("#pb-org-wrap");
            var v = enquiry ? enquiry.value : "";
            if (wrap){ wrap.style.display = (v && v !== "homeowner") ? "block" : "none"; }
        }

        function validateStep1(form){ // used for details step (index 1)
            clearErrors(form);
            var invalid = [];
            var noPostcodeCheck = form.querySelector("#pb-no-postcode-check");
            var skipPostcode = noPostcodeCheck && noPostcodeCheck.checked;

            function isHidden(el){
                for (var node = el; node && node !== form; node = node.parentElement){
                    if (node.hidden || node.style.display === "none"){ return true; }
                }
                return false;
            }

            function addInvalid(el){
                if (el && invalid.indexOf(el) === -1){ invalid.push(el); }
            }

            Array.prototype.forEach.call(form.querySelectorAll(\'[data-pb-required="1"]\'), function(el){
                if (!el || el.disabled || isHidden(el)){ return; }
                if (skipPostcode && el.id === "pb-postcode"){ return; }

                if (el.matches && el.matches("fieldset.pb-project-type")){
                    if (!form.querySelector(\'input[name="project_type[]"]:checked\')){ addInvalid(el); }
                    return;
                }

                if (el.matches && el.matches(\'input[type="checkbox"]\')){
                    if (!el.checked){ addInvalid(el); }
                    return;
                }

                if (!el.value || !el.value.trim()){ addInvalid(el); }
            });

            var emailEl = form.querySelector("#pb-email");
            if (emailEl && emailEl.value && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(emailEl.value)){ addInvalid(emailEl); }
            var phoneEl = form.querySelector("#pb-phone");
            if (phoneEl && phoneEl.value && !/^([0-9()\+\- ]*)$/.test(phoneEl.value)){ addInvalid(phoneEl); }

            if (invalid.length){
                for (var j=0;j<invalid.length;j++){
                    if (invalid[j]){
                        invalid[j].classList.add("is-invalid");
                        invalid[j].setAttribute("aria-invalid","true");
                    }
                }
                showError(form, invalid);
                return false;
            }
            return true;
        }


        // Clear invalid highlight as user types
        document.addEventListener("input", function(e){
            var el = e.target;
            var form = el.closest && el.closest("#pb-samples-form");
            if (!form) return;
            if (el.id === "pb-search"){ filterProducts(form); return; }
            if (el.id === "pb-project-size"){ syncProjectFields(form); }
            if (el.classList && el.classList.contains("is-invalid")){
                el.classList.remove("is-invalid"); el.removeAttribute("aria-invalid");
                var b = banner(form);
                if (b){ b.classList.remove("show"); b.textContent=""; }
            }
        });
        document.addEventListener("change", function(e){
            var el = e.target;
            var form = el.closest && el.closest("#pb-samples-form");
            if (!form) return;

            if (el.id === "pb-enquiry"){ toggleOrg(form); return; }
            if (el.name === "project_type[]"){ syncProjectFields(form); return; }

            if (el.classList && el.classList.contains("pb-choice")){
                var max = maxAllowed(form);
                if (selectedCount(form) > max){
                    el.checked = false;
                    alert("You can select a maximum of " + max + " samples.");
                }
                updateCount(form);
                updateSelectedVisual(form);
                updateSelectedList(form);
                updateTopBar(form);
                if (currentStepIndex(form) === 2){ renderReviewGrid(form); } // update live if already on review
                return;
            }

            if (el.id === "pb-type"){ filterProducts(form); return; }
        });

        // Ready + popup hooks
        if (document.readyState === "loading"){ document.addEventListener("DOMContentLoaded", scan); } else { scan(); }
        if (window.jQuery){ jQuery(document).on("elementor/popup/show", function(){ scan(); }); }
        if (window.elementorFrontend && window.elementorFrontend.hooks){
            try{
                window.elementorFrontend.hooks.addAction("popup:after_open", function(){ scan(); });
                window.elementorFrontend.hooks.addAction("frontend/element_ready/global", function(){ scan(); });
            }catch(e){}
        }
        try{
            var mo = new MutationObserver(function(){ scan(); });
            mo.observe(document.documentElement, { childList:true, subtree:true });
        }catch(e){}

        function closePopup(){
            // Elementor Pro API
            if (window.elementorProFrontend && elementorProFrontend.modules && elementorProFrontend.modules.popup){
                try{ elementorProFrontend.modules.popup.closePopup(); return true; }catch(e){}
            }
            // Elementor core
            if (window.elementorFrontend && elementorFrontend.modules && elementorFrontend.modules.popup && elementorFrontend.modules.popup.closePopup){
                try{ elementorFrontend.modules.popup.closePopup(); return true; }catch(e){}
            }
            // Fallbacks
            var btn = document.querySelector(".elementor-popup-modal .dialog-close-button, .elementor-popup-modal [aria-label=\'Close\']");
            if (btn){ btn.click(); return true; }
            var modal = document.querySelector(".elementor-popup-modal");
            if (modal){ modal.parentNode.removeChild(modal); return true; }
            try{ document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape"})); }catch(e){}
            return false;
        }

        // Delegated clicks
        document.addEventListener("click", function(e){
            var nextBtn = e.target.closest("button[data-next]");
            if (nextBtn){
                var form = nextBtn.closest("#pb-samples-form");
                if (!form) return;
                var idx = currentStepIndex(form);

                // Step 0 = selection; require at least one
                if (idx===0 && selectedCount(form)===0){
                    alert("Please select at least one sample.");
                    return;
                }
                // Step 1 = details; validate
                if (idx===1 && !validateStep1(form)) return;

                showStep(form, Math.min(idx+1, steps(form).length-1));
                return;
            }
            var prevBtn = e.target.closest("button[data-prev]");
            if (prevBtn){
                var form2 = prevBtn.closest("#pb-samples-form");
                if (!form2) return;
                var idx2 = currentStepIndex(form2);
                showStep(form2, Math.max(idx2-1, 0));
                return;
            }
            var submitBtn = e.target.closest("#pb-submit");
            if (submitBtn){
                var form3 = submitBtn.closest("#pb-samples-form");
                if (!form3) return;
                if (form3.dataset.pbSubmitted === "1") return; // guard
                var statusEl = form3.querySelector("#pb-status");
                if (statusEl) statusEl.innerHTML = "<p>Submitting...</p>";
                submitBtn.disabled = true;
                try {
                    setContext(form3);
                    syncProjectFields(form3);
                } catch (err) {
                    if (statusEl) statusEl.innerHTML = "<p class=\'err\'>The form could not prepare your submission. Please refresh and try again.</p>";
                    submitBtn.disabled = false;
                    return;
                }

                // Build FormData and append aligned names + skus
                var fd = new FormData(form3);
                var picks = form3.querySelectorAll(".pb-choice:checked");
                picks.forEach(function(c){
                    fd.append("product_names[]", c.getAttribute("data-name") || c.value);
                    fd.append("product_skus[]",  c.getAttribute("data-sku")  || "");
                });

                fetch(PBSAMPLES.ajax_url, { method:"POST", body:fd, headers: { "X-Requested-With": "XMLHttpRequest" }})
                .then(function(res){ return res.json(); })
                .then(function(data){
                    var relay = data && data.data && data.data.relay ? data.data.relay : null;
                    if (relay && relay.status === "blocked") {
                        if (statusEl) statusEl.innerHTML = "<p class=\'warn\'>" + esc(relay.message || "This request cannot be submitted right now.") + "</p>";
                        return;
                    }
                    if (data && data.success) {
    if (statusEl) statusEl.innerHTML = "<p class=\'ok\'><span style=\'font-size: 2em; font-weight: 700; color: #2f7d32\'>Submission successful</span> </br>Thank you for requesting samples. We have received your request and will process them for dispatch. You can expect them to arrive within around 3-5 days and if you have any questions in the meantime, please feel free to contact us through the <a href=\'/contact/\'>contact page</a></p>";

    // Hide review summary text if desired
    var review = form3.querySelector("#pb-review");
    if (review) review.style.display = "none";

    // Hide Submit button
    submitBtn.hidden = true;
    submitBtn.style.display = "none";
    form3.dataset.pbSubmitted = "1";

    // Hide ONLY the Previous button in Step 3
    var prevBtns = form3.querySelectorAll(".pb-step[data-step=\'3\'] [data-prev]");
    prevBtns.forEach(function(btn){
        btn.hidden = true;
        btn.style.display = "none";
        btn.classList.add("pb-hidden");
    });

    // Disable all form fields except Close
    Array.prototype.forEach.call(form3.querySelectorAll("input,select,textarea,button"), function(el){
        if (el.id !== "pb-finish") el.disabled = true;
    });

    // Show Close button now that submission succeeded
    var finishBtn = form3.querySelector("#pb-finish");
    if (finishBtn){
        finishBtn.hidden = false;
        finishBtn.disabled = false;
        finishBtn.style.display = "inline-block";
        finishBtn.classList.remove("pb-hidden");
    }

    // Adjust layout so Close aligns neatly
    var nav = form3.querySelector(".pb-step[data-step=\'3\'] .pb-nav");
    if (nav){
        nav.style.justifyContent = "flex-end";
    }
}


 else {
                        throw new Error((data && data.data && data.data.message) ? data.data.message : "Unknown error");
                    }
                })
                .catch(function(err){
                    if (statusEl) statusEl.innerHTML = "<p class=\\"err\\">There was an error with your submission. Please try again.</p>";
                    if (window.console && console.error) {
                        console.error("PB sample submission failed", err);
                    }
                })
                .finally(function(){ submitBtn.disabled = false; });
                return;
            }
            // Remove chip (works for sticky top)
            var removeBtn = e.target.closest(".pb-chip .remove");
            if (removeBtn){
                var form4 = removeBtn.closest("#pb-samples-form") || document.querySelector("#pb-samples-form");
                if (!form4) return;
                var value = removeBtn.parentElement.getAttribute("data-value") || "";
                var inputs = form4.querySelectorAll(".pb-choice");
                for (var i=0;i<inputs.length;i++){
                    if (inputs[i].getAttribute("data-name") === value || inputs[i].value === value){
                        inputs[i].checked = false;
                        updateCount(form4);
                        updateSelectedVisual(form4);
                        updateSelectedList(form4);
                        updateTopBar(form4);
                        if (currentStepIndex(form4) === 2){ renderReviewGrid(form4); }
                        break;
                    }
                }
                return;
            }
            // Finish button
            var fin = e.target.closest("#pb-finish");
            if (fin){
                closePopup();
                return;
            }
        });

        // Accordion: only one open at a time
        document.addEventListener("toggle", function(e){
            if(e.target && e.target.matches(".pb-accordion[open]")){
                document.querySelectorAll(".pb-accordion").forEach(function(d){
                    if(d!==e.target) d.removeAttribute("open");
                });
            }
        });

        // Boot
        // (scan already called above)
		// --- Google Places Autocomplete for address fields ---
window.initPbAddressAutocomplete = function () {
    if (typeof google === "undefined" || !google.maps || !google.maps.places) return;

    var input = document.getElementById("pb-street");
    if (!input) return; // Field not yet rendered

    // Prevent multiple bindings
    if (input.dataset.pbAutocompleteInit === "1") return;
    input.dataset.pbAutocompleteInit = "1";

    var autocomplete = new google.maps.places.Autocomplete(input, {
        types: ["address"],
        fields: ["address_components", "formatted_address"],
		

    });

autocomplete.addListener("place_changed", function () {
    var place = autocomplete.getPlace();
    if (!place || !place.address_components) return;

    var comps = {};
    place.address_components.forEach(function (c) {
        c.types.forEach(function (t) {
            comps[t] = c.long_name || "";
        });
    });

    // Construct full street
    var street = "";
    if (comps.street_number) street += comps.street_number + " ";
    if (comps.route) street += comps.route;

    // City / Town logic (UK-friendly)
    var city = comps.postal_town || comps.locality || comps.sublocality || "";

    // County logic (UK-friendly)
    var county = comps.administrative_area_level_2 || comps.administrative_area_level_1 || "";

    // Populate standard fields
    document.getElementById("pb-street").value = street.trim() || place.formatted_address || "";
    if (document.getElementById("pb-city")) document.getElementById("pb-city").value = city;
    if (document.getElementById("pb-country")) document.getElementById("pb-country").value = comps.country || "";
    if (document.getElementById("pb-postcode")) document.getElementById("pb-postcode").value = comps.postal_code || "";

    // Determine country behaviour
    var country = (comps.country || "").toLowerCase();
    var countyField = document.getElementById("pb-county");
    var postcodeField = document.getElementById("pb-postcode");

    // Handle County visibility
    if (countyField) {
        if (country === "united kingdom" || country === "uk" || country === "great britain") {
            countyField.value = county;
            countyField.parentElement.style.display = "";
        } else {
            countyField.value = "";
            countyField.parentElement.style.display = "none";
        }
    }

    // Create or reference "No postcode" checkbox container
    if (postcodeField) {
        var wrapper = postcodeField.parentElement;
        var existingBox = wrapper.querySelector(".pb-no-postcode");
        var postcodeRequiredBySettings = postcodeField.getAttribute("data-pb-required") === "1";

        if (!existingBox) {
            var div = document.createElement("div");
            div.className = "pb-no-postcode";
            div.innerHTML = `
                <label style="display:flex;align-items:center;gap:6px;margin-top:4px;font-size:0.9em;cursor:pointer;">
                    <input type="checkbox" id="pb-no-postcode-check" name="no_postcode" value="1" style="width:auto;vertical-align:middle;" />
                    <span>No postcode</span>
                </label>
            `;
            wrapper.appendChild(div);
        }

        var checkbox = document.getElementById("pb-no-postcode-check");

        // Only show checkbox for non-UK countries
        if (country === "united kingdom" || country === "uk" || country === "great britain") {
            if (checkbox) {
                checkbox.checked = false;
                checkbox.closest(".pb-no-postcode").style.display = "none";
            }
            if (postcodeRequiredBySettings) {
                postcodeField.setAttribute("required", "required");
            } else {
                postcodeField.removeAttribute("required");
            }
        } else {
            if (checkbox) checkbox.closest(".pb-no-postcode").style.display = "block";

            // Handle checkbox toggle behaviour
            checkbox.addEventListener("change", function () {
                if (this.checked) {
                    postcodeField.removeAttribute("required");
                    postcodeField.value = "";
                    postcodeField.disabled = true;
                    postcodeField.placeholder = "Postcode not required";
                } else {
                    postcodeField.disabled = false;
                    postcodeField.placeholder = "Postcode";
                    if (postcodeRequiredBySettings) {
                        postcodeField.setAttribute("required", "required");
                    } else {
                        postcodeField.removeAttribute("required");
                    }
                }
            });
        }
    }
});



};

// Load Google Maps Places API async (best-practice pattern)
(function(){
    function loadScript(){
        if (window.google && google.maps && google.maps.places) {
            window.initPbAddressAutocomplete();
            return;
        }
        var apiKey = (window.PBSAMPLES && PBSAMPLES.google_places_api_key) ? String(PBSAMPLES.google_places_api_key) : "";
        if (!apiKey) {
            return;
        }
        if (document.querySelector("script[data-pb-google-places=\'1\']")) {
            return;
        }
        const s = document.createElement("script");
        s.src = "https://maps.googleapis.com/maps/api/js?key=" + encodeURIComponent(apiKey) + "&libraries=places&callback=initPbAddressAutocomplete&loading=async";
        s.async = true;
        s.defer = true;
        s.setAttribute("data-pb-google-places", "1");
        document.head.appendChild(s);
    }

    // Initial load
    loadScript();

    // Re-init when Elementor popups open
    if (window.jQuery) {
        jQuery(document).on("elementor/popup/show", function(){
            if (window.google && google.maps && google.maps.places) {
                setTimeout(window.initPbAddressAutocomplete, 500);
            }
        });
    }

    // Defensive: if no Elementor events, check periodically for field
    var retry = setInterval(function(){
        if (document.getElementById("pb-street") && window.google && google.maps && google.maps.places) {
            clearInterval(retry);
            window.initPbAddressAutocomplete();
        }
    }, 2000);
})();


    })();
    ';
    wp_add_inline_script('pb-samples-js', $js);
    }

    public static function handle_submission() {
        if (!empty($_POST['website'])) {
            wp_send_json_error(['message' => 'Spam blocked.']);
        }

        if (!isset($_POST['pbsamples_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pbsamples_nonce'])), 'pbsamples_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.']);
        }

        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last = sanitize_text_field(wp_unslash($_POST['surname'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $enquiry = sanitize_text_field(wp_unslash($_POST['enquiry_type'] ?? ''));
        $org = sanitize_text_field(wp_unslash($_POST['organisation_name'] ?? ''));
        $street = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $addr2 = sanitize_text_field(wp_unslash($_POST['address_2'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $county = sanitize_text_field(wp_unslash($_POST['county'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $postcode = sanitize_text_field(wp_unslash($_POST['postcode'] ?? ''));
        $gdpr = isset($_POST['gdpr_consent']) ? 'yes' : 'no';
        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? ''));
        $referrer = esc_url_raw(wp_unslash($_POST['referrer'] ?? ''));
        $current_product = sanitize_text_field(wp_unslash($_POST['current_product'] ?? ''));
        $project_type = self::sanitize_project_types(wp_unslash($_POST['project_type'] ?? ($_POST['project_type_serialized'] ?? [])));
        $project_size_raw = sanitize_text_field(wp_unslash($_POST['project_size_m2'] ?? ($_POST['project_size_value'] ?? '')));
        $project_size = ($project_size_raw !== '' && preg_match('/^\d+$/', $project_size_raw)) ? (int) $project_size_raw : '';
        $no_postcode = !empty($_POST['no_postcode']);

        $product_names = array_map('sanitize_text_field', (array) wp_unslash($_POST['product_names'] ?? ($_POST['product_selection'] ?? [])));
        $product_skus = array_map('sanitize_text_field', (array) wp_unslash($_POST['product_skus'] ?? []));

        $max = (int) ($_POST['max'] ?? 4);
        if ($max < 1) {
            $max = 4;
        }

        $product_names = array_values(array_slice(array_unique($product_names), 0, $max));
        $aligned_skus = [];
        foreach ($product_names as $i => $name) {
            $aligned_skus[$i] = $product_skus[$i] ?? '';
        }

        $field_settings = self::form_fields();
        $required_values = [
            'first_name' => $first,
            'surname' => $last,
            'email' => $email,
            'phone' => $phone,
            'enquiry_type' => $enquiry,
            'organisation_name' => $org,
            'project_type' => $project_type,
            'project_size_m2' => $project_size_raw,
            'street' => $street,
            'address_2' => $addr2,
            'city' => $city,
            'county' => $county,
            'country' => $country,
            'postcode' => $postcode,
            'gdpr_consent' => $gdpr,
        ];

        foreach ($required_values as $key => $value) {
            if (!self::field_is_required($field_settings, $key)) {
                continue;
            }

            if ('organisation_name' === $key && ('' === $enquiry || 'homeowner' === $enquiry)) {
                continue;
            }

            if ('postcode' === $key && $no_postcode) {
                continue;
            }

            if ('gdpr_consent' === $key && 'yes' !== $value) {
                wp_send_json_error(['message' => 'Missing required fields.']);
            }

            if (is_array($value) && empty($value)) {
                wp_send_json_error(['message' => 'Missing required fields.']);
            }

            if (!is_array($value) && '' === trim((string) $value)) {
                wp_send_json_error(['message' => 'Missing required fields.']);
            }
        }

        if (($email !== '' && !is_email($email)) || empty($product_names)) {
            wp_send_json_error(['message' => 'Missing required fields.']);
        }

        $samples = [];
        foreach ($product_names as $i => $name) {
            $samples[] = [
                'name' => $name,
                'sku' => $aligned_skus[$i] ?? '',
            ];
        }

        $raw = [
            'source' => 'permabound_sample_request',
            'contact' => [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $phone,
                'enquiry_type' => $enquiry,
                'organisation_name' => $org,
                'gdpr_consent' => $gdpr === 'yes',
            ],
            'shipping' => [
                'street' => $street,
                'address_2' => $addr2,
                'city' => $city,
                'county' => $county,
                'country' => $country,
                'postcode' => $postcode,
            ],
            'samples' => $samples,
            'sample_names' => $product_names,
            'project_type' => $project_type,
            'project_size_m2' => $project_size,
            'context' => [
                'page_url' => $page_url,
                'referrer' => $referrer,
                'current_product' => $current_product,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            ],
        ];

        $idem = md5(wp_json_encode(['pb_submit_samples', $email, $samples, date('Y-m-d-H')]));
        $res = PBSR_Dispatcher::process($raw, 'permabound_sample_request', $idem);

        if (!empty($res['ok']) || ($res['status'] ?? '') === 'blocked') {
            wp_send_json_success([
                'ok' => !empty($res['ok']),
                'relay' => $res,
            ]);
        }

        wp_send_json_error([
            'message' => $res['message'] ?? 'Relay processing failed.',
            'relay' => $res,
        ]);
    }

    private static function get_product_sku($product_id) {
        $preferred_keys = ['sample_sku', 'sku', '_sku', 'product_sku'];

        foreach ($preferred_keys as $key) {
            $value = trim((string) get_post_meta($product_id, $key, true));
            if ($value !== '') {
                return $value;
            }
        }

        if (function_exists('get_field')) {
            foreach ($preferred_keys as $key) {
                $value = trim((string) get_field($key, $product_id));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function sanitize_project_types($values) {
        $allowed = ['Path/Patio', 'Driveway', 'Other'];

        if (is_string($values)) {
            $values = preg_split('/[\r\n,;|]+/', $values);
        }

        $sanitized = array_map('sanitize_text_field', (array) $values);

        return array_values(array_intersect($allowed, $sanitized));
    }
}

PBSR_Product_Selection_Form::init();

