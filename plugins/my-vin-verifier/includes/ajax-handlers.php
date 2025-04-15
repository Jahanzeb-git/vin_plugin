<?php
/**
 * AJAX Handler Functions for My VIN Verifier Plugin.
 * Handles requests from the front-end processing UI JavaScript, including PayPal SDK interactions.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates a VIN format server-side via AJAX.
 */
function my_vin_ajax_validate_vin() {
    // Verify nonce sent from localized script
    check_ajax_referer( MY_VIN_AJAX_NONCE_ACTION, 'nonce' );

    $vin = isset( $_POST['vin'] ) ? sanitize_text_field( strtoupper( $_POST['vin'] ) ) : '';

    // Basic server-side validation (matches JS validation)
    if ( empty( $vin ) || strlen( $vin ) !== 17 || ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $vin ) ) {
        wp_send_json_error( array( 'message' => __('Invalid VIN format provided.', 'my-vin-verifier') ) );
    }

    // --- Optional: Add further validation if needed (e.g., quick API check) ---
    // Example: Check if basic specs can be retrieved as a quick validity check
    // $specs = my_vin_get_specifications($vin);
    // if (is_wp_error($specs)) {
    //     wp_send_json_error( array( 'message' => __('VIN not found or could not be validated.', 'my-vin-verifier') ) );
    // }

    // If validation passes
    wp_send_json_success(); // Send success response (no extra data needed for this action)
}

/**
 * Fetches basic vehicle specifications via AJAX.
 */
function my_vin_ajax_get_basic_specs() {
    check_ajax_referer( MY_VIN_AJAX_NONCE_ACTION, 'nonce' );

    $vin = isset( $_POST['vin'] ) ? sanitize_text_field( strtoupper( $_POST['vin'] ) ) : '';

    if ( empty( $vin ) || strlen( $vin ) !== 17 || ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $vin ) ) {
        wp_send_json_error( array( 'message' => __('Invalid VIN provided for spec lookup.', 'my-vin-verifier') ) );
    }

    // Call the API function
    $response = my_vin_get_specifications( $vin );

    if ( is_wp_error( $response ) ) {
        // Send back a generic error or the specific API error message
        wp_send_json_error( array( 'message' => __('Error fetching specifications: ', 'my-vin-verifier') . $response->get_error_message() ) );
    } elseif ( isset( $response['attributes'] ) ) {
        // Extract only the needed fields for the front-end
        $specs_data = array(
            'year'   => isset( $response['attributes']['year'] ) ? esc_html($response['attributes']['year']) : 'N/A',
            'make'   => isset( $response['attributes']['make'] ) ? esc_html($response['attributes']['make']) : 'N/A',
            'model'  => isset( $response['attributes']['model'] ) ? esc_html($response['attributes']['model']) : 'N/A',
            'engine' => isset( $response['attributes']['engine'] ) ? esc_html($response['attributes']['engine']) : 'N/A',
            // Add other fields as needed, ensure they are escaped
        );
        wp_send_json_success( $specs_data );
    } else {
        // Handle cases where 'attributes' might be missing even if API call didn't return WP_Error
         wp_send_json_error( array( 'message' => __('Could not retrieve specification details for this VIN.', 'my-vin-verifier') ) );
    }
}

/**
 * Creates a PayPal order via AJAX for the JS SDK 'createOrder' callback.
 */
