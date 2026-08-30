<?php
// ==================== [ CONFIGURATION ] ====================
$bot_token   = "8998188048:AAFLvJ7xkydWpTPlskOIM4tiCwmVGPAbOZA"; 
$smm_api_url = "https://my.smmgen.com/api/v2";                 
$smm_api_key = "2e53b57414dc722db3e2e2f9aaf723dc";             
$admin_id    = 8248792819;                                    

// Create Data Directory
$data_dir = __DIR__ . '/data';
if (!file_exists($data_dir)) {
    mkdir($data_dir, 0777, true);
}

// Domain API Endpoint URL & Admin WebApp URL
$protocol          = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$base_url          = $protocol . "://" . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . dirname($_SERVER['SCRIPT_NAME']);
$my_domain_api_url = rtrim($base_url, '/') . "/api.php";
$admin_web_url     = rtrim($base_url, '/') . "/admin.php";

// ==================== [ TELEGRAM INPUT ] ====================
$content = file_get_contents("php://input");
$update  = json_decode($content, true);
if (!$update) { exit(); }

$chat_id       = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$text          = trim($update['message']['text'] ?? '');
$callback_data = $update['callback_query']['data'] ?? null;
$cb_message_id = $update['callback_query']['message']['message_id'] ?? null;
$cb_id         = $update['callback_query']['id'] ?? null;
$first_name    = $update['message']['from']['first_name'] ?? $update['callback_query']['from']['first_name'] ?? 'User';

if (!$chat_id) { exit(); }

// ==================== [ DATABASE & SETTINGS HELPERS ] ====================
function getSettings() {
    global $data_dir;
    $file = "$data_dir/settings.json";
    $default = [
        'ref_enabled'        => false,
        'ref_bonus'          => 0.05,
        'admin_username'     => 'admin',
        'force_join_enabled' => false,
        'force_channel_id'   => '',
        'force_channel_link' => '',
        'btn_per_row'        => 2, // 1 or 2
        'btn_active'         => [
            'services'  => true,
            'new_order' => true,
            'balance'   => true,
            'deposit'   => true,
            'api'       => true,
            'status'    => true,
            'refill'    => true,
            'referral'  => true
        ]
    ];
    return file_exists($file) ? array_merge($default, json_decode(file_get_contents($file), true) ?: []) : $default;
}

function saveSettings($settings) {
    global $data_dir;
    file_put_contents("$data_dir/settings.json", json_encode($settings, JSON_PRETTY_PRINT));
}

function getUsers() {
    global $data_dir;
    $file = "$data_dir/users.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function registerUser($chat_id, $first_name, $referrer_id = null) {
    global $data_dir;
    $file = "$data_dir/users.json";
    $users = getUsers();
    
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'name'         => $first_name,
            'balance'      => 0.00,
            'joined'       => date('Y-m-d H:i:s'),
            'api_key'      => bin2hex(random_bytes(16)),
            'referred_by'  => null,
            'ref_count'    => 0,
            'ref_earnings' => 0.00
        ];

        $settings = getSettings();
        if ($referrer_id && $referrer_id != $chat_id && isset($users[$referrer_id]) && !empty($settings['ref_enabled'])) {
            $bonus = floatval($settings['ref_bonus']);
            $users[$chat_id]['referred_by'] = $referrer_id;
            $users[$referrer_id]['balance'] += $bonus;
            $users[$referrer_id]['ref_count'] += 1;
            $users[$referrer_id]['ref_earnings'] += $bonus;

            sendMessage($referrer_id, "🎉 <b>New Referral Joined!</b>\n\nUser <b>{$first_name}</b> joined using your referral link. You earned <b>\${$bonus}</b>!");
        }

        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
    } elseif (empty($users[$chat_id]['api_key'])) {
        $users[$chat_id]['api_key'] = bin2hex(random_bytes(16));
        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
    }
}

function getUserBalance($chat_id) {
    $users = getUsers();
    return $users[$chat_id]['balance'] ?? 0.00;
}

