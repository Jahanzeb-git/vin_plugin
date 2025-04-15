<?php
/**
 * Database functions for My VIN Verifier Plugin.
 * Handles creation and interaction with the custom reports table.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the full name for the custom reports table, including the WP prefix.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @return string The prefixed table name.
 */
function my_vin_get_reports_table_name() {
    global $wpdb;
    // Use sanitize_key on the constant just in case, though it's defined internally.
    return $wpdb->prefix . sanitize_key( MY_VIN_REPORTS_TABLE );
}

/**
 * Creates or updates the custom database table on plugin activation using dbDelta.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function my_vin_create_reports_table() {
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // SQL schema for the table
    $sql = "CREATE TABLE $table_name (
        report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        user_email VARCHAR(255) NOT NULL,
        vin VARCHAR(17) NOT NULL,
        plan_type VARCHAR(50) NOT NULL,
        purchase_timestamp DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
        payment_status VARCHAR(50) DEFAULT 'pending' NOT NULL,
        payment_transaction_id VARCHAR(100) DEFAULT NULL, -- e.g., PayPal Order ID or Capture ID
        report_status VARCHAR(50) DEFAULT 'pending' NOT NULL, -- pending, processing, generated, failed
        report_file_path VARCHAR(1024) DEFAULT NULL, -- Store full server path for reliability
        vin_audit_report_id VARCHAR(100) DEFAULT NULL, -- Optional VinAudit internal ID if available/needed
        PRIMARY KEY  (report_id),
        INDEX idx_vin_email (vin(17), user_email(191)), -- Index with length limits for compatibility
        INDEX idx_user_id (user_id),
        INDEX idx_vin (vin(17)),
        INDEX idx_payment_transaction_id (payment_transaction_id(100)) -- Index for webhook lookup
    ) $charset_collate;";

    // Include upgrade functions and execute dbDelta
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Optional: Check for errors after dbDelta if needed
    // if (!empty($wpdb->last_error)) {
    //     error_log('dbDelta error for VIN reports table: ' . $wpdb->last_error);
    // }
}

/**
 * Saves report metadata to the database. Typically called when initiating payment.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @param array $data Associative array of data to insert. Keys should match column names.
 * @return int|false The ID of the newly inserted row (report_id), or false on failure.
 */
function my_vin_save_report_meta( $data ) {
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();

    // Ensure required fields are present
    if ( empty( $data['user_email'] ) || empty( $data['vin'] ) || empty( $data['plan_type'] ) ) {
        error_log('VIN Plugin Error: Missing required data for saving report meta.');
        return false;
    }

    // Prepare data for insertion with sanitization
    $insert_data = array(
        'user_id'                => isset( $data['user_id'] ) && $data['user_id'] > 0 ? absint( $data['user_id'] ) : null,
        'user_email'             => sanitize_email( $data['user_email'] ),
        'vin'                    => sanitize_text_field( strtoupper( $data['vin'] ) ),
        'plan_type'              => sanitize_key( $data['plan_type'] ),
        'purchase_timestamp'     => isset($data['purchase_timestamp']) ? $data['purchase_timestamp'] : current_time( 'mysql', 1 ), // Use GMT time
        'payment_status'         => isset( $data['payment_status'] ) ? sanitize_key( $data['payment_status'] ) : 'pending',
        'payment_transaction_id' => isset( $data['payment_transaction_id'] ) ? sanitize_text_field( $data['payment_transaction_id'] ) : null,
        'report_status'          => isset( $data['report_status'] ) ? sanitize_key( $data['report_status'] ) : 'pending',
        'report_file_path'       => isset( $data['report_file_path'] ) ? sanitize_text_field( $data['report_file_path'] ) : null,
        'vin_audit_report_id'    => isset( $data['vin_audit_report_id'] ) ? sanitize_text_field( $data['vin_audit_report_id'] ) : null,
    );

    // Define formats for $wpdb->insert (%d=integer, %s=string, %f=float)
    $formats = array(
        '%d', // user_id (allow NULL)
        '%s', // user_email
        '%s', // vin
        '%s', // plan_type
        '%s', // purchase_timestamp
        '%s', // payment_status
        '%s', // payment_transaction_id (allow NULL)
        '%s', // report_status
        '%s', // report_file_path (allow NULL)
        '%s', // vin_audit_report_id (allow NULL)
    );
    // Adjust format for user_id if it's NULL
    if ( is_null( $insert_data['user_id'] ) ) {
        $formats[0] = null; // Let wpdb handle NULL for integer column
    }


    $result = $wpdb->insert( $table_name, $insert_data, $formats );

    if ( $result ) {
        return $wpdb->insert_id; // Return the new report_id
    } else {
        error_log('VIN Plugin DB Error: Failed to insert report meta. Error: ' . $wpdb->last_error . ' Data: ' . print_r($insert_data, true));
        return false;
    }
}