function my_vin_ajax_create_paypal_order() {
    check_ajax_referer( MY_VIN_AJAX_NONCE_ACTION, 'nonce' );

    // Get data sent from JS (ensure JS sends these)
    $vin   = isset( $_POST['vin'] ) ? sanitize_text_field( strtoupper( $_POST['vin'] ) ) : '';
    $plan  = isset( $_POST['plan'] ) ? sanitize_key( $_POST['plan'] ) : '';

    // --- Get price securely based on plan ---
    $prices = array( 'silver' => 34.99, 'gold' => 44.99, 'platinum' => 59.99 ); // Define prices server-side
    $price = isset( $prices[$plan] ) ? $prices[$plan] : 0;

    if ( empty( $vin ) || strlen($vin) !== 17 || empty( $plan ) || $price <= 0 ) {
         wp_send_json_error( array( 'message' => __('Missing or invalid order information.', 'my-vin-verifier') ) );
    }

    // --- Create a preliminary record in your database ---
    $user_id = get_current_user_id(); // 0 if not logged in
    // Attempt to get email from logged-in user or potentially passed from JS (less secure)
    $user_email = $user_id ? wp_get_current_user()->user_email : (isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : 'guest@example.com'); // Placeholder for guests

    $preliminary_order_data = array(
        'user_id' => $user_id,
        'user_email' => $user_email, // Consider requiring email for guests before payment
        'vin' => $vin,
        'plan_type' => $plan,
        'payment_status' => 'pending_paypal',
        'report_status' => 'pending',
    );
    $internal_order_id = my_vin_save_report_meta( $preliminary_order_data );

    if ( ! $internal_order_id ) {
        error_log('[VIN Plugin] Failed to save preliminary order to DB for VIN: ' . $vin);
        wp_send_json_error( array( 'message' => __('Could not initiate order process. Please try again later.', 'my-vin-verifier') ) );
    }

    // --- Call PayPal API to create the order ---
    $description = sprintf( '%s VIN Report (%s)', ucfirst($plan), $vin );
    $paypal_response = my_vin_paypal_create_order( $price, 'USD', $internal_order_id, $description );

    if ( is_wp_error( $paypal_response ) ) {
        error_log('[VIN Plugin] PayPal Create Order API Error: ' . $paypal_response->get_error_message());
        // Update internal order status to 'failed' maybe?
        my_vin_update_report_meta($internal_order_id, ['payment_status' => 'failed', 'report_status' => 'failed']);
        wp_send_json_error( array( 'message' => __('Could not create PayPal order: ', 'my-vin-verifier') . $paypal_response->get_error_message() ) );
    } elseif ( isset( $paypal_response['id'] ) ) {
        // Store PayPal order ID against internal order ID
        my_vin_update_report_meta( $internal_order_id, ['payment_transaction_id' => $paypal_response['id']] );
        // Send only the PayPal Order ID back to the JS SDK
        wp_send_json_success( array( 'orderID' => $paypal_response['id'] ) );
    } else {
        error_log('[VIN Plugin] Unexpected response from PayPal create order: ' . print_r($paypal_response, true));
         my_vin_update_report_meta($internal_order_id, ['payment_status' => 'failed', 'report_status' => 'failed']);
        wp_send_json_error( array( 'message' => __('Unexpected response when creating PayPal order.', 'my-vin-verifier') ) );
    }
}

/**
 * Captures a PayPal order via AJAX for the JS SDK 'onApprove' callback.
 * This confirms the user approved, but fulfillment should ideally be triggered by Webhook.
 * This function attempts capture and can trigger fulfillment as a fallback/immediate attempt.
 */
