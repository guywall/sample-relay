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
        register_setting('pbsr_group', 'pbsr_settings');
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = get_option('pbsr_settings', []);
        $form_fields = PBSR_Settings::normalize_form_fields($s['form_fields'] ?? []);
        ?>
        <div class="wrap">
            <h1>PERMABOUND Sample Relay</h1>

            <form method="post" action="options.php">
                <?php settings_fields('pbsr_group'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th>Zoho DC</th>
                        <td><input name="pbsr_settings[zoho_dc]" value="<?php echo esc_attr($s['zoho_dc'] ?? 'eu'); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Books Org ID</th>
                        <td><input name="pbsr_settings[org_id]" value="<?php echo esc_attr($s['org_id'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td><input name="pbsr_settings[client_id]" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input name="pbsr_settings[client_secret]" value="<?php echo esc_attr($s['client_secret'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th>Refresh Token</th>
                        <td><input name="pbsr_settings[refresh_token]" value="<?php echo esc_attr($s['refresh_token'] ?? ''); ?>" size="60" /></td>
                    </tr>
                    <tr>
                        <th>Enable CRM</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pbsr_settings[enable_crm]" value="1" <?php checked(!empty($s['enable_crm'])); ?> />
                                Send data to Zoho CRM
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Enable Books</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pbsr_settings[enable_books]" value="1" <?php checked(!empty($s['enable_books'])); ?> />
                                Send data to Zoho Books
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Sample Cost Override (£)</th>
                        <td>
                            <input type="number" name="pbsr_settings[sample_cost_override]" value="<?php echo esc_attr($s['sample_cost_override'] ?? ''); ?>" step="0.01" min="0" style="width:120px;">
                            <br><small>If set, this price overrides the default rate for all sample line items in Zoho Books.</small>
                        </td>
                    </tr>
                    <tr>
                        <th>Repeat Limit (days)</th>
                        <td>
                            <input type="number" name="pbsr_settings[repeat_limit_days]" value="<?php echo esc_attr($s['repeat_limit_days'] ?? 30); ?>" step="1" min="1" style="width:120px;">
                            <br><small>Requests from the same email or household are blocked until this window expires.</small>
                        </td>
                    </tr>
                    <tr>
                        <th>Google Places API Key</th>
                        <td>
                            <input type="text" name="pbsr_settings[google_places_api_key]" value="<?php echo esc_attr($s['google_places_api_key'] ?? ''); ?>" style="width:400px;" autocomplete="off">
                            <br><small>Used for address autocomplete on the sample request form. Store a rotated, referrer-restricted key here instead of hardcoding it in the plugin.</small>
                        </td>
                    </tr>
                </table>

                <h3>First Page Form Fields</h3>
                <p>Control the contact and delivery field layout on the first details page.</p>
                <style>
                    .pbsr-field-options { max-width: 760px; }
                    .pbsr-field-options th,
                    .pbsr-field-options td { vertical-align: middle; }
                    .pbsr-field-options select { min-width: 120px; }
                </style>
                <table class="widefat striped pbsr-field-options">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Width</th>
                            <th>Mandatory</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($form_fields as $key => $field): ?>
                            <tr>
                                <td><?php echo esc_html($field['label']); ?></td>
                                <td>
                                    <select name="pbsr_settings[form_fields][<?php echo esc_attr($key); ?>][width]">
                                        <option value="half" <?php selected($field['width'], 'half'); ?>>1/2 width</option>
                                        <option value="full" <?php selected($field['width'], 'full'); ?>>Full width</option>
                                    </select>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="pbsr_settings[form_fields][<?php echo esc_attr($key); ?>][required]" value="1" <?php checked(!empty($field['required'])); ?> />
                                        Required
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3>Email Notifications</h3>
                <p>
                    <label>
                        <input type="checkbox" name="pbsr_settings[enable_notify]" value="1" <?php checked(!empty($s['enable_notify'])); ?> />
                        Enable email notifications
                    </label>
                </p>
                <p>
                    <label>Notification recipient(s)</label><br>
                    <input type="text" name="pbsr_settings[notify_emails]" value="<?php echo esc_attr($s['notify_emails'] ?? ''); ?>" style="width:400px;">
                    <br><small>Comma-separated list of addresses, e.g. <code>sales@yourdomain.com, accounts@yourdomain.com</code></small>
                </p>

                <h3>Allowed Sources</h3>
                <p>
                    <label>Allowed Sources (comma-separated)</label><br>
                    <input type="text" name="pbsr_settings[allowed_sources]" value="<?php echo esc_attr($s['allowed_sources'] ?? 'permabound_sample_request'); ?>" style="width:400px;">
                    <br><small>Only forms with these source values will be processed. Others are skipped.</small>
                </p>

                <h3>Sample Availability</h3>
                <p>
                    <label>Hidden / unavailable samples (SKU or name, one per line)</label><br>
                    <textarea name="pbsr_settings[hidden_samples]" rows="6" style="width:400px;"><?php echo esc_textarea($s['hidden_samples'] ?? ''); ?></textarea>
                    <br><small>Matching is case-insensitive and checks both SKU and sample name.</small>
                    <br><small>You can also mark individual products as unavailable on the product edit screen.</small>
                </p>

                <h3>Sample Availability</h3>
                <p>
                    <label>Hidden / unavailable samples (SKU or name, one per line)</label><br>
                    <textarea name="pbsr_settings[hidden_samples]" rows="6" style="width: 400px;"><?php echo esc_textarea($s['hidden_samples'] ?? ''); ?></textarea>
                    <br><small>Use this to temporarily hide samples that are out of stock. Matching is case-insensitive and checks both SKU and sample name.</small>
                    <br><small>You can also mark individual products as unavailable directly on the product edit screen using the "Sample Availability" checkbox.</small>
                </p>

                <p class="submit"><button class="button button-primary">Save Settings</button></p>
            </form>
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
            .log-body { max-height:160px; overflow:auto; font-family:monospace; white-space:pre-wrap; background:#fff; }
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
            echo '<td><div class="log-body">' . esc_html(substr(print_r($log['data'], true), 0, 1500)) . '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

PBSR_Admin_Page::init();
