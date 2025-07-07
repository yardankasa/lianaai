
<?php

// Idea Man , Author , Creator , publisher = https://github.com/yardankasa  , @After_world
// -- # -- ALL RIGHTS Reserved! 
// Follow for more T.me/pejvaksource

// you must put the Critical Keys into a file that name .env-private for more secure ! 
// GEMINI_KEY = ( the Gemini key that you must receive from google ai studio for free!)
// BOT_TOKEN  = your telegram token bot from @Botfather
// Set webhook on this file for sure!

function loadEnv($path = '.env-private') {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
loadEnv();
// ============== تنظيمات اصلی و ثابت‌ها ===============
$telegramBotToken = $_ENV['BOT_TOKEN'];;
echo $telegramBotToken;
// کليد API جمنای خود را در اينجا قرار دهيد
$geminiApiKey = $_ENV['GEMINI_KEY'];;
$geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . $geminiApiKey;

const ADMIN_USER_ID = 1604140942; // <<<<============== آیدی عددی خودتان را اینجا بگذارید
const CREATOR_INFO = "این ربات توسط *مهدی اسکندری* در تیم [پژواک سورس](https://t.me/pejvaksource) توسعه داده شده است.";
const MESSAGE_LIMIT_PER_MINUTE = 3;
const MESSAGE_COOLDOWN_SECONDS = 5;

// مسیر فایل‌ها
const USER_DATA_DIR = __DIR__ . '/user_data/';
const LOG_FILE = __DIR__ . '/error_log.txt';
const STATS_FILE = __DIR__ . '/bot_stats.json';
const CHANNELS_FILE = __DIR__ . '/channels.json';

// ====================================================

// --- توابع اصلی و کمکی ---

function apiRequest($method, $parameters) {
    global $telegramBotToken;
    $url = "https://api.telegram.org/bot" . $telegramBotToken . "/" . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($parameters), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    if ($error || (isset($result['ok']) && !$result['ok'])) {
        logError("Telegram API Error for method '{$method}': " . ($error ?: ($result['description'] ?? 'No description')));
        return $result;
    }
    return $result;
}

function logError($message) {
    file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

function getUserData($userId) {
    $userFile = USER_DATA_DIR . $userId . '.json';
    if (!file_exists($userFile)) {
        return ['timestamps' => [], 'role' => 'default', 'tone' => 'friendly', 'last_seen' => 0, 'status' => '', 'passed_channel_check' => false, 'preferences_set' => false];
    }
    return json_decode(file_get_contents($userFile), true);
}

function saveUserData($userId, $data) {
    $userFile = USER_DATA_DIR . $userId . '.json';
    $allUsersFile = USER_DATA_DIR . 'all_users.txt';
    if (!file_exists($allUsersFile) || strpos(file_get_contents($allUsersFile), strval($userId)) === false) {
        file_put_contents($allUsersFile, $userId . "\n", FILE_APPEND);
    }
    file_put_contents($userFile, json_encode($data, JSON_PRETTY_PRINT));
}

function getChannels() {
    if (!file_exists(CHANNELS_FILE)) return [];
    return json_decode(file_get_contents(CHANNELS_FILE), true);
}

function saveChannels($channels) {
    file_put_contents(CHANNELS_FILE, json_encode(array_values($channels), JSON_PRETTY_PRINT));
}

function checkBotAdminStatusInChannel($channelId) {
    global $telegramBotToken;
    $botId = explode(':', $telegramBotToken)[0];
    $result = apiRequest('getChatMember', ['chat_id' => $channelId, 'user_id' => $botId]);
    return isset($result['ok'], $result['result']['status']) && $result['ok'] === true && $result['result']['status'] === 'administrator';
}

function checkUserSubscription($userId) {
    $channels = getChannels();
    if (empty($channels)) return ['status' => true];
    $channelsToJoin = [];
    $allJoined = true;
    foreach ($channels as $channel) {
        $result = apiRequest('getChatMember', ['chat_id' => $channel['id'], 'user_id' => $userId]);
        $isMember = isset($result['result']['status']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
        if (!$isMember) {
            $allJoined = false;
            $channelsToJoin[] = $channel['id'];
        }
    }
    if ($allJoined) {
        return ['status' => true];
    } else {
        return ['status' => false, 'channels' => $channelsToJoin];
    }
}

function getSystemInstruction($role, $tone, $isTool = false, $toolType = '') {
    if ($isTool) {
        $toolInstructions = [
            'article' => "You are a professional article writer. Your task is to write a well-structured, informative, and engaging article about the user's topic. Use Markdown for headings (#), bold text (**), and lists (*). The article must have an introduction, multiple body paragraphs, and a conclusion. Write in Persian.",
            'instagram' => "You are a social media expert. Your task is to generate a compelling Instagram caption for the user's topic. The caption should be engaging, include relevant emojis, and end with a list of 5-10 highly relevant hashtags. Write in Persian.",
            'email' => "You are a professional assistant. Your task is to write a formal and effective email based on the user's request. The email should have a clear subject line, a proper salutation, a concise body, and a professional closing. Write in Persian."
        ];
        return $toolInstructions[$toolType] ?? "You are a helpful assistant.";
    }
    
    $baseInstruction = "You must strictly follow these rules: 1. Your creator is Mahdi Eskandari from Pejvak Source. 2. Never mention you are a Google model or AI. 3. Always answer in Persian.";
    
    switch ($tone) {
        case 'formal': $baseInstruction .= " 4. Your communication tone must be strictly formal and professional."; break;
        case 'humorous': $baseInstruction .= " 4. Your communication tone should be witty and humorous."; break;
        default: $baseInstruction .= " 4. Your communication tone should be friendly and welcoming."; break;
    }

    $roleInstructions = [
        'default'    => "You are a helpful and friendly general assistant.",
        'programmer' => "You are an expert programmer who provides clean, efficient code and clear explanations.",
        'poet'       => "You are a poet. You must answer in poetic and literary language.",
        'teacher'    => "You are a patient teacher who explains complex topics in a simple and understandable way.",
        'business'   => "You are a business consultant. Provide strategic advice on marketing, management, and growth. Your tone should be professional and data-driven.",
        'fitness'    => "You are a fitness coach. Provide workout routines, and nutritional advice. Your tone should be motivating and encouraging.",
        'chef'       => "You are a professional chef. Provide delicious and easy-to-follow recipes. List ingredients clearly and provide step-by-step instructions.",
        'translator' => "You are an expert translator. When the user provides a text, you must ask 'Translate to which language?' and then translate it accurately."
    ];
    return $baseInstruction . ($roleInstructions[$role] ?? $roleInstructions['default']);
}

function getGeminiResponse($prompt, $userId, $isTool = false, $toolType = '') {
    global $geminiApiUrl;
    $userData = getUserData($userId);
    $systemInstruction = getSystemInstruction($userData['role'] ?? 'default', $userData['tone'] ?? 'friendly', $isTool, $toolType);
    $data = ['systemInstruction' => ['parts' => [['text' => $systemInstruction]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]]];
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $geminiApiUrl, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 90]);
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    if ($curlError || $httpCode !== 200) {
        logError("Gemini API Error: HTTP {$httpCode} - cURL: {$curlError} - Response: " . $response);
        return "متاسفانه در ارتباط با سرویس هوش مصنوعی خطایی رخ داد.";
    }
    $result = json_decode($response, true);
    $aiResponse = $result['candidates'][0]['content']['parts'][0]['text'] ?? "پاسخی دریافت نشد.";
    incrementMessageCount();
    return $aiResponse;
}

function checkGeminiApiStatus() {
    global $geminiApiUrl;
    $data = ['contents' => [['parts' => [['text' => 'سلام']]]]];
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $geminiApiUrl, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    if ($curlError) return "🔴 *خطای اتصال:* `{$curlError}`";
    if ($httpCode === 200) return "✅ *سرویس فعال است.* (کد ۲۰۰)";
    $result = json_decode($response, true);
    $errorMessage = $result['error']['message'] ?? 'پاسخ نامشخص';
    return "🟠 *خطای سرویس:*\nکد: `{$httpCode}`\nپیام: `{$errorMessage}`";
}

function checkRateLimit($userId) {
    $now = time();
    $data = getUserData($userId);
    $data['timestamps'] = array_values(array_filter($data['timestamps'] ?? [], function($ts) use ($now) { return ($now - $ts) < 60; }));
    if (!empty($data['timestamps']) && ($now - end($data['timestamps'])) < MESSAGE_COOLDOWN_SECONDS) {
        return ['status' => false, 'message' => "⏳ لطفاً " . MESSAGE_COOLDOWN_SECONDS . " ثانیه صبر کنید."];
    }
    if (count($data['timestamps']) >= MESSAGE_LIMIT_PER_MINUTE) {
        $timeLeft = (isset($data['timestamps'][0]) ? ($data['timestamps'][0] + 60) : $now) - $now;
        return ['status' => false, 'message' => "🚫 شما به حد مجاز رسیده‌اید. لطفاً *" . $timeLeft . " ثانیه* دیگر تلاش کنید."];
    }
    $data['timestamps'][] = $now;
    saveUserData($userId, $data);
    return ['status' => true];
}

function incrementMessageCount() {
    $stats = file_exists(STATS_FILE) ? json_decode(file_get_contents(STATS_FILE), true) : ['total_messages' => 0];
    $stats['total_messages'] = ($stats['total_messages'] ?? 0) + 1;
    file_put_contents(STATS_FILE, json_encode($stats));
}

function showMainMenu($chatId, $messageId = null, $text = "به منوی اصلی بازگشتید. چه کاری برایتان انجام دهم؟") {
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🧠 ابزارها و نقش‌ها', 'callback_data' => 'features_menu']],
        [['text' => '❓ راهنما', 'callback_data' => 'guide'], ['text' => '🧑‍💻 درباره ما', 'callback_data' => 'creator']],
        [['text' => '⚙️ تنظیمات لحن گفتگو', 'callback_data' => 'settings_menu']]
    ]]);
    if ($messageId) {
        apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => $keyboard, 'parse_mode' => 'Markdown']);
    } else {
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $keyboard, 'parse_mode' => 'Markdown']);
    }
}

