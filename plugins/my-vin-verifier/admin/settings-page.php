<?php
/**
 * Admin Settings Page for My VIN Verifier Plugin.
 * Uses the WordPress Settings API.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add the settings page to the WordPress admin menu (under Settings).
 */
function my_vin_register_settings_page_menu() {
    add_options_page(
        __( 'VIN Verifier Settings', 'my-vin-verifier' ), // Page title
        __( 'VIN Verifier', 'my-vin-verifier' ),        // Menu title
        'manage_options',                               // Capability required
        'my-vin-verifier-settings',                     // Menu slug
        'my_vin_render_settings_page_html'              // Callback function to render the page HTML
    );
}
add_action( 'admin_menu', 'my_vin_register_settings_page_menu' );


/**
 * Register the settings, sections, and fields using the Settings API.
 */
function my_vin_settings_api_init() {
    // Register the main setting group (stores all options as one array)
    register_setting(
        'my_vin_verifier_options_group', // Option group name (used in settings_fields)
        'my_vin_verifier_settings',      // Option name in wp_options table
        'my_vin_sanitize_settings_callback' // Sanitization callback function
    );

    // --- VinAudit API Section ---
    add_settings_section(
        'my_vin_settings_section_api', // Section ID
        __( 'VinAudit API Settings', 'my-vin-verifier' ), // Section Title
        'my_vin_section_api_render_callback', // Callback for section description
        'my-vin-verifier-settings'            // Page slug where section appears
    );

    add_settings_field(
        MY_VIN_API_KEY_OPTION, // Field ID (use constant)
        __( 'VinAudit API Key', 'my-vin-verifier' ), // Field Title
        'my_vin_field_render_callback', // Common render callback
        'my-vin-verifier-settings',     // Page slug
        'my_vin_settings_section_api',  // Section ID
        [ // Arguments passed to render callback
            'type' => 'text',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_API_KEY_OPTION, // Use constant
            'description' => __( 'Your API key provided by VinAudit.', 'my-vin-verifier' )
        ]
    );

    // --- PayPal API Section ---
    add_settings_section(
        'my_vin_settings_section_paypal',
        __( 'PayPal API Settings', 'my-vin-verifier' ),
        'my_vin_section_paypal_render_callback',
        'my-vin-verifier-settings'
    );

     add_settings_field(
        MY_VIN_PAYPAL_MODE_OPTION,
        __( 'PayPal Mode', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'select',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_MODE_OPTION,
            'options' => [
                'sandbox' => __( 'Sandbox (Testing)', 'my-vin-verifier' ),
                'live' => __( 'Live (Production)', 'my-vin-verifier' )
            ],
            'description' => __( 'Select Sandbox for testing or Live for real transactions.', 'my-vin-verifier' )
        ]
    );

    add_settings_field(
        MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION,
        __( 'Sandbox Client ID', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'text',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION,
        ]
    );
    add_settings_field(
        MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION,
        __( 'Sandbox Secret Key', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'password', // Use password type
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION,
            'description' => __( 'Keep your Secret Key confidential.', 'my-vin-verifier' )
        ]
    );
     add_settings_field(
        MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION,
        __( 'Sandbox Webhook ID', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'text',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION,
            'description' => __( 'Webhook ID from PayPal for signature verification (Sandbox).', 'my-vin-verifier' )
        ]
    );

    add_settings_field(
        MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION,
        __( 'Live Client ID', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'text',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION,
        ]
    );
    add_settings_field(
        MY_VIN_PAYPAL_LIVE_SECRET_OPTION,
        __( 'Live Secret Key', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
         [
            'type' => 'password',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_LIVE_SECRET_OPTION,
            'description' => __( 'Keep your Secret Key confidential.', 'my-vin-verifier' )
        ]
    );
     add_settings_field(
        MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION,
        __( 'Live Webhook ID', 'my-vin-verifier' ),
        'my_vin_field_render_callback',
        'my-vin-verifier-settings',
        'my_vin_settings_section_paypal',
        [
            'type' => 'text',
            'option_name' => 'my_vin_verifier_settings',
            'key' => MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION,
            'description' => __( 'Webhook ID from PayPal for signature verification (Live).', 'my-vin-verifier' )
        ]
    );

}
add_action( 'admin_init', 'my_vin_settings_api_init' );


/**
 * Render callbacks for section descriptions.
 */
function my_vin_section_api_render_callback() {
    echo '<p>' . esc_html__( 'Enter your VinAudit API key below.', 'my-vin-verifier' ) . '</p>';
}
function my_vin_section_paypal_render_callback() {
    echo '<p>' . esc_html__( 'Configure your PayPal REST API credentials obtained from the PayPal Developer Dashboard. Ensure you have set up a Webhook for the selected mode.', 'my-vin-verifier' ) . '</p>';
    echo '<p><a href="https://developer.paypal.com/developer/applications/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Find your credentials here', 'my-vin-verifier' ) . '</a></p>';
    // Generate the webhook URL dynamically
    $webhook_url = get_rest_url( null, 'my-vin-verifier/v1/webhook/paypal' );
    echo '<p>' . esc_html__( 'Your Webhook Listener URL is:', 'my-vin-verifier' ) . ' <br><code>' . esc_url( $webhook_url ) . '</code><br>';
    echo '<small>' . esc_html__( 'You need to configure this URL in your PayPal Developer account under Webhooks for the corresponding mode (Sandbox/Live) and subscribe to events like PAYMENT.CAPTURE.COMPLETED.', 'my-vin-verifier' ) . '</small></p>';
}


