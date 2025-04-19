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
        MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION => '', // Use defined constant
        MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION => '',
        MY_VIN_PAYPAL_LIVE_SECRET_OPTION => '',
        MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION => '', // Use defined constant
    );
    // Ensure options are retrieved correctly
    $options_saved = get_option( 'my_vin_verifier_settings', array() );
    // Merge defaults with saved options to ensure all keys exist
    $options = wp_parse_args( $options_saved, $defaults );

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
        'webhook_id' => '',
    );

    if ( $mode === 'live' ) {
        $credentials['client_id'] = my_vin_get_setting( MY_VIN_PAYPAL_LIVE_CLIENT_ID_OPTION );
        $credentials['secret']    = my_vin_get_setting( MY_VIN_PAYPAL_LIVE_SECRET_OPTION );
        $credentials['webhook_id'] = my_vin_get_setting( MY_VIN_PAYPAL_WEBHOOK_ID_LIVE_OPTION );
    } else { // Sandbox mode
        $credentials['client_id'] = my_vin_get_setting( MY_VIN_PAYPAL_SANDBOX_CLIENT_ID_OPTION );
        $credentials['secret']    = my_vin_get_setting( MY_VIN_PAYPAL_SANDBOX_SECRET_OPTION );
        $credentials['webhook_id'] = my_vin_get_setting( MY_VIN_PAYPAL_WEBHOOK_ID_SANDBOX_OPTION );
    }

    return $credentials;
}
/**
 * Get Plan Features based on Plan ID and Vehicle Type (Car/Bike).
 * Updated based on VIN_Verification_Service_Packages.pdf and VinAudit API Documentation.txt.
 * Returns internal keys corresponding to expected API response structures.
 *
 * @param string $plan_id ('silver', 'gold', 'platinum').
 * @param string $vehicle_type ('car', 'bike'). Assumed 'car' if not 'bike'.
 * @return array List of internal feature keys included in the plan.
 */
function my_vin_get_plan_features( $plan_id, $vehicle_type = 'car' ) {
    $type = ( strtolower($vehicle_type) === 'bike' ) ? 'bike' : 'car';
    $plan = strtolower($plan_id);

    // --- Define Base Features Included in All Plans ---
    // Based on PDF and common sense (Specs/Overview always needed)
    $base_features = [
        'specs',        // Vehicle Specifications / Overview
        'thefts',       // Theft Record
        'market_value', // Market Value
        'accidents',    // Accident Record / Accidental Information
        'sale',         // Sales Listing
        'specs_warranties', // Active/Expired Warranties (part of Specs API response)
        // 'impounds',    // PDF includes, but no clear key in API docs sample. Omit for now.
        // 'recalls',     // PDF includes, but no clear key in API docs sample. Omit for now.
        // 'options_packages' // PDF includes, likely part of 'specs', rely on 'specs' inclusion.
    ];

    // --- Define Features Added Progressively by Plan ---
    $added_features = [
        'car' => [
            'silver' => ['images'], // Silver Car includes HQ Car Images
            'gold'   => ['images', 'titles', 'salvage', 'jsi', /*'exports'*/], // Gold adds Titles, Salvage/JSI. Exports unclear.
            'platinum' => ['images', 'titles', 'salvage', 'jsi', /*'exports',*/ 'checks'] // Platinum adds Title Brand (checks).
        ],
        'bike' => [
            'silver' => ['images'], // Silver Bike includes HQ Bike Images
            'gold'   => ['images', 'titles', 'salvage', 'jsi', /*'exports'*/], // Gold adds Titles, Salvage/JSI. Exports unclear.
            'platinum' => ['images', 'titles', 'salvage', 'jsi', /*'exports',*/ 'checks'] // Platinum adds Title Brand (checks). PDF says Plat bike uses Car images, but map to 'images' anyway.
        ]
        // Note: 'Mileage' and 'Accidental Information' are listed only for Silver in PDF, but Gold/Platinum include 'titles' and 'accidents' which contain this info.
        // We will rely on the inclusion of 'titles' and 'accidents' for Gold/Platinum rather than adding specific 'mileage' keys.
        // Note: 'Exports', 'Impounds', 'Open Recalls' are mentioned in PDF but lack clear keys in the provided API docs sample. They are omitted from the mapping for now.
    ];

    // Get the specific added features for the plan, default to silver if plan invalid
    $plan_specific_features = $added_features[$type][$plan] ?? $added_features[$type]['silver'] ?? [];

    // Combine base features with plan-specific features and remove duplicates
    $all_features = array_unique(array_merge($base_features, $plan_specific_features));

    // Ensure essential keys are always present if somehow missed (shouldn't happen with base_features)
    if (!in_array('specs', $all_features)) $all_features[] = 'specs';
    if (!in_array('market_value', $all_features)) $all_features[] = 'market_value';


    // error_log("Plan Features for {$type} / {$plan}: " . print_r($all_features, true)); // Debugging log

    return $all_features;
}