function updateUserBalance($chat_id, $amount) {
    global $data_dir;
    $file = "$data_dir/users.json";
    $users = getUsers();
    if (isset($users[$chat_id])) {
        $users[$chat_id]['balance'] += $amount;
        if ($users[$chat_id]['balance'] < 0) $users[$chat_id]['balance'] = 0;
        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
        return $users[$chat_id]['balance'];
    }
    return false;
}

function getServiceConfigs() {
    global $data_dir;
    $file = "$data_dir/service_configs.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function saveServiceConfigs($configs) {
    global $data_dir;
    $file = "$data_dir/service_configs.json";
    file_put_contents($file, json_encode($configs, JSON_PRETTY_PRINT));
}

function getUserState($chat_id) {
    global $data_dir;
    $file = "$data_dir/user_$chat_id.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: ['step' => 'none']) : ['step' => 'none'];
}

function setUserState($chat_id, $data) {
    global $data_dir;
    file_put_contents("$data_dir/user_$chat_id.json", json_encode($data));
}

function getPlatformName($service_name, $category_name) {
    $text = strtolower($service_name . " " . $category_name);
    if (strpos($text, 'tiktok') !== false) return 'TikTok';
    if (strpos($text, 'youtube') !== false) return 'YouTube';
    if (strpos($text, 'facebook') !== false || strpos($text, 'fb') !== false) return 'Facebook';
    if (strpos($text, 'telegram') !== false) return 'Telegram';
    if (strpos($text, 'instagram') !== false) return 'Instagram';
    return 'Others';
}

function sendMessage($chat_id, $text, $keyboard = null, $message_id = null) {
    global $bot_token;
    $method = $message_id ? "editMessageText" : "sendMessage";
    $url = "https://api.telegram.org/bot{$bot_token}/{$method}";
    
    $post_fields = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];
    if ($message_id) { $post_fields['message_id'] = $message_id; }
    if ($keyboard)   { $post_fields['reply_markup'] = json_encode($keyboard); }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function answerCb($cb_id, $msg = "", $show_alert = false) {
    global $bot_token;
    if (!$cb_id) return;
    $url = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['callback_query_id' => $cb_id, 'text' => $msg, 'show_alert' => $show_alert],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    curl_exec($ch);
    curl_close($ch);
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
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// FORCE JOIN MEMBER CHECK
function isSubscribed($chat_id, $channel_id) {
    global $bot_token;
    if (empty($channel_id)) return true;
    
    $channel_id = trim($channel_id);
    $url = "https://api.telegram.org/bot{$bot_token}/getChatMember?chat_id=" . urlencode($channel_id) . "&user_id=" . $chat_id;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['ok']) && $res['ok'] === true) {
        $status = $res['result']['status'] ?? '';
        return in_array($status, ['creator', 'administrator', 'member']);
    }

    return false;
}

// ==================== [ DYNAMIC MAIN KEYBOARD BUILDER ] ====================
function getMainMenuKeyboard($chat_id, $admin_id, $settings) {
    global $admin_web_url;

    $active_btns = $settings['btn_active'] ?? [];
    $per_row     = intval($settings['btn_per_row'] ?? 2);

    $all_map = [
        'services'  => "🛒 Services",
        'new_order' => "➕ New Order",
        'balance'   => "💰 My Balance",
        'deposit'   => "💳 Deposit",
        'api'       => "🔑 My API",
        'status'    => "🔍 Order Status",
        'refill'    => "♻️ Refill Order"
    ];

    if (!empty($settings['ref_enabled'])) {
        $all_map['referral'] = "👥 Refer & Earn";
    }

    $active_list = [];
    foreach ($all_map as $key => $label) {
        if (!isset($active_btns[$key]) || $active_btns[$key] === true) {
            $active_list[] = ['text' => $label];
        }
    }

    if (empty($active_list)) {
        $active_list = [
            ['text' => "🛒 Services"],
            ['text' => "➕ New Order"],
            ['text' => "💰 My Balance"],
            ['text' => "💳 Deposit"]
        ];
    }

    $keyboard = [];
    if ($per_row == 1) {
        foreach ($active_list as $b) {
            $keyboard[] = [$b];
        }
    } else {
        $chunks = array_chunk($active_list, 2);
        foreach ($chunks as $chunk) {
            $keyboard[] = $chunk;
        }
    }

    // ⚙️ অ্যাডমিন হলে সরাসরি WebApp বাটন যুক্ত হবে
    if ($chat_id == $admin_id) {
        $keyboard[] = [
            [
                'text' => "⚙️ Admin Panel",
                'web_app' => ['url' => $admin_web_url]
            ]
        ];
    }

    return ['keyboard' => $keyboard, 'resize_keyboard' => true];
}

