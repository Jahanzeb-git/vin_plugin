<?php
/**
 * Utility Functions for My VIN Verifier Plugin.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get a specific setting value from the main plugin options array.
 *
 * @param string $key The option key to retrieve (e.g., 'api_key', 'paypal_mode').
 * @param mixed $default The default value if the option isn't set.
 * @return mixed The setting value.
 */
function my_vin_get_setting( $key, $default = '' ) {
    // Retrieve the entire options array once, providing defaults for all keys
    $defaults = array(
        MY_VIN_API_KEY_OPTION => 'VA_DEMO_KEY',
        MY_VIN_PAYPAL_MODE_OPTION => 'sandbox',
        MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION => '',
        MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION => '',
        MY_VIN_PAYPAL_SANDBOX_WEBHOOK_ID_OPTION => '',
        MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION => '',
        MY_VIN_PAYPAL_LIVE_SECRET_OPTION => '',
        MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION => '',
    );
    $options = get_option( 'my_vin_verifier_settings', $defaults );
    // Merge defaults with saved options to ensure all keys exist
    $options = wp_parse_args( $options, $defaults );

    return isset( $options[$key] ) ? $options[$key] : $default;
}

/**
 * Get the appropriate PayPal API credentials based on the current mode (Sandbox/Live).
 *
 * @return array Associative array with 'client_id', 'secret', 'mode', 'base_url', 'webhook_id'. Returns empty strings if not configured.
 */
function my_vin_get_paypal_credentials() {
    $mode = my_vin_get_setting( MY_VIN_PAYPAL_MODE_OPTION, 'sandbox' );
    $credentials = array(
        'client_id' => '',
        'secret'    => '',
        'mode'      => $mode,
        'base_url'  => ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com',
        'webhook_id'=> '',
    );

    if ( $mode === 'live' ) {
        $credentials['client_id'] = my_vin_get_setting( MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION );
        $credentials['secret']    = my_vin_get_setting( MY_VIN_PAYPAL_LIVE_SECRET_OPTION );
        $credentials['webhook_id']= my_vin_get_setting( MY_VIN_PAYPAL_LIVE_WEBHOOK_ID_OPTION );
    } else { // Sandbox mode
        $credentials['client_id'] = my_vin_get_setting( MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION );
        $credentials['secret']    = my_vin_get_setting( MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION );
        $credentials['webhook_id']= my_vin_get_setting( MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION );
    }

    return $credentials;
}

/**
 * Get Plan Features based on Plan ID and Vehicle Type (Car/Bike).
 * Based on analysis of VIN_Verification_Service_Packages.pdf.
 * Uses internal keys for easier mapping to API data structure.
 *
 * @param string $plan_id ('silver', 'gold', 'platinum').
 * @param string $vehicle_type ('car', 'bike').
 * @return array List of internal feature keys included in the plan.
 */
function my_vin_get_plan_features( $plan_id, $vehicle_type = 'car' ) {
    $type = strtolower($vehicle_type);
    $plan = strtolower($plan_id);

    // Define features included in EACH plan using internal keys
    $features_map = [
        // --- CAR PLANS ---
        'car' => [
            'silver' => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images', // Assuming 'hq_car_images' maps to 'images' for cars
                'recalls', 'mileage', // Mileage/Accidental Info are listed but often derived, include base keys
                'sale', 'warranties', 'options_packages' // Assuming warranties & options come from specs
            ],
            'gold'   => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images',
                'recalls', 'sale', 'warranties', 'options_packages',
                'titles', 'salvage', 'exports' // Added for Gold (Mileage/Accidental Info removed per PDF)
            ],
            'platinum' => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images',
                'recalls', 'sale', 'warranties', 'options_packages',
                'titles', 'salvage', 'exports', 'checks' // Added 'checks' for Title Brand (Mileage/Accidental Info removed per PDF)
            ]
        ],
        // --- BIKE PLANS ---
        'bike' => [
             'silver' => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images', // Assuming 'hq_bike_images' maps to 'images' for bikes
                'recalls', 'mileage', 'accidental_information', // Keeping accidental info for bike silver per PDF
                'sale', 'warranties', 'options_packages'
            ],
            'gold'   => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images',
                'recalls', 'sale', 'warranties', 'options_packages',
                'titles', 'salvage', 'exports' // Added for Gold (Mileage/Accidental Info removed per PDF)
            ],
            'platinum' => [
                'specs', 'thefts', 'market_value', 'accidents', 'impounds', 'images', // Platinum Bike uses HQ *Car* Images per PDF, still map to 'images'
                'recalls', 'sale', 'warranties', 'options_packages',
                'titles', 'salvage', 'exports', 'checks' // Added 'checks' for Title Brand (Mileage/Accidental Info removed per PDF)
            ]
        ]
    ];

    // Fallback logic
    if (!isset($features_map[$type])) {
        $type = 'car'; // Default to car if type unknown
    }
    if (isset($features_map[$type][$plan])) {
        return $features_map[$type][$plan];
    }

    // Fallback to car silver if plan invalid
    return isset($features_map['car']['silver']) ? $features_map['car']['silver'] : [];
}