/**
 * Filter combined VinAudit API data based on plan features.
 * Uses the internal keys returned by my_vin_get_plan_features.
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

    // Always include basic info passed in
    $filtered_data['vin'] = isset($api_data['vin']) ? $api_data['vin'] : 'N/A';
    $filtered_data['plan'] = $plan_id;
    $filtered_data['generation_date'] = isset($api_data['generation_date']) ? $api_data['generation_date'] : current_time( 'mysql' );
    $filtered_data['vehicle_type'] = $vehicle_type; // Pass type to template

    // --- Define mapping from internal feature key to location in $api_data ---
    // Structure: 'feature_key' => ['top_level_key', 'optional_nested_key']
    $data_map = [
        'specs'        => ['specs'],
        'specs_warranties' => ['specs_warranties'], // Assumes warranties are passed separately now
        'value'        => ['value', 'prices'], // Market value API has 'prices' nested under 'value'
        'images'       => ['images'],
        // History items are nested under 'history' key in $api_data
        'titles'       => ['history', 'titles'],
        'thefts'       => ['history', 'thefts'],
        'accidents'    => ['history', 'accidents'],
        'salvage'      => ['history', 'salvage'],
        'jsi'          => ['history', 'jsi'], // Junk/Salvage/Insurance records
        'sale'         => ['history', 'sale'],
        'checks'       => ['history', 'checks'], // Title Brand Checks
        'lien'         => ['history', 'lien'], // Lien records (not in PDF, but in API docs) - Add if needed by a plan later
        // Keys omitted due to lack of clear mapping in provided API docs: impounds, recalls, exports
    ];

    // Iterate through allowed features and copy data
    foreach ($allowed_feature_keys as $feature_key) {
        if (isset($data_map[$feature_key])) {
            $location = $data_map[$feature_key];
            $top_key = $location[0];
            $nested_key = isset($location[1]) ? $location[1] : null;

            // Check if the top-level data exists
            if (isset($api_data[$top_key])) {
                if ($nested_key) {
                    // Handle nested data (e.g., history items, market value prices)
                    if (isset($api_data[$top_key][$nested_key])) {
                        // Ensure the parent array exists in filtered data
                        if (!isset($filtered_data[$top_key])) {
                            $filtered_data[$top_key] = [];
                        }
                        // Add the nested data
                        $filtered_data[$top_key][$nested_key] = $api_data[$top_key][$nested_key];
                    }
                } else {
                    // Handle top-level data (e.g., specs, images, specs_warranties)
                    $filtered_data[$top_key] = $api_data[$top_key];
                }
            }
        } else {
            // Log if an allowed feature key doesn't have a mapping (shouldn't happen often with current setup)
            error_log("VIN Plugin Warning: Allowed feature key '{$feature_key}' has no data mapping defined in my_vin_filter_data_for_plan.");
        }
    }

    // Ensure core sections exist in the output array for template consistency, even if empty
    $core_sections = ['specs', 'history', 'value', 'images', 'specs_warranties'];
    foreach ($core_sections as $section) {
        if (!isset($filtered_data[$section])) {
            // If it's history, ensure it's an array
            $filtered_data[$section] = ($section === 'history') ? [] : null;
        }
        // Ensure history sub-sections are arrays if history itself exists
        if ($section === 'history' && is_array($filtered_data['history'])) {
             $history_sub_keys = ['titles', 'thefts', 'accidents', 'salvage', 'jsi', 'sale', 'checks', 'lien'];
             foreach($history_sub_keys as $h_key) {
                 if (!isset($filtered_data['history'][$h_key])) {
                     $filtered_data['history'][$h_key] = [];
                 }
             }
        }
        // Ensure market value 'prices' exists if value exists
        if ($section === 'value' && isset($filtered_data['value']) && !isset($filtered_data['value']['prices'])) {
            $filtered_data['value']['prices'] = [];
        }
    }


    // error_log("Filtered Data for {$plan_id} / {$vehicle_type}: " . print_r($filtered_data, true)); // Debugging log

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