$user_state    = getUserState($chat_id);
$settings      = getSettings();
$main_keyboard = getMainMenuKeyboard($chat_id, $admin_id, $settings);

// ==================== [ FORCE JOIN CHECK ] ====================
if (!empty($settings['force_join_enabled']) && !empty($settings['force_channel_id']) && $chat_id != $admin_id) {
    if (!isSubscribed($chat_id, $settings['force_channel_id'])) {
        if ($callback_data == 'check_force_join') {
            answerCb($cb_id, "⚠️ You have not joined the channel yet!", true);
        }

        $channel_link = !empty($settings['force_channel_link']) ? $settings['force_channel_link'] : "https://t.me/" . ltrim($settings['force_channel_id'], '@');
        
        $join_kb = [
            'inline_keyboard' => [
                [['text' => "📢 Join Channel", 'url' => $channel_link]],
                [['text' => "🔄 Verify Membership", 'callback_data' => 'check_force_join']]
            ]
        ];

        sendMessage($chat_id, "⚠️ <b>Access Denied!</b>\n\nYou must join our official updates channel to use this bot.\n\nClick the button below to join, then click <b>Verify Membership</b>.", $join_kb);
        exit();
    }
}

if ($callback_data == 'check_force_join') {
    answerCb($cb_id, "✅ Verified! Welcome.");
    sendMessage($chat_id, "✅ <b>Thank you for joining!</b> You can now use the bot.", $main_keyboard);
    exit();
}

// ==================== [ START / RESET COMMAND ] ====================
if (strpos($text, '/start') === 0 || $text == "❌ Cancel / Reset") {
    setUserState($chat_id, ['step' => 'none']);
    
    $parts = explode(' ', $text);
    $referrer_id = (isset($parts[1]) && strpos($parts[1], 'ref_') === 0) ? intval(str_replace('ref_', '', $parts[1])) : null;

    registerUser($chat_id, $first_name, $referrer_id);
    $user_balance = getUserBalance($chat_id);
    
    $msg = "👋 <b>Welcome to SMM Services Bot!</b>\n\n";
    $msg .= "👤 <b>User:</b> {$first_name}\n";
    $msg .= "💰 <b>Your Balance:</b> \$" . number_format($user_balance, 2) . "\n\n";
    $msg .= "Select an option from the menu below:";
    
    sendMessage($chat_id, $msg, $main_keyboard);
    exit();
}

registerUser($chat_id, $first_name);

// ==================== [ 💳 DEPOSIT MENU ] ====================
if ($text == "💳 Deposit") {
    $admin_user = ltrim($settings['admin_username'], '@');
    $dep_kb = [
        'inline_keyboard' => [
            [['text' => "👨‍💻 Contact Admin for Deposit", 'url' => "https://t.me/{$admin_user}"]]
        ]
    ];

    $msg = "💳 <b>Deposit Funds</b>\n\n";
    $msg .= "To add funds to your account balance, please contact our Admin directly.\n\n";
    $msg .= "🔹 <b>Accepted Payment Methods:</b> Manual Mobile Banking, Crypto, Cards.\n";
    $msg .= "🔹 <b>Instant Credit:</b> Send your Chat ID (<code>{$chat_id}</code>) to Admin after payment.\n\n";
    $msg .= "Click the button below to message Admin:";

    sendMessage($chat_id, $msg, $dep_kb);
    exit();
}

