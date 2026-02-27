<?php
if (!defined('ABSPATH')) exit;

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
        $cats  = array_filter(array_map('trim', explode(',', $atts['categories'])));
        $max   = (int) $atts['max'];

        $auto_check = '';
        if (is_singular('product')) {
            $auto_check = get_the_title(get_the_ID());
        }

        ob_start(); ?>
        <form id="pb-samples-form" class="pb-form" novalidate>
            <input type="hidden" name="action" value="pb_submit_samples">
            <input type="hidden" name="pbsamples_nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" id="pb-max" value="<?php echo esc_attr($max); ?>">
            <input type="hidden" name="max" value="<?php echo esc_attr($max); ?>">
            <input type="text" name="website" class="hp" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;">
            <input type="hidden" name="page_url" value="<?php echo esc_url(home_url(add_query_arg([]))); ?>">
            <input type="hidden" name="referrer" value="<?php echo esc_url(wp_get_referer()); ?>">
            <input type="hidden" name="current_product" value="<?php echo esc_attr($auto_check); ?>">
            <input type="hidden" name="pbsr_enable" value="1">

            <section class="pb-step" data-step="1">
                <h3>Select up to <span id="pb-max-count"><?php echo esc_html($max); ?></span> samples</h3>
                <p class="pb-intro">Choose up to four blends you’d like to receive as samples. Once you’ve made your selections, click ‘Next’ to enter your delivery details.</p>

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
                    if (!$term || is_wp_error($term)) continue;

                    $q = new WP_Query([
                        'post_type' => ['product', 'products'],
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'tax_query' => [[
                            'taxonomy' => 'product_category',
                            'field'    => 'slug',
                            'terms'    => $slug,
                        ]],
                        'fields' => 'ids',
                    ]);

                    if ($q->have_posts()) {
                        $open = $slug === 'resin-bound-stone-blends' ? ' open' : '';
                        echo '<details class="pb-accordion"' . $open . '>';
                        echo '<summary class="pb-cat-title" data-cat="' . esc_attr($slug) . '">' . esc_html($term->name) . '</summary>';
                        echo '<div class="pb-grid pb-products" data-cat="' . esc_attr($slug) . '">';
                        foreach ($q->posts as $pid) {
                            if (class_exists('PBSR_Product_Availability') && PBSR_Product_Availability::is_unavailable($pid)) {
                                continue;
                            }

                            $name  = get_the_title($pid);
                            $thumb = get_the_post_thumbnail_url($pid, 'medium_large');
                            $sku = self::get_product_sku($pid);

                            if (PBSR_Mapper::isSampleHidden($name, $sku, $hidden)) {
                                continue;
                            }

                            $ptype = wp_get_post_terms($pid, 'product_type', ['fields' => 'slugs']);
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
                                <?php if ($thumb): ?><img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($name); ?>"><?php endif; ?>
                                <span class="pb-name"><?php echo esc_html($name); ?></span>
                            </label>
                            <?php
                        }
                        echo '</div></details>';
                        wp_reset_postdata();
                    }
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
        $css = '.pb-step[hidden]{display:none!important}.pb-products{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}.pb-card{border:1px solid #ccc;padding:12px}.pb-card img{width:100%;height:auto}.pb-card.is-selected{outline:2px solid #ff9f23}.pb-nav{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}.pb-review-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.pb-review-tile{aspect-ratio:1/1;position:relative;overflow:hidden}.pb-review-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}@media(max-width:640px){.pb-review-grid{grid-template-columns:repeat(2,1fr)}}';
        wp_register_style('pb-samples', false);
        wp_add_inline_style('pb-samples', $css);
        wp_enqueue_style('pb-samples');

        wp_register_script('pb-samples-js', '', [], null, true);
        wp_enqueue_script('pb-samples-js');
        wp_localize_script('pb-samples-js', 'PBSAMPLES', ['ajax_url' => admin_url('admin-ajax.php')]);

        $js = '(function(){
            function esc(s){var str=String(s||"");var map={"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"};return str.replace(/[&<>"]/g,function(ch){return map[ch];});}
            function scan(){document.querySelectorAll("#pb-samples-form").forEach(initForm);} 
            function initForm(form){ if(!form||form.dataset.pbInit)return; form.dataset.pbInit="1";
                const steps=[...form.querySelectorAll(".pb-step")];
                const max=parseInt((form.querySelector("#pb-max")||{value:"4"}).value,10)||4;
                const picks=()=>[...form.querySelectorAll(".pb-choice:checked")];
                const stepIndex=()=>steps.findIndex(s=>!s.hidden);
                const show=(i)=>{steps.forEach((s,k)=>s.hidden=k!==i); if(i===2){buildReview();renderGrid();}};
                const update=()=>{const c=picks().length; const ce=form.querySelector("#pb-count"); if(ce)ce.textContent=String(c); form.querySelectorAll(".pb-card").forEach(card=>{const cb=card.querySelector(".pb-choice"); card.classList.toggle("is-selected", !!(cb&&cb.checked));}); const ind=form.querySelector("#pb-step-indicator"); if(ind)ind.textContent=c+"/"+max+" selected";};
                const buildReview=()=>{const out=[]; const g=(id)=>{const e=form.querySelector("#"+id); return e&&e.value?e.value:"";}; const sels=picks().map(c=>c.dataset.name||c.value); out.push("<p><strong>Name:</strong> "+esc(g("pb-first"))+" "+esc(g("pb-last"))+"</p>"); out.push("<p><strong>Email:</strong> "+esc(g("pb-email"))+"</p>"); out.push("<p><strong>Phone:</strong> "+esc(g("pb-phone"))+"</p>"); out.push("<p><strong>Selected products:</strong> "+esc(sels.join(", "))+"</p>"); const rev=form.querySelector("#pb-review"); if(rev)rev.innerHTML=out.join("");};
                const renderGrid=()=>{const grid=form.querySelector("#pb-review-grid"); if(!grid)return; const items=picks().map(c=>({name:c.dataset.name||c.value,thumb:c.dataset.thumb||""})); if(!items.length){grid.innerHTML=""; return;} grid.innerHTML=items.map(it=>"<div class=\"pb-review-tile\">"+(it.thumb?"<img src=\""+esc(it.thumb)+"\" alt=\""+esc(it.name)+"\">":"")+"</div>").join("");};
                form.addEventListener("input",function(e){ if(e.target&&e.target.id==="pb-search"){const q=(e.target.value||"").toLowerCase(); form.querySelectorAll(".pb-card").forEach(card=>{const name=(card.querySelector(".pb-name")?.textContent||"").toLowerCase(); card.style.display=(!q||name.indexOf(q)!==-1)?"":"none";}); }});
                form.addEventListener("change",function(e){ if(e.target&&e.target.classList.contains("pb-choice")){ if(picks().length>max){e.target.checked=false; alert("You can select a maximum of "+max+" samples.");} update(); }
                    if(e.target&&e.target.id==="pb-enquiry"){const wrap=form.querySelector("#pb-org-wrap"); if(wrap){wrap.style.display=(e.target.value&&e.target.value!=="homeowner")?"block":"none";}}
                });
                form.addEventListener("click",function(e){
                    const next=e.target.closest("[data-next]"); if(next){const i=stepIndex(); if(i===0&&picks().length===0){alert("Please select at least one sample."); return;} if(i===1&&!form.checkValidity()){form.reportValidity(); return;} show(Math.min(i+1,2)); return;}
                    const prev=e.target.closest("[data-prev]"); if(prev){show(Math.max(stepIndex()-1,0)); return;}
                    const submit=e.target.closest("#pb-submit"); if(submit){ const status=form.querySelector("#pb-status"); submit.disabled=true; if(status)status.innerHTML="<p>Submitting...</p>"; const fd=new FormData(form); picks().forEach(c=>{fd.append("product_names[]",c.dataset.name||c.value);fd.append("product_skus[]",c.dataset.sku||"");}); fetch(PBSAMPLES.ajax_url,{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}}).then(r=>r.json()).then(data=>{ if(data&&data.success){ if(status)status.innerHTML="<p class=\"ok\">Submission successful</p>"; const fin=form.querySelector("#pb-finish"); if(fin)fin.hidden=false; } else { if(status)status.innerHTML="<p class=\"err\">"+((data&&data.data&&data.data.message)||"Submission failed")+"</p>"; submit.disabled=false; } }).catch(()=>{ if(status)status.innerHTML="<p class=\"err\">Submission failed</p>"; submit.disabled=false; }); return; }
                    const fin=e.target.closest("#pb-finish"); if(fin){ if(window.elementorProFrontend&&elementorProFrontend.modules&&elementorProFrontend.modules.popup){try{elementorProFrontend.modules.popup.closePopup();}catch(ex){}} }
                });
                update(); show(0);
            }
            if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",scan);}else{scan();}
            if(window.jQuery){jQuery(document).on("elementor/popup/show",scan);} 
        })();';

        wp_add_inline_script('pb-samples-js', $js);
    }

    public static function handle_submission() {
        if (!empty($_POST['website'])) wp_send_json_error(['message' => 'Spam blocked.']);
        if (!isset($_POST['pbsamples_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pbsamples_nonce'])), 'pbsamples_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce.']);
        }

        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last  = sanitize_text_field(wp_unslash($_POST['surname'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $enquiry = sanitize_text_field(wp_unslash($_POST['enquiry_type'] ?? ''));
        $org   = sanitize_text_field(wp_unslash($_POST['organisation_name'] ?? ''));
        $street= sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $addr2 = sanitize_text_field(wp_unslash($_POST['address_2'] ?? ''));
        $city  = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $county= sanitize_text_field(wp_unslash($_POST['county'] ?? ''));
        $country = sanitize_text_field(wp_unslash($_POST['country'] ?? ''));
        $postcode= sanitize_text_field(wp_unslash($_POST['postcode'] ?? ''));
        $gdpr = isset($_POST['gdpr_consent']) ? 'yes' : 'no';
        $page_url = esc_url_raw(wp_unslash($_POST['page_url'] ?? ''));
        $referrer = esc_url_raw(wp_unslash($_POST['referrer'] ?? ''));
        $current_product = sanitize_text_field(wp_unslash($_POST['current_product'] ?? ''));

        $product_names = array_map('sanitize_text_field', (array) wp_unslash($_POST['product_names'] ?? ($_POST['product_selection'] ?? [])));
        $product_skus  = array_map('sanitize_text_field', (array) wp_unslash($_POST['product_skus'] ?? []));

        $max = (int) ($_POST['max'] ?? 4);
        if ($max < 1) $max = 4;

        $product_names = array_values(array_slice(array_unique($product_names), 0, $max));
        $aligned_skus = [];
        foreach ($product_names as $i => $nm) {
            $aligned_skus[$i] = $product_skus[$i] ?? '';
        }

        if (!$first || !$last || !$email || !$phone || !$street || !$city || !$county || !$country || !$postcode || !$enquiry || $gdpr !== 'yes' || empty($product_names)) {
            wp_send_json_error(['message' => 'Missing required fields.']);
        }

        $samples = [];
        foreach ($product_names as $i => $nm) {
            $samples[] = [
                'name' => $nm,
                'sku'  => $aligned_skus[$i] ?? '',
            ];
        }

        $raw = [
            'source' => 'permabound_sample_request',
            'contact' => [
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'phone'      => $phone,
                'enquiry_type' => $enquiry,
                'organisation_name' => $org,
                'gdpr_consent' => true,
            ],
            'shipping' => [
                'street'   => $street,
                'address_2'=> $addr2,
                'city'     => $city,
                'county'   => $county,
                'country'  => $country,
                'postcode' => $postcode,
            ],
            'samples' => $samples,
            'sample_names' => $product_names,
            'context' => [
                'page_url' => $page_url,
                'referrer' => $referrer,
                'current_product' => $current_product,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ],
        ];

        $idem = md5(wp_json_encode(['pb_submit_samples', $email, $samples, date('Y-m-d-H')]));
        $res = PBSR_Dispatcher::process($raw, 'permabound_sample_request', $idem);

        if (!empty($res['ok'])) {
            wp_send_json_success(['ok' => true, 'relay' => $res]);
        }

        wp_send_json_error(['message' => 'Relay processing failed.', 'relay' => $res]);
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
}

PBSR_Product_Selection_Form::init();
