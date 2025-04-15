<?php
/**
 * PayPal REST API Interaction Functions.
 * Handles OAuth2 token retrieval, order creation/capture (v2/checkout/orders),
 * and webhook signature verification structure.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get PayPal API Base URL based on mode setting.
 * @return string Base URL.
 */
function my_vin_get_paypal_api_base_url() {
    $credentials = my_vin_get_paypal_credentials(); // From utils.php
    return $credentials['base_url']; // Returns sandbox or live URL
}

/**
 * Get PayPal Access Token using Client Credentials Grant.
 * Caches the token using WordPress Transients for performance.
 *
 * @return string|WP_Error Access token string on success, WP_Error on failure.
 */
function my_vin_paypal_get_access_token() {
    $credentials = my_vin_get_paypal_credentials();
    $mode = $credentials['mode'];
    $client_id = $credentials['client_id'];
    $secret = $credentials['secret'];
    $transient_key = 'my_vin_paypal_token_' . $mode;

    // Check cache first
    $cached_token = get_transient( $transient_key );
    if ( $cached_token ) {
        return $cached_token;
    }

    // Check if credentials are set
    if ( empty( $client_id ) || empty( $secret ) ) {
        return new WP_Error('paypal_credentials_missing', sprintf( __('PayPal API credentials are not configured for %s mode.', 'my-vin-verifier'), $mode) );
    }

    $api_base_url = my_vin_get_paypal_api_base_url();
    $auth_url = $api_base_url . '/v1/oauth2/token';

    $response = wp_remote_post( $auth_url, array(
        'method'    => 'POST',
        'headers'   => array(
            'Accept'        => 'application/json',
            'Accept-Language' => 'en_US',
            'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), // Basic Auth for token endpoint
        ),
        'body'      => array(
            'grant_type' => 'client_credentials', // Grant type for server-to-server token
        ),
        'timeout'   => 15,
    ));

    if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] PayPal Token HTTP Error: " . $response->get_error_message() );
        return new WP_Error( 'paypal_token_http_error', __('Failed to connect to PayPal to get access token.', 'my-vin-verifier') );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
        $error_message = isset( $data['error_description'] ) ? $data['error_description'] : __('Unknown error retrieving token', 'my-vin-verifier');
        error_log( "[VIN Plugin] PayPal Token API Error (Status: {$status_code}): {$error_message} | Body: " . $body );
        return new WP_Error( 'paypal_token_api_error', __('PayPal Token Error: ', 'my-vin-verifier') . esc_html($error_message) );
    }

    $access_token = $data['access_token'];
    // PayPal tokens have expiry, cache using transient
    $expires_in = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 32400; // Default ~9 hours

    // Cache the token for slightly less than its expiry time (e.g., expiry - 5 minutes)
    set_transient( $transient_key, $access_token, max(300, $expires_in - 300) ); // Cache for at least 5 mins

    return $access_token;
}

/**
 * Creates a PayPal Order using the v2/checkout/orders API.
 *
 * @param float $amount The amount for the order.
 * @param string $currency The currency code (e.g., 'USD').
 * @param int|string $internal_order_id Your internal reference ID (e.g., report_id from DB).
 * @param string $description Optional description for the purchase unit.
 * @return array|WP_Error Decoded JSON response from PayPal (containing order ID) on success, WP_Error on failure.
 */