// ==================== [ 🔑 MY API MENU ] ====================
if ($text == "🔑 My API") {
    $users = getUsers();
    $api_key = $users[$chat_id]['api_key'] ?? 'N/A';

    $api_kb = [
        'inline_keyboard' => [
            [['text' => "🔄 Regenerate New API Key", 'callback_data' => 'usr_regen_api']]
        ]
    ];

    $msg = "🔑 <b>Your API Integration Details</b>\n\n";
    $msg .= "Use this API to connect your own Telegram Bot or Website with our panel:\n\n";
    $msg .= "🌐 <b>API Endpoint URL:</b>\n<code>{$my_domain_api_url}</code>\n\n";
    $msg .= "🔑 <b>Your Secret API Key:</b>\n<code>{$api_key}</code>\n\n";
    $msg .= "💡 <i>Keep your API key private. When orders come from your Child Bot, charges will be automatically deducted from your account balance here.</i>";

    sendMessage($chat_id, $msg, $api_kb);
    exit();
}

if ($callback_data == 'usr_regen_api') {
    answerCb($cb_id, "New API Key Generated!");
    $users = getUsers();
    $new_key = bin2hex(random_bytes(16));
    $users[$chat_id]['api_key'] = $new_key;
    file_put_contents("$data_dir/users.json", json_encode($users, JSON_PRETTY_PRINT));

    $msg = "🔑 <b>Your API Integration Details</b>\n\n";
    $msg .= "🌐 <b>API Endpoint URL:</b>\n<code>{$my_domain_api_url}</code>\n\n";
    $msg .= "🔑 <b>Your New API Key:</b>\n<code>{$new_key}</code>\n\n";
    $msg .= "✅ Key updated successfully!";

    $api_kb = ['inline_keyboard' => [[['text' => "🔄 Regenerate New API Key", 'callback_data' => 'usr_regen_api']]]];
    sendMessage($chat_id, $msg, $api_kb, $cb_message_id);
    exit();
}

// ==================== [ ⚙️ ADMIN PANEL WEBAPP FALLBACK ] ====================
if (($text == "⚙️ Admin Panel" || $text == "/admin") && $chat_id == $admin_id) {
    $inline_admin = [
        'inline_keyboard' => [
            [['text' => "🌐 Open Admin Web Dashboard", 'web_app' => ['url' => $admin_web_url]]]
        ]
    ];
    sendMessage($chat_id, "⚙️ <b>Admin Control Panel</b>\n\nনিচের বাটনে ক্লিক করে ওয়েব এডমিন প্যানেলটি খুলুন:", $inline_admin);
    exit();
}

// ==================== [ USER REFER & EARN ] ====================
if ($text == "👥 Refer & Earn") {
    if (empty($settings['ref_enabled'])) { exit(); }
    $bot_uname = $update['message']['via_bot']['username'] ?? "YourBot";
    $ref_link = "https://t.me/{$bot_uname}?start=ref_{$chat_id}";
    sendMessage($chat_id, "👥 <b>Refer & Earn Link:</b>\n<code>{$ref_link}</code>\n\nReward: \${$settings['ref_bonus']} per invited active user.", $main_keyboard);
    exit();
}

// ==================== [ USER CATEGORY & ORDER FLOW ] ====================
if ($text == "🛒 Services" || $text == "➕ New Order") {
    $cat_keyboard = [
        'inline_keyboard' => [
            [['text' => "🎵 TikTok", 'callback_data' => 'usr_plat_TikTok'], ['text' => "📺 YouTube", 'callback_data' => 'usr_plat_YouTube']],
            [['text' => "📘 Facebook", 'callback_data' => 'usr_plat_Facebook'], ['text' => "✈️ Telegram", 'callback_data' => 'usr_plat_Telegram']],
            [['text' => "📷 Instagram", 'callback_data' => 'usr_plat_Instagram'], ['text' => "🌐 Others", 'callback_data' => 'usr_plat_Others']]
        ]
    ];
    sendMessage($chat_id, "📂 <b>Select Social Media Platform:</b>", $cat_keyboard);
    exit();
}

