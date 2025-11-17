<?php

/**

 * Plugin Name: PERMABOUND Sample Relay

 * Description: Sends sample requests from forms to Zoho Books & Zoho CRM with logs, retries, and Elementor integration.

 * Version: 1.02

 * Author: You

 */



if (!defined('ABSPATH')) exit;



define('PBSR_VER', '0.1.0');

define('PBSR_SLUG', 'permabound-sample-relay');

define('PBSR_PATH', plugin_dir_path(__FILE__));

define('PBSR_URL', plugin_dir_url(__FILE__));



require_once PBSR_PATH . 'src/class-settings.php';

require_once PBSR_PATH . 'src/class-logger.php';

require_once PBSR_PATH . 'src/class-token-store.php';

require_once PBSR_PATH . 'src/class-zoho-client.php';

require_once PBSR_PATH . 'src/class-zoho-crm.php';

require_once PBSR_PATH . 'src/class-zoho-books.php';

require_once PBSR_PATH . 'src/class-mapper.php';

require_once PBSR_PATH . 'src/class-dispatcher.php';

require_once PBSR_PATH . 'src/class-admin-page.php';

require_once PBSR_PATH . 'src/class-rest-endpoint.php';

require_once PBSR_PATH . 'src/integrations/elementor.php';



register_activation_hook(__FILE__, function() {

    global $wpdb;

    $table = $wpdb->prefix . 'pbsr_logs';

    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (

        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

        created_at DATETIME NOT NULL,

        source VARCHAR(50) NOT NULL,

        idempotency_key VARCHAR(191) NOT NULL,

        payload LONGTEXT NULL,

        crm_status VARCHAR(30) NULL,

        crm_response LONGTEXT NULL,

        books_status VARCHAR(30) NULL,

        books_response LONGTEXT NULL,

        retry_count INT UNSIGNED NOT NULL DEFAULT 0,

        PRIMARY KEY (id),

        UNIQUE KEY uniq_idem (idempotency_key)

    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);

});