function my_vin_paypal_create_order( $amount, $currency = 'USD', $internal_order_id = null, $description = 'VIN Report Purchase' ) {
    $access_token = my_vin_paypal_get_access_token();
    if ( is_wp_error( $access_token ) ) {
        return $access_token; // Propagate error
    }

    $api_base_url = my_vin_get_paypal_api_base_url();
    $order_url = $api_base_url . '/v2/checkout/orders';

    // Validate amount
    $amount = round( floatval( $amount ), 2 );
    if ( $amount <= 0 ) {
        return new WP_Error('invalid_amount', __('Invalid order amount provided.', 'my-vin-verifier'));
    }

    // Prepare payload according to PayPal v2/checkout/orders API reference
    $payload = array(
        'intent' => 'CAPTURE', // Capture payment immediately upon buyer approval
        'purchase_units' => array(
            array(
                'amount' => array(
                    'currency_code' => strtoupper( $currency ),
                    'value' => sprintf( '%.2f', $amount ), // Ensure correct format "10.00"
                ),
                'description' => sanitize_text_field($description),
                // Use 'custom_id' to link back to your internal order/report ID for webhook reconciliation
                'custom_id' => $internal_order_id ? strval($internal_order_id) : null,
                // 'invoice_id' => 'INV-' . $internal_order_id, // Optional: If you have unique invoice IDs
            ),
        ),
        // Optional: Define application context for branding, return URLs etc.
        // Return/Cancel URLs less critical for JS SDK 'capture' intent but good practice.
         'application_context' => array(
             'brand_name' => get_bloginfo('name'), // Your site/brand name
             'landing_page' => 'LOGIN', // Or 'BILLING' - Controls initial PayPal page view
             'user_action' => 'PAY_NOW', // Button text on PayPal review page
             // 'return_url' => site_url('/paypal/return?orderId=' . $internal_order_id), // Example return URL
             // 'cancel_url' => site_url('/paypal/cancel?orderId=' . $internal_order_id), // Example cancel URL
         )
    );

    $response = wp_remote_post( $order_url, array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
            'PayPal-Request-Id' => 'vin-' . ($internal_order_id ?? uniqid()), // Example request ID for idempotency
        ),
        'body'    => wp_json_encode( $payload ), // Use wp_json_encode
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] PayPal Create Order HTTP Error: " . $response->get_error_message() );
        return new WP_Error( 'paypal_create_http_error', __('Failed to connect to PayPal to create order.', 'my-vin-verifier') );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    // PayPal returns 201 Created on success
    if ( $status_code !== 201 || empty( $data['id'] ) ) {
         $error_message = isset( $data['message'] ) ? $data['message'] : __('Unknown error creating PayPal order', 'my-vin-verifier');
         error_log( "[VIN Plugin] PayPal Create Order API Error (Status: {$status_code}): {$error_message} | Body: " . $body );
         return new WP_Error( 'paypal_create_api_error', __('PayPal Create Order Error: ', 'my-vin-verifier') . esc_html($error_message) );
    }

    // Return the full response data, must contain 'id' (PayPal Order ID)
    return $data;
}

/**
 * Captures a PayPal Order using the v2/checkout/orders/{order_id}/capture API.
 *
 * @param string $paypal_order_id The Order ID obtained from PayPal after creation/approval.
 * @return array|WP_Error Decoded JSON response from PayPal capture endpoint on success, WP_Error on failure.
 */
function my_vin_paypal_capture_order( $paypal_order_id ) {
    $access_token = my_vin_paypal_get_access_token();
    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    if ( empty( $paypal_order_id ) ) {
        return new WP_Error('missing_order_id', __('PayPal Order ID is required to capture payment.', 'my-vin-verifier'));
    }

    $api_base_url = my_vin_get_paypal_api_base_url();
    $capture_url = $api_base_url . '/v2/checkout/orders/' . sanitize_text_field($paypal_order_id) . '/capture';

    $response = wp_remote_post( $capture_url, array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
            'PayPal-Request-Id' => 'capture-' . $paypal_order_id . '-' . time(), // Idempotency key for capture
            // 'Prefer' => 'return=representation', // Optional: Include if you want full resource details in response
        ),
        'body'    => null, // No body needed for capture with intent=CAPTURE
        'timeout' => 45, // Capture can take longer, increase timeout
    ));

     if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] PayPal Capture Order HTTP Error: " . $response->get_error_message() );
        return new WP_Error( 'paypal_capture_http_error', __('Failed to connect to PayPal to capture order.', 'my-vin-verifier') );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    // PayPal returns 201 Created on successful capture.
    // Might return 200 OK if already captured or in certain scenarios.
    if ( ($status_code !== 201 && $status_code !== 200) || empty( $data['status'] ) ) {
         $error_message = isset( $data['message'] ) ? $data['message'] : __('Unknown error capturing PayPal order', 'my-vin-verifier');
         error_log( "[VIN Plugin] PayPal Capture Order API Error (Status: {$status_code}): {$error_message} | Body: " . $body );
         // Check for specific error 'ORDER_ALREADY_CAPTURED' if status code is 422 (Unprocessable Entity)
         if ($status_code === 422 && isset($data['details'][0]['issue']) && $data['details'][0]['issue'] === 'ORDER_ALREADY_CAPTURED') {
              // This might not be a fatal error if webhook handles fulfillment, treat as success? Or let caller handle.
              // For now, return specific error. Webhook should be primary fulfillment trigger.
              return new WP_Error( 'order_already_captured', __('This PayPal order has already been captured.', 'my-vin-verifier'), $data );
         }
         return new WP_Error( 'paypal_capture_api_error', __('PayPal Capture Order Error: ', 'my-vin-verifier') . esc_html($error_message), $data ); // Include data for debugging
    }

    // Return the full successful capture response data (includes status, payer info, transaction details)
    return $data;
}