/**
 * Retrieves completed report metadata for a given VIN and email.
 * Used for the "Retrieve Previous Report" feature.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @param string $vin The VIN to search for.
 * @param string $email The user's email to search for.
 * @return object|null The report data object (row from DB), or null if not found or not generated.
 */
function my_vin_get_previous_report_meta( $vin, $email ) {
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();

    $vin = sanitize_text_field( strtoupper( $vin ) );
    $email = sanitize_email( $email );

    if ( empty( $vin ) || empty( $email ) ) {
        return null;
    }

    // Query for a report that was successfully generated and paid for
    $sql = $wpdb->prepare(
        "SELECT * FROM $table_name
         WHERE vin = %s
           AND user_email = %s
           AND report_status = %s
           AND payment_status = %s
         ORDER BY purchase_timestamp DESC
         LIMIT 1",
        $vin,
        $email,
        'generated', // Must be generated
        'completed'  // Must be paid for
    );

    $report = $wpdb->get_row( $sql ); // Get a single row object

    return $report; // Returns null if no matching row found
}

/**
 * Updates the status, file path, or payment details of a report.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @param int $report_id The ID of the report record to update.
 * @param array $data Associative array of data to update (e.g., ['report_status' => 'generated', 'report_file_path' => '...']).
 * @return bool True on success, false on failure.
 */
function my_vin_update_report_meta( $report_id, $data ) {
    global $wpdb;
    $table_name = my_vin_get_reports_table_name();

    $report_id = absint( $report_id );
    if ( ! $report_id || empty( $data ) || ! is_array($data) ) {
        return false;
    }

    // Define allowed fields to update and their formats
    $allowed_fields = array(
        'payment_status'         => '%s',
        'payment_transaction_id' => '%s', // Store PayPal Order ID or Capture ID here
        'report_status'          => '%s',
        'report_file_path'       => '%s',
    );

    $update_data = array();
    $update_formats = array();

    // Build arrays for update based on allowed fields
    foreach ( $data as $key => $value ) {
        if ( array_key_exists( $key, $allowed_fields ) ) {
            // Sanitize based on expected type (simple example)
             $update_data[$key] = sanitize_text_field( $value ); // Adjust sanitization if needed (e.g., for paths)
             $update_formats[] = $allowed_fields[$key];
        }
    }

    if ( empty( $update_data ) ) {
        return false; // No valid fields to update
    }

    // Define the WHERE clause
    $where = array( 'report_id' => $report_id );
    $where_formats = array( '%d' );

    // Perform the update
    $updated = $wpdb->update( $table_name, $update_data, $where, $update_formats, $where_formats );

    // $wpdb->update returns number of rows updated or false on error.
    if ( $updated === false ) {
        error_log('VIN Plugin DB Error: Failed to update report meta for report_id ' . $report_id . '. Error: ' . $wpdb->last_error . ' Data: ' . print_r($update_data, true));
    }

    return $updated !== false;
}

?>
