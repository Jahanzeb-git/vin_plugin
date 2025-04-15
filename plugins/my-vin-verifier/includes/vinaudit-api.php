<?php
/**
 * VinAudit API Interaction Functions.
 * Handles communication with the VinAudit Specifications, History, Market Value, and Image APIs.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the VinAudit API key securely from options.
 *
 * @return string The API key or an empty string if not set.
 */
function my_vin_get_api_key() {
    return my_vin_get_setting( MY_VIN_API_KEY_OPTION, 'VA_DEMO_KEY' ); // Use helper from utils.php
}

/**
 * Makes a request to a VinAudit API endpoint using WordPress HTTP API.
 * Handles JSON and basic XML responses, converting XML to array.
 *
 * @param string $endpoint_url The full URL of the API endpoint.
 * @param array  $params       Associative array of query parameters (key, vin, format, etc.).
 * @param string $expected_format 'json' or 'xml'. Defaults to 'json'.
 * @return array|WP_Error An associative array decoded from JSON/XML on success, or a WP_Error object on failure.
 */
function my_vin_make_api_request( $endpoint_url, $params, $expected_format = 'json' ) {
    // Add API key if not already present in params
    if ( ! isset( $params['key'] ) ) {
        $params['key'] = my_vin_get_api_key();
        // If even the default demo key is somehow empty, return error
        if ( empty($params['key']) ) {
             return new WP_Error( 'api_key_missing', __('VinAudit API Key is not configured.', 'my-vin-verifier') );
        }
    }

    // Set format
    $params['format'] = $expected_format;

    // Build the query string
    $request_url = add_query_arg( $params, $endpoint_url );

    // Use WordPress HTTP API for requests
    $args = array(
        'timeout' => 25, // Increased timeout slightly
        'headers' => array(
            'Accept' => ($expected_format === 'json') ? 'application/json' : 'application/xml',
        ),
    );
    $response = wp_remote_get( esc_url_raw( $request_url ), $args );

    // --- Response Handling ---
    if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] HTTP Error calling {$endpoint_url}: " . $response->get_error_message() );
        return new WP_Error( 'http_error', __('Failed to connect to VinAudit API. Please try again later.', 'my-vin-verifier') ); // User-friendly message
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $status_code !== 200 ) {
        error_log( "[VIN Plugin] API HTTP Status Error ({$endpoint_url}): Status Code {$status_code} | Body: " . substr($body, 0, 500) );
        return new WP_Error( 'api_http_error', sprintf(__('VinAudit API returned status code: %d. Please check VIN or contact support.', 'my-vin-verifier'), $status_code) );
    }

    if ( empty( $body ) ) {
        error_log( "[VIN Plugin] API Empty Response ({$endpoint_url})" );
        return new WP_Error( 'empty_response', __('VinAudit API returned an empty response.', 'my-vin-verifier') );
    }

    // Decode the response based on expected format
    $data = null;
    $parse_error = false;
    if ($expected_format === 'json') {
        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( "[VIN Plugin] JSON Decode Error ({$endpoint_url}): " . json_last_error_msg() . " | Body: " . substr($body, 0, 500) );
            $parse_error = true;
        }
    } elseif ($expected_format === 'xml') {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            $xml_errors = libxml_get_errors(); // Consider logging these errors
            libxml_clear_errors();
            error_log( "[VIN Plugin] XML Parse Error ({$endpoint_url}): " . print_r($xml_errors, true) . " | Body: " . substr($body, 0, 500) );
            $parse_error = true;
        } else {
            // Convert SimpleXML object to array
            $data = json_decode(json_encode($xml), true);
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                 error_log( "[VIN Plugin] XML->JSON Conversion Error ({$endpoint_url}): " . json_last_error_msg() );
                 $parse_error = true; // Treat conversion error as parse error
            }
        }
    } else {
         return new WP_Error('invalid_format', __('Invalid expected format specified for API request.', 'my-vin-verifier'));
    }

    if ($parse_error) {
         return new WP_Error( 'parse_error', __('Failed to understand the response from VinAudit API.', 'my-vin-verifier') );
    }

    // Check for API-level errors (standardized check)
    $api_success = true;
    $error_message = __('An unknown error occurred processing the VIN data.', 'my-vin-verifier'); // Default user-friendly message

    // Check for 'success' field (common in JSON)
    if ( isset( $data['success'] ) && ( $data['success'] === false || strtolower(strval($data['success'])) === 'false' || $data['success'] === 0 ) ) {
        $api_success = false;
        $error_message = isset( $data['error'] ) ? strval($data['error']) : $error_message;
    }
    // Check for non-empty 'error' field (common in both JSON/XML converted)
    elseif (isset($data['error']) && !empty(trim(strval($data['error'])))) {
         $api_success = false;
         $error_message = strval($data['error']);
    }
     // Add specific checks if an API indicates errors differently without 'success' or 'error' fields

    if (!$api_success) {
        error_log( "[VIN Plugin] API Logic Error ({$endpoint_url}): " . $error_message );
        // Make common API error messages slightly more user-friendly
        if (stripos($error_message, 'invalid_input') !== false || stripos($error_message, 'no_data') !== false) {
            $error_message = __('VIN not found or invalid input provided.', 'my-vin-verifier');
        } elseif (stripos($error_message, 'invalid_key') !== false) {
             $error_message = __('API configuration error. Please contact support.', 'my-vin-verifier'); // Don't expose key issues to user
        } else {
            $error_message = __('VinAudit API reported an error: ', 'my-vin-verifier') . esc_html($error_message); // Escape potentially unsafe API messages
        }
        return new WP_Error( 'api_logic_error', $error_message );
    }

    return $data; // Return the decoded associative array
}