if ($callback_data && strpos($callback_data, 'usr_plat_') === 0) {
    answerCb($cb_id);
    $platform = str_replace('usr_plat_', '', $callback_data);
    $all_api_services = callSmmGenApi('services');
    $configs = getServiceConfigs();

    $subcategories = [];
    if (is_array($all_api_services)) {
        foreach ($all_api_services as $s) {
            if (getPlatformName($s['name'], $s['category']) == $platform) {
                $srv_id = $s['service'];
                if (isset($configs[$srv_id]) && !empty($configs[$srv_id]['active'])) {
                    if (!in_array($s['category'], $subcategories)) {
                        $subcategories[] = $s['category'];
                    }
                }
            }
        }
    }

    if (empty($subcategories)) {
        sendMessage($chat_id, "❌ No active services available under <b>{$platform}</b> right now.");
        exit();
    }

    $buttons = [];
    $user_state['subcats'] = [];
    foreach ($subcategories as $sub_cat) {
        $short_key = substr(md5($sub_cat), 0, 8);
        $user_state['subcats'][$short_key] = $sub_cat;
        $buttons[] = [['text' => "📁 " . $sub_cat, 'callback_data' => "usr_subcat_{$short_key}"]];
    }
    
    $user_state['step'] = 'none';
    setUserState($chat_id, $user_state);
    sendMessage($chat_id, "📂 <b>Select Category for {$platform}:</b>", ['inline_keyboard' => $buttons]);
    exit();
}

if ($callback_data && strpos($callback_data, 'usr_subcat_') === 0) {
    answerCb($cb_id);
    $short_key = str_replace('usr_subcat_', '', $callback_data);
    $subcat_name = $user_state['subcats'][$short_key] ?? null;

    if (!$subcat_name) {
        sendMessage($chat_id, "⚠️ Session expired. Please select platform again from 🛒 Services.");
        exit();
    }

    $all_api_services = callSmmGenApi('services');
    $configs = getServiceConfigs();

    sendMessage($chat_id, "📋 <b>Active Services in {$subcat_name}:</b>");

    if (is_array($all_api_services)) {
        foreach ($all_api_services as $s) {
            if ($s['category'] == $subcat_name) {
                $srv_id = $s['service'];
                if (isset($configs[$srv_id]) && !empty($configs[$srv_id]['active'])) {
                    $user_rate = $configs[$srv_id]['rate'] ?? $s['rate'];
                    $user_min  = $configs[$srv_id]['min']  ?? $s['min'];
                    $user_max  = $configs[$srv_id]['max']  ?? $s['max'];

                    $msg = "🆔 <b>Service ID:</b> <code>{$srv_id}</code>\n📌 <b>Service:</b> {$s['name']}\n💵 <b>Rate:</b> \${$user_rate} / 1000\n📊 <b>Min:</b> {$user_min} - <b>Max:</b> {$user_max}";
                    sendMessage($chat_id, $msg, ['inline_keyboard' => [[['text' => "🛒 Order Now", 'callback_data' => "usr_ord_{$srv_id}"]]]]);
                }
            }
        }
    }
    exit();
}

if ($callback_data && strpos($callback_data, 'usr_ord_') === 0) {
    answerCb($cb_id);
    $srv_id = str_replace('usr_ord_', '', $callback_data);
    $all_api_services = callSmmGenApi('services');
    $configs = getServiceConfigs();

    $api_srv = null;
    foreach ($all_api_services as $s) { if ($s['service'] == $srv_id) { $api_srv = $s; break; } }

    if ($api_srv && !empty($configs[$srv_id]['active'])) {
        setUserState($chat_id, [
            'step'   => 'usr_awaiting_link',
            'srv_id' => $srv_id,
            'rate'   => $configs[$srv_id]['rate'] ?? $api_srv['rate'],
            'min'    => $configs[$srv_id]['min']  ?? $api_srv['min'],
            'max'    => $configs[$srv_id]['max']  ?? $api_srv['max'],
            'name'   => $api_srv['name']
        ]);
        sendMessage($chat_id, "🔗 Send your <b>Target Link/URL</b> (Video/Post/Profile Link):");
    }
    exit();
}

