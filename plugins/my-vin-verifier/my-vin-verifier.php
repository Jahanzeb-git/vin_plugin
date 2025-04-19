<?php
/**
 * Plugin Name:       My VIN Verification Service
 * Plugin URI:        https://example.com/plugins/my-vin-verifier/
 * Description:       Provides VIN verification services using the VinAudit API, custom UI shortcode integration, PayPal payments, and PDF report generation.
 * Version:           1.1.0
 * Author:            Jahanzeb Ahmed ()
 * Author URI:        https://jahanzebahmed.mail@gmail.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       my-vin-verifier
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --- Plugin Constants ---
// Use function_exists check for potential redefinition if included elsewhere (unlikely for main file)
if ( ! defined( 'MY_VIN_PLUGIN_VERSION' ) ) define( 'MY_VIN_PLUGIN_VERSION', '1.1.0' );
if ( ! defined( 'MY_VIN_PLUGIN_PATH' ) ) define( 'MY_VIN_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'MY_VIN_PLUGIN_URL' ) ) define( 'MY_VIN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'MY_VIN_API_KEY_OPTION' ) ) define( 'MY_VIN_API_KEY_OPTION', 'my_vin_verifier_api_key' );
if ( ! defined( 'MY_VIN_PAYPAL_MODE_OPTION' ) ) define( 'MY_VIN_PAYPAL_MODE_OPTION', 'my_vin_paypal_mode' );
if ( ! defined( 'MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION' ) ) define( 'MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION', 'my_vin_paypal_client_id_sandbox' );
if ( ! defined( 'MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION' ) ) define( 'MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION', 'my_vin_paypal_secret_sandbox' );
if ( ! defined( 'MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION' ) ) define( 'MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION', 'my_vin_paypal_client_id_live' );
if ( ! defined( 'MY_VIN_PAYPAL_LIVE_SECRET_OPTION' ) ) define( 'MY_VIN_PAYPAL_LIVE_SECRET_OPTION', 'my_vin_paypal_secret_live' );
if ( ! defined( 'MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION' ) ) define( 'MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION', 'my_vin_paypal_webhook_id_sandbox' );
if ( ! defined( 'MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION' ) ) define( 'MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION', 'my_vin_paypal_webhook_id_live' );
if ( ! defined( 'MY_VIN_REPORTS_TABLE' ) ) define( 'MY_VIN_REPORTS_TABLE', 'vin_reports' );
if ( ! defined( 'MY_VIN_PDF_STORAGE_DIR' ) ) define( 'MY_VIN_PDF_STORAGE_DIR', 'vin_reports' );
if ( ! defined( 'MY_VIN_AJAX_NONCE_ACTION' ) ) define( 'MY_VIN_AJAX_NONCE_ACTION', 'my_vin_ajax_nonce' );
// Add this line after the existing webhook ID constant definitions
if ( ! defined( 'MY_VIN_PAYPAL_SANDBOX_WEBHOOK_ID_OPTION' ) ) define( 'MY_VIN_PAYPAL_SANDBOX_WEBHOOK_ID_OPTION', 'my_vin_paypal_webhook_id_sandbox' );
if ( ! defined( 'MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION' ) ) define( 'MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION', 'my_vin_paypal_webhook_id_live' );
if ( ! defined( 'MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION' ) ) define( 'MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION', 'my_vin_paypal_webhook_id_live' );

// --- Composer Autoloader ---
if ( file_exists( MY_VIN_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
    require_once MY_VIN_PLUGIN_PATH . 'vendor/autoload.php';
} else {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo wp_kses_post( __( '<b>My VIN Verifier Plugin:</b> Composer dependencies (like Dompdf) not found. PDF generation will not work. Please run <code>composer install</code> in the plugin directory.', 'my-vin-verifier' ) );
        echo '</p></div>';
    });
    // Allow execution but PDF gen will fail.
}


// --- Include Core Files ---
require_once MY_VIN_PLUGIN_PATH . 'includes/database.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/utils.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/vinaudit-api.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/paypal-api.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/ajax-handlers.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/pdf-generator.php';
require_once MY_VIN_PLUGIN_PATH . 'includes/webhook-handler.php';

// --- Include Admin Files ---
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
   require_once MY_VIN_PLUGIN_PATH . 'admin/settings-page.php';
}

// --- Activation / Deactivation Hooks ---
register_activation_hook( __FILE__, 'my_vin_activate_plugin' );
register_deactivation_hook( __FILE__, 'my_vin_deactivate_plugin' );

/**
 * Plugin Activation Hook Implementation.
 */