function my_vin_ajax_capture_paypal_order() {
    check_ajax_referer( MY_VIN_AJAX_NONCE_ACTION, 'nonce' );

    $paypal_order_id = isset( $_POST['orderID'] ) ? sanitize_text_field( $_POST['orderID'] ) : '';

     if ( empty( $paypal_order_id ) ) {
        wp_send_json_error( array( 'message' => __('Missing PayPal Order ID.', 'my-vin-verifier') ) );
    }

    // --- Find internal order using PayPal Order ID ---
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE payment_transaction_id = %s", $paypal_order_id ) );

    if ( ! $order ) {
         error_log('[VIN Plugin] Capture Error: Could not find internal order matching PayPal Order ID: ' . $paypal_order_id);
         // Even if internal order isn't found, try to capture payment to secure funds? Or error out? Error out for now.
         wp_send_json_error( array( 'message' => __('Internal order details not found for this payment.', 'my-vin-verifier') ) );
    }

    // Check if already completed (e.g., by webhook) to avoid double capture/fulfillment attempt
    if ($order->payment_status === 'completed' && $order->report_status === 'generated') {
        // Already done, return success and download link
        $download_url = my_vin_get_report_download_url( $order->report_file_path );
        wp_send_json_success( array(
            'message' => __('Payment already processed and report generated.', 'my-vin-verifier'),
            'downloadUrl' => $download_url ? $download_url : null
        ) );
    }


    // --- Call PayPal API to capture the order ---
    $capture_response = my_vin_paypal_capture_order( $paypal_order_id );

    if ( is_wp_error( $capture_response ) ) {
        $error_code = $capture_response->get_error_code();
        error_log("[VIN Plugin] PayPal Capture Order API Error: " . $capture_response->get_error_message());

        // If already captured, treat as success for fulfillment check, but log it.
        if ($error_code === 'order_already_captured') {
             // Proceed to check/trigger fulfillment, as payment is secured.
             // Log this occurrence.
             error_log("[VIN Plugin] Info: Capture attempt on already captured PayPal Order ID: " . $paypal_order_id);
        } else {
             // For other capture errors, update DB status and inform user.
             my_vin_update_report_meta($order->report_id, ['payment_status' => 'failed']);
             wp_send_json_error( array( 'message' => __('Could not capture PayPal payment: ', 'my-vin-verifier') . $capture_response->get_error_message() ) );
        }
    }

    // --- PAYMENT CAPTURE CONFIRMED (or was already captured) ---
    // Proceed with fulfillment attempt (function handles idempotency)

    // Update payment status in DB first
    // Get the actual capture ID from the response if available (useful for refunds)
    $paypal_capture_id = null;
    if (!is_wp_error($capture_response) && isset($capture_response['purchase_units'][0]['payments']['captures'][0]['id'])) {
        $paypal_capture_id = $capture_response['purchase_units'][0]['payments']['captures'][0]['id'];
    }
    my_vin_update_report_meta($order->report_id, [
        'payment_status' => 'completed',
        // Optionally store capture ID if different from order ID and needed for refunds
        // 'payment_transaction_id' => $paypal_capture_id ?? $paypal_order_id
    ]);


    // Trigger Fulfillment (VinAudit Calls, PDF Generation)
    $fulfillment_result = my_vin_fulfill_order( $order->report_id ); // This function MUST be idempotent

    if ( is_wp_error( $fulfillment_result ) ) {
        // Fulfillment failed AFTER payment capture! Critical error.
        error_log("[VIN Plugin] CRITICAL: Fulfillment failed after payment capture for Order ID: {$order->report_id}, PayPal Order: {$paypal_order_id}. Error: " . $fulfillment_result->get_error_message());

        // --- TODO: Initiate REFUND process via PayPal API ---
        // $refund_result = my_vin_paypal_issue_refund( $paypal_capture_id ?? $paypal_order_id ); // Need capture ID ideally
        // if (is_wp_error($refund_result)) { error_log("[VIN Plugin] CRITICAL: Refund attempt failed after fulfillment error. Capture ID: " . ($paypal_capture_id ?? 'N/A')); }
        // else { my_vin_update_report_meta($order->report_id, ['payment_status' => 'refunded', 'report_status' => 'fulfillment_failed']); }

        wp_send_json_error( array( 'message' => __('Payment captured, but report generation failed: ', 'my-vin-verifier') . $fulfillment_result->get_error_message() . __(' Please contact support.', 'my-vin-verifier') ) );
    } else {
        // Fulfillment Success! fulfillment_result contains the download URL.
        wp_send_json_success( array(
            'message' => __('Payment successful, report generated.', 'my-vin-verifier'),
            'downloadUrl' => $fulfillment_result
        ) );
    }
}


/**
 * Retrieves a previously generated report via AJAX.
 */
function my_vin_ajax_retrieve_report() {
    check_ajax_referer( MY_VIN_AJAX_NONCE_ACTION, 'nonce' );

    $vin   = isset( $_POST['vin'] ) ? sanitize_text_field( strtoupper( $_POST['vin'] ) ) : '';
    $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';

    if ( empty( $vin ) || ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => __('Invalid VIN or email address provided.', 'my-vin-verifier') ) );
    }

    // Query the database for a matching, completed report
    $report_meta = my_vin_get_previous_report_meta( $vin, $email ); // from database.php

    if ( $report_meta && ! empty( $report_meta->report_file_path ) ) {
        // Generate a download URL for the stored file path
        $download_url = my_vin_get_report_download_url( $report_meta->report_file_path ); // from utils.php

        if ( $download_url ) {
            wp_send_json_success( array( 'downloadUrl' => $download_url ) );
        } else {
             // Log error: File missing or URL generation failed.
             error_log("[VIN Plugin] Retrieval Error: File path found in DB but file missing or URL generation failed for report_id: {$report_meta->report_id}, Path: {$report_meta->report_file_path}");
             wp_send_json_error( array( 'message' => __('Report record found, but the file is currently unavailable. Please contact support.', 'my-vin-verifier') ) );
        }

    } else {
        wp_send_json_error( array( 'message' => __('No previously generated report found for this VIN and email.', 'my-vin-verifier') ) );
    }
}


// --- Fulfillment Helper ---

