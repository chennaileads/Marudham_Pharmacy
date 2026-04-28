<?php
header('Content-Type: application/json');

// ==================== CONFIGURATION ====================
$whatsapp_number = "919629276131"; // Without + sign
$upload_dir = "uploads/";
$log_file = "logs/prescription_requests.log"; // Log file path
$log_dir = "logs/";
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$max_file_size = 5 * 1024 * 1024; // 5MB

// ==================== AUTO-CREATE DIRECTORIES ====================
// Create uploads directory if it doesn't exist
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create uploads directory. Please check permissions.'
        ]);
        exit;
    }
    // Create index.html to prevent directory listing
    file_put_contents($upload_dir . 'index.html', '');
}

// Create logs directory if it doesn't exist
if (!file_exists($log_dir)) {
    if (!mkdir($log_dir, 0755, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create logs directory. Please check permissions.'
        ]);
        exit;
    }
    // Create index.html to prevent directory listing
    file_put_contents($log_dir . 'index.html', '');
}

// ==================== FUNCTION TO LOG DATA ====================
function logPrescriptionRequest($data, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "========================================\n";
    $log_entry .= "TIMESTAMP: $timestamp\n";
    $log_entry .= "REQUEST ID: " . $data['request_id'] . "\n";
    $log_entry .= "----------------------------------------\n";
    $log_entry .= "PATIENT DETAILS:\n";
    $log_entry .= "  Name: " . $data['patient_name'] . "\n";
    $log_entry .= "  Mobile: " . $data['mobile'] . "\n";
    $log_entry .= "  Email: " . ($data['email'] ?: 'Not provided') . "\n";
    $log_entry .= "----------------------------------------\n";
    $log_entry .= "MEDICAL DETAILS:\n";
    $log_entry .= "  Medicine: " . ($data['medicine'] ?: 'Not specified') . "\n";
    $log_entry .= "  Subject: " . ($data['subject'] ?: 'Not specified') . "\n";
    $log_entry .= "  Address: " . ($data['address'] ?: 'Not provided') . "\n";
    $log_entry .= "----------------------------------------\n";
    $log_entry .= "FILE DETAILS:\n";
    $log_entry .= "  Original Name: " . $data['original_filename'] . "\n";
    $log_entry .= "  Saved Name: " . $data['saved_filename'] . "\n";
    $log_entry .= "  File Size: " . $data['file_size'] . " bytes (" . round($data['file_size']/1024, 2) . " KB)\n";
    $log_entry .= "  File Type: " . $data['file_type'] . "\n";
    $log_entry .= "  File URL: " . $data['file_url'] . "\n";
    $log_entry .= "  Server Path: " . $data['server_path'] . "\n";
    $log_entry .= "----------------------------------------\n";
    $log_entry .= "STATUS: " . $data['status'] . "\n";
    $log_entry .= "WHATSAPP NUMBER: " . $data['whatsapp_number'] . "\n";
    $log_entry .= "IP ADDRESS: " . $data['ip_address'] . "\n";
    $log_entry .= "USER AGENT: " . $data['user_agent'] . "\n";
    $log_entry .= "========================================\n\n";
    
    // Append to log file
    return file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ==================== FUNCTION TO CREATE CSV LOG ====================
function logToCSV($data, $csv_file) {
    $csv_dir = dirname($csv_file);
    if (!file_exists($csv_dir)) {
        mkdir($csv_dir, 0755, true);
    }
    
    // Create CSV with headers if file doesn't exist
    if (!file_exists($csv_file)) {
        $headers = [
            'Timestamp', 'Request ID', 'Patient Name', 'Mobile', 'Email', 
            'Medicine', 'Subject', 'Address', 'Original Filename', 'Saved Filename',
            'File Size (KB)', 'File Type', 'File URL', 'Status', 'WhatsApp Number', 'IP Address'
        ];
        $fp = fopen($csv_file, 'w');
        fputcsv($fp, $headers);
        fclose($fp);
    }
    
    // Prepare data row
    $row = [
        date('Y-m-d H:i:s'),
        $data['request_id'],
        $data['patient_name'],
        $data['mobile'],
        $data['email'] ?: 'N/A',
        $data['medicine'] ?: 'N/A',
        $data['subject'] ?: 'N/A',
        $data['address'] ?: 'N/A',
        $data['original_filename'],
        $data['saved_filename'],
        round($data['file_size']/1024, 2),
        $data['file_type'],
        $data['file_url'],
        $data['status'],
        $data['whatsapp_number'],
        $data['ip_address']
    ];
    
    $fp = fopen($csv_file, 'a');
    fputcsv($fp, $row);
    fclose($fp);
}

// ==================== MAIN PROCESS ====================
try {
    // Check if file was uploaded
    if (!isset($_FILES['prescription']) || $_FILES['prescription']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Please select a prescription file to upload.");
    }

    $file = $_FILES['prescription'];

    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception("Invalid file type. Please upload an image (JPG, PNG, GIF, WebP) or PDF file.");
    }

    // Validate file size
    if ($file['size'] > $max_file_size) {
        throw new Exception("File is too large. Maximum size is 5MB.");
    }

    // Generate unique request ID
    $request_id = 'REQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $unique_filename = time() . '_' . $request_id . '.' . $file_extension;
    $upload_path = $upload_dir . $unique_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception("Failed to upload file. Please try again.");
    }

    // Get form data
    $patient_name = $_POST['patient_name'] ?? '';
    $mobile = $_POST['mobile'] ?? '';
    $email = $_POST['email'] ?? '';
    $medicine = $_POST['medicine'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $address = $_POST['address'] ?? '';

    // Create public URL for the file
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $file_url = $protocol . $domain . '/' . $upload_path;

    // Prepare data for logging
    $log_data = [
        'request_id' => $request_id,
        'patient_name' => $patient_name,
        'mobile' => $mobile,
        'email' => $email,
        'medicine' => $medicine,
        'subject' => $subject,
        'address' => $address,
        'original_filename' => $file['name'],
        'saved_filename' => $unique_filename,
        'file_size' => $file['size'],
        'file_type' => $file['type'],
        'file_url' => $file_url,
        'server_path' => $upload_path,
        'status' => 'SUCCESS',
        'whatsapp_number' => $whatsapp_number,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ];

    // ==================== LOGGING ====================
    // 1. Log to text file (detailed)
    logPrescriptionRequest($log_data, $log_file);
    
    // 2. Log to CSV file (for Excel/spreadsheet)
    $csv_file = $log_dir . 'prescription_requests.csv';
    logToCSV($log_data, $csv_file);

    // 3. Optional: Create individual log file for each request
    $individual_log = $log_dir . 'requests/' . $request_id . '.json';
    $individual_log_dir = $log_dir . 'requests/';
    if (!file_exists($individual_log_dir)) {
        mkdir($individual_log_dir, 0755, true);
    }
    file_put_contents($individual_log, json_encode($log_data, JSON_PRETTY_PRINT));

    // ==================== BUILD WHATSAPP MESSAGE ====================
    $whatsapp_message = "🆕 *New Prescription Request*\n\n";
    $whatsapp_message .= "🔖 *Request ID:* $request_id\n";
    $whatsapp_message .= "📅 *Date:* " . date('d-m-Y H:i') . "\n";
    $whatsapp_message .= "👤 *Patient:* $patient_name\n";
    $whatsapp_message .= "📱 *Phone:* $mobile\n";
    
    if (!empty($email)) {
        $whatsapp_message .= "📧 *Email:* $email\n";
    }
    
    if (!empty($medicine)) {
        $whatsapp_message .= "💊 *Medicine:* $medicine\n";
    }
    
    if (!empty($subject)) {
        $whatsapp_message .= "📝 *Details:* $subject\n";
    }
    
    if (!empty($address)) {
        $whatsapp_message .= "📍 *Address:* $address\n";
    }
    
    $whatsapp_message .= "\n📎 *Prescription File:*\n$file_url";
    $whatsapp_message .= "\n\n_File uploaded successfully at " . date('h:i A') . "_";
    $whatsapp_message .= "\n_ID: $request_id_";

    // Create WhatsApp URL
    $whatsapp_url = "https://wa.me/$whatsapp_number?text=" . urlencode($whatsapp_message);

    // ==================== RETURN SUCCESS RESPONSE ====================
    echo json_encode([
        'success' => true,
        'message' => 'Prescription uploaded successfully!',
        'request_id' => $request_id,
        'file_url' => $file_url,
        'whatsapp_url' => $whatsapp_url,
        'filename' => $unique_filename,
        'logged_to' => [
            'text_log' => $log_file,
            'csv_log' => $csv_file,
            'individual_log' => $individual_log
        ]
    ]);

} catch (Exception $e) {
    // Log the error
    $error_data = [
        'request_id' => 'FAILED-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
        'patient_name' => $_POST['patient_name'] ?? 'Unknown',
        'mobile' => $_POST['mobile'] ?? 'Unknown',
        'email' => $_POST['email'] ?? '',
        'medicine' => $_POST['medicine'] ?? '',
        'subject' => $_POST['subject'] ?? '',
        'address' => $_POST['address'] ?? '',
        'original_filename' => $_FILES['prescription']['name'] ?? 'No file',
        'saved_filename' => 'UPLOAD_FAILED',
        'file_size' => $_FILES['prescription']['size'] ?? 0,
        'file_type' => $_FILES['prescription']['type'] ?? 'Unknown',
        'file_url' => 'N/A',
        'server_path' => 'N/A',
        'status' => 'FAILED - ' . $e->getMessage(),
        'whatsapp_number' => $whatsapp_number,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ];
    
    // Log failed attempts too
    logPrescriptionRequest($error_data, $log_file);
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
