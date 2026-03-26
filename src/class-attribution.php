<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Attribution {
    const COOKIE_NAME = 'pbsr_attr';
    const COOKIE_TTL = 7776000; // 90 days

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_capture_script'], 1);
    }

    public static function enqueue_capture_script() {
        if (is_admin()) {
            return;
        }

        wp_register_script('pbsr-attribution', '', [], PBSR_VER, false);
        wp_enqueue_script('pbsr-attribution');
        wp_add_inline_script('pbsr-attribution', self::capture_script());
    }

    public static function enrichContext(array $context = []) {
        $cookie = self::readCookie();
        $attr = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];

        $utm = is_array($context['utm'] ?? null) ? $context['utm'] : [];

        $attr['utm_source'] = self::pick(
            $attr['utm_source'] ?? null,
            $utm['utm_source'] ?? null,
            $context['utm_source'] ?? null,
            $cookie['utm_source'] ?? null
        );
        $attr['utm_medium'] = self::pick(
            $attr['utm_medium'] ?? null,
            $utm['utm_medium'] ?? null,
            $context['utm_medium'] ?? null,
            $cookie['utm_medium'] ?? null
        );
        $attr['utm_campaign'] = self::pick(
            $attr['utm_campaign'] ?? null,
            $utm['utm_campaign'] ?? null,
            $context['utm_campaign'] ?? null,
            $cookie['utm_campaign'] ?? null
        );
        $attr['utm_term'] = self::pick(
            $attr['utm_term'] ?? null,
            $utm['utm_term'] ?? null,
            $context['utm_term'] ?? null,
            $cookie['utm_term'] ?? null
        );
        $attr['utm_content'] = self::pick(
            $attr['utm_content'] ?? null,
            $context['utm_content'] ?? null,
            $cookie['utm_content'] ?? null
        );
        $attr['gclid'] = self::pick($attr['gclid'] ?? null, $context['gclid'] ?? null, $cookie['gclid'] ?? null);
        $attr['msclkid'] = self::pick($attr['msclkid'] ?? null, $context['msclkid'] ?? null, $cookie['msclkid'] ?? null);
        $attr['fbclid'] = self::pick($attr['fbclid'] ?? null, $context['fbclid'] ?? null, $cookie['fbclid'] ?? null);
        $attr['landing_page'] = self::pick($attr['landing_page'] ?? null, $context['landing_page'] ?? null, $cookie['landing_page'] ?? null);
        $attr['submit_page'] = self::pick($attr['submit_page'] ?? null, $context['page_url'] ?? null, $cookie['submit_page'] ?? null);
        $attr['referrer'] = self::pick($attr['referrer'] ?? null, $context['referrer'] ?? null, $cookie['referrer'] ?? null);

        $classification = self::classify($attr);
        $attr['channel'] = $classification['channel'];
        $attr['source_detail'] = $classification['source_detail'];

        $context['page_url'] = self::pick($context['page_url'] ?? null, $attr['submit_page'] ?? null);
        $context['referrer'] = self::pick($context['referrer'] ?? null, $attr['referrer'] ?? null);
        $context['utm'] = [
            'utm_source' => $attr['utm_source'] ?? '',
            'utm_medium' => $attr['utm_medium'] ?? '',
            'utm_campaign' => $attr['utm_campaign'] ?? '',
            'utm_term' => $attr['utm_term'] ?? '',
            'utm_content' => $attr['utm_content'] ?? '',
        ];
        $context['attribution'] = $attr;

        return $context;
    }

    public static function classify(array $attr) {
        $utm_source = strtolower((string) ($attr['utm_source'] ?? ''));
        $utm_medium = strtolower((string) ($attr['utm_medium'] ?? ''));
        $referrer = (string) ($attr['referrer'] ?? '');
        $ref_host = self::hostFromUrl($referrer);
        $engine = self::detectSearchEngine($utm_source, $ref_host);
        $social = self::detectSocialSource($utm_source, $utm_medium, $ref_host, $attr);
        $paid_medium = preg_match('/(^|[_\-\s])(cpc|ppc|paid|paidsearch|paid_search|sem)($|[_\-\s])/', $utm_medium) === 1;

        if (!empty($attr['gclid']) || $ref_host === 'googleadservices.com' || ($engine === 'google' && $paid_medium)) {
            return [
                'channel' => 'Google PPC',
                'source_detail' => 'Google',
            ];
        }

        if (!empty($attr['msclkid']) || ($engine === 'bing' && $paid_medium)) {
            return [
                'channel' => 'Bing PPC',
                'source_detail' => 'Bing',
            ];
        }

        if ($social) {
            return [
                'channel' => 'Social',
                'source_detail' => $social,
            ];
        }

        if ($engine && ($utm_medium === 'organic' || $ref_host !== '')) {
            return [
                'channel' => self::prettyLabel($engine) . ' Organic',
                'source_detail' => self::prettyLabel($engine),
            ];
        }

        if ($ref_host === '' && self::isEmptyAttribution($attr)) {
            return [
                'channel' => 'Direct',
                'source_detail' => 'Direct',
            ];
        }

        return [
            'channel' => 'Referral',
            'source_detail' => self::prettyLabel($utm_source ?: $ref_host ?: 'Referral'),
        ];
    }

    public static function readCookie() {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode(rawurldecode(wp_unslash($raw)), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function pick(...$values) {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function isEmptyAttribution(array $attr) {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'msclkid', 'fbclid', 'referrer'];
        foreach ($keys as $key) {
            if (!empty($attr[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function hostFromUrl($url) {
        if (!is_string($url) || trim($url) === '') {
            return '';
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        return preg_replace('/^www\./', '', $host);
    }

    private static function detectSearchEngine($utm_source, $ref_host) {
        $haystack = strtolower(trim((string) $utm_source . ' ' . $ref_host));
        $map = [
            'google' => ['google.', 'google'],
            'bing' => ['bing.', 'bing', 'msn.com'],
            'yahoo' => ['yahoo.'],
            'duckduckgo' => ['duckduckgo.'],
            'ecosia' => ['ecosia.'],
            'baidu' => ['baidu.'],
        ];

        foreach ($map as $engine => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($haystack, $needle) !== false) {
                    return $engine;
                }
            }
        }

        return '';
    }

    private static function detectSocialSource($utm_source, $utm_medium, $ref_host, array $attr) {
        $haystack = strtolower(trim(implode(' ', [$utm_source, $utm_medium, $ref_host])));
        $map = [
            'Facebook' => ['facebook.', 'fb.', 'meta', 'fbclid'],
            'Instagram' => ['instagram.'],
            'LinkedIn' => ['linkedin.'],
            'Pinterest' => ['pinterest.'],
            'X' => ['twitter.', 't.co', 'x.com'],
            'TikTok' => ['tiktok.'],
        ];

        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if ($needle === 'fbclid' && !empty($attr['fbclid'])) {
                    return $label;
                }

                if ($needle !== 'fbclid' && $needle !== '' && strpos($haystack, $needle) !== false) {
                    return $label;
                }
            }
        }

        if (strpos($utm_medium, 'social') !== false) {
            return self::prettyLabel($utm_source ?: 'Social');
        }

        return '';
    }

    private static function prettyLabel($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $map = [
            'google' => 'Google',
            'bing' => 'Bing',
            'duckduckgo' => 'DuckDuckGo',
            'ecosia' => 'Ecosia',
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'pinterest' => 'Pinterest',
            'tiktok' => 'TikTok',
            'x' => 'X',
        ];

        $key = strtolower($value);
        if (isset($map[$key])) {
            return $map[$key];
        }

        return ucwords(str_replace(['-', '_'], ' ', $value));
    }

    private static function capture_script() {
        $cookie_name = wp_json_encode(self::COOKIE_NAME);
        $ttl = (int) self::COOKIE_TTL;

        return <<<JS
(function(){
    var cookieName = {$cookie_name};
    var ttl = {$ttl};

    function trimValue(value, max){
        var str = String(value || '').trim();
        return str.length > max ? str.slice(0, max) : str;
    }

    function readCookie(){
        var match = document.cookie.match(new RegExp('(?:^|; )' + cookieName.replace(/[.$?*|{}()\\[\\]\\\\/+^]/g, '\\\\$&') + '=([^;]*)'));
        if(!match){ return {}; }
        try { return JSON.parse(decodeURIComponent(match[1])); } catch (e) { return {}; }
    }

    function writeCookie(data){
        var encoded = encodeURIComponent(JSON.stringify(data));
        document.cookie = cookieName + '=' + encoded + '; path=/; max-age=' + ttl + '; SameSite=Lax';
        try { window.localStorage.setItem(cookieName, JSON.stringify(data)); } catch (e) {}
    }

    function readStore(){
        var data = readCookie();
        if(Object.keys(data).length){ return data; }
        try {
            var stored = window.localStorage.getItem(cookieName);
            return stored ? JSON.parse(stored) : {};
        } catch (e) {
            return {};
        }
    }

    function sameHost(url){
        try {
            return new URL(url, window.location.href).host === window.location.host;
        } catch (e) {
            return false;
        }
    }

    var data = readStore();
    var currentUrl = trimValue(window.location.href, 512);
    var referrer = trimValue(document.referrer, 512);
    var params = new URLSearchParams(window.location.search);

    if (!data.landing_page) {
        data.landing_page = currentUrl;
    }

    if (!data.referrer && referrer && !sameHost(referrer)) {
        data.referrer = referrer;
    }

    data.submit_page = currentUrl;

    ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','msclkid','fbclid'].forEach(function(key){
        var value = trimValue(params.get(key), 200);
        if (value) {
            data[key] = value;
        }
    });

    writeCookie(data);
    window.PBSRAttribution = {
        read: function(){ return readStore(); }
    };
})();
JS;
    }
}

PBSR_Attribution::init();
