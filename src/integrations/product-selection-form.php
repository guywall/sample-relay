<?php
if (!defined('ABSPATH')) exit;

class PBSR_Product_Selection_Form {

    const AJAX_ACTION = 'pbsr_handle_product_selection_form_submission';

    public static function init() {
        add_action('init', [__CLASS__, 'register_hooks'], 999);
    }

    public static function register_hooks() {
        remove_shortcode('product_selection_form');
        add_shortcode('product_selection_form', [__CLASS__, 'render_shortcode']);

        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
    }

    public static function render_shortcode() {
        $products = self::get_available_products();
        $nonce = wp_create_nonce('product_selection_form_nonce');

        ob_start();
        ?>
        <form id="pbsr-product-selection-form" class="pbsr-form" method="post" action="">
            <input type="hidden" name="product_selection_form_nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">

            <div id="pbsr-step-1" class="pbsr-step">
                <h3>Personal Details</h3>

                <p><label for="pbsr-first-name">First Name</label><br>
                <input id="pbsr-first-name" type="text" name="first_name" required></p>

                <p><label for="pbsr-surname">Surname</label><br>
                <input id="pbsr-surname" type="text" name="surname" required></p>

                <p><label for="pbsr-email">Email</label><br>
                <input id="pbsr-email" type="email" name="email" required></p>

                <p><label for="pbsr-phone">Phone</label><br>
                <input id="pbsr-phone" type="text" name="phone" pattern="[0-9+ ]*" required></p>

                <p><label for="pbsr-enquiry-type">Enquiry Type</label><br>
                <select id="pbsr-enquiry-type" name="enquiry_type" required>
                    <option value="">-- Please select --</option>
                    <option value="homeowner">Homeowner</option>
                    <option value="contractor_installer">Contractor/Installer</option>
                    <option value="merchant_reseller">Merchant/Reseller</option>
                    <option value="local_authority">Local Authority</option>
                    <option value="other">Other</option>
                </select></p>

                <p id="pbsr-org-wrap" style="display:none;"><label for="pbsr-organisation-name">Organisation Name</label><br>
                <input id="pbsr-organisation-name" type="text" name="organisation_name"></p>

                <p><label for="pbsr-street">Street</label><br>
                <input id="pbsr-street" type="text" name="street" required></p>

                <p><label for="pbsr-address-2">Address 2</label><br>
                <input id="pbsr-address-2" type="text" name="address_2"></p>

                <p><label for="pbsr-city">Town / City</label><br>
                <input id="pbsr-city" type="text" name="city" required></p>

                <p><label for="pbsr-county">County</label><br>
                <input id="pbsr-county" type="text" name="county" required></p>

                <p><label for="pbsr-country">Country</label><br>
                <input id="pbsr-country" type="text" name="country" required></p>

                <p><label for="pbsr-postcode">Postcode</label><br>
                <input id="pbsr-postcode" type="text" name="postcode" required></p>

                <button type="button" class="pbsr-next" data-next="2">Next</button>
            </div>

            <div id="pbsr-step-2" class="pbsr-step" style="display:none;">
                <h3>Select up to 3 sample products</h3>
                <?php if (empty($products)) : ?>
                    <p>No sample products are currently available.</p>
                <?php else : ?>
                    <div class="pbsr-products-grid">
                        <?php foreach ($products as $product) : ?>
                            <label class="pbsr-product-option">
                                <input
                                    type="checkbox"
                                    name="product_selection[]"
                                    value="<?php echo esc_attr($product['id']); ?>"
                                    data-name="<?php echo esc_attr($product['name']); ?>"
                                >
                                <?php if ($product['thumbnail']) : ?>
                                    <img src="<?php echo esc_url($product['thumbnail']); ?>" alt="<?php echo esc_attr($product['name']); ?>">
                                <?php endif; ?>
                                <span><?php echo esc_html($product['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <button type="button" class="pbsr-prev" data-prev="1">Previous</button>
                <button type="button" class="pbsr-next" data-next="3">Next</button>
            </div>

            <div id="pbsr-step-3" class="pbsr-step" style="display:none;">
                <h3>Review &amp; Submit</h3>
                <div id="pbsr-review"></div>
                <div id="pbsr-submission-status" style="margin:10px 0;"></div>
                <button type="button" class="pbsr-prev" data-prev="2">Previous</button>
                <button type="button" id="pbsr-submit-btn">Submit</button>
            </div>
        </form>

        <style>
            .pbsr-products-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:16px; margin-bottom:16px; }
            .pbsr-product-option { border:1px solid #ddd; padding:10px; text-align:center; }
            .pbsr-product-option img { max-width:100%; height:auto; display:block; margin:0 auto 10px; }
        </style>

        <script>
            (function() {
                const form = document.getElementById('pbsr-product-selection-form');
                if (!form) return;

                const steps = [1,2,3];
                const showStep = function(step) {
                    steps.forEach(function(i) {
                        const el = document.getElementById('pbsr-step-' + i);
                        if (el) el.style.display = i === step ? 'block' : 'none';
                    });

                    if (step === 3) {
                        renderReview();
                    }
                };

                const selectedProducts = function() {
                    return Array.from(form.querySelectorAll('input[name="product_selection[]"]:checked'));
                };

                const validateStep1 = function() {
                    const required = ['first_name','surname','email','phone','street','city','county','country','postcode','enquiry_type'];
                    for (const field of required) {
                        const el = form.querySelector('[name="' + field + '"]');
                        if (!el || !el.value.trim()) {
                            alert('Please complete all required fields before continuing.');
                            return false;
                        }
                    }
                    return true;
                };

                const renderReview = function() {
                    const review = document.getElementById('pbsr-review');
                    if (!review) return;

                    const products = selectedProducts().map(function(el){ return el.dataset.name || ''; }).filter(Boolean);
                    review.innerHTML = '' +
                        '<p><strong>Name:</strong> ' + form.first_name.value + ' ' + form.surname.value + '</p>' +
                        '<p><strong>Email:</strong> ' + form.email.value + '</p>' +
                        '<p><strong>Phone:</strong> ' + form.phone.value + '</p>' +
                        '<p><strong>Address:</strong> ' + form.street.value + ', ' + form.city.value + ', ' + form.county.value + ', ' + form.country.value + ' ' + form.postcode.value + '</p>' +
                        '<p><strong>Selected Products:</strong> ' + (products.join(', ') || 'None') + '</p>';
                };

                form.addEventListener('change', function(e) {
                    if (e.target && e.target.name === 'enquiry_type') {
                        const orgWrap = document.getElementById('pbsr-org-wrap');
                        if (orgWrap) {
                            orgWrap.style.display = (e.target.value && e.target.value !== 'homeowner') ? 'block' : 'none';
                        }
                    }

                    if (e.target && e.target.name === 'product_selection[]') {
                        const picks = selectedProducts();
                        if (picks.length > 3) {
                            e.target.checked = false;
                            alert('You can select a maximum of three products.');
                        }
                    }
                });

                form.querySelectorAll('.pbsr-next').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const next = parseInt(btn.dataset.next, 10);
                        if (next === 2 && !validateStep1()) return;
                        showStep(next);
                    });
                });

                form.querySelectorAll('.pbsr-prev').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        showStep(parseInt(btn.dataset.prev, 10));
                    });
                });

                const submitBtn = document.getElementById('pbsr-submit-btn');
                if (submitBtn) {
                    submitBtn.addEventListener('click', function() {
                        const status = document.getElementById('pbsr-submission-status');
                        if (status) status.textContent = 'Submitting...';

                        const formData = new FormData(form);
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data && data.success) {
                                if (status) status.textContent = 'Submission successful. Thank you.';
                                submitBtn.disabled = true;
                            } else {
                                const msg = (data && data.data && data.data.message) ? data.data.message : 'Submission failed. Please try again.';
                                if (status) status.textContent = msg;
                            }
                        })
                        .catch(function() {
                            if (status) status.textContent = 'Submission failed. Please try again.';
                        });
                    });
                }
            })();
        </script>
        <?php

        return ob_get_clean();
    }

    public static function handle_submission() {
        if (!isset($_POST['product_selection_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['product_selection_form_nonce'])), 'product_selection_form_nonce')) {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $surname = sanitize_text_field(wp_unslash($_POST['surname'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $enquiry_type = sanitize_text_field(wp_unslash($_POST['enquiry_type'] ?? ''));
        $organisation_name = sanitize_text_field(wp_unslash($_POST['organisation_name'] ?? ''));
        $street = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $address_2 = sanitize_text_field(wp_unslash($_POST['address_2'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $county = sanitize_text_field(wp_unslash($_POST['county'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $postcode = sanitize_text_field(wp_unslash($_POST['postcode'] ?? ''));

        $selected_ids = isset($_POST['product_selection']) ? array_map('absint', (array) wp_unslash($_POST['product_selection'])) : [];
        $selected_ids = array_values(array_filter(array_unique($selected_ids)));

        if (count($selected_ids) > 3) {
            wp_send_json_error(['message' => 'You can select a maximum of three products.']);
        }

        $samples = self::build_samples_from_ids($selected_ids);
        if (empty($samples)) {
            wp_send_json_error(['message' => 'Please select at least one available product.']);
        }

        $raw = [
            'source' => 'permabound_sample_request',
            'first_name' => $first_name,
            'last_name' => $surname,
            'name' => trim($first_name . ' ' . $surname),
            'email' => $email,
            'phone' => $phone,
            'enquiry_type' => $enquiry_type,
            'organisation_name' => $organisation_name,
            'street' => $street,
            'address_2' => $address_2,
            'city' => $city,
            'state' => $county,
            'county' => $county,
            'country' => $country,
            'postcode' => $postcode,
            'samples' => $samples,
            'blends' => array_values(array_map(function($sample) {
                return $sample['name'];
            }, $samples)),
            'context' => [
                'page_url' => esc_url_raw(wp_get_referer() ?: ''),
            ],
        ];

        $idempotency_key = md5(wp_json_encode(['shortcode', $email, $selected_ids, date('Y-m-d-H')]));
        $res = PBSR_Dispatcher::process($raw, 'permabound_sample_request', $idempotency_key);

        if (!empty($res['ok'])) {
            wp_send_json_success([
                'message' => 'Submission processed.',
                'relay' => $res,
            ]);
        }

        wp_send_json_error([
            'message' => 'Relay processing failed.',
            'relay' => $res,
        ]);
    }

    private static function get_available_products() {
        $settings = PBSR_Settings::get();
        $hidden = PBSR_Mapper::parseHiddenSamples($settings['hidden_samples'] ?? '');

        $products = get_posts([
            'post_type' => ['product', 'products'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $out = [];
        foreach ($products as $product_id) {
            if (class_exists('PBSR_Product_Availability') && PBSR_Product_Availability::is_unavailable($product_id)) {
                continue;
            }

            $name = get_the_title($product_id);
            $sku = self::get_product_sku($product_id);

            if (self::is_product_hidden($product_id, $name, $sku, $hidden)) {
                continue;
            }

            $out[] = [
                'id' => $product_id,
                'name' => $name,
                'sku' => $sku,
                'thumbnail' => get_the_post_thumbnail_url($product_id, 'thumbnail'),
            ];
        }

        return $out;
    }


    private static function is_product_hidden($product_id, $name, $sku, array $hidden) {
        if (PBSR_Mapper::isSampleHidden($name, $sku, $hidden)) {
            return true;
        }

        foreach (self::get_sku_candidates($product_id) as $candidate) {
            if (PBSR_Mapper::isSampleHidden('', $candidate, $hidden)) {
                return true;
            }
        }

        return false;
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

        $candidates = self::get_sku_candidates($product_id);
        return !empty($candidates) ? $candidates[0] : '';
    }

    private static function get_sku_candidates($product_id) {
        $candidates = [];
        $all_meta = get_post_meta($product_id);
        if (is_array($all_meta)) {
            foreach ($all_meta as $key => $values) {
                if (stripos((string) $key, 'sku') === false) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $candidates[] = $value;
                    }
                }
            }
        }

        if (function_exists('get_fields')) {
            $acf_fields = get_fields($product_id);
            if (is_array($acf_fields)) {
                foreach ($acf_fields as $key => $value) {
                    if (stripos((string) $key, 'sku') === false || !is_scalar($value)) {
                        continue;
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $candidates[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function build_samples_from_ids(array $product_ids) {
        if (empty($product_ids)) {
            return [];
        }

        $available = self::get_available_products();
        $available_by_id = [];
        foreach ($available as $product) {
            $available_by_id[(int) $product['id']] = $product;
        }

        $samples = [];
        foreach ($product_ids as $id) {
            if (empty($available_by_id[$id])) {
                continue;
            }

            $product = $available_by_id[$id];
            $samples[] = [
                'name' => $product['name'],
                'sku' => $product['sku'],
            ];
        }

        return $samples;
    }
}

PBSR_Product_Selection_Form::init();
