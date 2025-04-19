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
    $capture_succeeded = false; // Flag to track if capture was successful or already done

    if ( is_wp_error( $capture_response ) ) {
        $error_code = $capture_response->get_error_code();
        error_log("[VIN Plugin] PayPal Capture Order API Error: " . $capture_response->get_error_message());

        // If already captured, treat as success for fulfillment check, but log it.
        if ($error_code === 'order_already_captured') {
             // Proceed to check/trigger fulfillment, as payment is secured.
             // Log this occurrence.
             error_log("[VIN Plugin] Info: Capture attempt on already captured PayPal Order ID: " . $paypal_order_id);
             $capture_succeeded = true; // Mark as succeeded for fulfillment logic
        } else {
             // For other capture errors, update DB status and inform user.
             my_vin_update_report_meta($order->report_id, ['payment_status' => 'failed']);
             wp_send_json_error( array( 'message' => __('Could not capture PayPal payment: ', 'my-vin-verifier') . $capture_response->get_error_message() ) );
             // Exit here, don't proceed to fulfillment if capture failed
             return;
        }
    } else {
        // Capture API call was successful (status 200 or 201)
        $capture_succeeded = true;
    }

    // --- PAYMENT CAPTURE CONFIRMED (or was already captured) ---
    if ($capture_succeeded) {
        // Update payment status in DB first
        // Get the actual capture ID from the response if available (useful for refunds)
        $paypal_capture_id = null;
        if (!is_wp_error($capture_response) && isset($capture_response['purchase_units'][0]['payments']['captures'][0]['id'])) {
            $paypal_capture_id = $capture_response['purchase_units'][0]['payments']['captures'][0]['id'];
        } elseif ($error_code === 'order_already_captured' && isset($capture_response->get_error_data()['details'])) {
            // If already captured, the capture ID might be in the error data (needs verification based on actual PayPal response)
             error_log("[VIN Plugin] Note: Order already captured. Capture ID might be in error details: " . print_r($capture_response->get_error_data(), true));
             // Attempt to find capture ID in DB if needed, or rely on webhook having stored it?
             // For now, use the Order ID as fallback if capture ID isn't directly available from this flow.
             $paypal_capture_id = $order->payment_transaction_id; // Might be order ID or previous capture ID
        } else {
             // Fallback if capture ID not found in successful response (unlikely but possible)
             $paypal_capture_id = $order->payment_transaction_id; // Use existing ID (might be order ID)
        }

        // Update DB: Mark payment as completed and store the best available transaction ID (Capture ID preferred)
        my_vin_update_report_meta($order->report_id, [
            'payment_status' => 'completed',
            'payment_transaction_id' => $paypal_capture_id // Store Capture ID if available, otherwise keeps Order ID
        ]);

        // --- Trigger Fulfillment (VinAudit Calls, PDF Generation) ---
        // Pass the updated order object or just the ID
        $fulfillment_result = my_vin_fulfill_order( $order->report_id ); // This function MUST be idempotent

        if ( is_wp_error( $fulfillment_result ) ) {
            // Fulfillment failed AFTER payment capture! Critical error. Refund already handled inside fulfill_order.
            // Send error message back to user.
            wp_send_json_error( array( 'message' => $fulfillment_result->get_error_message() ) );
        } else {
            // Fulfillment Success! fulfillment_result contains the download URL.
            wp_send_json_success( array(
                'message' => __('Payment successful, report generated.', 'my-vin-verifier'),
                'downloadUrl' => $fulfillment_result
            ) );
        }
    }
    // If capture didn't succeed (and wasn't already captured), the function would have exited earlier.
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
 * Includes refund logic on critical failure after payment.
 *
 * @param int $internal_order_id The ID from the wp_vin_reports table.
 * @return string|WP_Error Download URL on success, WP_Error containing user-friendly message on failure.
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
    // Prevent re-processing if already failed permanently or refunded.
    if ( in_array($order->report_status, ['failed', 'refunded'] ) ) {
         return new WP_Error('fulfillment_already_failed_or_refunded', __('Report generation previously failed or was refunded for this order.', 'my-vin-verifier'));
    }
    // Prevent processing if payment wasn't completed (e.g., webhook failed before capture completed)
    if ( $order->payment_status !== 'completed' ) {
         error_log("[VIN Plugin] Fulfillment Error: Attempted to fulfill order {$internal_order_id} but payment status is {$order->payment_status}.");
         return new WP_Error('payment_not_completed', __('Payment not completed for this order.', 'my-vin-verifier'));
    }
     // Prevent processing if already marked as processing by another request
     if ( $order->report_status === 'processing' ) {
         // Check timestamp? If processing for too long, maybe reset? For now, just prevent re-entry.
         error_log("[VIN Plugin] Fulfillment Info: Order {$internal_order_id} is already being processed. Skipping duplicate attempt.");
         return new WP_Error('fulfillment_in_progress', __('Report generation is already in progress for this order.', 'my-vin-verifier'));
     }

     // Mark as processing to prevent race conditions (e.g., AJAX + Webhook hitting simultaneously)
     $updated = my_vin_update_report_meta($internal_order_id, ['report_status' => 'processing']);
     if (!$updated) {
         error_log("[VIN Plugin] Fulfillment Error: Failed to update order {$internal_order_id} status to 'processing'. Aborting.");
         return new WP_Error('db_update_failed', __('Could not lock order for processing.', 'my-vin-verifier'));
     }

    // --- Start Fulfillment Process ---
    $vin = $order->vin;
    $plan = $order->plan_type;
    $combined_api_data = ['vin' => $vin, 'generation_date' => $order->purchase_timestamp];
    $vehicle_type = 'car'; // Default type
    $fulfillment_error = null; // Variable to hold any WP_Error encountered

    // 2. Gather required API data based on plan
    // Call Specs API
    $specs_response = my_vin_get_specifications( $vin );
    if ( ! is_wp_error( $specs_response ) && isset( $specs_response['attributes'] ) ) {
        $combined_api_data['specs'] = $specs_response['attributes'];
        $combined_api_data['specs_warranties'] = isset($specs_response['warranties']) ? $specs_response['warranties'] : [];
        $vehicle_type = isset($specs_response['attributes']['type']) ? strtolower($specs_response['attributes']['type']) : 'car';
    } else {
         error_log("[VIN Plugin] Fulfillment Warning: Specs API failed for order {$internal_order_id}, VIN {$vin}. Proceeding without full specs.");
         // Consider if this is fatal. For now, we proceed but log it.
         // If specs API is critical, set $fulfillment_error here:
         // $fulfillment_error = new WP_Error('specs_api_failed', __('Failed to retrieve essential vehicle specifications.', 'my-vin-verifier'), $specs_response);
    }

    // Call History API (Only proceed if no critical error yet)
    if (!$fulfillment_error) {
        $history_response = my_vin_get_history_report( $vin, $internal_order_id ); // Pass internal ID
        if ( is_wp_error( $history_response ) ) {
            $fulfillment_error = new WP_Error('history_api_failed', __('Failed to retrieve vehicle history data: ', 'my-vin-verifier') . $history_response->get_error_message(), $history_response);
        } else {
            $combined_api_data['history'] = $history_response; // Pass the whole history part
        }
    }

     // Call Market Value API (Only proceed if no critical error yet)
     if (!$fulfillment_error) {
         $value_response = my_vin_get_market_value( $vin );
         if ( ! is_wp_error( $value_response ) && isset( $value_response['prices'] ) ) {
             $combined_api_data['value'] = $value_response['prices'];
         } else {
             error_log("[VIN Plugin] Fulfillment Warning: Market Value API failed for order {$internal_order_id}, VIN {$vin}. Proceeding without value.");
             // Non-fatal, proceed without value data.
         }
     }

    // Call Image API (if plan includes images and no critical error yet)
    if (!$fulfillment_error) {
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
    }


    // --- Check if a critical API call failed ---
    if ($fulfillment_error) {
        error_log("[VIN Plugin] CRITICAL Fulfillment Error (API Stage) for order {$internal_order_id}: " . $fulfillment_error->get_error_message());
        // Update DB status to failed
        my_vin_update_report_meta($internal_order_id, ['report_status' => 'failed']);
        // --- Trigger REFUND ---
        my_vin_trigger_refund($order, "VinAudit API call failed: " . $fulfillment_error->get_error_message());
        // Return the specific API error to the user
        return $fulfillment_error;
    }


    // 3. Filter data based on the actual plan
    $filtered_data = my_vin_filter_data_for_plan( $combined_api_data, $plan, $vehicle_type );


    // 4. Generate PDF
    $pdf_result = my_vin_generate_pdf_report( $filtered_data ); // Pass filtered data directly

    if ( is_wp_error( $pdf_result ) ) {
        error_log("[VIN Plugin] CRITICAL Fulfillment Error (PDF Stage) for order {$internal_order_id}: " . $pdf_result->get_error_message());
        // Update DB status to failed
        my_vin_update_report_meta($internal_order_id, ['report_status' => 'failed']);
        // --- Trigger REFUND ---
        my_vin_trigger_refund($order, "PDF Generation failed: " . $pdf_result->get_error_message());
        // Return the PDF generation error to the user
        return new WP_Error('pdf_generation_failed', __('Failed to generate PDF report: ', 'my-vin-verifier') . $pdf_result->get_error_message());
    }

    // --- Fulfillment Successful ---

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
         // Don't trigger refund here as the report *was* generated.
         return new WP_Error('db_update_failed', __('Failed to update report status after PDF generation. Please contact support.', 'my-vin-verifier'));
    }

    // 6. Return download URL
    $download_url = my_vin_get_report_download_url( $relative_file_path ); // Use relative path
    if (!$download_url) {
        error_log("[VIN Plugin] Error: Could not generate download URL for generated report. Order ID: {$internal_order_id}, Path: {$relative_file_path}");
        // Report generated, DB updated, but URL failed. Don't refund.
        return new WP_Error('url_generation_failed', __('Report generated, but could not create download link. Please contact support.', 'my-vin-verifier'));
    }

    // --- Optional: Send Email Notification ---
    // wp_mail( $order->user_email, 'Your VIN Report is Ready', 'You can download your report here: ' . $download_url );

    error_log("[VIN Plugin] Order {$internal_order_id} fulfilled successfully. Report: {$relative_file_path}");
    return $download_url; // Return the download URL string on success
}


