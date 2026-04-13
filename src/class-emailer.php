<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Emailer {
    public static function sendAdminNotification(array $settings, array $data, array $raw, array $result) {
        if (empty($settings['enable_notify']) || empty($settings['notify_emails'])) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', (string) $settings['notify_emails'])));
        if (empty($recipients)) {
            return;
        }

        $attribution = $raw['context']['attribution'] ?? [];
        $subject = sprintf(
            '[%s] Sample request %s',
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            strtolower((string) ($result['status'] ?? 'accepted'))
        );

        $address = self::fullAddress($raw, $data);
        $message = [];
        $message[] = 'Request status: ' . ($result['status'] ?? 'accepted');
        if (!empty($result['message'])) {
            $message[] = 'Message: ' . $result['message'];
        }
        if (!empty($result['blocked_reason'])) {
            $message[] = 'Blocked reason: ' . $result['blocked_reason'];
        }
        $message[] = 'Name: ' . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $message[] = 'Company: ' . ($data['company'] ?? $raw['organisation_name'] ?? '');
        $message[] = 'Email: ' . ($data['email'] ?? '');
        $message[] = 'Phone: ' . ($data['phone'] ?? '');
        if ($address !== '') {
            $message[] = 'Address: ' . $address;
        }
        $message[] = 'Samples: ' . implode(', ', $data['blends'] ?? []);
        $project_type = array_values(array_filter((array) ($raw['project_type'] ?? [])));
        if (!empty($project_type)) {
            $message[] = 'Project Type: ' . implode(', ', $project_type);
        }
        if (($raw['project_size_m2'] ?? '') !== '') {
            $message[] = 'Project Size (m2): ' . $raw['project_size_m2'];
        }
        $message[] = 'Lead channel: ' . ($attribution['channel'] ?? 'Direct');
        $message[] = 'Lead detail: ' . ($attribution['source_detail'] ?? 'Direct');
        $message[] = 'Landing page: ' . ($attribution['landing_page'] ?? '');
        $message[] = 'Submit page: ' . ($attribution['submit_page'] ?? ($raw['context']['page_url'] ?? ''));
        $message[] = 'Referrer: ' . ($attribution['referrer'] ?? ($raw['context']['referrer'] ?? ''));
        $message[] = 'UTM source: ' . ($attribution['utm_source'] ?? '');
        $message[] = 'UTM medium: ' . ($attribution['utm_medium'] ?? '');
        $message[] = 'UTM campaign: ' . ($attribution['utm_campaign'] ?? '');
        $message[] = 'UTM term: ' . ($attribution['utm_term'] ?? '');
        $message[] = 'UTM content: ' . ($attribution['utm_content'] ?? '');
        $message[] = 'gclid: ' . ($attribution['gclid'] ?? '');
        $message[] = 'msclkid: ' . ($attribution['msclkid'] ?? '');
        $message[] = 'CRM Status: ' . ($result['crm_status'] ?? 'skipped');
        $message[] = 'Books Status: ' . ($result['books_status'] ?? 'skipped');

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        foreach ($recipients as $recipient) {
            if (is_email($recipient)) {
                wp_mail($recipient, $subject, implode("\n", $message), $headers);
            }
        }
    }

    public static function sendRequesterConfirmation(array $data, array $raw) {
        $email = $data['email'] ?? '';
        if (!is_email($email)) {
            return false;
        }

        $branding = self::branding();
        $subject = sprintf('%s sample request confirmation', $branding['site_name']);
        $samples = $data['blends'] ?? [];
        $address = self::fullAddress($raw, $data);
        $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        $sample_items = '';
        foreach ($samples as $sample) {
            $sample_items .= '<li style="margin:0 0 8px;">' . esc_html($sample) . '</li>';
        }

        $logo_html = '';
        if ($branding['logo_url'] !== '') {
            $logo_html = '<p style="margin:0 0 24px;"><img src="' . esc_url($branding['logo_url']) . '" alt="' . esc_attr($branding['site_name']) . '" style="max-width:180px;height:auto;"></p>';
        }

        $body = '
            <div style="background:#f4f1ea;padding:32px 16px;font-family:Arial,sans-serif;color:#1e293b;">
                <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
                    <div style="padding:32px;">
                        ' . $logo_html . '
                        <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;color:#0f172a;">Sample request received</h1>
                        <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Hi ' . esc_html($name ?: 'there') . ',</p>
                        <p style="margin:0 0 24px;font-size:16px;line-height:1.6;">Thank you for requesting samples from ' . esc_html($branding['site_name']) . '. We have received your request and our team will process it shortly.</p>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:0 0 24px;">
                            <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Your request</h2>
                            <p style="margin:0 0 8px;font-size:15px;"><strong>Name:</strong> ' . esc_html($name) . '</p>
                            <p style="margin:0 0 8px;font-size:15px;"><strong>Email:</strong> ' . esc_html($email) . '</p>
                            <p style="margin:0;font-size:15px;"><strong>Delivery address:</strong> ' . esc_html($address) . '</p>
                        </div>
                        <div style="margin:0 0 24px;">
                            <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Selected samples</h2>
                            <ul style="padding-left:20px;margin:0;font-size:15px;line-height:1.6;">' . $sample_items . '</ul>
                        </div>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">If you have any questions in the meantime, please visit <a href="' . esc_url($branding['site_url']) . '" style="color:#9a3412;">' . esc_html($branding['site_url']) . '</a>.</p>
                    </div>
                    <div style="padding:20px 32px;background:#0f172a;color:#e2e8f0;font-size:13px;line-height:1.6;">
                        ' . esc_html($branding['site_name']) . '<br>
                        <a href="' . esc_url($branding['site_url']) . '" style="color:#fbbf24;text-decoration:none;">' . esc_html($branding['site_url']) . '</a>
                    </div>
                </div>
            </div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $reply_to = get_option('admin_email');
        if (is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $branding['site_name'] . ' <' . $reply_to . '>';
        }

        return wp_mail($email, $subject, $body, $headers);
    }

    private static function branding() {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $site_url = home_url('/');
        $logo_url = '';
        $logo_id = function_exists('get_theme_mod') ? (int) get_theme_mod('custom_logo') : 0;

        if ($logo_id) {
            $logo_url = (string) wp_get_attachment_image_url($logo_id, 'full');
        }

        if ($logo_url === '') {
            $logo_url = (string) get_site_icon_url(256);
        }

        return [
            'site_name' => $site_name ?: 'Our website',
            'site_url' => $site_url,
            'logo_url' => $logo_url,
        ];
    }

    private static function fullAddress(array $raw, array $data) {
        $parts = array_filter([
            $raw['street'] ?? $data['street'] ?? '',
            $raw['address_2'] ?? $data['address_2'] ?? '',
            $raw['city'] ?? $data['city'] ?? '',
            $raw['county'] ?? $data['state'] ?? '',
            $raw['country'] ?? $data['country'] ?? '',
            $raw['postcode'] ?? $data['zip'] ?? '',
        ]);

        return implode(', ', array_map('trim', $parts));
    }
}
