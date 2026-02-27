<?php
if (!defined('ABSPATH')) exit;

class PBSR_Product_Selection_Form {

    const AJAX_ACTION = 'pb_submit_samples';

    public static function init() {
        add_action('init', [__CLASS__, 'register_hooks'], 999);
    }

    public static function register_hooks() {
        remove_shortcode('product_selection_form');
        remove_shortcode('permabound_sample_request');

        add_shortcode('product_selection_form', [__CLASS__, 'render_shortcode']);
        add_shortcode('permabound_sample_request', [__CLASS__, 'render_shortcode']);

        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'handle_submission']);
    }

    public static function render_shortcode($atts = []) {
        $atts = shortcode_atts([
            'categories' => 'resin-bound-stone-blends,rubber-mulch,soft-gravel,colourbound',
            'max' => 4,
        ], $atts);

        $nonce = wp_create_nonce('pbsamples_nonce');
        $cats = array_values(array_filter(array_map('trim', explode(',', (string) $atts['categories']))));
        $max = max(1, (int) $atts['max']);
        $max = min($max, 8);

        $products = self::get_available_products($cats);

        ob_start();
        ?>
        <form id="pb-samples-form" class="pb-form" novalidate>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::AJAX_ACTION); ?>">
            <input type="hidden" name="pbsamples_nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" id="pb-max" value="<?php echo esc_attr($max); ?>">
            <input type="hidden" name="max" value="<?php echo esc_attr($max); ?>">
            <input type="text" name="website" class="hp" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;">

            <section class="pb-step" data-step="1">
                <h3>Select up to <?php echo esc_html($max); ?> samples</h3>
                <p><input type="search" id="pb-search" placeholder="Search products..."></p>

                <div id="pb-products-wrap">
                    <?php foreach ($products as $category_label => $items) : ?>
                        <details class="pb-accordion" open>
                            <summary><?php echo esc_html($category_label); ?></summary>
                            <div class="pb-products">
                                <?php foreach ($items as $product) : ?>
                                    <label class="pb-card" data-name="<?php echo esc_attr(strtolower($product['name'])); ?>">
                                        <input type="checkbox" class="pb-choice" name="product_selection[]" value="<?php echo esc_attr($product['name']); ?>" data-name="<?php echo esc_attr($product['name']); ?>" data-sku="<?php echo esc_attr($product['sku']); ?>">
                                        <?php if (!empty($product['thumbnail'])) : ?>
                                            <img src="<?php echo esc_url($product['thumbnail']); ?>" alt="<?php echo esc_attr($product['name']); ?>">
                                        <?php endif; ?>
                                        <span class="pb-name"><?php echo esc_html($product['name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <p id="pb-count-wrap"><strong><span id="pb-count">0</span>/<?php echo esc_html($max); ?></strong> selected</p>
                <p><button type="button" class="button button-primary" data-next>Next</button></p>
            </section>

            <section class="pb-step" data-step="2" hidden>
                <h3>Your details</h3>
                <p><input name="first_name" placeholder="First name" required></p>
                <p><input name="surname" placeholder="Surname" required></p>
                <p><input name="email" type="email" placeholder="Email" required></p>
                <p><input name="phone" placeholder="Phone" required></p>
                <p><input name="organisation_name" placeholder="Organisation name"></p>
                <p><input name="street" placeholder="Street" required></p>
                <p><input name="address_2" placeholder="Address 2"></p>
                <p><input name="city" placeholder="Town / City" required></p>
                <p><input name="county" placeholder="County" required></p>
                <p><input name="country" placeholder="Country" value="United Kingdom" required></p>
                <p><input name="postcode" placeholder="Postcode" required></p>
                <p>
                    <select name="enquiry_type" required>
                        <option value="">Please select enquiry type</option>
                        <option value="homeowner">Homeowner</option>
                        <option value="contractor_installer">Contractor/Installer</option>
                        <option value="merchant_reseller">Merchant/Reseller</option>
                        <option value="local_authority">Local Authority</option>
                        <option value="other">Other</option>
                    </select>
                </p>
                <p><label><input type="checkbox" name="gdpr_consent" value="1" required> I agree to be contacted.</label></p>
                <p>
                    <button type="button" class="button" data-prev>Previous</button>
                    <button type="button" class="button button-primary" data-next>Next</button>
                </p>
            </section>

            <section class="pb-step" data-step="3" hidden>
                <h3>Review and submit</h3>
                <div id="pb-review"></div>
                <div id="pb-status"></div>
                <p>
                    <button type="button" class="button" data-prev>Previous</button>
                    <button type="button" class="button button-primary" id="pb-submit">Submit</button>
                </p>
            </section>
        </form>

        <style>
            #pb-samples-form .pb-products{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
            #pb-samples-form .pb-card{display:flex;flex-direction:column;gap:8px;border:1px solid #ddd;padding:8px;border-radius:8px}
            #pb-samples-form .pb-card img{max-width:100%;height:auto}
            #pb-samples-form .pb-step[hidden]{display:none!important}
            #pb-samples-form .is-selected{outline:2px solid #ff9f23}
            #pb-samples-form .hp{display:none!important}
        </style>

        <script>
            (function(){
                const form = document.getElementById('pb-samples-form');
                if (!form || form.dataset.pbInit) return;
                form.dataset.pbInit = '1';

                const steps = Array.from(form.querySelectorAll('.pb-step'));
                const max = parseInt((form.querySelector('#pb-max')||{}).value || '4', 10);

                function stepIndex(){ return steps.findIndex(s => !s.hasAttribute('hidden')); }
                function showStep(idx){ steps.forEach((s,i)=> i===idx ? s.removeAttribute('hidden') : s.setAttribute('hidden','hidden')); if (idx===2) buildReview(); }
                function picks(){ return Array.from(form.querySelectorAll('.pb-choice:checked')); }
                function updateCount(){
                    const c = picks().length;
                    const count = form.querySelector('#pb-count');
                    if (count) count.textContent = String(c);
                    form.querySelectorAll('.pb-card').forEach(card => {
                        const cb = card.querySelector('.pb-choice');
                        card.classList.toggle('is-selected', !!(cb && cb.checked));
                    });
                }

                function buildReview(){
                    const names = picks().map(x => x.dataset.name || x.value).join(', ');
                    const review = form.querySelector('#pb-review');
                    if (!review) return;
                    review.innerHTML = '<p><strong>Name:</strong> ' + (form.first_name.value||'') + ' ' + (form.surname.value||'') + '</p>' +
                        '<p><strong>Email:</strong> ' + (form.email.value||'') + '</p>' +
                        '<p><strong>Selected products:</strong> ' + names + '</p>';
                }

                form.addEventListener('change', function(e){
                    if (e.target.classList.contains('pb-choice')) {
                        if (picks().length > max) {
                            e.target.checked = false;
                            alert('You can select a maximum of ' + max + ' samples.');
                        }
                        updateCount();
                    }
                });

                const search = form.querySelector('#pb-search');
                if (search) {
                    search.addEventListener('input', function(){
                        const q = (search.value||'').toLowerCase().trim();
                        form.querySelectorAll('.pb-card').forEach(card => {
                            const name = card.getAttribute('data-name') || '';
                            card.style.display = !q || name.indexOf(q) !== -1 ? '' : 'none';
                        });
                    });
                }

                form.addEventListener('click', function(e){
                    const next = e.target.closest('[data-next]');
                    if (next) {
                        const idx = stepIndex();
                        if (idx === 0 && picks().length === 0) {
                            alert('Please select at least one sample.');
                            return;
                        }
                        if (idx === 1 && !form.checkValidity()) {
                            form.reportValidity();
                            return;
                        }
                        showStep(Math.min(idx + 1, 2));
                        return;
                    }

                    const prev = e.target.closest('[data-prev]');
                    if (prev) {
                        showStep(Math.max(stepIndex() - 1, 0));
                        return;
                    }

                    const submit = e.target.closest('#pb-submit');
                    if (submit) {
                        submit.disabled = true;
                        const status = form.querySelector('#pb-status');
                        if (status) status.textContent = 'Submitting...';

                        const fd = new FormData(form);
                        picks().forEach(c => {
                            fd.append('product_names[]', c.getAttribute('data-name') || c.value);
                            fd.append('product_skus[]', c.getAttribute('data-sku') || '');
                        });

                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            body: fd,
                            headers: {'X-Requested-With':'XMLHttpRequest'}
                        }).then(r => r.json()).then(data => {
                            if (data && data.success) {
                                if (status) status.innerHTML = '<p class="ok">Submission successful.</p>';
                            } else {
                                const msg = (data && data.data && data.data.message) ? data.data.message : 'Submission failed.';
                                if (status) status.innerHTML = '<p class="err">' + msg + '</p>';
                                submit.disabled = false;
                            }
                        }).catch(() => {
                            if (status) status.innerHTML = '<p class="err">Submission failed.</p>';
                            submit.disabled = false;
                        });
                    }
                });

                updateCount();
                showStep(0);
            })();
        </script>
        <?php
        return ob_get_clean();
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

        $gdpr = !empty($_POST['gdpr_consent']);

        $product_names = isset($_POST['product_names']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['product_names'])) : [];
        $product_skus = isset($_POST['product_skus']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['product_skus'])) : [];

        $max = isset($_POST['max']) ? (int) $_POST['max'] : 4;
        if ($max < 1) {
            $max = 4;
        }

        $samples = [];
        foreach ($product_names as $i => $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $samples[] = [
                'name' => $name,
                'sku' => trim((string) ($product_skus[$i] ?? '')),
            ];
        }
        $samples = array_slice($samples, 0, $max);

        if (!$first || !$last || !$email || !$phone || !$street || !$city || !$county || !$country || !$postcode || !$enquiry || !$gdpr || empty($samples)) {
            wp_send_json_error(['message' => 'Missing required fields.']);
        }

        $raw = [
            'source' => 'permabound_sample_request',
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'enquiry_type' => $enquiry,
            'organisation_name' => $org,
            'street' => $street,
            'address_2' => $addr2,
            'city' => $city,
            'state' => $county,
            'county' => $county,
            'country' => $country,
            'postcode' => $postcode,
            'samples' => $samples,
            'sample_names' => array_values(array_map(function($s){ return $s['name']; }, $samples)),
            'context' => [
                'page_url' => esc_url_raw(wp_unslash($_POST['page_url'] ?? '')),
                'referrer' => esc_url_raw(wp_unslash($_POST['referrer'] ?? '')),
            ],
        ];

        $idem = md5(wp_json_encode(['pb_submit_samples', $email, $samples, date('Y-m-d-H')]));
        $res = PBSR_Dispatcher::process($raw, 'permabound_sample_request', $idem);

        if (!empty($res['ok'])) {
            wp_send_json_success(['ok' => true, 'relay' => $res]);
        }

        wp_send_json_error(['message' => 'Relay processing failed.', 'relay' => $res]);
    }

    private static function get_available_products(array $category_slugs) {
        $settings = PBSR_Settings::get();
        $hidden = PBSR_Mapper::parseHiddenSamples($settings['hidden_samples'] ?? '');
        $grouped = [];

        foreach ($category_slugs as $slug) {
            $term = get_term_by('slug', $slug, 'product_category');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $ids = get_posts([
                'post_type' => ['product', 'products'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
                'tax_query' => [[
                    'taxonomy' => 'product_category',
                    'field' => 'slug',
                    'terms' => $slug,
                ]],
            ]);

            $items = [];
            foreach ($ids as $product_id) {
                if (class_exists('PBSR_Product_Availability') && PBSR_Product_Availability::is_unavailable($product_id)) {
                    continue;
                }

                $name = get_the_title($product_id);
                $sku = self::get_product_sku($product_id);

                if (self::is_product_hidden($product_id, $name, $sku, $hidden)) {
                    continue;
                }

                $items[] = [
                    'id' => (int) $product_id,
                    'name' => $name,
                    'sku' => $sku,
                    'thumbnail' => get_the_post_thumbnail_url($product_id, 'medium_large'),
                ];
            }

            if (!empty($items)) {
                $grouped[$term->name] = $items;
            }
        }

        return $grouped;
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
}

PBSR_Product_Selection_Form::init();