// --- منطق اصلی ربات ---
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit();

// --- پردازش Callback Query ---
if (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $userId = $callbackQuery['from']['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackQuery['id']]);
    $userData = getUserData($userId);

    if ($data === 'check_join_again') {
        $subscriptionCheck = checkUserSubscription($userId);
        if ($subscriptionCheck['status']) {
            apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
            $userData = getUserData($userId); // Get fresh data
            if (!($userData['passed_channel_check'] ?? false)) {
                $currentChannels = getChannels();
                foreach ($currentChannels as $key => $channel) { $currentChannels[$key]['join_checks'] = ($channel['join_checks'] ?? 0) + 1; }
                saveChannels($currentChannels);
                $userData['passed_channel_check'] = true;
                saveUserData($userId, $userData);
            }
            $setupKeyboard = json_encode(['inline_keyboard' => [
                [['text' => '😊 دوستانه', 'callback_data' => 'set_tone_friendly']],
                [['text' => '👔 رسمی', 'callback_data' => 'set_tone_formal']],
                [['text' => '😂 طنز', 'callback_data' => 'set_tone_humorous']]
            ]]);
            apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ عضویت شما تایید شد. خوش آمدید!\n\nبرای شروع، لطفاً لحن گفتگوی مورد علاقه خود را انتخاب کنید:", 'reply_markup' => $setupKeyboard]);
        } else {
            apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => 'هنوز عضو تمام کانال‌ها نشده‌اید.']);
        }
        exit();
    }
    
    if (strpos($data, 'set_tone_') === 0) {
        $tone = substr($data, 9);
        $userData['tone'] = $tone;
        $userData['preferences_set'] = true;
        saveUserData($userId, $userData);
        showMainMenu($chatId, $messageId, "✅ تنظیمات ذخیره شد!\n\nسلام! 👋 به دستیار هوشمند خودتان خوش آمدید.");
        exit();
    }

    if ($userId === ADMIN_USER_ID) {
        $adminPanelKeyboard = json_encode(['inline_keyboard' => [
            [['text' => '📊 آمار ربات', 'callback_data' => 'admin_stats']],
            [['text' => '📢 ارسال همگانی', 'callback_data' => 'admin_broadcast']],
            [['text' => '📡 وضعیت API جمنای', 'callback_data' => 'admin_api_status']],
            [['text' => 'مدیریت کانال‌ها 📢', 'callback_data' => 'admin_channels_menu']]
        ]]);
        
        switch($data) {
            case 'admin_stats':
                $totalUsers = count(glob(USER_DATA_DIR . '*.json'));
                $stats = file_exists(STATS_FILE) ? json_decode(file_get_contents(STATS_FILE), true) : ['total_messages' => 0, 'blocked_users' => 0];
                $totalMessages = $stats['total_messages'] ?? 0;
                $blockedUsersCount = $stats['blocked_users'] ?? 0;
                $activeToday = 0;
                $allUserFiles = glob(USER_DATA_DIR . '*.json');
                foreach ($allUserFiles as $file) {
                    $uData = json_decode(file_get_contents($file), true);
                    if (isset($uData['last_seen']) && (time() - $uData['last_seen']) < 86400) { $activeToday++; }
                }
                $statsText = "📊 *آمار دقیق ربات:*\n\n👤 *کل کاربران:* {$totalUsers}\n☀️ *فعال امروز:* {$activeToday}\n💬 *کل پیام‌ها:* {$totalMessages}\n🚫 *بلاک کرده:* {$blockedUsersCount}";
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $statsText, 'parse_mode' => 'Markdown']);
                exit();

            case 'admin_broadcast':
                $userData['status'] = 'awaiting_broadcast';
                saveUserData($userId, $userData);
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "لطفاً پیام همگانی خود را ارسال کنید."]);
                exit();

            case 'admin_api_status':
                $statusMessage = checkGeminiApiStatus();
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $statusMessage, 'parse_mode' => 'Markdown']);
                exit();
            
            case 'admin_back_to_panel':
                apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "⚙️ *پنل مدیریت ربات*", 'reply_markup' => $adminPanelKeyboard, 'parse_mode' => 'Markdown']);
                exit();

            case 'admin_channels_menu':
                $keyboard = json_encode(['inline_keyboard' => [
                    [['text' => 'افزودن کانال ➕', 'callback_data' => 'admin_add_channel']],
                    [['text' => 'حذف کانال ➖', 'callback_data' => 'admin_remove_channel_menu']],
                    [['text' => 'لیست و آمار 📊', 'callback_data' => 'admin_list_channels']],
                    [['text' => '➡️ بازگشت', 'callback_data' => 'admin_back_to_panel']]
                ]]);
                apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "⚙️ *مدیریت کانال‌ها*", 'reply_markup' => $keyboard, 'parse_mode' => 'Markdown']);
                exit();
            
            case 'admin_add_channel':
                $userData['status'] = 'awaiting_add_channel';
                saveUserData($userId, $userData);
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "لطفاً نام کاربری کانال را با @ ارسال کنید."]);
                exit();

            case 'admin_list_channels':
                $channels = getChannels();
                $text = empty($channels) ? "هیچ کانالی تنظیم نشده." : "📊 *لیست و آمار کانال‌ها:*\n\n";
                foreach ($channels as $channel) {
                    $text .= "- شناسه: `{$channel['id']}` | عضو شده‌ها: *{$channel['join_checks']}* نفر\n";
                }
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
                exit();

            case 'admin_remove_channel_menu':
                $channels = getChannels();
                if (empty($channels)) { apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "کانالی برای حذف وجود ندارد."]); exit(); }
                $keyboard = [];
                foreach ($channels as $channel) $keyboard[] = [['text' => "❌ " . $channel['id'], 'callback_data' => 'admin_remove_' . $channel['id']]];
                apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "کانال مورد نظر برای حذف را انتخاب کنید:", 'reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
                exit();
        }
        
        if (strpos($data, 'admin_remove_') === 0) {
            $channelIdToRemove = substr($data, 13);
            $channels = array_filter(getChannels(), function($c) use ($channelIdToRemove) { return $c['id'] !== $channelIdToRemove; });
            saveChannels($channels);
            apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "✅ کانال `{$channelIdToRemove}` حذف شد.", 'parse_mode' => 'Markdown']);
            exit();
        }
    }
    
    if ($data === 'back_to_main') { showMainMenu($chatId, $messageId); exit(); }
    if ($data === 'features_menu') {
        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '🎭 نقش‌های گفتگو', 'callback_data' => 'roles_menu']],
            [['text' => '📝 ابزارهای تولید محتوا', 'callback_data' => 'tools_menu']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'back_to_main']]
        ]]);
        apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "لطفاً یکی از دسته‌بندی‌های زیر را انتخاب کنید:", 'reply_markup' => $keyboard]);
        exit();
    }
    if ($data === 'roles_menu') {
        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '💬 عمومی', 'callback_data' => 'role_default'], ['text' => '💻 برنامه‌نویس', 'callback_data' => 'role_programmer']],
            [['text' => '✍️ شاعر', 'callback_data' => 'role_poet'], ['text' => '🧑‍🏫 معلم', 'callback_data' => 'role_teacher']],
            [['text' => '📈 مشاور کسب‌وکار', 'callback_data' => 'role_business'], ['text' => '💪 مربی ورزشی', 'callback_data' => 'role_fitness']],
            [['text' => '🧑‍🍳 آشپز', 'callback_data' => 'role_chef'], ['text' => '🌐 مترجم', 'callback_data' => 'role_translator']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'features_menu']]
        ]]);
        apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "دوست دارید در چه نقشی با شما صحبت کنم؟", 'reply_markup' => $keyboard]);
        exit();
    }
    if ($data === 'tools_menu') {
        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '✍️ نویسنده مقاله', 'callback_data' => 'tool_article']],
            [['text' => '📝 پست اینستاگرام', 'callback_data' => 'tool_instagram']],
            [['text' => '✉️ ایمیل حرفه‌ای', 'callback_data' => 'tool_email']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'features_menu']]
        ]]);
        apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "کدام ابزار تولید محتوا را نیاز دارید؟", 'reply_markup' => $keyboard]);
        exit();
    }
    if ($data === 'settings_menu') {
        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '😊 دوستانه', 'callback_data' => 'set_tone_friendly']],
            [['text' => '👔 رسمی', 'callback_data' => 'set_tone_formal']],
            [['text' => '😂 طنز', 'callback_data' => 'set_tone_humorous']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'back_to_main']]
        ]]);
        apiRequest('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => "لحن گفتگوی ربات را انتخاب کنید:", 'reply_markup' => $keyboard]);
        exit();
    }
    if (strpos($data, 'role_') === 0) {
        $role = substr($data, 5);
        $userData['role'] = $role;
        saveUserData($userId, $userData);
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ نقش ربات با موفقیت تغییر کرد.\n*نقش فعلی:* " . ucfirst($role), 'parse_mode' => 'Markdown']);
    } elseif ($data === 'creator') {
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => CREATOR_INFO, 'parse_mode' => 'Markdown']);
    } elseif ($data === 'guide') {
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "راهنما:\n- با ارسال هر پیام، می‌توانید گفتگو را شروع کنید.\n- از منوی /start می‌توانید ابزارها و نقش‌های مختلف را انتخاب کنید.", 'parse_mode' => 'Markdown']);
    }
    exit();
}