/**
 * Verifies a PayPal Webhook Signature using PayPal's API.
 *
 * @param array $headers Request headers from the webhook POST (case-insensitive keys expected from WP_REST_Request).
 * @param string $raw_body Raw request body from the webhook POST.
 * @return bool|WP_Error True if verified, WP_Error otherwise.
 */
function my_vin_paypal_verify_webhook_signature( $headers, $raw_body ) {
     $credentials = my_vin_get_paypal_credentials();
     $webhook_id = $credentials['webhook_id']; // Get the configured Webhook ID (Sandbox or Live)
     $access_token = my_vin_paypal_get_access_token(); // Need token for verification API

     if ( is_wp_error( $access_token ) ) {
         error_log("[VIN Plugin] Webhook Verify Error: Could not get access token.");
         return $access_token;
     }
     if ( empty($webhook_id) ) {
         error_log("[VIN Plugin] Webhook Verify Error: PayPal Webhook ID is not configured.");
         return new WP_Error('webhook_id_missing', __('PayPal Webhook ID is not configured in settings.', 'my-vin-verifier'));
     }

     // Extract required headers (keys are lowercase from WP_REST_Request->get_headers())
     $auth_algo = isset($headers['paypal-auth-algo'][0]) ? sanitize_text_field($headers['paypal-auth-algo'][0]) : null;
     $cert_url = isset($headers['paypal-cert-url'][0]) ? esc_url_raw($headers['paypal-cert-url'][0]) : null;
     $transmission_id = isset($headers['paypal-transmission-id'][0]) ? sanitize_text_field($headers['paypal-transmission-id'][0]) : null;
     $transmission_sig = isset($headers['paypal-transmission-sig'][0]) ? sanitize_text_field($headers['paypal-transmission-sig'][0]) : null;
     $transmission_time = isset($headers['paypal-transmission-time'][0]) ? sanitize_text_field($headers['paypal-transmission-time'][0]) : null;

     if (!$auth_algo || !$cert_url || !$transmission_id || !$transmission_sig || !$transmission_time) {
          error_log("[VIN Plugin] Webhook Verify Error: Missing required PayPal headers.");
          return new WP_Error('webhook_missing_headers', __('Missing required headers for webhook verification.', 'my-vin-verifier'));
     }

     // --- Call PayPal's verify-webhook-signature API ---
     $api_base_url = my_vin_get_paypal_api_base_url();
     $verify_url = $api_base_url . '/v1/notifications/verify-webhook-signature';

     $payload = array(
        'auth_algo'         => $auth_algo,
        'cert_url'          => $cert_url,
        'transmission_id'   => $transmission_id,
        'transmission_sig'  => $transmission_sig,
        'transmission_time' => $transmission_time,
        'webhook_id'        => $webhook_id,
        'webhook_event'     => json_decode($raw_body) // Send parsed event body
     );

     $response = wp_remote_post( $verify_url, array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] Webhook Verify HTTP Error: " . $response->get_error_message() );
        return new WP_Error( 'webhook_verify_http_error', __('Failed to connect to PayPal to verify webhook.', 'my-vin-verifier') );
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $status_code !== 200 || !isset($data['verification_status']) ) {
         $error_message = isset( $data['message'] ) ? $data['message'] : __('Unknown error verifying webhook', 'my-vin-verifier');
         error_log( "[VIN Plugin] Webhook Verify API Error (Status: {$status_code}): {$error_message} | Body: " . $body );
         return new WP_Error( 'webhook_verify_api_error', __('PayPal Webhook Verification Error: ', 'my-vin-verifier') . esc_html($error_message) );
    }

    // Check the verification status
    if ( $data['verification_status'] !== 'SUCCESS' ) {
         error_log( "[VIN Plugin] Webhook Verify Failed: Status is " . $data['verification_status'] );
         return new WP_Error('webhook_verification_failed', __('PayPal Webhook signature verification failed.', 'my-vin-verifier'));
    }

     // Verification successful!
     return true;
}


