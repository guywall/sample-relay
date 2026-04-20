<?php
/**
 * Plugin Name: PERMABOUND Sample Relay
 * Description: Sends sample requests from forms to Zoho Books & Zoho CRM with logs, retries, and Elementor integration.
 * Version: 1.7.11
 * Author: You
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PBSR_VER', '1.7.11');
define('PBSR_SLUG', 'permabound-sample-relay');
define('PBSR_PATH', plugin_dir_path(__FILE__));
define('PBSR_URL', plugin_dir_url(__FILE__));

require_once PBSR_PATH . 'src/class-settings.php';
require_once PBSR_PATH . 'src/class-attribution.php';
require_once PBSR_PATH . 'src/class-logger.php';
require_once PBSR_PATH . 'src/class-emailer.php';
require_once PBSR_PATH . 'src/class-token-store.php';
require_once PBSR_PATH . 'src/class-zoho-client.php';
require_once PBSR_PATH . 'src/class-zoho-crm.php';
require_once PBSR_PATH . 'src/class-zoho-books.php';
require_once PBSR_PATH . 'src/class-mapper.php';
require_once PBSR_PATH . 'src/class-dispatcher.php';
require_once PBSR_PATH . 'src/class-admin-page.php';
require_once PBSR_PATH . 'src/class-product-availability.php';
require_once PBSR_PATH . 'src/class-rest-endpoint.php';
require_once PBSR_PATH . 'src/integrations/elementor.php';
require_once PBSR_PATH . 'src/integrations/product-selection-form.php';
require_once PBSR_PATH . 'src/class-installer.php';

register_activation_hook(__FILE__, ['PBSR_Installer', 'activate']);

PBSR_Installer::init();