/**
 * Fulfills an order after successful payment capture confirmation (either via AJAX or Webhook).
 * Calls VinAudit APIs, generates PDF, updates DB. Designed to be IDEMPOTENT.
 *
 * @param int $internal_order_id The ID from the wp_vin_reports table.
 * @return string|WP_Error Download URL on success, WP_Error on failure.
 */
function my_vin_fulfill_order( $internal_order_id ) {
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();
    $internal_order_id = absint($internal_order_id);

    // 1. Get fresh order details from DB
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE report_id = %d", $internal_order_id ) );

    if ( ! $order ) {
        return new WP_Error('order_not_found', __('Internal order not found for fulfillment.', 'my-vin-verifier'));
    }

    // --- Idempotency Check ---
    // If report is already generated and file path exists, return existing URL
    if ( $order->report_status === 'generated' && ! empty( $order->report_file_path ) ) {
        $existing_url = my_vin_get_report_download_url( $order->report_file_path );
        if ($existing_url) {
             error_log("[VIN Plugin] Info: Fulfillment attempted on already generated order ID: {$internal_order_id}. Returning existing URL.");
             return $existing_url;
        } else {
             // File path exists in DB but file missing? Log error, maybe try regeneration?
             error_log("[VIN Plugin] Warning: Order {$internal_order_id} marked generated but file missing at path: {$order->report_file_path}. Attempting regeneration.");
             // Allow proceeding to regenerate below.
        }
    }
    // Prevent re-processing if already failed permanently, unless manual reset occurs.
    if ( $order->report_status === 'failed' ) { // Use 'failed', not 'fulfillment_failed' if possible
         return new WP_Error('fulfillment_already_failed', __('Report generation previously failed for this order.', 'my-vin-verifier'));
    }
    // Prevent processing if payment wasn't completed (e.g., webhook failed before capture completed)
    if ( $order->payment_status !== 'completed' ) {
         error_log("[VIN Plugin] Fulfillment Error: Attempted to fulfill order {$internal_order_id} but payment status is {$order->payment_status}.");
         return new WP_Error('payment_not_completed', __('Payment not completed for this order.', 'my-vin-verifier'));
    }

     // Mark as processing to prevent race conditions (e.g., AJAX + Webhook hitting simultaneously)
     $updated = my_vin_update_report_meta($internal_order_id, ['report_status' => 'processing']);
     if (!$updated) {
         error_log("[VIN Plugin] Fulfillment Error: Failed to update order {$internal_order_id} status to 'processing'. Aborting.");
         return new WP_Error('db_update_failed', __('Could not lock order for processing.', 'my-vin-verifier'));
     }


    // 2. Gather required API data based on plan
    $vin = $order->vin;
    $plan = $order->plan_type;
    $combined_api_data = ['vin' => $vin, 'generation_date' => $order->purchase_timestamp];
    $vehicle_type = 'car'; // Default type

    // Call Specs API
    $specs_response = my_vin_get_specifications( $vin );
    if ( ! is_wp_error( $specs_response ) && isset( $specs_response['attributes'] ) ) {
        $combined_api_data['specs'] = $specs_response['attributes'];
        $combined_api_data['specs_warranties'] = isset($specs_response['warranties']) ? $specs_response['warranties'] : [];
        $vehicle_type = isset($specs_response['attributes']['type']) ? strtolower($specs_response['attributes']['type']) : 'car';
    } else {
         error_log("[VIN Plugin] Fulfillment Warning: Specs API failed for order {$internal_order_id}, VIN {$vin}. Proceeding without full specs.");
         // Decide if this is a fatal error or if we can proceed without specs
    }

    // Call History API
    $history_response = my_vin_get_history_report( $vin, $internal_order_id ); // Pass internal ID
     if ( is_wp_error( $history_response ) ) {
         my_vin_update_report_meta($internal_order_id, ['report_status' => 'failed']);
         return new WP_Error('history_api_failed', __('Failed to retrieve vehicle history data: ', 'my-vin-verifier') . $history_response->get_error_message());
     }
     $combined_api_data['history'] = $history_response; // Pass the whole history part


     // Call Market Value API
     $value_response = my_vin_get_market_value( $vin );
      if ( ! is_wp_error( $value_response ) && isset( $value_response['prices'] ) ) {
          $combined_api_data['value'] = $value_response['prices'];
      } else {
          error_log("[VIN Plugin] Fulfillment Warning: Market Value API failed for order {$internal_order_id}, VIN {$vin}. Proceeding without value.");
      }

    // Call Image API (if plan includes images)
    $allowed_features = my_vin_get_plan_features($plan, $vehicle_type);
    $needs_images = (in_array('images', $allowed_features)); // Simplified check using internal key 'images'

     if ($needs_images) {
         $image_response = my_vin_get_images( $vin );
         if ( ! is_wp_error( $image_response ) && !empty( $image_response['images'] ) ) {
             $combined_api_data['images'] = $image_response['images']; // Use processed array from api function
         } else {
             error_log("[VIN Plugin] Fulfillment Warning: Image API failed or returned no images for order {$internal_order_id}, VIN {$vin}. Proceeding without images.");
             $combined_api_data['images'] = []; // Ensure key exists but is empty
         }
     }


    // 3. Filter data based on the actual plan
    $filtered_data = my_vin_filter_data_for_plan( $combined_api_data, $plan, $vehicle_type );


    // 4. Generate PDF
    $pdf_result = my_vin_generate_pdf_report( $filtered_data ); // Pass filtered data directly

    if ( is_wp_error( $pdf_result ) ) {
        error_log("[VIN Plugin] CRITICAL: PDF Generation failed for order {$internal_order_id}. Error: " . $pdf_result->get_error_message());
        my_vin_update_report_meta($internal_order_id, ['report_status' => 'failed']);

        // --- Trigger REFUND ---
        // Need the PayPal Capture ID, which should be stored in payment_transaction_id after successful capture
        $paypal_capture_id = $order->payment_transaction_id; // Assuming capture ID was stored here
        if ($paypal_capture_id) {
             error_log("[VIN Plugin] Attempting refund for failed fulfillment. Order ID: {$internal_order_id}, PayPal Capture ID: {$paypal_capture_id}");
             $refund_result = my_vin_paypal_issue_refund( $paypal_capture_id, null, 'USD', 'Failed to generate report after payment.' );
             if (is_wp_error($refund_result)) {
                 error_log("[VIN Plugin] CRITICAL: Refund attempt failed after fulfillment error. PayPal Capture ID: {$paypal_capture_id}. Error: " . $refund_result->get_error_message());
                 // Notify admin!
             } else {
                 error_log("[VIN Plugin] Refund successful for Order ID: {$internal_order_id}. PayPal Refund ID: " . ($refund_result['id'] ?? 'N/A'));
                 my_vin_update_report_meta($internal_order_id, ['payment_status' => 'refunded']);
             }
        } else {
             error_log("[VIN Plugin] CRITICAL: Cannot refund failed fulfillment for order {$internal_order_id} because PayPal Capture ID was not found in DB.");
             // Notify admin!
        }
        return new WP_Error('pdf_generation_failed', __('Failed to generate PDF report: ', 'my-vin-verifier') . $pdf_result->get_error_message());
    }

    // 5. Update Database with file path and status
    $full_file_path = $pdf_result; // generate_pdf returns the full server path
    // Convert full path to relative path for storage (relative to uploads base dir)
    $upload_dir = wp_upload_dir();
    $relative_file_path = str_replace( trailingslashit($upload_dir['basedir']), '', $full_file_path );

    $update_data = array(
        'report_status' => 'generated',
        'report_file_path' => $relative_file_path // Store relative path
    );
    $updated = my_vin_update_report_meta( $internal_order_id, $update_data );

    if ( ! $updated ) {
         error_log("[VIN Plugin] CRITICAL: Failed to update DB after PDF generation for order {$internal_order_id}. PDF saved at: {$full_file_path}");
         // PDF is generated but user might not be able to retrieve it easily. Maybe try deleting PDF? Or notify admin.
         return new WP_Error('db_update_failed', __('Failed to update report status after PDF generation.', 'my-vin-verifier'));
    }

    // 6. Return download URL
    $download_url = my_vin_get_report_download_url( $relative_file_path ); // Use relative path
    if (!$download_url) {
        error_log("[VIN Plugin] Error: Could not generate download URL for generated report. Order ID: {$internal_order_id}, Path: {$relative_file_path}");
        return new WP_Error('url_generation_failed', __('Report generated, but could not create download link.', 'my-vin-verifier'));
    }

    // --- Optional: Send Email Notification ---
    // wp_mail( $order->user_email, 'Your VIN Report is Ready', 'You can download your report here: ' . $download_url );

    error_log("[VIN Plugin] Order {$internal_order_id} fulfilled successfully. Report: {$relative_file_path}");
    return $download_url;
}


?>