/**
 * Fetches basic vehicle specifications from VinAudit.
 *
 * @param string $vin The 17-digit VIN.
 * @return array|WP_Error Decoded JSON data array on success, WP_Error on failure.
 */
function my_vin_get_specifications( $vin ) {
    $endpoint = 'https://specifications.vinaudit.com/v3/specifications';
    $params = array(
        'vin' => sanitize_text_field( strtoupper( $vin ) ),
    );
    return my_vin_make_api_request( $endpoint, $params, 'json' );
}

/**
 * Fetches the full vehicle history report from VinAudit.
 *
 * @param string $vin The 17-digit VIN.
 * @param string|int $report_id Internal Order/Report ID to pass to VinAudit.
 * @return array|WP_Error Decoded JSON data array on success, WP_Error on failure.
 */
function my_vin_get_history_report( $vin, $report_id ) {
    $endpoint = 'https://api.vinaudit.com/v2/pullreport';
    $params = array(
        'vin' => sanitize_text_field( strtoupper( $vin ) ),
        'id'  => sanitize_text_field( $report_id ), // Pass internal ID
    );
    return my_vin_make_api_request( $endpoint, $params, 'json' );
}

/**
 * Fetches the market value from VinAudit.
 *
 * @param string $vin The 17-digit VIN.
 * @param int $period Time period in days (e.g., 90).
 * @param string $mileage Mileage parameter (e.g., 'average', or specific mileage).
 * @return array|WP_Error Decoded JSON data array on success, WP_Error on failure.
 */
function my_vin_get_market_value( $vin, $period = 90, $mileage = 'average' ) {
    // Use HTTPS if supported, check VinAudit docs. Defaulting to HTTPS.
    $endpoint = 'https://marketvalue.vinaudit.com/getmarketvalue.php';
    $params = array(
        'vin'     => sanitize_text_field( strtoupper( $vin ) ),
        'period'  => absint( $period ),
        'mileage' => sanitize_text_field( $mileage ),
    );
    return my_vin_make_api_request( $endpoint, $params, 'json' );
}

/**
 * Fetches vehicle images from VinAudit.
 * Attempts to request JSON, handles potential variations in response structure.
 *
 * @param string $vin The 17-digit VIN.
 * @return array|WP_Error Decoded JSON data array with a structured ['images'] key on success, WP_Error on failure.
 */
function my_vin_get_images( $vin ) {
    $endpoint = 'https://images.vinaudit.com/v3/images';
    $params = array(
        'vin' => sanitize_text_field( strtoupper( $vin ) ),
    );
    // Request JSON
    $response = my_vin_make_api_request( $endpoint, $params, 'json' );

    // Process the response to ensure 'images' key contains an array of URL objects
    if (!is_wp_error($response)) {
        $processed_images = [];
        if (isset($response['images'])) {
            // Handle case where 'image' might be single string or array
            if (isset($response['images']['image'])) {
                $images_raw = is_array($response['images']['image']) ? $response['images']['image'] : [$response['images']['image']];
                foreach ($images_raw as $img_url) {
                    // Check if it's a direct URL string or potentially nested structure
                    $url_to_check = is_array($img_url) ? ($img_url['url'] ?? null) : $img_url;
                    if (is_string($url_to_check) && filter_var($url_to_check, FILTER_VALIDATE_URL)) {
                        // Store consistently as an array of objects with 'url' key
                        $processed_images[] = ['url' => esc_url_raw($url_to_check)];
                    }
                }
            }
            // Sometimes the API might return URLs directly under 'images' array
             elseif (is_array($response['images'])) {
                 foreach ($response['images'] as $img_url) {
                      if (is_string($img_url) && filter_var($img_url, FILTER_VALIDATE_URL)) {
                         $processed_images[] = ['url' => esc_url_raw($img_url)];
                     }
                 }
             }
        }
        // Replace original 'images' data with the processed array
        $response['images'] = $processed_images;
    }

    return $response;
}

?>