/**
 * Filter combined VinAudit API data based on plan features.
 *
 * @param array $api_data Associative array containing combined data keys:
 * 'vin', 'generation_date', 'specs', 'specs_warranties', 'history', 'value', 'images'.
 * @param string $plan_id ('silver', 'gold', 'platinum').
 * @param string $vehicle_type ('car', 'bike').
 * @return array Filtered data suitable for the PDF template.
 */
function my_vin_filter_data_for_plan( $api_data, $plan_id, $vehicle_type = 'car' ) {
    $allowed_feature_keys = my_vin_get_plan_features( $plan_id, $vehicle_type );
    $filtered_data = [];

    // Map internal feature keys (from get_plan_features) to the structure of $api_data
    $feature_to_data_location = [
        'specs' => ['specs'], // Top level 'specs' array from Specs API
        'thefts' => ['history', 'thefts'], // 'thefts' array within 'history' data
        'titles' => ['history', 'titles'],
        'checks' => ['history', 'checks'], // For Title Brand
        'market_value' => ['value'], // Top level 'value' array from Market Value API
        'accidents' => ['history', 'accidents'],
        'accidental_information' => ['history', 'accidents'], // Map this also to accidents for Bike Silver
        'salvage' => ['history', ['salvage', 'jsi']], // Check both 'salvage' and 'jsi' under history
        'impounds' => [], // Placeholder - No clear mapping from provided API docs
        'images' => ['images'], // Top level 'images' array from Image API
        'recalls' => [], // Placeholder - No clear mapping for Open Recalls
        'mileage' => ['history', 'titles'], // Check within title records for mileage data
        'exports' => [], // Placeholder - No clear mapping
        'sale' => ['history', 'sale'], // Sales listing info
        'warranties' => ['specs_warranties'], // Top level 'specs_warranties' array from Specs API
        'options_packages' => [], // Placeholder - Often part of detailed specs, needs specific key identification
    ];

    // Always include basic info passed in
    $filtered_data['vin'] = isset($api_data['vin']) ? $api_data['vin'] : 'N/A';
    $filtered_data['plan'] = $plan_id;
    $filtered_data['generation_date'] = isset($api_data['generation_date']) ? $api_data['generation_date'] : current_time( 'mysql' );
    $filtered_data['vehicle_type'] = $vehicle_type; // Pass type to template

    // Add data based on allowed features
    foreach ($allowed_feature_keys as $feature_key) {
        if (isset($feature_to_data_location[$feature_key])) {
            $location = $feature_to_data_location[$feature_key];

            // Handle top-level keys
            if (count($location) === 1) {
                $key = $location[0];
                if (isset($api_data[$key])) {
                    $filtered_data[$key] = $api_data[$key];
                }
            }
            // Handle nested keys (e.g., under 'history')
            elseif (count($location) === 2 && $location[0] === 'history') {
                $sub_keys = (array) $location[1]; // Ensure sub_keys is an array
                if (!isset($filtered_data['history'])) $filtered_data['history'] = [];

                foreach ($sub_keys as $sub_key) {
                    if (isset($api_data['history'][$sub_key])) {
                        // Merge if key already exists (e.g., salvage + jsi mapped to 'salvage')
                        if (isset($filtered_data['history'][$sub_key]) && is_array($filtered_data['history'][$sub_key]) && is_array($api_data['history'][$sub_key])) {
                           $filtered_data['history'][$sub_key] = array_merge($filtered_data['history'][$sub_key], $api_data['history'][$sub_key]);
                        } else {
                           $filtered_data['history'][$sub_key] = $api_data['history'][$sub_key];
                        }
                    }
                }
                // Special handling for mileage/accidental info if needed (currently mapped to titles/accidents)
                if ($feature_key === 'mileage' && !isset($filtered_data['history']['titles'])) {
                    // If titles are excluded but mileage is allowed, maybe extract from elsewhere if possible? Unlikely.
                }

            }
            // Add more handlers if structure is deeper/different
        } else {
             // Log warning only if the feature key isn't empty (handles placeholders)
             if (!empty($feature_key)) {
                 error_log("VIN Plugin Warning: Feature key '{$feature_key}' for plan '{$plan_id}' / type '{$vehicle_type}' has no defined data location mapping.");
             }
        }
    }

    // Ensure core sections exist in the output array for template consistency
     $core_sections = ['specs', 'history', 'value', 'images', 'specs_warranties'];
     foreach ($core_sections as $section) {
         if (!isset($filtered_data[$section])) {
             $filtered_data[$section] = [];
         }
     }

    return $filtered_data;
}


