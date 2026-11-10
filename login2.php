<?php
// ================================================================
// login2.php - API تسجيل الدخول (يعيد JSON)
// متصل بقاعدة البيانات: 4777865_home
// ================================================================

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(0);
ini_set('display_errors', 0);

// ========== إعدادات قاعدة البيانات (تم التعديل هنا) ==========
$host = "fdb1030.awardspace.net";
$dbuser = "4777865_home";
$dbpass = "Aa12345678##";
$dbname = "4777865_home";

// ========== الاتصال بقاعدة البيانات ==========
$conn = new mysqli($host, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "خطأ في الاتصال بقاعدة البيانات: " . $conn->connect_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset("utf8mb4");

// ========== إنشاء جدول المستخدمين ==========
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(20) UNIQUE NOT NULL,
    username VARCHAR(100) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    balance DECIMAL(15,2) DEFAULT 0,
    account_balance DECIMAL(15,2) DEFAULT 0,
    status ENUM('active', 'banned', 'pending', 'disabled') DEFAULT 'active',
    device_id VARCHAR(255) DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 1,
    is_admin TINYINT(1) DEFAULT 0,
    reference_number VARCHAR(50) DEFAULT NULL,
    full_account_number VARCHAR(100) DEFAULT NULL,
    account_type ENUM('savings', 'current', 'premium') DEFAULT 'savings',
    branch VARCHAR(100) DEFAULT 'الفرع الرئيسي',
    role VARCHAR(50) DEFAULT 'user',
    last_login TIMESTAMP NULL DEFAULT NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account_number (account_number),
    INDEX idx_username (username),
    INDEX idx_status (status)
)");

// ========== إضافة مستخدمين تجريبيين ==========
$conn->query("INSERT IGNORE INTO users (account_number, username, full_name, password, balance, status, full_account_number) VALUES 
    ('2125759', 'user', 'مستخدم تجريبي', MD5('bdbsvg'), 5000, 'active', '21257590001'),
    ('1000001', 'admin', 'مدير النظام', MD5('admin123'), 5000, 'active', '10000010001'),
    ('1000002', 'user1', 'مستخدم تجريبي 1', MD5('123456'), 3000, 'active', '10000020001'),
    ('1000003', 'user2', 'مستخدم تجريبي 2', MD5('123456'), 2000, 'active', '10000030001')
");

// ================================================================
// قراءة البيانات الواردة
// ================================================================
$account = $_POST['account_number'] ?? $_GET['account_number'] ?? '';
$password = md5($_POST['password'] ?? $_GET['password'] ?? '');
$device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';

// تنظيف رقم الحساب
$account = ltrim($account, '0');

// ================================================================
// 1. التحقق من وجود رقم الحساب
// ================================================================
if (empty($account)) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "❌ رقم الحساب مطلوب"
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

// ================================================================
// 2. البحث عن الحساب
// ================================================================
$stmt = $conn->prepare("SELECT * FROM users WHERE account_number = ? OR full_account_number = ?");
$stmt->bind_param("ss", $account, $account);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows == 0) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "❌ الحساب غير موجود"
    ], JSON_UNESCAPED_UNICODE);
    $stmt->close();
    $conn->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ================================================================
// 3. التحقق من حالة الحساب (محظور)
// ================================================================
if (isset($user['status']) && ($user['status'] == 'banned' || $user['status'] == 'disabled')) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "⚠️التطبيق في وضع الصيانه يرجى المحاوله لاحقا"
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

// ================================================================
// 4. التحقق من كلمة المرور
// ================================================================
if ($user['password'] != $password) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "❌ كلمة المرور غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

// ================================================================
// 5. التحقق من الجهاز (اختياري)
// ================================================================
if (!empty($user['device_id']) && !empty($device_id) && $user['device_id'] != $device_id) {
    echo json_encode([
        "status" => "failed",
        "success" => false,
        "message" => "⚠️ الحساب مرتبط بجهاز آخر"
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

// ================================================================
// 6. ربط الجهاز إذا كان جديداً
// ================================================================
if (empty($user['device_id']) && !empty($device_id)) {
    $updateStmt = $conn->prepare("UPDATE users SET device_id = ? WHERE account_number = ?");
    $updateStmt->bind_param("ss", $device_id, $user['account_number']);
    $updateStmt->execute();
    $updateStmt->close();
}

// ================================================================
// 7. تحديث آخر تسجيل دخول
// ================================================================
$updateLoginStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE account_number = ?");
$updateLoginStmt->bind_param("s", $user['account_number']);
$updateLoginStmt->execute();
$updateLoginStmt->close();

// ================================================================
// 8. نجاح تسجيل الدخول - إرجاع JSON
// ================================================================
echo json_encode([
    "status" => "success",
    "success" => true,
    "message" => "✅ تم تسجيل الدخول بنجاح",
    "account_number" => $user['account_number'],
    "username" => $user['username'],
    "full_name" => $user['full_name'] ?? $user['username'],
    "balance" => $user['balance'] ?? 0,
    "account_balance" => $user['account_balance'] ?? 0,
    "account_type" => $user['account_type'] ?? 'savings',
    "branch" => $user['branch'] ?? 'الفرع الرئيسي',
    "role" => $user['role'] ?? 'user',
    "status" => $user['status'] ?? 'active'
], JSON_UNESCAPED_UNICODE);

$conn->close();
?>
