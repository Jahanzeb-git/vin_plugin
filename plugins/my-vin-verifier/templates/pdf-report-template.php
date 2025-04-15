<?php
/**
 * Enhanced Basic HTML Template for PDF Report Generation.
 * Receives $report_data variable containing FILTERED API data based on plan.
 * Requires significant CSS styling for a professional look.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Extract data for easier access and escaping
$vin = isset($report_data['vin']) ? esc_html($report_data['vin']) : 'N/A';
$plan = isset($report_data['plan']) ? esc_html($report_data['plan']) : 'N/A';
$gen_date = isset($report_data['generation_date']) ? date('F j, Y, g:i a T', strtotime($report_data['generation_date'])) : 'N/A';
$specs = isset($report_data['specs']) ? $report_data['specs'] : [];
$history = isset($report_data['history']) ? $report_data['history'] : [];
$value = isset($report_data['value']) ? $report_data['value'] : [];
$images = isset($report_data['images']) ? $report_data['images'] : []; // Array of image URL objects: [['url'=>'...'], ['url'=>'...']]
$warranties = isset($report_data['specs_warranties']) ? $report_data['specs_warranties'] : [];

// Helper function to display history sections nicely
function display_history_section($title, $items) {
    if (isset($items) && !empty($items)) {
        echo '<div class="section">';
        echo '<h2 class="section-title">' . esc_html($title) . '</h2>';
        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    echo '<table class="history-table">';
                    // Add caption only if there are multiple records of the same type
                    if (count($items) > 1) {
                        echo '<caption>Record #' . ($index + 1) . '</caption>';
                    }
                    echo '<tbody>'; // Add tbody
                    foreach ($item as $key => $val) {
                        // Display only non-empty, non-array values for simplicity
                        if ($val && !is_array($val)) {
                            echo '<tr>';
                            echo '<th>' . esc_html(ucwords(str_replace(['_', '-'], ' ', $key))) . '</th>';
                            // Format date if key suggests it
                            if (stripos($key, 'date') !== false && ($timestamp = strtotime($val)) !== false) {
                                echo '<td>' . esc_html(date('M j, Y', $timestamp)) . '</td>';
                            } else {
                                echo '<td>' . esc_html($val) . '</td>';
                            }
                            echo '</tr>';
                        }
                        // Add handling for nested arrays here if needed (e.g., multiple damages in salvage)
                    }
                     echo '</tbody>';
                    echo '</table>';
                }
            }
        } else {
            // This case should ideally not happen if $items is checked for !empty() first
            echo '<p>No ' . esc_html(strtolower($title)) . ' records available for this plan.</p>';
        }
        echo '</div>';
    } else {
         // Optionally show a section indicating data is not included or not found
         echo '<div class="section">';
         echo '<h2 class="section-title">' . esc_html($title) . '</h2>';
         echo '<p>No ' . esc_html(strtolower($title)) . ' records found or included in this report plan.</p>';
         echo '</div>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VIN Report - <?php echo $vin; ?></title>
    <style>
        /* --- Basic PDF Styling (Enhance Significantly!) --- */
        @page { margin: 25mm 20mm 25mm 20mm; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10pt; color: #333; line-height: 1.4; }
        h1, h2, h3, h4 { font-family: 'Helvetica', 'Arial', sans-serif; color: #003366; margin:0 0 0.5em 0; padding:0; font-weight: bold; page-break-after: avoid; }
        h1 { font-size: 20pt; text-align: center; margin-bottom: 15mm; color: #0057ff; }
        h2.section-title { font-size: 14pt; border-bottom: 2px solid #0057ff; padding-bottom: 3px; margin-top: 10mm; color: #003366; page-break-before: auto; page-break-after: avoid; }
        h3 { font-size: 12pt; color: #111827; margin-top: 8mm; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; page-break-inside: auto; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; vertical-align: top; font-size: 9pt; }
        th { background-color: #f0f5ff; font-weight: bold; width: 35%; color: #003366; }
        td { width: 65%; }
        table.history-table { margin-bottom: 10mm; } /* More space after history tables */
        caption { caption-side: top; font-weight: bold; margin-bottom: 5px; text-align: left; font-size: 10pt; color: #333; page-break-after: avoid; }

        .header, .footer { position: fixed; left: 0mm; right: 0mm; color: #777; font-size: 8pt; }
        .header { top: -18mm; height: 15mm; } /* Adjust position/height */
        .footer { bottom: -18mm; height: 15mm; text-align: center; }
        .page-number:after { content: counter(page); } /* Use :after for page number */

        .logo { text-align: left; float: left; max-height: 12mm; margin-right: 5mm; } /* Use mm */
        .logo img { max-height: 12mm; width: auto; }
        .report-info { text-align: right; float: right; font-size: 9pt; line-height: 1.3; }
        .clearfix::after { content: ""; clear: both; display: table; }

        .section { margin-bottom: 8mm; page-break-inside: avoid; }
        .disclaimer { font-size: 8pt; color: #666; margin-top: 15mm; border-top: 1px solid #ccc; padding-top: 5mm; }
        .vehicle-images { text-align: center; } /* Center images */
        .vehicle-images img {
            max-width: 48%; /* Allow two images side-by-side */
            height: auto;
            margin: 1%;
            border: 1px solid #ccc;
            padding: 2px;
            vertical-align: middle;
            page-break-inside: avoid;
        }
        ul { padding-left: 20px; }
        li { margin-bottom: 3px; }
        p { margin-top: 0; margin-bottom: 0.8em; }

    </style>
</head>
<body>

    <div class="header">
        <div class="logo">
             <span style="font-size: 14pt; font-weight: bold; color: #0057ff;">YourLogo</span> </div>
        <div class="report-info">
            VIN Report: <?php echo $vin; ?><br/>
            Report Plan: <?php echo ucfirst($plan); ?><br/>
            Generated: <?php echo $gen_date; ?>
        </div>
        <div class="clearfix"></div>
        <hr style="margin-top: 2mm; border: 0; border-top: 1px solid #ccc;">
    </div>

    <div class="footer">
        <?php echo esc_html( get_bloginfo( 'name' ) ); ?> &copy; <?php echo date('Y'); ?> | Confidential Report for VIN: <?php echo $vin; ?> | Page <span class="page-number"></span>
    </div>

    <main>
        <h1>Vehicle History Report</h1>

        <?php if (!empty($images)): ?>
        <div class="section vehicle-images">
            <h2 class="section-title">Vehicle Images</h2>
            <?php foreach ($images as $img_data): ?>
                <?php if (isset($img_data['url'])): ?>
                    <img src="<?php echo esc_url($img_data['url']); ?>" alt="Vehicle Image for <?php echo $vin; ?>">
                <?php endif; ?>
            <?php endforeach; ?>
             <div class="clearfix"></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($specs)): ?>
        <div class="section">
            <h2 class="section-title">Vehicle Specifications</h2>
            <table>
                <tbody>
                <?php
                // Define desired order/labels for specs
                $spec_order = ['year', 'make', 'model', 'trim', 'style', 'type', 'size', 'category', 'made_in', 'engine', 'engine_size', 'engine_cylinders', 'transmission', 'drivetrain', 'fuel_type', 'fuel_capacity', 'city_mileage', 'highway_mileage', 'doors', 'standard_seating', 'anti_brake_system', 'steering_type', 'curb_weight', 'overall_height', 'overall_length', 'overall_width', 'wheelbase_length'];
                foreach ($spec_order as $key):
                    if (isset($specs[$key]) && $specs[$key]): ?>
                    <tr>
                        <th><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></th>
                        <td><?php echo esc_html($specs[$key]); ?></td>
                    </tr>
                <?php endif; endforeach; ?>
                 <?php // Add any remaining specs not in the defined order
                 foreach ($specs as $key => $val): if ($val && !in_array($key, $spec_order)): ?>
                     <tr>
                         <th><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></th>
                         <td><?php echo esc_html($val); ?></td>
                     </tr>
                 <?php endif; endforeach; ?>
                 </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($warranties)): ?>
        <div class="section">
            <h2 class="section-title">Warranty Information</h2>
             <table>
                 <thead><tr><th>Type</th><th>Duration (Months)</th><th>Duration (Miles)</th></tr></thead>
                 <tbody>
                 <?php foreach ($warranties as $warranty): ?>
                     <tr>
                         <td><?php echo isset($warranty['type']) ? esc_html($warranty['type']) : 'N/A'; ?></td>
                         <td><?php echo isset($warranty['months']) ? esc_html($warranty['months']) : 'N/A'; ?></td>
                         <td><?php echo isset($warranty['miles']) ? esc_html($warranty['miles']) : 'N/A'; ?></td>
                     </tr>
                 <?php endforeach; ?>
                 </tbody>
             </table>
        </div>
        <?php endif; ?>


         <?php if (!empty($value)): ?>
        <div class="section">
            <h2 class="section-title">Market Value Estimate</h2>
            <table>
                <tbody>
                <?php foreach ($value as $key => $val): ?>
                <tr>
                    <th><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?> Value</th>
                    <td>$<?php echo esc_html(number_format(floatval($val))); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
             <p style="font-size: 9pt; color: #666;">Market value based on recent transactions. Actual value may vary.</p>
        </div>
        <?php endif; ?>


        <?php
            display_history_section('Title Records', $history['titles'] ?? null);
            display_history_section('Title Brands / Checks', $history['checks'] ?? null);
            display_history_section('Accident Records', $history['accidents'] ?? null);
            display_history_section('Theft Records', $history['thefts'] ?? null);
            // Combine Salvage & JSI under one heading if either exists
            $salvage_jsi = array_merge($history['salvage'] ?? [], $history['jsi'] ?? []);
            display_history_section('Salvage / Junk Records', $salvage_jsi);
            display_history_section('Sales Listings', $history['sale'] ?? null);
            // Add calls for Exports, Impounds, Recalls etc. if data keys are known
            // display_history_section('Export Records', $history['exports'] ?? null);
            // display_history_section('Impound Records', $history['impounds'] ?? null);
            // display_history_section('Open Recalls', $history['recalls'] ?? null);
        ?>

        <div class="disclaimer">
            <strong>Disclaimer:</strong> This report is generated based on data provided by VinAudit and other sources. While we strive for accuracy, <?php echo esc_html( get_bloginfo( 'name' ) ); ?> cannot guarantee the completeness or absolute accuracy of all records. Information is subject to change and may contain errors or omissions. This report should be used as one tool among others when evaluating a vehicle. VIN: <?php echo $vin; ?>.
        </div>

    </main>

</body>
</html>
