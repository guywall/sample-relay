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

    public static function render_shortcode($atts) {
        $atts = shortcode_atts([
            'categories' => 'resin-bound-stone-blends,rubber-mulch,soft-gravel,colourbound',
            'max' => 4,
        ], $atts);

        $nonce = wp_create_nonce('pbsamples_nonce');
        $cats = array_filter(array_map('trim', explode(',', $atts['categories'])));
        $max = (int) $atts['max'];
        $auto_check = '';

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
                $settings = PBSR_Settings::get();
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
                    echo '<div class="pb-grid pb-products" data-cat="' . esc_attr($slug) . '">';

                    foreach ($query->posts as $product_id) {
                        if (class_exists('PBSR_Product_Availability') && PBSR_Product_Availability::is_unavailable($product_id)) {
                            continue;
                        }

                        $name = get_the_title($product_id);
                        $thumb = get_the_post_thumbnail_url($product_id, 'medium_large');
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
                    <div class="pb-field">
                        <label for="pb-first">First name</label>
                        <input id="pb-first" name="first_name" type="text" required>
                    </div>
                    <div class="pb-field">
                        <label for="pb-last">Surname</label>
                        <input id="pb-last" name="surname" type="text" required>
                    </div>
                </div>
                <div class="pb-grid">
                    <div class="pb-field">
                        <label for="pb-email">Email</label>
                        <input id="pb-email" name="email" type="email" required>
                    </div>
                    <div class="pb-field">
                        <label for="pb-phone">Phone</label>
                        <input id="pb-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required>
                    </div>
                </div>

                <div class="pb-grid">
                    <div class="pb-field">
                        <label for="pb-enquiry">Enquiry type</label>
                        <select id="pb-enquiry" name="enquiry_type" required>
                            <option value="">Please select</option>
                            <option value="homeowner">Homeowner</option>
                            <option value="contractor_installer">Contractor/Installer</option>
                            <option value="merchant_reseller">Merchant/Reseller</option>
                            <option value="local_authority">Local Authority</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="pb-field" id="pb-org-wrap" style="display:none;">
                        <label for="pb-org">Organisation name</label>
                        <input id="pb-org" name="organisation_name" type="text">
                    </div>
                </div>

                <div class="pb-grid">
                    <div class="pb-field">
                        <span class="pb-label">Project Type</span>
                        <div class="pb-check-group">
                            <label><input type="checkbox" name="project_type[]" value="Path/Patio"> Path/Patio</label>
                            <label><input type="checkbox" name="project_type[]" value="Driveway"> Driveway</label>
                            <label><input type="checkbox" name="project_type[]" value="Other"> Other</label>
                        </div>
                    </div>
                    <div class="pb-field">
                        <label for="pb-project-size">Project Size in m&sup2;</label>
                        <input id="pb-project-size" name="project_size_m2" type="number" inputmode="numeric" min="0" step="1">
                    </div>
                </div>

                <fieldset class="pb-address">
                    <legend>Shipping address</legend>
                    <label for="pb-street">Street</label>
                    <input id="pb-street" name="street" type="text" required>
                    <label for="pb-address2">Address 2</label>
                    <input id="pb-address2" name="address_2" type="text">
                    <div class="pb-grid">
                        <div class="pb-field">
                            <label for="pb-city">Town/City</label>
                            <input id="pb-city" name="city" type="text" required>
                        </div>
                        <div class="pb-field">
                            <label for="pb-county">County</label>
                            <input id="pb-county" name="county" type="text" required>
                        </div>
                    </div>
                    <div class="pb-grid">
                        <div class="pb-field">
                            <label for="pb-country">Country</label>
                            <input id="pb-country" name="country" type="text" required value="United Kingdom">
                        </div>
                        <div class="pb-field">
                            <label for="pb-postcode">Postcode</label>
                            <input id="pb-postcode" name="postcode" type="text" required>
                        </div>
                    </div>
                </fieldset>

                <div class="pb-consent">
                    <label>
                        <input type="checkbox" name="gdpr_consent" id="pb-gdpr" required>
                        I agree to be contacted about my sample request and understand how my data will be used.
                    </label>
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
        $css = <<<'CSS'
.pb-step[hidden]{display:none!important}
#pb-samples-form .pb-products{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}
#pb-samples-form .pb-card{border:1px solid #ccc;padding:12px}
#pb-samples-form .pb-card img{width:100%;height:auto}
#pb-samples-form .pb-card.is-selected{outline:2px solid #ff9f23}
#pb-samples-form .pb-nav{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
#pb-samples-form .pb-review-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
#pb-samples-form .pb-review-tile{aspect-ratio:1/1;position:relative;overflow:hidden}
#pb-samples-form .pb-review-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
#pb-samples-form .pb-check-group{display:grid;gap:8px}
#pb-samples-form .pb-check-group label{display:flex;align-items:center;gap:8px}
#pb-samples-form .pb-label{display:block;margin-bottom:6px;font-weight:600}
.pb-status .ok{color:#166534}
.pb-status .warn{color:#9a3412}
.pb-status .err{color:#991b1b}
@media(max-width:1024px){#pb-samples-form .pb-products{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
@media(max-width:768px){#pb-samples-form .pb-products{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:640px){#pb-samples-form .pb-review-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
CSS;
        wp_register_style('pb-samples', false);
        wp_add_inline_style('pb-samples', $css);
        wp_enqueue_style('pb-samples');

        wp_register_script('pb-samples-js', '', [], PBSR_VER, true);
        wp_enqueue_script('pb-samples-js');
        wp_localize_script('pb-samples-js', 'PBSAMPLES', ['ajax_url' => admin_url('admin-ajax.php')]);

        $js = <<<'JS'
(function(){
    function esc(s){
        var str = String(s || "");
        var map = {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"};
        return str.replace(/[&<>"]/g, function(ch){ return map[ch]; });
    }

    function tracking(){
        try {
            return window.PBSRAttribution && window.PBSRAttribution.read ? window.PBSRAttribution.read() : {};
        } catch (e) {
            return {};
        }
    }

    function scan(){
        document.querySelectorAll("#pb-samples-form").forEach(initForm);
    }

    function initForm(form){
        if (!form || form.dataset.pbInit) {
            return;
        }

        form.dataset.pbInit = "1";
        var steps = [].slice.call(form.querySelectorAll(".pb-step"));
        var max = parseInt((form.querySelector("#pb-max") || {value:"4"}).value, 10) || 4;

        function picks(){
            return [].slice.call(form.querySelectorAll(".pb-choice:checked"));
        }

        function stepIndex(){
            return steps.findIndex(function(step){ return !step.hidden; });
        }

        function show(index){
            steps.forEach(function(step, stepIndex){
                step.hidden = stepIndex !== index;
            });

            if (index === 2) {
                buildReview();
                renderGrid();
            }
        }

        function setContext(){
            var page = form.querySelector('input[name="page_url"]');
            var ref = form.querySelector('input[name="referrer"]');
            var attr = tracking();

            if (page) {
                page.value = window.location.href;
            }

            if (ref) {
                ref.value = attr.referrer || document.referrer || "";
            }
        }

        function update(){
            var count = picks().length;
            var indicator = form.querySelector("#pb-step-indicator");

            if (indicator) {
                indicator.textContent = count + "/" + max + " selected";
            }

            form.querySelectorAll(".pb-card").forEach(function(card){
                var choice = card.querySelector(".pb-choice");
                card.classList.toggle("is-selected", !!(choice && choice.checked));
            });
        }

        function buildReview(){
            var out = [];
            var sels = picks().map(function(choice){ return choice.dataset.name || choice.value; });
            function getValue(id){
                var el = form.querySelector("#" + id);
                return el && el.value ? el.value : "";
            }
            function getCheckedValues(name){
                return [].slice.call(form.querySelectorAll('input[name="' + name + '"]:checked')).map(function(input){
                    return input.value || "";
                }).filter(Boolean);
            }

            var projectTypes = getCheckedValues("project_type[]");
            var projectSize = getValue("pb-project-size");

            out.push("<p><strong>Name:</strong> " + esc(getValue("pb-first")) + " " + esc(getValue("pb-last")) + "</p>");
            out.push("<p><strong>Email:</strong> " + esc(getValue("pb-email")) + "</p>");
            out.push("<p><strong>Phone:</strong> " + esc(getValue("pb-phone")) + "</p>");
            if (projectTypes.length) {
                out.push("<p><strong>Project type:</strong> " + esc(projectTypes.join(", ")) + "</p>");
            }
            if (projectSize) {
                out.push("<p><strong>Project size:</strong> " + esc(projectSize) + " m&sup2;</p>");
            }
            out.push("<p><strong>Selected products:</strong> " + esc(sels.join(", ")) + "</p>");

            var review = form.querySelector("#pb-review");
            if (review) {
                review.innerHTML = out.join("");
            }
        }

        function renderGrid(){
            var grid = form.querySelector("#pb-review-grid");
            if (!grid) {
                return;
            }

            var items = picks().map(function(choice){
                return {
                    name: choice.dataset.name || choice.value,
                    thumb: choice.dataset.thumb || ""
                };
            });

            if (!items.length) {
                grid.innerHTML = "";
                return;
            }

            grid.innerHTML = items.map(function(item){
                return "<div class=\"pb-review-tile\">" + (item.thumb ? "<img src=\"" + esc(item.thumb) + "\" alt=\"" + esc(item.name) + "\">" : "") + "</div>";
            }).join("");
        }

        form.addEventListener("input", function(e){
            if (e.target && e.target.id === "pb-search") {
                var query = (e.target.value || "").toLowerCase();
                form.querySelectorAll(".pb-card").forEach(function(card){
                    var name = ((card.querySelector(".pb-name") || {}).textContent || "").toLowerCase();
                    card.style.display = (!query || name.indexOf(query) !== -1) ? "" : "none";
                });
            }
        });

        form.addEventListener("change", function(e){
            if (e.target && e.target.classList.contains("pb-choice")) {
                if (picks().length > max) {
                    e.target.checked = false;
                    alert("You can select a maximum of " + max + " samples.");
                }
                update();
            }

            if (e.target && e.target.id === "pb-enquiry") {
                var wrap = form.querySelector("#pb-org-wrap");
                if (wrap) {
                    wrap.style.display = (e.target.value && e.target.value !== "homeowner") ? "block" : "none";
                }
            }
        });

        form.addEventListener("click", function(e){
            var next = e.target.closest("[data-next]");
            if (next) {
                var current = stepIndex();
                if (current === 0 && picks().length === 0) {
                    alert("Please select at least one sample.");
                    return;
                }
                if (current === 1 && !form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                show(Math.min(current + 1, 2));
                return;
            }

            var prev = e.target.closest("[data-prev]");
            if (prev) {
                show(Math.max(stepIndex() - 1, 0));
                return;
            }

            var submit = e.target.closest("#pb-submit");
            if (submit) {
                var status = form.querySelector("#pb-status");
                var finish = form.querySelector("#pb-finish");

                setContext();
                var fd = new FormData(form);
                submit.disabled = true;

                if (status) {
                    status.innerHTML = "<p>Submitting...</p>";
                }

                picks().forEach(function(choice){
                    fd.append("product_names[]", choice.dataset.name || choice.value);
                    fd.append("product_skus[]", choice.dataset.sku || "");
                });

                fetch(PBSAMPLES.ajax_url, {
                    method: "POST",
                    body: fd,
                    headers: {"X-Requested-With":"XMLHttpRequest"}
                })
                .then(function(response){ return response.json(); })
                .then(function(response){
                    var relay = response && response.data && response.data.relay ? response.data.relay : null;

                    if (!response || !response.success || !relay) {
                        if (status) {
                            status.innerHTML = "<p class=\"err\">" + ((response && response.data && response.data.message) || "Submission failed") + "</p>";
                        }
                        submit.disabled = false;
                        return;
                    }

                    if (relay.status === "accepted" || relay.status === "duplicate") {
                        if (status) {
                            status.innerHTML = "<p class=\"ok\">" + esc(relay.message || "Submission successful") + "</p>";
                        }
                        if (finish) {
                            finish.hidden = false;
                        }
                        return;
                    }

                    if (relay.status === "blocked") {
                        if (status) {
                            status.innerHTML = "<p class=\"warn\">" + esc(relay.message || "This request cannot be submitted right now.") + "</p>";
                        }
                        if (finish) {
                            finish.hidden = false;
                        }
                        return;
                    }

                    if (status) {
                        status.innerHTML = "<p class=\"err\">" + esc(relay.message || "Submission failed") + "</p>";
                    }
                    submit.disabled = false;
                })
                .catch(function(){
                    if (status) {
                        status.innerHTML = "<p class=\"err\">Submission failed</p>";
                    }
                    submit.disabled = false;
                });
                return;
            }

            var finish = e.target.closest("#pb-finish");
            if (finish && window.elementorProFrontend && elementorProFrontend.modules && elementorProFrontend.modules.popup) {
                try {
                    elementorProFrontend.modules.popup.closePopup();
                } catch (ex) {}
            }
        });

        setContext();
        update();
        show(0);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", scan);
    } else {
        scan();
    }

    if (window.jQuery) {
        jQuery(document).on("elementor/popup/show", scan);
    }
})();
JS;

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
        $project_type = self::sanitize_project_types((array) wp_unslash($_POST['project_type'] ?? []));
        $project_size_raw = sanitize_text_field(wp_unslash($_POST['project_size_m2'] ?? ''));
        $project_size = ($project_size_raw !== '' && preg_match('/^\d+$/', $project_size_raw)) ? (int) $project_size_raw : '';

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

        if (!$first || !$last || !$email || !$phone || !$street || !$city || !$county || !$country || !$postcode || !$enquiry || $gdpr !== 'yes' || empty($product_names)) {
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
                'gdpr_consent' => true,
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

    private static function sanitize_project_types(array $values) {
        $allowed = ['Path/Patio', 'Driveway', 'Other'];
        $sanitized = array_map('sanitize_text_field', $values);

        return array_values(array_intersect($allowed, $sanitized));
    }
}

PBSR_Product_Selection_Form::init();
