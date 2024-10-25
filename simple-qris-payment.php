<?php
/*
Plugin Name: Simple QRIS Payment
Description: A simple plugin to generate QRIS codes using Xendit API
Version: 1.0
Author: Achmad Azman
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin activation hook
register_activation_hook(__FILE__, 'qris_payment_activate');

function qris_payment_activate() {
    // custom table for transactions
    global $wpdb;
    $table_name = $wpdb->prefix . 'qris_transactions';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        external_id varchar(100) NOT NULL,
        amount decimal(10,2) NOT NULL,
        qr_string text NOT NULL,
        status varchar(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Add menu to WordPress admin
add_action('admin_menu', 'qris_payment_menu');

function qris_payment_menu() {
    add_menu_page(
        'QRIS Payment Settings',
        'QRIS Payment',
        'manage_options',
        'qris-payment-settings',
        'qris_payment_settings_page',
        'dashicons-money'
    );
}

// Settings page
function qris_payment_settings_page() {
    ?>
    <div class="wrap">
        <h1>QRIS Payment Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('qris_payment_options');
            do_settings_sections('qris-payment-settings');
            submit_button();
            ?>
        </form>

        <!-- Shortcode Information Section -->
        <div class="qris-shortcode-info" style="margin-top: 40px;">
            <h2>Shortcode Usage</h2>
            <div class="qris-info-card">
                <h3>Basic Usage</h3>
                <p>Copy and paste this shortcode into any page or post where you want to display the QRIS payment form:</p>
                <code style="display: block; padding: 15px; background: #f5f5f5; margin: 10px 0;">[qris_payment_form]</code>
                
                <h3>Example</h3>
                <p>Add the shortcode to your page content like this:</p>
                <pre style="background: #f5f5f5; padding: 15px; margin: 10px 0;">
Welcome to our payment page!

[qris_payment_form]

Thank you for your payment.</pre>
                
                <h3>PHP Usage</h3>
                <p>If you want to add the form directly in your theme files, use this PHP code:</p>
                <code style="display: block; padding: 15px; background: #f5f5f5; margin: 10px 0;">
echo do_shortcode('[qris_payment_form]');</code>
            </div>
        </div>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'qris_payment_settings_init');

function qris_payment_settings_init() {
    register_setting('qris_payment_options', 'qris_payment_secret_key');
    
    add_settings_section(
        'qris_payment_settings_section',
        'API Settings',
        'qris_payment_settings_section_callback',
        'qris-payment-settings'
    );
    
    add_settings_field(
        'qris_payment_secret_key',
        'Secret Key',
        'qris_payment_secret_key_callback',
        'qris-payment-settings',
        'qris_payment_settings_section'
    );
}

function qris_payment_settings_section_callback() {
    echo '<p>Enter your Xendit API credentials here. You can get these from your Xendit Dashboard.</p>';
}

function qris_payment_secret_key_callback() {
    $secret_key = get_option('qris_payment_secret_key');
    echo '<input type="text" id="qris_payment_secret_key" name="qris_payment_secret_key" value="' . esc_attr($secret_key) . '" class="regular-text">';
}

// QRIS API Integration
function generate_qris_code($amount, $external_id) {
    $secret_key = get_option('qris_payment_secret_key');
    
    // Xendit API endpoint (sandbox)
    $api_url = 'https://api.xendit.co/qr_codes';
    
    $body = array(
        'external_id' => $external_id,
        'type' => 'DYNAMIC',
        'callback_url' => home_url('/wp-json/qris-payment/v1/callback'),
        'amount' => $amount
    );
    
    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($secret_key . ':'),
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($body)
    );
    
    $response = wp_remote_post($api_url, $args);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['qr_string'])) {
        // Save to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'qris_transactions';
        
        $wpdb->insert(
            $table_name,
            array(
                'external_id' => $external_id,
                'amount' => $amount,
                'qr_string' => $data['qr_string'],
                'status' => 'PENDING'
            )
        );
        
        return $data['qr_string'];
    }
    
    return false;
}

// Register REST API endpoint for callback
add_action('rest_api_init', function () {
    register_rest_route('qris-payment/v1', '/callback', array(
        'methods' => 'POST',
        'callback' => 'handle_qris_callback',
        'permission_callback' => '__return_true'
    ));
});

function handle_qris_callback($request) {
    $body = $request->get_json_params();
    
    if (isset($body['external_id']) && isset($body['status'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qris_transactions';
        
        $wpdb->update(
            $table_name,
            array('status' => $body['status']),
            array('external_id' => $body['external_id'])
        );
        
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    return new WP_REST_Response(array('status' => 'error'), 400);
}

// Shortcode for QRIS payment form
add_shortcode('qris_payment_form', 'qris_payment_form_shortcode');

function qris_payment_form_shortcode($atts) {
    
    $output = '
    <div class="qris-payment-container">
        <form id="qris-payment-form" class="qris-form">
            <div class="form-header">
                <h2>QRIS Payment</h2>
                <p>Enter amount and scan QR code to pay</p>
            </div>
            
            <div class="form-group">
                <label for="amount">Amount (IDR)</label>
                <input type="number" id="amount" name="amount" required min="10000" placeholder="Minimum IDR 10,000">
            </div>
            
            <button type="submit" class="qris-submit-btn">
                <span class="loading-spinner" style="display: none;"></span>
                Generate QRIS
            </button>
        </form>
        
        <div id="qris-result" style="display:none;">
            <div id="qris-code"></div>
            <div class="qris-amount"></div>
            <div class="qris-timer"></div>
            <div class="qris-status pending"></div>
            
            <div class="payment-instructions">
                <h3>How to Pay</h3>
                <ol>
                    <li>Open your mobile banking or e-wallet app</li>
                    <li>Select the QRIS/Scan QR menu</li>
                    <li>Scan the QR code above</li>
                    <li>Check the amount and merchant name</li>
                    <li>Proceed with payment</li>
                </ol>
            </div>
        </div>
    </div>';
    
    return $output;
}

// Register assets
add_action('wp_enqueue_scripts', 'qris_payment_register_assets');

function qris_payment_register_assets() {
    // Only register and enqueue on a page with our shortcode
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'qris_payment_form')) {
        // Register QR Code library
        wp_register_script(
            'qrcode-lib',
            'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Register styles
        wp_register_style(
            'qris-payment-style',
            plugins_url('css/style.css', __FILE__)
        );

        // Register script
        wp_register_script(
            'qris-payment-script',
            plugins_url('js/script.js', __FILE__),
            array('jquery', 'qrcode-lib'),
            '1.0.0',
            true
        );

        // Enqueue everything
        wp_enqueue_script('qrcode-lib');
        wp_enqueue_style('qris-payment-style');
        wp_enqueue_script('qris-payment-script');

        wp_localize_script('qris-payment-script', 'qrisAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qris-nonce')
        ));
    }
}

// AJAX handler for generating QRIS
add_action('wp_ajax_generate_qris', 'handle_generate_qris');
add_action('wp_ajax_nopriv_generate_qris', 'handle_generate_qris');

function handle_generate_qris() {
    check_ajax_referer('qris-nonce', 'nonce');
    
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    
    if ($amount < 10000) {
        wp_send_json_error(array(
            'message' => 'Minimum amount is IDR 1,000'
        ));
        return;
    }
    
    try {
        $external_id = 'QRIS-' . time();
        $qr_string = generate_qris_code($amount, $external_id);
        
        if ($qr_string) {
            wp_send_json_success(array(
                'qr_string' => $qr_string,
                'external_id' => $external_id,
                'amount' => number_format($amount, 0, ',', '.')
            ));
        } else {
            throw new Exception('Failed to generate QRIS code');
        }
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => 'Error: ' . $e->getMessage()
        ));
    }
}

// AJAX handler for checking payment status
add_action('wp_ajax_check_payment_status', 'handle_check_payment_status');
add_action('wp_ajax_nopriv_check_payment_status', 'handle_check_payment_status');

function handle_check_payment_status() {
    check_ajax_referer('qris-nonce', 'nonce');
    
    $external_id = isset($_POST['external_id']) ? sanitize_text_field($_POST['external_id']) : '';
    
    if (!$external_id) {
        wp_send_json_error('Invalid external ID');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'qris_transactions';
    
    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM $table_name WHERE external_id = %s",
        $external_id
    ));
    
    wp_send_json_success(array('status' => $status));
}