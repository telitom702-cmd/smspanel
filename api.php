<?php
// ==================== [ SMM PROVIDER API V2 ENDPOINT ] ====================
header('Content-Type: application/json');

// Global Configs
$smm_api_url = "https://my.smmgen.com/api/v2";                 // Main Upstream Provider API
$smm_api_key = "2e53b57414dc722db3e2e2f9aaf723dc";             // Main Upstream Provider Key

$data_dir = __DIR__ . '/data';
$users_file = "$data_dir/users.json";
$configs_file = "$data_dir/service_configs.json";

function getUsersData() {
    global $users_file;
    return file_exists($users_file) ? (json_decode(file_get_contents($users_file), true) ?: []) : [];
}

function saveUsersData($data) {
    global $users_file;
    file_put_contents($users_file, json_encode($data, JSON_PRETTY_PRINT));
}

function getServiceConfigsData() {
    global $configs_file;
    return file_exists($configs_file) ? (json_decode(file_get_contents($configs_file), true) ?: []) : [];
}

// Upstream API Call Helper
function callUpstreamApi($action, $params = []) {
    global $smm_api_url, $smm_api_key;
    $params['key'] = $smm_api_key;
    $params['action'] = $action;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $smm_api_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Receive POST Inputs
$api_key = trim($_POST['key'] ?? '');
$action  = trim($_POST['action'] ?? '');

if (empty($api_key) || empty($action)) {
    echo json_encode(["error" => "Invalid API key or Action missing"]);
    exit();
}

// Validate API Key
$users = getUsersData();
$client_id = null;

foreach ($users as $uid => $udata) {
    if (isset($udata['api_key']) && $udata['api_key'] === $api_key) {
        $client_id = $uid;
        break;
    }
}

if (!$client_id) {
    echo json_encode(["error" => "Incorrect API key"]);
    exit();
}

// -------------------- [ ACTION 1: CHECK BALANCE ] --------------------
if ($action == 'balance') {
    $bal = number_format($users[$client_id]['balance'] ?? 0, 2, '.', '');
    echo json_encode([
        "balance"  => (string)$bal,
        "currency" => "USD"
    ]);
    exit();
}

// -------------------- [ ACTION 2: LIST ACTIVE SERVICES ] --------------------
if ($action == 'services') {
    $all_services = callUpstreamApi('services');
    $configs = getServiceConfigsData();
    $active_services = [];

    if (is_array($all_services)) {
        foreach ($all_services as $srv) {
            $sid = $srv['service'];
            // Only send services activated by Admin
            if (isset($configs[$sid]) && !empty($configs[$sid]['active'])) {
                $srv['rate'] = (string)($configs[$sid]['rate'] ?? $srv['rate']);
                $srv['min']  = (string)($configs[$sid]['min']  ?? $srv['min']);
                $srv['max']  = (string)($configs[$sid]['max']  ?? $srv['max']);
                $active_services[] = $srv;
            }
        }
    }
    echo json_encode($active_services);
    exit();
}

// -------------------- [ ACTION 3: PLACE NEW ORDER ] --------------------
if ($action == 'add') {
    $service_id = trim($_POST['service'] ?? '');
    $link       = trim($_POST['link'] ?? '');
    $quantity   = intval($_POST['quantity'] ?? 0);

    if (empty($service_id) || empty($link) || $quantity <= 0) {
        echo json_encode(["error" => "Invalid parameters"]);
        exit();
    }

    $configs = getServiceConfigsData();
    if (!isset($configs[$service_id]) || empty($configs[$service_id]['active'])) {
        echo json_encode(["error" => "Service is currently disabled"]);
        exit();
    }

    $user_rate = floatval($configs[$service_id]['rate'] ?? 0);
    $user_min  = intval($configs[$service_id]['min'] ?? 1);
    $user_max  = intval($configs[$service_id]['max'] ?? 1000000);

    if ($quantity < $user_min || $quantity > $user_max) {
        echo json_encode(["error" => "Quantity must be between {$user_min} and {$user_max}"]);
        exit();
    }

    // Cost Calculation
    $total_cost = ($user_rate / 1000) * $quantity;
    $client_bal = floatval($users[$client_id]['balance'] ?? 0);

    if ($client_bal < $total_cost) {
        echo json_encode(["error" => "Not enough balance"]);
        exit();
    }

    // Forward Order to Upstream Provider (SMMGen)
    $res = callUpstreamApi('add', [
        'service'  => $service_id,
        'link'     => $link,
        'quantity' => $quantity
    ]);

    if (isset($res['order'])) {
        // Deduct balance from API Client
        $users[$client_id]['balance'] -= $total_cost;
        saveUsersData($users);

        echo json_encode(["order" => $res['order']]);
    } else {
        $error_msg = $res['error'] ?? "Failed to place order upstream";
        echo json_encode(["error" => $error_msg]);
    }
    exit();
}

// -------------------- [ ACTION 4: ORDER STATUS ] --------------------
if ($action == 'status') {
    $order_id = intval($_POST['order'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["error" => "Invalid Order ID"]);
        exit();
    }
    $res = callUpstreamApi('status', ['order' => $order_id]);
    echo json_encode($res);
    exit();
}

// -------------------- [ ACTION 5: REFILL ORDER ] --------------------
if ($action == 'refill') {
    $order_id = intval($_POST['order'] ?? 0);
    $res = callUpstreamApi('refill', ['order' => $order_id]);
    echo json_encode($res);
    exit();
}

// -------------------- [ ACTION 6: CANCEL ORDER ] --------------------
if ($action == 'cancel') {
    $orders = $_POST['orders'] ?? '';
    $res = callUpstreamApi('cancel', ['orders' => $orders]);
    echo json_encode($res);
    exit();
}

// Default fallback
echo json_encode(["error" => "Invalid Action"]);
exit();
?>