if ($user_state['step'] == 'usr_awaiting_link') {
    setUserState($chat_id, [
        'step'   => 'usr_awaiting_qty',
        'srv_id' => $user_state['srv_id'],
        'link'   => $text,
        'rate'   => $user_state['rate'],
        'min'    => $user_state['min'],
        'max'    => $user_state['max'],
        'name'   => $user_state['name']
    ]);
    sendMessage($chat_id, "🔢 <b>Manual Quantity Input:</b>\n\nEnter the exact number of Likes / Views / Followers / Comments you want:\n\n📊 <i>Allowed Range: Min {$user_state['min']} to Max {$user_state['max']}</i>");
    exit();
}

if ($user_state['step'] == 'usr_awaiting_qty') {
    $qty = intval($text);
    if ($qty < $user_state['min'] || $qty > $user_state['max']) {
        sendMessage($chat_id, "⚠️ Invalid quantity! Please enter a number between {$user_state['min']} and {$user_state['max']}:");
        exit();
    }

    $total_cost = ($user_state['rate'] / 1000) * $qty;
    setUserState($chat_id, [
        'step'       => 'usr_confirm_order',
        'srv_id'     => $user_state['srv_id'],
        'link'       => $user_state['link'],
        'qty'        => $qty,
        'rate'       => $user_state['rate'],
        'total_cost' => $total_cost,
        'name'       => $user_state['name']
    ]);

    $confirm_kb = [
        'inline_keyboard' => [
            [['text' => "📊 Check Amount & Breakdown", 'callback_data' => 'usr_check_amount']],
            [['text' => "✅ Confirm Order (\$" . number_format($total_cost, 4) . ")", 'callback_data' => 'usr_place_order_now']],
            [['text' => "❌ Cancel Order", 'callback_data' => 'usr_cancel_order_now']]
        ]
    ];
    
    $msg = "📋 <b>Order Ready for Confirmation!</b>\n\n";
    $msg .= "📌 <b>Service:</b> {$user_state['name']}\n";
    $msg .= "🔢 <b>Entered Quantity:</b> " . number_format($qty) . "\n";
    $msg .= "💵 <b>Calculated Charge:</b> \$" . number_format($total_cost, 4) . "\n\n";
    $msg .= "👉 Click <b>📊 Check Amount & Breakdown</b> to view detailed calculation or <b>✅ Confirm Order</b> to place now:";

    sendMessage($chat_id, $msg, $confirm_kb);
    exit();
}

// 📊 CHECK AMOUNT BREAKDOWN CALLBACK
if ($callback_data == 'usr_check_amount' && $user_state['step'] == 'usr_confirm_order') {
    $qty        = $user_state['qty'];
    $rate       = $user_state['rate'];
    $total_cost = $user_state['total_cost'];
    $my_bal     = getUserBalance($chat_id);
    $rem_bal    = $my_bal - $total_cost;

    $alert_txt  = "📊 Calculation Breakdown:\n";
    $alert_txt .= "• Quantity: " . number_format($qty) . "\n";
    $alert_txt .= "• Rate per 1k: $" . number_format($rate, 4) . "\n";
    $alert_txt .= "• Total Cost: $" . number_format($total_cost, 4) . "\n";
    $alert_txt .= "• Your Balance: $" . number_format($my_bal, 4);

    answerCb($cb_id, $alert_txt, true);

    $confirm_kb = [
        'inline_keyboard' => [
            [['text' => "✅ Place Order (\$" . number_format($total_cost, 4) . ")", 'callback_data' => 'usr_place_order_now']],
            [['text' => "❌ Cancel Order", 'callback_data' => 'usr_cancel_order_now']]
        ]
    ];

    $msg  = "📊 <b>Detailed Cost Breakdown:</b>\n\n";
    $msg .= "📌 <b>Service:</b> {$user_state['name']}\n";
    $msg .= "🔗 <b>Target Link:</b> <code>{$user_state['link']}</code>\n";
    $msg .= "🔢 <b>Selected Quantity:</b> " . number_format($qty) . "\n";
    $msg .= "💵 <b>Rate per 1,000:</b> \$" . number_format($rate, 4) . "\n";
    $msg .= "-------------------------------------\n";
    $msg .= "💳 <b>Total Payable Amount:</b> \$" . number_format($total_cost, 4) . "\n";
    $msg .= "💰 <b>Your Current Balance:</b> \$" . number_format($my_bal, 4) . "\n";
    
    if ($rem_bal >= 0) {
        $msg .= "📉 <b>Remaining Balance After Purchase:</b> \$" . number_format($rem_bal, 4) . "\n\n";
        $msg .= "✅ Everything looks good! Click confirm to complete purchase.";
    } else {
        $msg .= "⚠️ <b>Shortage Balance:</b> \$" . number_format(abs($rem_bal), 4) . "\n\n";
        $msg .= "❌ You do not have enough balance for this order. Please deposit first!";
    }

    sendMessage($chat_id, $msg, $confirm_kb, $cb_message_id);
    exit();
}

