<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Admin_Page {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function menu() {
        add_menu_page(
            'Sample Relay',
            'Sample Relay',
            'manage_options',
            'pbsr_admin',
            [__CLASS__, 'render'],
            'dashicons-randomize',
            58
        );

        add_submenu_page(
            'pbsr_admin',
            'Relay Logs',
            'Relay Logs',
            'manage_options',
            'pbsr_admin_logs',
            [__CLASS__, 'render_logs']
        );
    }

    public static function register() {
        register_setting('pbsr_group', 'pbsr_settings', [
            'sanitize_callback' => ['PBSR_Settings', 'sanitize'],
        ]);
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = PBSR_Settings::get();
        $fields = $s['form_fields'];
        $mapping_rules = $s['mapping_rules'];
        $crm_fields = PBSR_Zoho_Field_Manager::getCachedFields('crm');
        $books_contact_fields = PBSR_Zoho_Field_Manager::getCachedFields('books_contact');
        $books_document_fields = PBSR_Zoho_Field_Manager::getCachedFields('books_document');
        $sync_errors = PBSR_Zoho_Field_Manager::getSyncErrors();
        $legacy_mappings = PBSR_Zoho_Field_Manager::legacyMappingReference();
        ?>
        <div class="wrap">
            <h1>PERMABOUND Sample Relay</h1>
            <?php self::render_notices(); ?>

            <style>
                .pbsr-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:16px 0}
                .pbsr-flex{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
                .pbsr-flex > .pbsr-card{flex:1 1 360px}
                .pbsr-field-table{width:100%;border-collapse:collapse}
                .pbsr-field-table th,.pbsr-field-table td{border:1px solid #e2e8f0;padding:8px;vertical-align:top}
                .pbsr-field-table th{background:#f8fafc;text-align:left}
                .pbsr-field-table input[type="text"],.pbsr-field-table input[type="number"],.pbsr-field-table select,.pbsr-field-table textarea{width:100%}
                .pbsr-row-actions{display:flex;gap:6px;flex-wrap:wrap}
                .pbsr-helper{color:#50575e}
                .pbsr-mono{font-family:Consolas,Monaco,monospace}
                .pbsr-sync-meta{margin:0 0 12px}
                .pbsr-inline-note{margin-top:8px}
                .pbsr-small{font-size:12px;color:#50575e}
            </style>

            <?php self::render_mapping_datalist('pbsr-crm-targets', $crm_fields); ?>
            <?php self::render_mapping_datalist('pbsr-books-contact-targets', $books_contact_fields); ?>
            <?php self::render_mapping_datalist('pbsr-books-document-targets', $books_document_fields); ?>

            <div class="pbsr-flex">
                <div class="pbsr-card">
                    <h2>Zoho Connection</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('pbsr_group'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Zoho DC</th>
                                <td><input name="pbsr_settings[zoho_dc]" value="<?php echo esc_attr($s['zoho_dc']); ?>"></td>
                            </tr>
                            <tr>
                                <th>CRM Module</th>
                                <td>
                                    <select name="pbsr_settings[crm_module]">
                                        <option value="Contacts" <?php selected($s['crm_module'], 'Contacts'); ?>>Contacts</option>
                                        <option value="Leads" <?php selected($s['crm_module'], 'Leads'); ?>>Leads</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Books Doc Type</th>
                                <td>
                                    <select name="pbsr_settings[books_doc_type]">
                                        <option value="salesorder" <?php selected($s['books_doc_type'], 'salesorder'); ?>>Sales Order</option>
                                        <option value="estimate" <?php selected($s['books_doc_type'], 'estimate'); ?>>Estimate</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Books Org ID</th>
                                <td><input name="pbsr_settings[org_id]" value="<?php echo esc_attr($s['org_id']); ?>"></td>
                            </tr>
                            <tr>
                                <th>Client ID</th>
                                <td><input name="pbsr_settings[client_id]" value="<?php echo esc_attr($s['client_id']); ?>"></td>
                            </tr>
                            <tr>
                                <th>Client Secret</th>
                                <td><input name="pbsr_settings[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>"></td>
                            </tr>
                            <tr>
                                <th>Refresh Token</th>
                                <td><input name="pbsr_settings[refresh_token]" value="<?php echo esc_attr($s['refresh_token']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Enable CRM</th>
                                <td><label><input type="checkbox" name="pbsr_settings[enable_crm]" value="1" <?php checked(!empty($s['enable_crm'])); ?>> Send data to Zoho CRM</label></td>
                            </tr>
                            <tr>
                                <th>Enable Books</th>
                                <td><label><input type="checkbox" name="pbsr_settings[enable_books]" value="1" <?php checked(!empty($s['enable_books'])); ?>> Send data to Zoho Books</label></td>
                            </tr>
                        </table>

                        <h2>Relay Settings</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Sample Cost Override (£)</th>
                                <td><input type="number" name="pbsr_settings[sample_cost_override]" value="<?php echo esc_attr($s['sample_cost_override']); ?>" step="0.01" min="0"></td>
                            </tr>
                            <tr>
                                <th>Repeat Limit (days)</th>
                                <td><input type="number" name="pbsr_settings[repeat_limit_days]" value="<?php echo esc_attr($s['repeat_limit_days']); ?>" step="1" min="1"></td>
                            </tr>
                            <tr>
                                <th>Allowed Sources</th>
                                <td>
                                    <input type="text" name="pbsr_settings[allowed_sources]" value="<?php echo esc_attr($s['allowed_sources']); ?>" class="regular-text">
                                    <p class="pbsr-small">Comma-separated source keys that are allowed to process submissions.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Hidden / unavailable samples</th>
                                <td><textarea name="pbsr_settings[hidden_samples]" rows="5" class="large-text"><?php echo esc_textarea($s['hidden_samples']); ?></textarea></td>
                            </tr>
                        </table>

                        <h2>Email Notifications</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Enable notifications</th>
                                <td><label><input type="checkbox" name="pbsr_settings[enable_notify]" value="1" <?php checked(!empty($s['enable_notify'])); ?>> Send admin notification emails</label></td>
                            </tr>
                            <tr>
                                <th>Recipients</th>
                                <td><input type="text" name="pbsr_settings[notify_emails]" value="<?php echo esc_attr($s['notify_emails']); ?>" class="regular-text"></td>
                            </tr>
                        </table>

                        <div class="pbsr-card" style="padding:16px 0 0;border:none;box-shadow:none;margin:0;">
                            <h2>Shortcode Form Builder</h2>
                            <p class="pbsr-helper">This controls only the built-in shortcode form details step. Locked relay fields cannot be deleted, but you can edit their labels and position.</p>
                            <table class="pbsr-field-table" id="pbsr-field-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Order</th>
                                        <th style="width:120px;">Key</th>
                                        <th style="width:160px;">Label</th>
                                        <th style="width:100px;">Type</th>
                                        <th style="width:70px;">Required</th>
                                        <th style="width:70px;">Hidden</th>
                                        <th>Default</th>
                                        <th>Placeholder</th>
                                        <th>Help Text</th>
                                        <th>Options</th>
                                        <th>Condition</th>
                                        <th style="width:90px;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_values($fields) as $index => $field) : ?>
                                    <?php self::render_field_row($field, $index, $fields); ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="pbsr-inline-note"><button type="button" class="button" id="pbsr-add-field">Add Field</button></p>
                            <p class="pbsr-small">Options format: one per line as <span class="pbsr-mono">value|Label</span>. Use hidden fields for system/default values that should submit without showing in the form.</p>
                        </div>

                        <div class="pbsr-card" style="padding:16px 0 0;border:none;box-shadow:none;margin:0;">
                            <h2>Zoho Mapping Builder</h2>
                            <p class="pbsr-helper">Each saved field can map to CRM, Books contact, and Books document targets at the same time. Save field changes first if a newly added field is missing here. You can type API names manually even if Zoho field sync is unavailable.</p>
                            <table class="pbsr-field-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>CRM Target</th>
                                        <th>Books Contact Target</th>
                                        <th>Books Document Target</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($fields as $field) : ?>
                                    <?php $map = $mapping_rules[$field['key']] ?? ['crm' => '', 'books_contact' => '', 'books_document' => '']; ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($field['label']); ?></strong><br>
                                            <span class="pbsr-small pbsr-mono"><?php echo esc_html($field['key']); ?></span>
                                        </td>
                                        <td><?php self::render_mapping_input('pbsr_settings[mapping_rules][' . $field['key'] . '][crm]', $map['crm'] ?? '', 'pbsr-crm-targets', 'CRM API name or custom field id'); ?></td>
                                        <td><?php self::render_mapping_input('pbsr_settings[mapping_rules][' . $field['key'] . '][books_contact]', $map['books_contact'] ?? '', 'pbsr-books-contact-targets', 'Books contact target'); ?></td>
                                        <td><?php self::render_mapping_input('pbsr_settings[mapping_rules][' . $field['key'] . '][books_document]', $map['books_document'] ?? '', 'pbsr-books-document-targets', 'Books document target'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="pbsr-small">The hidden system field <span class="pbsr-mono">full_name</span> is available for joined-name targets such as Zoho Books <span class="pbsr-mono">contact_name</span>.</p>
                        </div>

                        <div class="pbsr-card" style="padding:16px 0 0;border:none;box-shadow:none;margin:0;">
                            <h2>Legacy Mapping Reference</h2>
                            <p class="pbsr-helper">These were the built-in mappings before the mapping builder existed. They are shown here so you can recreate or adapt them in your saved mapping table.</p>
                            <?php self::render_legacy_mapping_table('CRM defaults', $legacy_mappings['crm'] ?? []); ?>
                            <?php self::render_legacy_mapping_table('Books contact defaults', $legacy_mappings['books_contact'] ?? []); ?>
                            <?php self::render_legacy_mapping_table('Books document defaults', $legacy_mappings['books_document'] ?? []); ?>
                        </div>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>

                <div class="pbsr-card" style="max-width:420px;">
                    <h2>Zoho Field Sync</h2>
                    <p class="pbsr-sync-meta"><strong>Last synced:</strong> <?php echo esc_html(PBSR_Zoho_Field_Manager::lastSyncedAt() ?: 'Never'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('pbsr_sync_zoho_fields'); ?>
                        <input type="hidden" name="action" value="pbsr_sync_zoho_fields">
                        <p><button class="button button-secondary">Sync Zoho Fields</button></p>
                    </form>
                    <p class="pbsr-small">The sync reads available fields from the selected CRM module plus Zoho Books contact and current document type, then caches them locally for the mapping builder.</p>

                    <h3>Cached Counts</h3>
                    <ul>
                        <li>CRM fields: <?php echo esc_html(count($crm_fields)); ?></li>
                        <li>Books contact fields: <?php echo esc_html(count($books_contact_fields)); ?></li>
                        <li>Books document fields: <?php echo esc_html(count($books_document_fields)); ?></li>
                    </ul>

                    <?php if (!empty($sync_errors)) : ?>
                        <h3>Last Sync Errors</h3>
                        <ul>
                            <?php foreach ($sync_errors as $scope => $error) : ?>
                                <li><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $scope))); ?>:</strong> <?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <script type="text/html" id="tmpl-pbsr-field-row">
                <?php
                self::render_field_row([
                    'key' => '',
                    'label' => '',
                    'type' => 'text',
                    'required' => 0,
                    'hidden' => 0,
                    'default_value' => '',
                    'placeholder' => '',
                    'help_text' => '',
                    'options' => [],
                    'condition_field' => '',
                    'condition_operator' => '',
                    'condition_value' => '',
                    'locked' => 0,
                ], '__INDEX__', $fields);
                ?>
            </script>

            <script>
            (function(){
                var tableBody = document.querySelector('#pbsr-field-table tbody');
                var addButton = document.getElementById('pbsr-add-field');
                var template = document.getElementById('tmpl-pbsr-field-row');
                if (!tableBody || !addButton || !template) {
                    return;
                }

                function refreshConditionOptions() {
                    var rows = [].slice.call(tableBody.querySelectorAll('tr'));
                    var options = rows.map(function(row){
                        var keyInput = row.querySelector('.pbsr-key-input');
                        var labelInput = row.querySelector('.pbsr-label-input');
                        var key = keyInput ? keyInput.value.trim() : '';
                        var label = labelInput ? labelInput.value.trim() : key;
                        return { key: key, label: label || key };
                    }).filter(function(item){ return item.key !== ''; });

                    rows.forEach(function(row){
                        var currentKey = (row.querySelector('.pbsr-key-input') || {}).value || '';
                        var select = row.querySelector('.pbsr-condition-field');
                        if (!select) {
                            return;
                        }

                        var selected = select.getAttribute('data-selected') || select.value || '';
                        select.innerHTML = '<option value="">None</option>';
                        options.forEach(function(item){
                            if (item.key === currentKey) {
                                return;
                            }
                            var opt = document.createElement('option');
                            opt.value = item.key;
                            opt.textContent = item.label + ' (' + item.key + ')';
                            if (selected === item.key) {
                                opt.selected = true;
                            }
                            select.appendChild(opt);
                        });
                    });
                }

                function bindRow(row) {
                    var deleteButton = row.querySelector('.pbsr-delete-row');
                    var moveUp = row.querySelector('.pbsr-move-up');
                    var moveDown = row.querySelector('.pbsr-move-down');
                    var keyInput = row.querySelector('.pbsr-key-input');
                    var labelInput = row.querySelector('.pbsr-label-input');

                    if (deleteButton) {
                        deleteButton.addEventListener('click', function(){
                            if (deleteButton.disabled) {
                                return;
                            }
                            row.remove();
                            refreshConditionOptions();
                        });
                    }

                    if (moveUp) {
                        moveUp.addEventListener('click', function(){
                            var prev = row.previousElementSibling;
                            if (prev) {
                                tableBody.insertBefore(row, prev);
                            }
                        });
                    }

                    if (moveDown) {
                        moveDown.addEventListener('click', function(){
                            var next = row.nextElementSibling;
                            if (next) {
                                tableBody.insertBefore(next, row);
                            }
                        });
                    }

                    [keyInput, labelInput].forEach(function(input){
                        if (input) {
                            input.addEventListener('input', refreshConditionOptions);
                        }
                    });
                }

                [].slice.call(tableBody.querySelectorAll('tr')).forEach(bindRow);
                refreshConditionOptions();

                addButton.addEventListener('click', function(){
                    var index = Date.now().toString();
                    var html = template.innerHTML.replace(/__INDEX__/g, index);
                    var wrap = document.createElement('tbody');
                    wrap.innerHTML = html.trim();
                    var row = wrap.firstElementChild;
                    tableBody.appendChild(row);
                    bindRow(row);
                    refreshConditionOptions();
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function render_logs() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>PERMABOUND Sample Relay Logs</h1>';

        $logs = PBSR_Logger::recent(100);
        if (empty($logs)) {
            echo '<p>No log entries found.</p></div>';
            return;
        }

        echo '<style>
            table.pbsr-logs { width:100%; border-collapse:collapse; font-size:14px; }
            table.pbsr-logs th, table.pbsr-logs td { border:1px solid #ddd; padding:6px 8px; vertical-align:top; }
            table.pbsr-logs th { background:#fafafa; text-align:left; }
            table.pbsr-logs tbody tr:nth-child(even){ background:#f9f9f9; }
            .log-body { max-height:200px; overflow:auto; font-family:monospace; white-space:pre-wrap; background:#fff; }
        </style>';

        echo '<table class="pbsr-logs"><thead><tr>
            <th>Time</th><th>Status</th><th>Lead Source</th><th>Email</th><th>Source</th><th>CRM</th><th>Books</th><th>Key</th><th>Data Excerpt</th>
        </tr></thead><tbody>';

        foreach ($logs as $log) {
            $lead = trim(($log['lead_channel'] ?? '') . ' / ' . ($log['lead_source_detail'] ?? ''), ' /');
            echo '<tr>';
            echo '<td>' . esc_html($log['time'] ?? '') . '</td>';
            echo '<td>' . esc_html($log['request_status'] ?? '') . '</td>';
            echo '<td>' . esc_html($lead ?: '-') . '</td>';
            echo '<td>' . esc_html($log['requester_email'] ?? '') . '</td>';
            echo '<td>' . esc_html($log['source'] ?? '') . '</td>';
            echo '<td>' . esc_html($log['crm_status'] ?? '') . '</td>';
            echo '<td>' . esc_html($log['books_status'] ?? '') . '</td>';
            echo '<td>' . esc_html($log['key'] ?? '') . '</td>';
            echo '<td><div class="log-body">' . esc_html(substr(print_r($log['data'], true), 0, 2500)) . '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function render_notices() {
        $notice = sanitize_key($_GET['pbsr_notice'] ?? '');
        $message = isset($_GET['pbsr_message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['pbsr_message']))) : '';

        if ($notice === 'sync_ok') {
            echo '<div class="notice notice-success"><p>' . esc_html($message ?: 'Zoho fields synced successfully.') . '</p></div>';
        }

        if ($notice === 'sync_failed') {
            echo '<div class="notice notice-error"><p>' . esc_html($message ?: 'Zoho field sync failed.') . '</p></div>';
        }

        if ($notice === 'sync_partial') {
            echo '<div class="notice notice-warning"><p>' . esc_html($message ?: 'Zoho field sync partially completed.') . '</p></div>';
        }
    }

    private static function render_field_row(array $field, $index, array $all_fields) {
        $options_lines = [];
        foreach ($field['options'] ?? [] as $option) {
            $options_lines[] = ($option['value'] ?? '') . '|' . ($option['label'] ?? '');
        }
        ?>
        <tr>
            <td>
                <div class="pbsr-row-actions">
                    <button type="button" class="button-link pbsr-move-up">Up</button>
                    <button type="button" class="button-link pbsr-move-down">Down</button>
                    <button type="button" class="button-link-delete pbsr-delete-row" <?php disabled(!empty($field['locked'])); ?>>Delete</button>
                </div>
            </td>
            <td>
                <input type="text" class="pbsr-key-input pbsr-mono" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][key]" value="<?php echo esc_attr($field['key']); ?>" <?php disabled(!empty($field['locked'])); ?>>
            </td>
            <td>
                <input type="text" class="pbsr-label-input" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($field['label']); ?>">
            </td>
            <td>
                <select name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][type]" <?php disabled(!empty($field['locked'])); ?>>
                    <?php foreach (['text','email','tel','textarea','select','radio','checkbox','hidden','date','number','url'] as $type) : ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($field['type'], $type); ?>><?php echo esc_html($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><label><input type="checkbox" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][required]" value="1" <?php checked(!empty($field['required'])); ?> <?php disabled(!empty($field['locked'])); ?>></label></td>
            <td><label><input type="checkbox" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][hidden]" value="1" <?php checked(!empty($field['hidden'])); ?> <?php disabled(!empty($field['locked'])); ?>></label></td>
            <td><input type="text" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][default_value]" value="<?php echo esc_attr($field['default_value'] ?? ''); ?>"></td>
            <td><input type="text" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][placeholder]" value="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"></td>
            <td><input type="text" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][help_text]" value="<?php echo esc_attr($field['help_text'] ?? ''); ?>"></td>
            <td><textarea rows="3" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][options]"><?php echo esc_textarea(implode("\n", $options_lines)); ?></textarea></td>
            <td>
                <select class="pbsr-condition-field" data-selected="<?php echo esc_attr($field['condition_field'] ?? ''); ?>" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][condition_field]" <?php disabled(!empty($field['locked'])); ?>>
                    <option value="">None</option>
                    <?php foreach ($all_fields as $other) : ?>
                        <?php if (($other['key'] ?? '') === ($field['key'] ?? '')) { continue; } ?>
                        <option value="<?php echo esc_attr($other['key']); ?>" <?php selected($field['condition_field'] ?? '', $other['key']); ?>>
                            <?php echo esc_html(($other['label'] ?? $other['key']) . ' (' . $other['key'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][condition_operator]" <?php disabled(!empty($field['locked'])); ?>>
                    <option value="" <?php selected($field['condition_operator'] ?? '', ''); ?>>None</option>
                    <option value="equals" <?php selected($field['condition_operator'] ?? '', 'equals'); ?>>equals</option>
                    <option value="not_equals" <?php selected($field['condition_operator'] ?? '', 'not_equals'); ?>>not equals</option>
                </select>
                <input type="text" name="pbsr_settings[form_fields][<?php echo esc_attr($index); ?>][condition_value]" value="<?php echo esc_attr($field['condition_value'] ?? ''); ?>" placeholder="Match value" <?php disabled(!empty($field['locked'])); ?>>
            </td>
            <td>
                <?php if (!empty($field['locked'])) : ?>
                    <strong>Locked</strong>
                <?php else : ?>
                    <span class="pbsr-small">Custom</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function render_mapping_input($name, $selected, $list_id, $placeholder) {
        echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($selected) . '" list="' . esc_attr($list_id) . '" placeholder="' . esc_attr($placeholder) . '">';
        echo '<div class="pbsr-small">' . esc_html($placeholder) . '</div>';
    }

    private static function render_mapping_datalist($id, array $options) {
        echo '<datalist id="' . esc_attr($id) . '">';
        foreach ($options as $option) {
            $label = $option['label'] ?? ($option['target'] ?? '');
            if (!empty($option['custom'])) {
                $label .= ' [Custom]';
            }
            echo '<option value="' . esc_attr($option['target'] ?? '') . '" label="' . esc_attr($label) . '"></option>';
        }
        echo '</datalist>';
    }

    private static function render_legacy_mapping_table($title, array $rows) {
        if (empty($rows)) {
            return;
        }

        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<table class="pbsr-field-table">';
        echo '<thead><tr><th>Source</th><th>Target</th><th>Notes</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><span class="pbsr-mono">' . esc_html($row['field'] ?? '') . '</span></td>';
            echo '<td><span class="pbsr-mono">' . esc_html($row['target'] ?? '') . '</span></td>';
            echo '<td>' . esc_html($row['note'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

PBSR_Admin_Page::init();