/**
 * Issues a refund via PayPal API (Placeholder Structure).
 * Requires PayPal Capture ID from the completed transaction.
 *
 * @param string $paypal_capture_id The ID of the capture transaction to refund.
 * @param float|null $amount Optional amount to refund (full if null).
 * @param string $currency Currency code.
 * @param string $reason Optional reason for refund.
 * @return array|WP_Error Refund details on success, WP_Error on failure.
 */
function my_vin_paypal_issue_refund( $paypal_capture_id, $amount = null, $currency = 'USD', $reason = 'Report generation failed' ) {
     $access_token = my_vin_paypal_get_access_token();
    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }
     if ( empty( $paypal_capture_id ) ) {
        return new WP_Error('missing_capture_id', __('PayPal Capture ID is required to issue refund.', 'my-vin-verifier'));
    }

    $api_base_url = my_vin_get_paypal_api_base_url();
    // Endpoint for refunding a captured payment
    $refund_url = $api_base_url . '/v2/payments/captures/' . sanitize_text_field($paypal_capture_id) . '/refund';

    $payload = array(
        'note_to_payer' => sanitize_text_field($reason),
    );
    // If partial refund amount is specified
    if ( $amount !== null && is_numeric($amount) && $amount > 0 ) {
        $payload['amount'] = array(
            'value' => sprintf('%.2f', round(floatval($amount), 2)),
            'currency_code' => strtoupper($currency)
        );
    } // Otherwise, PayPal defaults to full refund

     $response = wp_remote_post( $refund_url, array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
             'PayPal-Request-Id' => 'refund-' . $paypal_capture_id . '-' . time(),
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 30,
    ));

     if ( is_wp_error( $response ) ) {
        error_log( "[VIN Plugin] PayPal Refund HTTP Error: " . $response->get_error_message() );
        return new WP_Error( 'paypal_refund_http_error', __('Failed to connect to PayPal to issue refund.', 'my-vin-verifier') );
    }

     $status_code = wp_remote_retrieve_response_code( $response );
     $body = wp_remote_retrieve_body( $response );
     $data = json_decode( $body, true );

     // PayPal returns 201 Created on successful refund
     if ( $status_code !== 201 || empty($data['status']) || $data['status'] !== 'COMPLETED' ) { // Check status in response
          $error_message = isset( $data['message'] ) ? $data['message'] : __('Unknown error issuing PayPal refund', 'my-vin-verifier');
          error_log( "[VIN Plugin] PayPal Refund API Error (Status: {$status_code}): {$error_message} | Body: " . $body );
          return new WP_Error( 'paypal_refund_api_error', __('PayPal Refund Error: ', 'my-vin-verifier') . esc_html($error_message), $data );
     }

     // Return refund details
     return $data; // Contains refund ID, status, etc.
}


?>
