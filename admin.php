<?php
// ==================== [ CONFIG & HELPERS ] ====================
// ⚠️ বট টোকেন ও এপিআই কি রিসেট করে এখানে বসান!
$bot_token   = "8998188048:AAFLvJ7xkydWpTPlskOIM4tiCwmVGPAbOZA"; 
$smm_api_url = "https://my.smmgen.com/api/v2";                 
$smm_api_key = "2e53b57414dc722db3e2e2f9aaf723dc";             

$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) {
    @mkdir($data_dir, 0755, true);
}

function getSettings() {
    global $data_dir;
    $file = "$data_dir/settings.json";
    $default = [
        'ref_enabled' => false, 'ref_bonus' => 0.05, 'admin_username' => 'admin',
        'force_join_enabled' => false, 'force_channel_id' => '', 'force_channel_link' => '',
        'btn_per_row' => 2, 'btn_active' => ['services'=>true,'new_order'=>true,'balance'=>true,'deposit'=>true,'api'=>true,'status'=>true,'refill'=>true,'referral'=>true]
    ];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? array_merge($default, $data) : $default;
    }
    return $default;
}

function saveSettings($settings) {
    global $data_dir;
    return file_put_contents("$data_dir/settings.json", json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getUsers() {
    global $data_dir;
    $file = "$data_dir/users.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function saveUsers($users) {
    global $data_dir;
    file_put_contents("$data_dir/users.json", json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getServiceConfigs() {
    global $data_dir;
    $file = "$data_dir/service_configs.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function saveServiceConfigs($configs) {
    global $data_dir;
    file_put_contents("$data_dir/service_configs.json", json_encode($configs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function callSmmGenApi($action, $params = []) {
    global $smm_api_url, $smm_api_key;
    $params['key'] = $smm_api_key;
    $params['action'] = $action;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $smm_api_url, 
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params), 
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 4
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function getCachedServices($force_refresh = false) {
    global $data_dir;
    $cache_file = "$data_dir/services_cache.json";
    
    if (!$force_refresh && file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    $services = callSmmGenApi('services');
    if (is_array($services) && !empty($services)) {
        file_put_contents($cache_file, json_encode($services));
    } else if (file_exists($cache_file)) {
        $services = json_decode(file_get_contents($cache_file), true) ?: [];
    }
    return $services ?: [];
}

function getCachedBalance() {
    global $data_dir;
    $cache_file = "$data_dir/bal_cache.json";
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 120)) {
        return file_get_contents($cache_file);
    }
    $balData = callSmmGenApi('balance');
    $bal = $balData['balance'] ?? '0.00';
    file_put_contents($cache_file, $bal);
    return $bal;
}

function sendTelegramMessage($chat_id, $text) {
    global $bot_token;
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 3
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ==================== [ FORM POST PROCESSING (AJAX SUPPORTED) ] ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'অজানা অনুরোধ!'];

    if ($action == 'update_settings') {
        $st = getSettings();
        $st['admin_username']     = trim($_POST['admin_username'] ?? 'admin');
        $st['force_join_enabled'] = isset($_POST['force_join_enabled']);
        $st['force_channel_id']   = trim($_POST['force_channel_id'] ?? '');
        $st['force_channel_link'] = trim($_POST['force_channel_link'] ?? '');
        $st['ref_enabled']        = isset($_POST['ref_enabled']);
        $st['ref_bonus']          = floatval($_POST['ref_bonus'] ?? 0);
        $st['btn_per_row']        = intval($_POST['btn_per_row'] ?? 2);
        
        foreach (['services','new_order','balance','deposit','api','status','refill','referral'] as $btn) {
            $st['btn_active'][$btn] = isset($_POST["btn_$btn"]);
        }
        
        if (saveSettings($st) !== false) {
            $response = ['status' => 'success', 'message' => '✅ সেটিং সফলভাবে সেভ করা হয়েছে!'];
        } else {
            $response = ['status' => 'error', 'message' => '❌ ফাইল সেভ করার পারমিশন নেই!'];
        }
    }

    if ($action == 'add_balance') {
        $uid = trim($_POST['user_id']);
        $amount = floatval($_POST['amount']);
        $users = getUsers();
        if (isset($users[$uid])) {
            $users[$uid]['balance'] += $amount;
            saveUsers($users);
            sendTelegramMessage($uid, "💳 <b>Balance Added!</b>\nAdmin added <b>\${$amount}</b> to your account.\nNew Balance: <b>\${$users[$uid]['balance']}</b>");
            $response = ['status' => 'success', 'message' => "✅ User {$uid} এর একাউন্টে \${$amount} যুক্ত করা হয়েছে!"];
        } else {
            $response = ['status' => 'error', 'message' => "❌ ইউজার আইডি পাওয়া যায়নি!"];
        }
    }

    if ($action == 'save_service') {
        $srv_id = $_POST['srv_id'];
        $configs = getServiceConfigs();
        $configs[$srv_id] = [
            'active' => isset($_POST['active']),
            'rate'   => floatval($_POST['rate']),
            'min'    => intval($_POST['min']),
            'max'    => intval($_POST['max'])
        ];
        saveServiceConfigs($configs);
        $response = ['status' => 'success', 'message' => "✅ সার্ভিস #{$srv_id} আপডেট হয়েছে!"];
    }

    if ($action == 'refresh_services') {
        getCachedServices(true);
        $response = ['status' => 'success', 'message' => "🔄 API থেকে নতুন সার্ভিস সিঙ্ক করা হয়েছে!"];
    }

    if ($action == 'send_broadcast') {
        @set_time_limit(0);
        $broadcast_msg = trim($_POST['broadcast_message']);
        if (!empty($broadcast_msg)) {
            $users = getUsers();
            $count = 0;
            foreach ($users as $uid => $d) {
                sendTelegramMessage($uid, "📢 <b>ANNOUNCEMENT:</b>\n\n" . $broadcast_msg);
                $count++;
                usleep(30000); 
            }
            $response = ['status' => 'success', 'message' => "✅ মোট {$count} জন ইউজারের কাছে ব্রডকাস্ট পাঠানো হয়েছে!"];
        } else {
            $response = ['status' => 'error', 'message' => "❌ মেসেজ খালি রাখা যাবে না!"];
        }
    }

    // AJAX রিকোয়েস্ট হলে JSON রেসপন্স রিটার্ন করবে (হ্যাং হওয়া আটকাবে)
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$settings = getSettings();
$provider_bal = getCachedBalance();
$all_users = getUsers();
$all_services = getCachedServices();
$service_configs = getServiceConfigs();

// ==================== [ AUTO CATEGORIZE SERVICES ] ====================
$categorized_services = [
    'Facebook'  => [], 'YouTube'   => [], 'TikTok'    => [],
    'Telegram'  => [], 'Instagram' => [], 'Others'    => []
];

if (is_array($all_services)) {
    foreach ($all_services as $s) {
        $cat_name  = $s['category'] ?? '';
        $s_name    = $s['name'] ?? '';
        $search_str = strtolower($cat_name . ' ' . $s_name);

        if (strpos($search_str, 'facebook') !== false || strpos($search_str, 'fb') !== false) {
            $categorized_services['Facebook'][] = $s;
        } elseif (strpos($search_str, 'youtube') !== false || strpos($search_str, 'yt') !== false) {
            $categorized_services['YouTube'][] = $s;
        } elseif (strpos($search_str, 'tiktok') !== false) {
            $categorized_services['TikTok'][] = $s;
        } elseif (strpos($search_str, 'telegram') !== false || strpos($search_str, 'tg') !== false) {
            $categorized_services['Telegram'][] = $s;
        } elseif (strpos($search_str, 'instagram') !== false || strpos($search_str, 'ig') !== false) {
            $categorized_services['Instagram'][] = $s;
        } else {
            $categorized_services['Others'][] = $s;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #FFF9F2 0%, #FFF3E0 50%, #F4F7FB 100%);
            --card-bg: #FFFFFF;
            --text-main: #1E293B;
            --text-muted: #64748B;
            --mango-primary: #FF8C00;
            --mango-secondary: #FFA500;
            --mango-gradient: linear-gradient(135deg, #FF8C00 0%, #FFB703 100%);
            --mango-shadow: 0 10px 25px -5px rgba(255, 140, 0, 0.3);
            --card-3d-shadow: 0 12px 28px rgba(0, 0, 0, 0.05), 0 2px 6px rgba(0, 0, 0, 0.02);
            --card-border: rgba(226, 232, 240, 0.8);
        }

        body { 
            background: var(--bg-gradient); 
            color: var(--text-main); 
            font-family: 'Hind Siliguri', 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            padding-bottom: 40px;
        }

        .card { 
            background-color: var(--card-bg); 
            border: 1px solid var(--card-border); 
            border-radius: 20px; 
            box-shadow: var(--card-3d-shadow);
            transition: all 0.3s ease;
        }

        .header-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #FFFDF9 100%);
            border: 1px solid rgba(255, 140, 0, 0.2);
        }
        
        .stat-badge {
            background: #FFFFFF;
            border: 1px solid #FFE0B2;
            border-radius: 14px;
            padding: 8px 16px;
            box-shadow: 0 4px 12px rgba(255, 140, 0, 0.08);
        }

        .form-control, .form-select { 
            background-color: #F8FAFC; 
            border: 1px solid #E2E8F0; 
            color: var(--text-main); 
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .form-control:focus, .form-select:focus { 
            background-color: #FFFFFF; 
            color: var(--text-main); 
            border-color: var(--mango-primary); 
            box-shadow: 0 0 0 4px rgba(255, 140, 0, 0.15); 
        }

        .dash-box {
            background: #FFFFFF;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 20px;
            padding: 24px 20px;
            cursor: pointer;
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            box-shadow: 0 8px 20px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .dash-box:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 18px 35px rgba(255, 140, 0, 0.18);
            border-color: var(--mango-secondary);
        }
        .dash-box .icon-box {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .icon-mango { background: var(--mango-gradient); color: #FFF; box-shadow: var(--mango-shadow); }
        .icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFF; }
        .icon-blue  { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFF; }
        .icon-red   { background: linear-gradient(135deg, #EF4444, #DC2626); color: #FFF; }

        .btn-mango {
            background: var(--mango-gradient);
            color: #FFFFFF;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            box-shadow: var(--mango-shadow);
            transition: all 0.3s ease;
        }
        .btn-mango:hover {
            color: #FFF;
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(255, 140, 0, 0.4);
        }

        .platform-nav .nav-link {
            color: var(--text-muted);
            background: #FFFFFF;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 10px 18px;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
        }
        .platform-nav .nav-link:hover {
            background: #FFF8F0;
            color: var(--mango-primary);
        }
        .platform-nav .nav-link.active {
            background: var(--mango-gradient);
            color: #FFF !important;
            border-color: transparent;
            box-shadow: var(--mango-shadow);
        }

        .subpage-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 2px solid #F1F5F9;
        }
        
        .subpage { display: none; }
        .subpage.active { 
            display: block; 
            animation: fadeIn 0.4s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .table { color: var(--text-main); }
        .table thead th {
            background-color: #F8FAFC;
            color: #475569;
            font-weight: 700;
            border-bottom: 2px solid #E2E8F0;
            padding: 12px;
        }
        .table-hover tbody tr:hover { background-color: #FFFDF9; }

        .form-check-input:checked {
            background-color: var(--mango-primary);
            border-color: var(--mango-primary);
        }
    </style>
</head>
<body class="p-2 p-md-4">

<div class="container-fluid" style="max-width: 1050px;">
    <!-- Top Header -->
    <div class="card header-card mb-4 p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="icon-box icon-mango m-0" style="width:50px; height:50px; border-radius:14px; font-size:22px;">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div>
                    <h4 class="m-0 fw-bold" style="color: #1E293B;">SMM Bot Admin Panel</h4>
                    <small class="text-muted fw-semibold">সহজ ও সুন্দর কন্ট্রোল ড্যাশবোর্ড</small>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <form method="POST" class="ajax-form m-0">
                    <input type="hidden" name="action" value="refresh_services">
                    <button type="submit" class="btn btn-sm btn-outline-warning rounded-3 me-2" title="API থেকে নতুন সার্ভিস সিঙ্ক করুন">
                        <i class="fa-solid fa-rotate"></i> Sync Services
                    </button>
                </form>
                <div class="stat-badge text-center">
                    <small class="text-muted d-block fw-bold" style="font-size:11px;">PROVIDER BAL</small>
                    <span class="fw-bold text-success" style="font-size:16px;">$<?= htmlspecialchars($provider_bal) ?></span>
                </div>
                <div class="stat-badge text-center">
                    <small class="text-muted d-block fw-bold" style="font-size:11px;">TOTAL USERS</small>
                    <span class="fw-bold text-primary" style="font-size:16px;"><?= count($all_users) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Toast Area -->
    <div id="alertArea"></div>

    <!-- MAIN DASHBOARD MENU (GRID BOXES) -->
    <div id="mainDashboard">
        <div class="row g-3 g-md-4">
            <div class="col-6 col-md-3">
                <div class="dash-box" onclick="openSubPage('settingsPage')">
                    <div class="icon-box icon-mango"><i class="fa-solid fa-sliders"></i></div>
                    <h5 class="fw-bold m-0" style="font-size: 17px;">বট সেটিং</h5>
                    <small class="text-muted mt-1">চ্যানেল ও বোনাস কন্ট্রোল</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dash-box" onclick="openSubPage('servicesPage')">
                    <div class="icon-box icon-green"><i class="fa-solid fa-layer-group"></i></div>
                    <h5 class="fw-bold m-0" style="font-size: 17px;">সার্ভিস সেটিং</h5>
                    <small class="text-muted mt-1">রেট ও ক্যাটাগরি ফিল্টার</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dash-box" onclick="openSubPage('usersPage')">
                    <div class="icon-box icon-blue"><i class="fa-solid fa-users-gear"></i></div>
                    <h5 class="fw-bold m-0" style="font-size: 17px;">ইউজার ওয়ালেট</h5>
                    <small class="text-muted mt-1">ব্যালেন্স এড ও ইউজার লিস্ট</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="dash-box" onclick="openSubPage('broadcastPage')">
                    <div class="icon-box icon-red"><i class="fa-solid fa-paper-plane"></i></div>
                    <h5 class="fw-bold m-0" style="font-size: 17px;">ব্রডকাস্ট</h5>
                    <small class="text-muted mt-1">নোটিফিকেশন মেসেজ পাঠান</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== [ SUB PAGES ] ==================== -->

    <!-- ⚙️ SETTINGS SUB-PAGE -->
    <div id="settingsPage" class="subpage">
        <div class="subpage-header">
            <h4 class="m-0 fw-bold"><i class="fa-solid fa-sliders text-warning me-2"></i>বট সেটিং ও কনফিগারেশন</h4>
            <button class="btn btn-light border btn-sm rounded-3 fw-bold" onclick="showDashboard()"><i class="fa-solid fa-arrow-left me-1"></i> মেনু</button>
        </div>

        <form method="POST" class="ajax-form">
            <input type="hidden" name="action" value="update_settings">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-shield text-warning me-2"></i>এডমিন প্রোফাইল</h5>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Admin Telegram Username (without @)</label>
                            <input type="text" name="admin_username" class="form-control" value="<?= htmlspecialchars($settings['admin_username'] ?? 'admin') ?>">
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn text-warning me-2"></i>Force Join Settings</h5>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="force_join_enabled" id="fj_check" <?= !empty($settings['force_join_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="fj_check">Force Join সক্রিয় করুন</label>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Channel Username (with @)</label>
                            <input type="text" name="force_channel_id" class="form-control mb-3" value="<?= htmlspecialchars($settings['force_channel_id'] ?? '') ?>" placeholder="@MyChannel">
                            <label class="form-label fw-semibold">Channel Invite Link</label>
                            <input type="text" name="force_channel_link" class="form-control" value="<?= htmlspecialchars($settings['force_channel_link'] ?? '') ?>" placeholder="https://t.me/MyChannel">
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-users-viewfinder text-warning me-2"></i>Referral System</h5>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="ref_enabled" id="ref_check" <?= !empty($settings['ref_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="ref_check">রেফারেল সিস্টেম চালু রাখুন</label>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Referral Bonus Amount ($)</label>
                            <input type="number" step="0.01" name="ref_bonus" class="form-control" value="<?= $settings['ref_bonus'] ?? 0.05 ?>">
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-table-cells text-warning me-2"></i>Menu Button Control</h5>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Button Layout (প্রতি সারিতে বাটন)</label>
                            <select name="btn_per_row" class="form-select">
                                <option value="1" <?= ($settings['btn_per_row'] ?? 2) == 1 ? 'selected' : '' ?>>১ টি করে বাটন</option>
                                <option value="2" <?= ($settings['btn_per_row'] ?? 2) == 2 ? 'selected' : '' ?>>২ টি করে বাটন</option>
                            </select>
                        </div>
                        <h6 class="fw-bold mb-2 text-secondary">দৃশ্যমান বাটনসমূহ:</h6>
                        <div class="row g-2">
                            <?php 
                            $b_names = ['services'=>'🛒 Services', 'new_order'=>'➕ New Order', 'balance'=>'💰 Balance', 'deposit'=>'💳 Deposit', 'api'=>'🔑 API', 'status'=>'🔍 Status', 'refill'=>'♻️ Refill', 'referral'=>'👥 Referral'];
                            foreach ($b_names as $k => $name): 
                                $active = $settings['btn_active'][$k] ?? true;
                            ?>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="btn_<?= $k ?>" id="b_<?= $k ?>" <?= $active ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="b_<?= $k ?>"><?= $name ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-mango btn-lg w-100 mt-4"><i class="fa-solid fa-floppy-disk me-2"></i>সব সেটিং সেভ করুন</button>
        </form>
    </div>

    <!-- 🛒 SERVICES MANAGEMENT SUB-PAGE -->
    <div id="servicesPage" class="subpage">
        <div class="subpage-header">
            <h4 class="m-0 fw-bold"><i class="fa-solid fa-layer-group text-success me-2"></i>সার্ভিস ম্যানেজমেন্ট</h4>
            <button class="btn btn-light border btn-sm rounded-3 fw-bold" onclick="showDashboard()"><i class="fa-solid fa-arrow-left me-1"></i> মেনু</button>
        </div>

        <ul class="nav nav-pills platform-nav mb-3" id="serviceCatTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#cat-Facebook"><i class="fa-brands fa-facebook text-primary me-1"></i> Facebook</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#cat-YouTube"><i class="fa-brands fa-youtube text-danger me-1"></i> YouTube</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#cat-TikTok"><i class="fa-brands fa-tiktok text-dark me-1"></i> TikTok</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#cat-Telegram"><i class="fa-brands fa-telegram text-info me-1"></i> Telegram</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#cat-Instagram"><i class="fa-brands fa-instagram text-danger me-1"></i> Instagram</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#cat-Others"><i class="fa-solid fa-cubes text-secondary me-1"></i> অন্যান্য</a></li>
        </ul>

        <div class="tab-content card p-3 p-md-4">
            <?php 
            $first_cat = true;
            foreach ($categorized_services as $cat_key => $cat_services): 
            ?>
            <div class="tab-pane fade <?= $first_cat ? 'show active' : '' ?>" id="cat-<?= $cat_key ?>">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-folder-open text-warning me-2"></i><?= $cat_key ?> সার্ভিসেস (<?= count($cat_services) ?>)</h5>
                
                <?php if (empty($cat_services)): ?>
                    <p class="text-muted text-center py-5">এই ক্যাটাগরিতে কোনো সার্ভিস পাওয়া যায়নি!</p>
                <?php else: ?>
                <div class="table-responsive" style="max-height: 520px;">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Provider Rate</th>
                                <th>My Rate ($/1k)</th>
                                <th>Min / Max</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cat_services as $s): 
                                $srv_id = $s['service'];
                                $cfg = $service_configs[$srv_id] ?? [];
                                $is_act = $cfg['active'] ?? false;
                                $rate   = $cfg['rate']   ?? $s['rate'];
                                $min    = $cfg['min']    ?? $s['min'];
                                $max    = $cfg['max']    ?? $s['max'];
                            ?>
                            <tr>
                                <form method="POST" class="ajax-form">
                                    <input type="hidden" name="action" value="save_service">
                                    <input type="hidden" name="srv_id" value="<?= $srv_id ?>">
                                    <td><span class="badge bg-light text-dark border">#<?= $srv_id ?></span></td>
                                    <td style="font-size: 13px; max-width: 250px;" class="fw-semibold"><?= htmlspecialchars($s['name']) ?></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary">$<?= $s['rate'] ?></span></td>
                                    <td style="width: 120px;"><input type="number" step="0.0001" name="rate" class="form-control form-control-sm" value="<?= $rate ?>"></td>
                                    <td style="width: 140px;">
                                        <div class="d-flex gap-1">
                                            <input type="number" name="min" class="form-control form-control-sm" value="<?= $min ?>" placeholder="Min">
                                            <input type="number" name="max" class="form-control form-control-sm" value="<?= $max ?>" placeholder="Max">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="form-check form-switch m-0">
                                                <input class="form-check-input" type="checkbox" name="active" <?= $is_act ? 'checked' : '' ?>>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-success rounded-3"><i class="fa-solid fa-check"></i></button>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php 
            $first_cat = false;
            endforeach; 
            ?>
        </div>
    </div>

    <!-- 💳 USERS SUB-PAGE -->
    <div id="usersPage" class="subpage">
        <div class="subpage-header">
            <h4 class="m-0 fw-bold"><i class="fa-solid fa-users-gear text-primary me-2"></i>ইউজার ও ওয়ালেট ম্যানেজমেন্ট</h4>
            <button class="btn btn-light border btn-sm rounded-3 fw-bold" onclick="showDashboard()"><i class="fa-solid fa-arrow-left me-1"></i> মেনু</button>
        </div>

        <div class="row g-4">
            <div class="col-md-5">
                <div class="card p-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-wallet text-success me-2"></i>ইউজারকে ব্যালেন্স দিন</h5>
                    <form method="POST" class="ajax-form">
                        <input type="hidden" name="action" value="add_balance">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">User Chat ID</label>
                            <input type="text" name="user_id" class="form-control" placeholder="123456789" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Amount ($)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="5.00" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100 py-2 rounded-3 fw-bold"><i class="fa-solid fa-plus me-2"></i>ব্যালেন্স যুক্ত করুন</button>
                    </form>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card p-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-ul text-primary me-2"></i>সকল ইউজারের তালিকা</h5>
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Chat ID</th>
                                    <th>Name</th>
                                    <th>Balance</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_users as $uid => $u): ?>
                                <tr>
                                    <td><code class="fw-bold text-dark"><?= $uid ?></code></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($u['name'] ?? 'User') ?></td>
                                    <td><span class="badge bg-success-subtle text-success fw-bold">$<?= number_format($u['balance'] ?? 0, 2) ?></span></td>
                                    <td><small class="text-muted"><?= $u['joined'] ?? 'N/A' ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📢 BROADCAST SUB-PAGE -->
    <div id="broadcastPage" class="subpage">
        <div class="subpage-header">
            <h4 class="m-0 fw-bold"><i class="fa-solid fa-paper-plane text-danger me-2"></i>ব্রডকাস্ট নোটিফিকেশন</h4>
            <button class="btn btn-light border btn-sm rounded-3 fw-bold" onclick="showDashboard()"><i class="fa-solid fa-arrow-left me-1"></i> মেনু</button>
        </div>

        <div class="card p-4">
            <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-bullhorn text-danger me-2"></i>সব ইউজারকে মেসেজ পাঠান</h5>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="action" value="send_broadcast">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Broadcast Text (HTML Supportable)</label>
                    <textarea name="broadcast_message" class="form-control" rows="6" placeholder="আপনার মেসেজ এখানে লিখুন..." required></textarea>
                </div>
                <button type="submit" class="btn btn-mango btn-lg w-100 mt-2">
                    <i class="fa-solid fa-paper-plane me-2"></i>ব্রডকাস্ট পাঠান
                </button>
            </form>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openSubPage(pageId) {
        document.getElementById('mainDashboard').style.display = 'none';
        const pages = document.querySelectorAll('.subpage');
        pages.forEach(page => page.classList.remove('active'));
        document.getElementById(pageId).classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showDashboard() {
        const pages = document.querySelectorAll('.subpage');
        pages.forEach(page => page.classList.remove('active'));
        document.getElementById('mainDashboard').style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ⚡ AJAX Form Handler (টেলিগ্রাম মিনি অ্যাপ ঝুলবে না বা ব্লক হবে না)
    document.querySelectorAll('.ajax-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = form.querySelector('button[type="submit"]');
            const originalBtnHtml = btn ? btn.innerHTML : '';
            
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> সেভ হচ্ছে...';
            }

            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                }

                const alertArea = document.getElementById('alertArea');
                const isSuccess = data.status === 'success';
                
                alertArea.innerHTML = `
                    <div class="alert alert-${isSuccess ? 'success' : 'danger'} alert-dismissible fade show border-0 text-white mb-4 rounded-4 shadow-sm" style="background-color: ${isSuccess ? '#10B981' : '#EF4444'}">
                        <i class="fa-solid ${isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation'} me-2"></i>${data.message}
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                    </div>
                `;
                
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                }
                alert('❌ সেভ করতে সমস্যা হয়েছে! ইন্টারনেট বা সার্ভার চেক করুন।');
            });
        });
    });

    if (window.Telegram && window.Telegram.WebApp) {
        window.Telegram.WebApp.expand();
    }
</script>
</body>
</html>