/**
 * Ensure the dedicated directory for storing PDF reports exists and is writable.
 *
 * @return bool True if directory exists and is writable, false otherwise.
 */
function my_vin_ensure_reports_directory_exists() {
    $upload_dir = wp_upload_dir();
    // Check if uploads directory is valid and writable first
    if ( ! $upload_dir || $upload_dir['error'] !== false || ! is_writable( $upload_dir['basedir'] ) ) {
        error_log('VIN Plugin Error: WordPress upload directory is missing or not writable. Cannot create reports directory.');
        // Optionally add admin notice
        if ( is_admin() && current_user_can('manage_options') ) {
             add_action('admin_notices', function() {
                 echo '<div class="notice notice-error is-dismissible"><p>';
                 echo esc_html__( 'My VIN Verifier Error: The WordPress uploads directory is missing or not writable by the server. PDF reports cannot be saved.', 'my-vin-verifier' );
                 echo '</p></div>';
             });
         }
        return false;
    }

    $reports_dir = trailingslashit( $upload_dir['basedir'] ) . MY_VIN_PDF_STORAGE_DIR;

    // Attempt to create the directory if it doesn't exist
    if ( ! file_exists( $reports_dir ) ) {
        // Use WP Filesystem API if available for better permission handling
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ( ABSPATH . '/wp-admin/includes/file.php' );
            WP_Filesystem();
        }

        if ( ! $wp_filesystem || ! $wp_filesystem->mkdir( $reports_dir ) ) {
             // Fallback or direct mkdir attempt (might fail due to permissions)
             if ( ! @wp_mkdir_p( $reports_dir ) ) { // @ suppresses potential PHP warning if fails
                 error_log('VIN Plugin Error: Could not create reports directory: ' . $reports_dir);
                 return false;
             }
        }

        // Add security files after creation
        if ( file_exists( $reports_dir ) ) {
            if ( ! file_exists( trailingslashit( $reports_dir ) . 'index.php' ) ) {
                @file_put_contents( trailingslashit( $reports_dir ) . 'index.php', '<?php // Silence is golden.' );
            }
            if ( got_mod_rewrite() && ! file_exists( trailingslashit( $reports_dir ) . '.htaccess' ) ) {
                $htaccess_content = "Options -Indexes\ndeny from all";
                 if ( $wp_filesystem ) {
                     $wp_filesystem->put_contents( trailingslashit( $reports_dir ) . '.htaccess', $htaccess_content, FS_CHMOD_FILE );
                 } else {
                     @file_put_contents( trailingslashit( $reports_dir ) . '.htaccess', $htaccess_content );
                 }
            }
        }
    }

    // Final check if directory exists and is writable
    if ( ! is_writable( $reports_dir ) ) {
         error_log('VIN Plugin Error: Reports directory exists but is not writable: ' . $reports_dir);
         // Optionally add admin notice
          if ( is_admin() && current_user_can('manage_options') ) {
             add_action('admin_notices', function() use ($reports_dir) {
                 echo '<div class="notice notice-error is-dismissible"><p>';
                 echo sprintf(
                    esc_html__( 'My VIN Verifier Error: The reports directory (%s) is not writable by the server. Please check file permissions. PDF reports cannot be saved.', 'my-vin-verifier' ),
                    '<code>' . esc_html($reports_dir) . '</code>'
                 );
                 echo '</p></div>';
             });
         }
         return false;
    }

    return true;
}

/**
 * Generates a download URL for a stored report file path.
 * Assumes path is stored relative to WP uploads directory base.
 *
 * @param string $relative_file_path Path relative to WP_CONTENT_DIR/uploads/ (e.g., '/vin_reports/report.pdf').
 * @return string|false URL or false on error/file not found.
 */
function my_vin_get_report_download_url( $relative_file_path ) {
     if ( empty( $relative_file_path ) ) {
        return false;
    }

    $upload_dir = wp_upload_dir();
    $full_file_path = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_file_path, '/\\' );

    // Check if file actually exists before generating URL
    if ( file_exists( $full_file_path ) ) {
        $file_url = trailingslashit( $upload_dir['baseurl'] ) . ltrim( $relative_file_path, '/\\' );
        return esc_url( $file_url ); // Return escaped URL
    } else {
        error_log('VIN Plugin Warning: Attempted to get download URL for non-existent file: ' . $full_file_path);
        return false;
    }

    // For more security, implement a download script that checks permissions/nonces
    // before serving the file, instead of returning a direct URL.
}


?>