/**
 * Helper function to attempt a refund and log the result.
 * Called internally by my_vin_fulfill_order on failure.
 *
 * @param object $order The order object from the database.
 * @param string $reason The reason for the refund.
 */
function my_vin_trigger_refund( $order, $reason = 'Fulfillment failed' ) {
    // Check if order object and transaction ID are valid
    if ( ! is_object($order) || empty($order->payment_transaction_id) || empty($order->report_id) ) {
        error_log("[VIN Plugin] Refund Trigger Error: Invalid order data provided for refund attempt.");
        return;
    }

    // Prevent refunding if already refunded
    if ($order->payment_status === 'refunded') {
        error_log("[VIN Plugin] Refund Trigger Info: Order {$order->report_id} is already marked as refunded. Skipping refund attempt.");
        return;
    }

    $paypal_capture_id = $order->payment_transaction_id; // This should be the Capture ID after successful capture
    $internal_order_id = $order->report_id;

    error_log("[VIN Plugin] Attempting refund for failed fulfillment. Order ID: {$internal_order_id}, PayPal Capture/Transaction ID: {$paypal_capture_id}, Reason: {$reason}");

    // Call the refund function (assuming full refund)
    $refund_result = my_vin_paypal_issue_refund( $paypal_capture_id, null, 'USD', $reason );

    if (is_wp_error($refund_result)) {
        $error_code = $refund_result->get_error_code();
        // Check if it's already refunded
        if ($error_code === 'paypal_refund_already_done') {
             error_log("[VIN Plugin] Refund attempt failed for Order ID {$internal_order_id}: Already refunded. Updating DB status.");
             my_vin_update_report_meta($internal_order_id, ['payment_status' => 'refunded']);
        } else {
             // Log critical failure to refund
             error_log("[VIN Plugin] CRITICAL: Refund attempt failed for Order ID {$internal_order_id}. Capture ID: {$paypal_capture_id}. Error Code: {$error_code}, Message: " . $refund_result->get_error_message());
             // --- TODO: Notify site admin urgently! ---
        }
    } else {
        // Refund request accepted by PayPal (status might be PENDING or COMPLETED)
        $refund_status = isset($refund_result['status']) ? $refund_result['status'] : 'UNKNOWN';
        error_log("[VIN Plugin] Refund request successful for Order ID: {$internal_order_id}. PayPal Refund ID: " . ($refund_result['id'] ?? 'N/A') . ", Status: {$refund_status}");
        // Update internal status to 'refunded' regardless of PayPal's immediate status (PENDING/COMPLETED)
        // as the refund process has been successfully initiated.
        my_vin_update_report_meta($internal_order_id, ['payment_status' => 'refunded']);
    }
}

?>