// --- پردازش Message ---
if (isset($update['message'])) {
    $message = $update['message'];
    $userId = $message['from']['id'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    
    $userData = getUserData($userId);

    if ($userId === ADMIN_USER_ID) {
        if ($text === '/panel') {
            $keyboard = json_encode(['inline_keyboard' => [
                [['text' => '📊 آمار ربات', 'callback_data' => 'admin_stats']],
                [['text' => '📢 ارسال همگانی', 'callback_data' => 'admin_broadcast']],
                [['text' => '📡 وضعیت API جمنای', 'callback_data' => 'admin_api_status']],
                [['text' => 'مدیریت کانال‌ها 📢', 'callback_data' => 'admin_channels_menu']]
            ]]);
            apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "⚙️ *پنل مدیریت ربات*", 'reply_markup' => $keyboard, 'parse_mode' => 'Markdown']);
            exit();
        }

        if (isset($userData['status'])) {
            if ($userData['status'] === 'awaiting_add_channel') {
                if (strpos($text, '@') !== 0) {
                    apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "فرمت اشتباه است. با @ ارسال کنید."]);
                } elseif (!checkBotAdminStatusInChannel($text)) {
                    apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "❌ خطا: ربات در کانال `{$text}` ادمین نیست.", 'parse_mode' => 'Markdown']);
                } else {
                    $channels = getChannels();
                    $channels[] = ['id' => $text, 'join_checks' => 0];
                    saveChannels($channels);
                    apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ کانال `{$text}` اضافه شد.", 'parse_mode' => 'Markdown']);
                }
                $userData['status'] = '';
                saveUserData($userId, $userData);
                exit();
            }
            if ($userData['status'] === 'awaiting_broadcast') {
                $allUsersFile = USER_DATA_DIR . 'all_users.txt';
                if (file_exists($allUsersFile)) {
                    $users = array_filter(explode("\n", file_get_contents($allUsersFile)));
                    $sentCount = 0; $blockedCount = 0;
                    apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "⏳ در حال ارسال پیام همگانی..."]);
                    foreach ($users as $targetUserId) {
                        if (trim($targetUserId) === '') continue;
                        $result = apiRequest('sendMessage', ['chat_id' => trim($targetUserId), 'text' => $text, 'parse_mode' => 'Markdown']);
                        if ($result && isset($result['ok']) && !$result['ok'] && ($result['error_code'] == 403 || $result['error_code'] == 400)) {
                             $blockedCount++;
                        } else { $sentCount++; }
                        usleep(100000);
                    }
                    $stats = file_exists(STATS_FILE) ? json_decode(file_get_contents(STATS_FILE), true) : [];
                    $stats['blocked_users'] = ($stats['blocked_users'] ?? 0) + $blockedCount;
                    file_put_contents(STATS_FILE, json_encode($stats));
                    apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ پیام برای {$sentCount} کاربر ارسال شد.\n{$blockedCount} کاربر ربات را مسدود کرده بودند."]);
                }
                $userData['status'] = '';
                saveUserData($userId, $userData);
                exit();
            }
        }
    }
    
    $userData['last_seen'] = time();
    saveUserData($userId, $userData);
    
    $subscriptionCheck = checkUserSubscription($userId);
    if (!$subscriptionCheck['status']) {
        $keyboard = [];
        foreach ($subscriptionCheck['channels'] as $channelId) $keyboard[] = [['text' => 'عضویت در ' . $channelId, 'url' => 'https://t.me/' . ltrim($channelId, '@')]];
        $keyboard[] = [['text' => '✅ عضو شدم', 'callback_data' => 'check_join_again']];
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "❗️برای استفاده از ربات، در کانال‌های زیر عضو شوید:", 'reply_markup' => json_encode(['inline_keyboard' => $keyboard])]);
        exit();
    }
    
    if (!($userData['preferences_set'] ?? false)) {
        $setupKeyboard = json_encode(['inline_keyboard' => [
            [['text' => '😊 دوستانه', 'callback_data' => 'set_tone_friendly']],
            [['text' => '👔 رسمی', 'callback_data' => 'set_tone_formal']],
            [['text' => '😂 طنز', 'callback_data' => 'set_tone_humorous']]
        ]]);
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "👋 سلام! قبل از شروع، لطفاً لحن گفتگوی مورد علاقه خود را انتخاب کنید:", 'reply_markup' => $setupKeyboard]);
        exit();
    }
    
    $rateLimitResult = checkRateLimit($userId);
    if (!$rateLimitResult['status']) {
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $rateLimitResult['message'], 'parse_mode' => 'Markdown']);
        exit();
    }
    
    if (isset($userData['status']) && strpos($userData['status'], 'awaiting_tool_') === 0) {
        $toolType = substr($userData['status'], 14);
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "✅ دریافت شد. در حال تولید محتوا با ابزار *{$toolType}*...", 'parse_mode' => 'Markdown']);
        apiRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        $aiResponse = getGeminiResponse($text, $userId, true, $toolType);
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $aiResponse, 'parse_mode' => 'Markdown']);
        $userData['status'] = '';
        saveUserData($userId, $userData);
        exit();
    }

    if ($text === '/start') {
        showMainMenu($chatId, null, "سلام! به دستیار هوشمند خودتان خوش آمدید.");
    } else {
        apiRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        $aiResponse = getGeminiResponse($text, $userId);
        apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $aiResponse, 'parse_mode' => 'Markdown']);
    }
}

exit();