if ($callback_data == 'usr_place_order_now' && $user_state['step'] == 'usr_confirm_order') {
    answerCb($cb_id);
    $total_cost = $user_state['total_cost'];
    
    if (getUserBalance($chat_id) < $total_cost) {
        setUserState($chat_id, ['step' => 'none']);
        sendMessage($chat_id, "❌ <b>Insufficient Balance!</b> Required: \$" . number_format($total_cost, 4) . "\n\nPlease click 💳 Deposit to add funds.", $main_keyboard);
        exit();
    }

    $res = callSmmGenApi('add', ['service' => $user_state['srv_id'], 'link' => $user_state['link'], 'quantity' => $user_state['qty']]);

    if (isset($res['order'])) {
        updateUserBalance($chat_id, -$total_cost);
        $msg = "✅ <b>Order Placed Successfully!</b>\n🆔 <b>Order ID:</b> <code>{$res['order']}</code>\n💳 <b>Cost Deducted:</b> \$" . number_format($total_cost, 4);
    } else {
        $msg = "❌ Order Failed: " . ($res['error'] ?? "Unknown provider error");
    }

    setUserState($chat_id, ['step' => 'none']);
    sendMessage($chat_id, $msg, $main_keyboard);
    exit();
}

if ($callback_data == 'usr_cancel_order_now') {
    setUserState($chat_id, ['step' => 'none']);
    sendMessage($chat_id, "❌ Order cancelled.", $main_keyboard);
    exit();
}

// 💰 MY BALANCE
if ($text == "💰 My Balance") {
    sendMessage($chat_id, "👤 <b>User ID:</b> <code>{$chat_id}</code>\n💰 <b>Current Balance:</b> \$" . number_format(getUserBalance($chat_id), 4), $main_keyboard);
    exit();
}

// 🔍 ORDER STATUS
if ($text == "🔍 Order Status") {
    setUserState($chat_id, ['step' => 'usr_awaiting_status_id']);
    sendMessage($chat_id, "🔍 Send your <b>Order ID</b> to check status:");
    exit();
}

if ($user_state['step'] == 'usr_awaiting_status_id') {
    $res = callSmmGenApi('status', ['order' => intval($text)]);
    $msg = isset($res['status']) ? "⚙️ <b>Order Status:</b> {$res['status']}\n💵 <b>Charge:</b> \${$res['charge']}\n📊 <b>Remains:</b> {$res['remains']}" : "❌ Order ID not found.";
    setUserState($chat_id, ['step' => 'none']);
    sendMessage($chat_id, $msg, $main_keyboard);
    exit();
}

// ♻️ REFILL ORDER
if ($text == "♻️ Refill Order") {
    setUserState($chat_id, ['step' => 'usr_awaiting_refill_id']);
    sendMessage($chat_id, "♻️ Send your <b>Order ID</b> to request a refill:");
    exit();
}

if ($user_state['step'] == 'usr_awaiting_refill_id') {
    $res = callSmmGenApi('refill', ['order' => intval($text)]);
    $msg = isset($res['refill']) ? "✅ <b>Refill Requested!</b> Refill ID: <code>{$res['refill']}</code>" : "❌ Refill Failed: " . ($res['error'] ?? "Not eligible for refill.");
    setUserState($chat_id, ['step' => 'none']);
    sendMessage($chat_id, $msg, $main_keyboard);
    exit();
}
?>