function my_vin_activate_plugin() {
    my_vin_create_reports_table();
    $defaults = [
        'my_vin_verifier_api_key' => 'VA_DEMO_KEY',
        'my_vin_paypal_mode' => 'sandbox',
        'my_vin_paypal_client_id_sandbox' => '',
        'my_vin_paypal_secret_sandbox' => '',
        'my_vin_paypal_webhook_id_sandbox' => '',
        'my_vin_paypal_client_id_live' => '',
        'my_vin_paypal_secret_live' => '',
        'my_vin_paypal_webhook_id_live' => '',
    ];
    update_option('my_vin_verifier_settings', $defaults);
    my_vin_ensure_reports_directory_exists();
    flush_rewrite_rules();
}

/**
 * Plugin Deactivation Hook Implementation.
 */
function my_vin_deactivate_plugin() {
    flush_rewrite_rules();
}

/**
 * Initialize the plugin - register main hooks.
 */
function my_vin_init_plugin() {
    // Register AJAX actions
    add_action( 'wp_ajax_validate_vin_backend', 'my_vin_ajax_validate_vin' );
    add_action( 'wp_ajax_nopriv_validate_vin_backend', 'my_vin_ajax_validate_vin' );
    add_action( 'wp_ajax_get_basic_specs', 'my_vin_ajax_get_basic_specs' );
    add_action( 'wp_ajax_nopriv_get_basic_specs', 'my_vin_ajax_get_basic_specs' );
    add_action( 'wp_ajax_create_paypal_order', 'my_vin_ajax_create_paypal_order' );
    add_action( 'wp_ajax_nopriv_create_paypal_order', 'my_vin_ajax_create_paypal_order' );
    add_action( 'wp_ajax_capture_paypal_order', 'my_vin_ajax_capture_paypal_order' );
    add_action( 'wp_ajax_nopriv_capture_paypal_order', 'my_vin_ajax_capture_paypal_order' );
    add_action( 'wp_ajax_retrieve_report', 'my_vin_ajax_retrieve_report' );
    add_action( 'wp_ajax_nopriv_retrieve_report', 'my_vin_ajax_retrieve_report' );

    // Register Webhook Listener Endpoint
    add_action( 'rest_api_init', 'my_vin_register_webhook_endpoint' );

    // Load text domain for localization
    load_plugin_textdomain( 'my-vin-verifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'my_vin_init_plugin' );

/**
 * Enqueue scripts and styles needed by the plugin on the front-end.
 * Handles PayPal SDK and localization for the processing UI JS.
 */
function my_vin_enqueue_plugin_scripts() {
    if ( my_vin_is_processing_page_active() ) {
        $paypal_mode = my_vin_get_setting( MY_VIN_PAYPAL_MODE_OPTION, 'sandbox' );
        $client_id_option = ($paypal_mode === 'live') ? MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION : MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION;
        $client_id = my_vin_get_setting( $client_id_option );
        $processing_ui_script_handle = 'processing-ui-script';
        if ( ! empty( $client_id ) ) {
            wp_enqueue_script(
                'paypal-sdk',
                'https://www.paypal.com/sdk/js?client-id=' . esc_attr( $client_id ) . '&currency=USD&intent=capture&commit=true', // Fix 'currency' parameter
                array(),
                null,
                true
            );
            if ( wp_script_is( $processing_ui_script_handle, 'enqueued' ) ) {
                wp_localize_script( $processing_ui_script_handle, 'processingUiData', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( MY_VIN_AJAX_NONCE_ACTION ),
                    'paypal_client_id' => $client_id,
                    'paypal_mode' => $paypal_mode,
                    'error_messages' => [
                        'generic' => __('An unexpected error occurred. Please try again.', 'my-vin-verifier'),
                        'vin_invalid' => __('Please enter a valid 17-digit VIN.', 'my-vin-verifier'),
                        'api_error' => __('Could not retrieve vehicle data at this time.', 'my-vin-verifier'),
                        'payment_create_failed' => __('Could not initiate payment. Please try again.', 'my-vin-verifier'),
                        'payment_capture_failed' => __('Payment was approved, but finalizing it failed. Please contact support.', 'my-vin-verifier'),
                        'fulfillment_failed' => __('Payment successful, but report generation failed. Please contact support. Your payment may be refunded.', 'my-vin-verifier'),
                        'retrieval_failed' => __('Could not find a previous report for this VIN and email.', 'my-vin-verifier'),
                        'retrieval_file_missing' => __('Report record found, but the file is unavailable. Please contact support.', 'my-vin-verifier'),
                    ]
                ));
            } else {
                if ( current_user_can('manage_options') && is_admin() ) {
                    add_action('admin_notices', function() use ($processing_ui_script_handle) {
                        echo '<div class="notice notice-warning is-dismissible"><p>';
                        echo sprintf(
                            esc_html__( 'My VIN Verifier: Could not localize data for front-end script (handle "%s" not found). Ensure the theme enqueues the UI script correctly before this plugin\'s script hook (priority 30).', 'my-vin-verifier' ),
                            esc_html($processing_ui_script_handle)
                        );
                        echo '</p></div>';
                    });
                }
                error_log('My VIN Verifier Error: Cannot localize data because script handle "' . $processing_ui_script_handle . '" was not found.');
            }
        } else {
            if ( current_user_can('manage_options') && is_admin() ) {
                add_action('admin_notices', function() use ($paypal_mode) {
                    echo '<div class="notice notice-warning is-dismissible"><p>';
                    echo sprintf(
                        esc_html__( 'My VIN Verifier: PayPal Client ID for %s mode is not configured in settings. Payment processing will not work.', 'my-vin-verifier' ),
                        esc_html( $paypal_mode )
                    );
                    echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=my-vin-verifier-settings' ) ) . '">' . esc_html__('Configure Settings', 'my-vin-verifier') . '</a>';
                    echo '</p></div>';
                });
            }
        }
    }
}
// Hook later to ensure theme/other plugins have registered their scripts
add_action( 'wp_enqueue_scripts', 'my_vin_enqueue_plugin_scripts', 30 );


/**
 * Register the REST API endpoint for the PayPal Webhook.
 */
function my_vin_register_webhook_endpoint() {
    register_rest_route( 'my-vin-verifier/v1', '/webhook/paypal', array(
        'methods'             => WP_REST_Server::CREATABLE, // POST method
        'callback'            => 'my_vin_handle_paypal_webhook_request', // Defined in webhook-handler.php
        'permission_callback' => '__return_true', // Webhook needs to be publicly accessible
    ));
}

// --- Helper function included in main file for broad access ---
/**
 * Helper function to check if the processing UI shortcode is active on the current page.
 *
 * @global WP_Post $post WordPress post object.
 * @return bool True if the shortcode is found on a singular page/post.
 */
function my_vin_is_processing_page_active() {
    global $post;
    // Check if $post is an object and has post_content property
    return ( is_singular() && is_a( $post, 'WP_Post' ) && !empty($post->post_content) && has_shortcode( $post->post_content, 'processing_ui' ) );
}

?>



