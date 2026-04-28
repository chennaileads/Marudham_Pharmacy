<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers FIRST before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Please use POST method.'
    ]);
    exit;
}

// ==================== CONFIGURATION ====================
$whatsapp_number = "919629276131"; // Without + sign
$upload_dir = __DIR__ . "/uploads/";
$log_dir = __DIR__ . "/logs/";
$log_file = $log_dir . "prescription_requests.log";
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$max_file_size = 5 * 1024 * 1024; // 5MB

// ==================== AUTO-CREATE DIRECTORIES ====================
try {
    // Create uploads directory
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create uploads directory');
        }
        file_put_contents($upload_dir . 'index.html', '<!-- Directory listing disabled -->');
    }

    // Create logs directory
    if (!file_exists($log_dir)) {
        if (!mkdir($log_dir, 0755, true)) {
            throw new Exception('Failed to create logs directory');
        }
        file_put_contents($log_dir . 'index.html', '<!-- Directory listing disabled -->');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error: ' . $e->getMessage()
    ]);
    exit;
}

// ==================== FUNCTION TO LOG DATA ====================
function logPrescriptionRequest($data, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "\n========================================\n";
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
    $log_entry .= "========================================\n";
    
    return file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ==================== MAIN PROCESS ====================
try {
    // Check if file was uploaded
    if (!isset($_FILES['prescription'])) {
        throw new Exception("No prescription file uploaded. Please select a file.");
    }

    $file = $_FILES['prescription'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
        throw new Exception("Upload error: " . $errorMsg);
    }

    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception("Invalid file type (" . $file['type'] . "). Please upload an image or PDF file.");
    }

    // Validate file size
    if ($file['size'] > $max_file_size) {
        throw new Exception("File is too large (" . round($file['size']/1024, 2) . " KB). Maximum size is 5MB.");
    }

    // Get form data
    $patient_name = trim($_POST['patient_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $medicine = trim($_POST['medicine'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Validate required fields
    if (empty($patient_name)) {
        throw new Exception("Patient name is required.");
    }
    if (empty($mobile)) {
        throw new Exception("Mobile number is required.");
    }

    // Generate unique request ID
    $request_id = 'REQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    // Generate unique filename
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $unique_filename = date('Y-m-d_H-i-s') . '_' . $request_id . '.' . $file_extension;
    $upload_path = $upload_dir . $unique_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception("Failed to save file. Please try again.");
    }

    // Create public URL for the file
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $file_url = $protocol . $domain . '/uploads/' . $unique_filename;

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
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ];

    // Log the request
    logPrescriptionRequest($log_data, $log_file);

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
    $whatsapp_message .= "\n\n_File uploaded at " . date('h:i A') . "_";
    $whatsapp_message .= "\n_ID: $request_id_";

    // Create WhatsApp URL
    $whatsapp_url = "https://wa.me/$whatsapp_number?text=" . urlencode($whatsapp_message);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Prescription uploaded successfully! Opening WhatsApp...',
        'request_id' => $request_id,
        'file_url' => $file_url,
        'whatsapp_url' => $whatsapp_url,
        'filename' => $unique_filename
    ]);

} catch (Exception $e) {
    // Log failed attempts
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
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ];
    
    logPrescriptionRequest($error_data, $log_file);
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