/**
 * Common callback function for rendering settings fields.
 * Uses arguments passed from add_settings_field.
 */
function my_vin_field_render_callback( $args ) {
    // Get the saved options array
    $options = get_option( 'my_vin_verifier_settings' ); // No need for default here, handled below

    $option_key = $args['key'];
    // Get value for this specific key, provide default if not set in saved options
    $value = isset( $options[$option_key] ) ? $options[$option_key] : (isset($args['default']) ? $args['default'] : '');
    $type = isset( $args['type'] ) ? $args['type'] : 'text';

    switch ( $type ) {
        case 'select':
            if ( ! empty( $args['options'] ) && is_array( $args['options'] ) ) {
                printf( "<select id='%s' name='%s[%s]'>",
                    esc_attr( $option_key ),
                    esc_attr( $args['option_name'] ),
                    esc_attr( $option_key )
                );
                foreach ( $args['options'] as $val => $label ) {
                    printf( "<option value='%s' %s>%s</option>",
                        esc_attr( $val ),
                        selected( $value, $val, false ), // Use selected() helper
                        esc_html( $label )
                    );
                }
                echo "</select>";
            }
            break;
        case 'password':
            printf( "<input type='password' id='%s' name='%s[%s]' value='%s' class='regular-text' autocomplete='new-password'>",
                esc_attr( $option_key ),
                esc_attr( $args['option_name'] ),
                esc_attr( $option_key ),
                esc_attr( $value )
            );
            break;
        case 'text':
        default:
            printf( "<input type='text' id='%s' name='%s[%s]' value='%s' class='regular-text'>",
                esc_attr( $option_key ),
                esc_attr( $args['option_name'] ),
                esc_attr( $option_key ),
                esc_attr( $value )
            );
            break;
    }

    // Render description if provided
    if ( isset( $args['description'] ) && ! empty( $args['description'] ) ) {
        echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
    }
}


/**
 * Sanitize the settings input array before saving to the database.
 */
function my_vin_sanitize_settings_callback( $input ) {
    $sanitized_input = array();
    // Get existing options to handle empty password fields correctly
    $current_options = get_option( 'my_vin_verifier_settings', array() );

    // Define keys and their expected sanitization type
    $fields = [
        MY_VIN_API_KEY_OPTION => 'text',
        MY_VIN_PAYPAL_MODE_OPTION => 'key',
        MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION => 'text',
        MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION => 'text', // Special handling for password
        MY_VIN_PAYPAL_SANDBOX_WEBHOOK_ID_OPTION => 'text',
        MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION => 'text',
        MY_VIN_PAYPAL_LIVE_SECRET_OPTION => 'text', // Special handling for password
        MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION => 'text',
    ];

    foreach ($fields as $key => $type) {
        // Check if the key exists in the input submitted by the form
        if ( isset( $input[$key] ) ) {
            switch ($type) {
                case 'key':
                     $sanitized_input[$key] = in_array( $input[$key], ['sandbox', 'live'] ) ? $input[$key] : 'sandbox';
                     break;
                case 'text':
                default:
                    // Handle password fields: If submitted empty, keep the existing value.
                    $is_password = ($key === MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION || $key === MY_VIN_PAYPAL_LIVE_SECRET_OPTION);
                    if ( $is_password && empty( trim( $input[$key] ) ) ) {
                         $sanitized_input[$key] = isset($current_options[$key]) ? $current_options[$key] : '';
                    } else {
                         // Sanitize non-empty passwords and other text fields
                         $sanitized_input[$key] = sanitize_text_field( $input[$key] );
                    }
                    break;
            }
        } else {
             // If a field is completely missing from input (e.g., checkbox unchecked),
             // assign a default or retain old value if appropriate.
             // For these text/select fields, if missing, likely means error or tampering,
             // maybe retain old value or set default from current options.
             $sanitized_input[$key] = isset($current_options[$key]) ? $current_options[$key] : '';
        }
    }

    // Add admin notice for feedback on save
    add_settings_error(
        'my_vin_verifier_settings_notices', // Slug title of the setting error
        'settings_updated',                 // Error code
        __( 'Settings saved successfully.', 'my-vin-verifier' ), // Message
        'updated'                           // Type ('updated', 'success', 'error', 'warning', 'info')
    );

    return $sanitized_input;
}


/**
 * Render the HTML structure for the settings page.
 */
function my_vin_render_settings_page_html() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'my-vin-verifier' ) );
    }

    // Show confirmation messages or errors saved by add_settings_error
    settings_errors( 'my_vin_verifier_settings_notices' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            // Output security fields for the registered setting group (nonce etc.)
            settings_fields( 'my_vin_verifier_options_group' );
            // Output the settings sections and their fields for this page slug
            do_settings_sections( 'my-vin-verifier-settings' );
            // Output save settings button
            submit_button( __( 'Save Settings', 'my-vin-verifier' ) );
            ?>
        </form>
    </div>
    <?php
}

?>
