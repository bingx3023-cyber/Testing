<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| STAR SHOP FINAL FIXED - SINGLE FILE TELEGRAM WEBHOOK BOT
|--------------------------------------------------------------------------
| فقط این موارد را تنظیم کن:
| BOT_TOKEN
| ADMIN_IDS
| WEBHOOK_SECRET اختیاری
| BOT_USERNAME اختیاری
|--------------------------------------------------------------------------
| اجرای ربات فقط با Webhook
| پنل مدیریت با دکمه «🛠 مدیریت» داخل /start باز می‌شود.
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);
date_default_timezone_set('Asia/Tehran');

/* ===================== CONFIG ===================== */

define('BOT_TOKEN', '7656448308:AAEPkCNpBtiiw70r-pKuFPdWo6StZnBTeEE');
define('ADMIN_IDS',[6454282779]);
define('WEBHOOK_SECRET', '');
define('BOT_USERNAME', '');

define('DB_FILE', __DIR__ . '/starjjhshs_shwhwhop_final_fixed.sqlite');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

if (BOT_TOKEN === '' || BOT_TOKEN === 'PUT_YOUR_BOT_TOKEN_HERE') {
    http_response_code(500);
    exit('Set BOT_TOKEN first');
}

/* ===================== BASIC HELPERS ===================== */

function sw(string $text, string $prefix): bool
{
    return substr($text, 0, strlen($prefix)) === $prefix;
}

function now_time(): string
{
    return date('Y-m-d H:i:s');
}

function today_date(): string
{
    return date('Y-m-d');
}

function cut(string $text, int $limit = 4096): string
{
    $text = trim($text);
    if ($text === '') {
        return '…';
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

function fmt(int $n): string
{
    return number_format($n);
}

function norm_digits(string $text): string
{
    return strtr($text, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ',' => '', '،' => '', '٬' => '', '‌' => ' ',
    ]);
}

function to_amount(string $text): int
{
    $text = norm_digits(trim($text));

    if ($text === '') {
        return 0;
    }

    if (preg_match('/^-?\d+$/', $text)) {
        return (int)$text;
    }

    preg_match_all('/\d+/', $text, $m);

    return $m[0] ? (int)implode('', $m[0]) : 0;
}

function status_fa(string $status): string
{
    return [
        'pending' => 'در انتظار ارسال',
        'delivered' => 'ارسال‌شده',
        'auto_delivered' => 'تحویل خودکار',
        'canceled' => 'لغوشده',
        'approved' => 'تایید شده',
        'rejected' => 'رد شده',
        'available' => 'موجود',
        'sold' => 'فروخته‌شده',
        'disabled' => 'غیرفعال',
        'deleted' => 'حذف‌شده',
        'running' => 'در حال ارسال',
        'done' => 'تمام‌شده',
    ][$status] ?? $status;
}

function mode_fa(string $mode): string
{
    return [
        'manual' => 'ثبت سفارش / دستی',
        'stock' => 'مخزنی / تحویل خودکار',
        'hybrid' => 'ترکیبی',
    ][$mode] ?? $mode;
}

/* ===================== DB CONNECT ===================== */

try {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 10000');
} catch (Throwable $e) {
    error_log('DB CONNECT ERROR: ' . $e->getMessage());
    http_response_code(500);
    exit('Database error');
}

function has_col(PDO $pdo, string $table, string $col): bool
{
    $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
    foreach ($rows as $r) {
        if ((string)$r['name'] === $col) {
            return true;
        }
    }
    return false;
}

function add_col(PDO $pdo, string $table, string $col, string $definition): void
{
    if (!has_col($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
    }
}

/* ===================== SETTINGS ===================== */

function default_settings(): array
{
    return [
        'welcome_msg' => "🌟 به فروشگاه خوش آمدی\n\nاز دکمه‌های زیر استفاده کن:",
        'maintenance_msg' => '🔧 ربات در حال بروزرسانی است.',
        'force_join_msg' => '🔒 برای استفاده از ربات باید عضو موارد زیر شوی.',
        'invalid_cmd_msg' => '❌ دستور نامعتبر است.',
        'help_msg' => "ℹ️ راهنما\n\nمحصول را انتخاب کن و با کیف پول پرداخت کن.\nاگر محصول مخزنی باشد فوری تحویل می‌گیری.\nاگر محصول ثبت سفارش باشد، پشتیبانی ارسال می‌کند.",
        'support_msg' => "📞 پیام خودت را برای پشتیبانی بفرست.\nمتن، عکس، فیلم، فایل، ویس، موزیک یا استیکر قابل ارسال است.",
        'support_sent_msg' => '✅ پیام شما برای پشتیبانی ارسال شد.',
        'order_pending_msg' => "✅ سفارش شما ثبت شد.\n⏳ پس از بررسی توسط پشتیبانی ارسال می‌شود.",
        'order_auto_msg' => '✅ سفارش شما با موفقیت پرداخت و خودکار تحویل شد.',
        'low_balance_msg' => '❌ موجودی کیف پول کافی نیست.',
        'out_stock_msg' => '❌ موجودی این محصول تمام شده است.',
        'topup_start_msg' => '➕ کارت پرداخت را انتخاب کن:',
        'topup_amount_msg' => '💰 مبلغ شارژ را به تومان وارد کن:',
        'topup_receipt_msg' => '📸 رسید پرداخت را ارسال کن.',
        'topup_sent_msg' => '✅ درخواست افزایش موجودی ثبت شد.',
        'topup_no_card_msg' => '❌ فعلاً کارت پرداختی ثبت نشده است.',

        'btn_buy' => '🛍 خرید محصول',
        'btn_search' => '🔍 جستجو',
        'btn_profile' => '👤 پروفایل',
        'btn_wallet' => '💰 کیف پول',
        'btn_topup' => '➕ شارژ کیف پول',
        'btn_orders' => '📦 سفارش‌های من',
        'btn_support' => '📞 پشتیبانی',
        'btn_join' => '📢 عضویت اجباری',
        'btn_help' => 'ℹ️ راهنما',
        'btn_games' => '🎮 چالش',
        'btn_giveaway' => '🎁 قرعه‌کشی',
        'btn_referral' => '🎁 دعوت دوستان',
        'btn_admin' => '🛠 مدیریت',
        'btn_back' => '🔙 بازگشت',
        'btn_confirm_join' => '✅ تایید عضویت',
        'btn_pay' => '✅ پرداخت از کیف پول',
        'btn_cancel' => '❌ انصراف',

        'enabled_btn_buy' => '1',
        'enabled_btn_search' => '1',
        'enabled_btn_profile' => '1',
        'enabled_btn_wallet' => '1',
        'enabled_btn_topup' => '1',
        'enabled_btn_orders' => '1',
        'enabled_btn_support' => '1',
        'enabled_btn_join' => '1',
        'enabled_btn_help' => '1',
        'enabled_btn_games' => '1',
        'enabled_btn_giveaway' => '1',
        'enabled_btn_referral' => '1',

        'ref_enabled' => '1',
        'ref_join_bonus' => '0',
        'ref_purchase_percent' => '0',
        'ref_first_purchase_only' => '0',
        'ref_require_force_join' => '1',
        'ref_silver_count' => '10',
        'ref_gold_count' => '50',
        'ref_vip_count' => '100',
        'ref_silver_bonus_percent' => '1',
        'ref_gold_bonus_percent' => '2',
        'ref_vip_bonus_percent' => '3',
        'bot_username_cache' => '',
    ];
}

function text_meta(): array
{
    return [
        'welcome_msg' => 'پیام خوشامد',
        'maintenance_msg' => 'پیام نگهداری',
        'force_join_msg' => 'پیام عضویت اجباری',
        'invalid_cmd_msg' => 'پیام نامعتبر',
        'help_msg' => 'متن راهنما',
        'support_msg' => 'متن پشتیبانی',
        'support_sent_msg' => 'متن ارسال پشتیبانی',
        'order_pending_msg' => 'متن ثبت سفارش',
        'order_auto_msg' => 'متن تحویل خودکار',
        'low_balance_msg' => 'متن کمبود موجودی',
        'out_stock_msg' => 'متن اتمام موجودی',
        'topup_start_msg' => 'متن شروع شارژ',
        'topup_amount_msg' => 'متن مبلغ شارژ',
        'topup_receipt_msg' => 'متن رسید شارژ',
        'topup_sent_msg' => 'متن ثبت شارژ',
        'topup_no_card_msg' => 'متن نبود کارت',
    ];
}

function button_meta(): array
{
    return [
        'btn_buy' => 'خرید محصول',
        'btn_search' => 'جستجو',
        'btn_profile' => 'پروفایل',
        'btn_wallet' => 'کیف پول',
        'btn_topup' => 'شارژ کیف پول',
        'btn_orders' => 'سفارش‌های من',
        'btn_support' => 'پشتیبانی',
        'btn_join' => 'عضویت اجباری',
        'btn_help' => 'راهنما',
        'btn_games' => 'چالش',
        'btn_giveaway' => 'قرعه‌کشی',
        'btn_referral' => 'دعوت دوستان',
        'btn_admin' => 'مدیریت',
        'btn_back' => 'بازگشت',
        'btn_confirm_join' => 'تایید عضویت',
        'btn_pay' => 'پرداخت',
        'btn_cancel' => 'انصراف',
    ];
}

/* ===================== DB INIT ===================== */

function db_init(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        first_name TEXT DEFAULT '',
        last_name TEXT DEFAULT '',
        username TEXT DEFAULT '',
        phone TEXT DEFAULT '',
        balance INTEGER NOT NULL DEFAULT 0,
        join_date TEXT DEFAULT '',
        last_seen TEXT DEFAULT '',
        banned INTEGER NOT NULL DEFAULT 0,
        banned_until TEXT DEFAULT '',
        banned_reason TEXT DEFAULT '',
        vip INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        user_id INTEGER PRIMARY KEY,
        role TEXT NOT NULL DEFAULT 'admin',
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cat_id INTEGER NOT NULL DEFAULT 0,
        name TEXT NOT NULL,
        price INTEGER NOT NULL DEFAULT 0,
        description TEXT NOT NULL DEFAULT '',
        photo_file_id TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        delivery_mode TEXT NOT NULL DEFAULT 'manual',
        min_stock_alert INTEGER NOT NULL DEFAULT 3,
        max_per_user_daily INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        item_type TEXT NOT NULL DEFAULT 'text',
        payload_json TEXT NOT NULL DEFAULT '',
        preview TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'available',
        added_by INTEGER NOT NULL DEFAULT 0,
        sold_to INTEGER NOT NULL DEFAULT 0,
        sold_order_id INTEGER NOT NULL DEFAULT 0,
        sold_at TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        price INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        delivery_mode TEXT NOT NULL DEFAULT 'manual',
        payment_method TEXT NOT NULL DEFAULT 'wallet',
        stock_item_id INTEGER NOT NULL DEFAULT 0,
        service_preview TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        delivered_by INTEGER NOT NULL DEFAULT 0,
        delivered_at TEXT NOT NULL DEFAULT '',
        canceled_by INTEGER NOT NULL DEFAULT 0,
        canceled_at TEXT NOT NULL DEFAULT '',
        cancel_reason TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        amount INTEGER NOT NULL,
        type TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT '',
        card_number TEXT NOT NULL DEFAULT '',
        card_holder TEXT NOT NULL DEFAULT '',
        extra_text TEXT NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS topup_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        card_id INTEGER NOT NULL DEFAULT 0,
        amount INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        receipt_preview TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        handled_by INTEGER NOT NULL DEFAULT 0,
        handled_at TEXT NOT NULL DEFAULT '',
        reject_reason TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        rating INTEGER NOT NULL,
        review_text TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        UNIQUE(user_id, product_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS force_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_type TEXT NOT NULL DEFAULT 'channel',
        chat_id TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL DEFAULT '',
        join_link TEXT NOT NULL DEFAULT '',
        checkable INTEGER NOT NULL DEFAULT 1,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_states (
        user_id INTEGER PRIMARY KEY,
        state TEXT NOT NULL DEFAULT '',
        data TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance (
        id INTEGER PRIMARY KEY CHECK(id=1),
        enabled INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mode TEXT NOT NULL DEFAULT 'copy',
        target TEXT NOT NULL DEFAULT 'all',
        users_json TEXT NOT NULL DEFAULT '',
        from_chat_id INTEGER NOT NULL DEFAULT 0,
        message_id INTEGER NOT NULL DEFAULT 0,
        offset_pos INTEGER NOT NULL DEFAULT 0,
        total INTEGER NOT NULL DEFAULT 0,
        sent INTEGER NOT NULL DEFAULT 0,
        failed INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'running',
        created_by INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT '',
        finished_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_settings (
        game_key TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        emoji TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        game_key TEXT NOT NULL,
        result_value INTEGER NOT NULL DEFAULT 0,
        is_win INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        winners_count INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT '',
        winners_json TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS giveaway_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        giveaway_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE(giveaway_id, user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referrer_id INTEGER NOT NULL,
        referred_id INTEGER NOT NULL UNIQUE,
        rewarded_join INTEGER NOT NULL DEFAULT 0,
        first_purchase_rewarded INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referrer_id INTEGER NOT NULL,
        referred_id INTEGER NOT NULL,
        amount INTEGER NOT NULL DEFAULT 0,
        reward_type TEXT NOT NULL DEFAULT '',
        order_id INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL DEFAULT 0,
        action TEXT NOT NULL DEFAULT '',
        details TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT ''
    )");

    foreach (ADMIN_IDS as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $pdo->prepare("INSERT OR IGNORE INTO admins (user_id, role, created_at) VALUES (?, 'owner', ?)")
                ->execute([$id, now_time()]);
        }
    }

    foreach (default_settings() as $key => $value) {
        $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)")
            ->execute([$key, $value]);
    }

    $games = [
        ['dice', '🎲 تاس', '🎲'],
        ['dart', '🎯 دارت', '🎯'],
        ['slot', '🎰 اسلات', '🎰'],
        ['football', '⚽ فوتبال', '⚽'],
        ['basketball', '🏀 بسکتبال', '🏀'],
        ['bowling', '🎳 بولینگ', '🎳'],
    ];

    foreach ($games as $g) {
        $pdo->prepare("INSERT OR IGNORE INTO game_settings (game_key, title, emoji, enabled) VALUES (?, ?, ?, 1)")
            ->execute($g);
    }

    $pdo->exec("INSERT OR IGNORE INTO maintenance (id, enabled) VALUES (1, 0)");
}

db_init($pdo);

/* ===================== TELEGRAM API ===================== */

function tg_json(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api(string $method, array $data = []): array
{
    $ch = curl_init(API_URL . $method);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $err) {
        error_log("TG CURL ERROR {$method}: " . ($err ?: 'unknown'));
        return ['ok' => false, 'description' => $err ?: 'curl error'];
    }

    $json = json_decode((string)$res, true);

    if (!is_array($json)) {
        error_log("TG JSON ERROR {$method}: " . $res);
        return ['ok' => false, 'description' => 'bad json'];
    }

    if (!($json['ok'] ?? false)) {
        error_log("TG API ERROR {$method}: " . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $json;
}

function send_msg($chatId, string $text, ?array $markup = null): array
{
    $data = [
        'chat_id' => $chatId,
        'text' => cut($text),
        'disable_web_page_preview' => true,
    ];

    if ($markup) {
        $data['reply_markup'] = tg_json($markup);
    }

    return api('sendMessage', $data);
}

function send_photo_msg($chatId, string $fileId, string $caption = '', ?array $markup = null): array
{
    $data = [
        'chat_id' => $chatId,
        'photo' => $fileId,
        'caption' => cut($caption, 1024),
    ];

    if ($markup) {
        $data['reply_markup'] = tg_json($markup);
    }

    return api('sendPhoto', $data);
}

function copy_msg($toChatId, $fromChatId, int $messageId): array
{
    return api('copyMessage', [
        'chat_id' => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id' => $messageId,
    ]);
}

function forward_msg($toChatId, $fromChatId, int $messageId): array
{
    return api('forwardMessage', [
        'chat_id' => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id' => $messageId,
    ]);
}

function answer_cb(string $id, string $text = '', bool $alert = false): void
{
    api('answerCallbackQuery', [
        'callback_query_id' => $id,
        'text' => cut($text, 180),
        'show_alert' => $alert,
    ]);
}

/* ===================== SETTINGS + UI ===================== */

function get_setting(string $key): string
{
    global $pdo;

    $s = $pdo->prepare("SELECT value FROM settings WHERE key=?");
    $s->execute([$key]);
    $value = $s->fetchColumn();

    if ($value !== false && trim((string)$value) !== '') {
        return (string)$value;
    }

    $defaults = default_settings();
    return $defaults[$key] ?? '';
}

function set_setting(string $key, string $value): void
{
    global $pdo;

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
        ->execute([$key, $value]);
}

function setting_bool(string $key): bool
{
    return get_setting($key) === '1';
}

function ikb(array $rows): array
{
    return ['inline_keyboard' => $rows];
}

function btn(string $text, string $data): array
{
    return ['text' => $text, 'callback_data' => $data];
}

function url_btn(string $text, string $url): array
{
    return ['text' => $text, 'url' => $url];
}

function enabled_button(string $key): bool
{
    return get_setting('enabled_' . $key) === '1';
}

function back_home(): array
{
    return [btn(get_setting('btn_back'), 'home')];
}

function back_admin(): array
{
    return [btn('🔙 مدیریت', 'admin.home')];
}

function cancel_btn(): array
{
    return [btn('❌ لغو عملیات', 'cancel')];
}

/* ===================== USER + ADMIN + STATE ===================== */

function is_admin(int $uid): bool
{
    global $pdo;

    $s = $pdo->prepare("SELECT 1 FROM admins WHERE user_id=?");
    $s->execute([$uid]);
    return (bool)$s->fetchColumn();
}

function admin_ids(): array
{
    global $pdo;
    return array_map('intval', $pdo->query("SELECT user_id FROM admins")->fetchAll(PDO::FETCH_COLUMN));
}

function ensure_user(array $from, ?array $contact = null): bool
{
    global $pdo;

    $uid = (int)($from['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    $check = $pdo->prepare("SELECT 1 FROM users WHERE user_id=?");
    $check->execute([$uid]);
    $isNew = !(bool)$check->fetchColumn();

    $first = trim((string)($from['first_name'] ?? ''));
    $last = trim((string)($from['last_name'] ?? ''));
    $username = trim((string)($from['username'] ?? ''));
    $phone = '';

    if ($contact && (int)($contact['user_id'] ?? 0) === $uid) {
        $phone = trim((string)($contact['phone_number'] ?? ''));
    }

    $pdo->prepare("
        INSERT OR IGNORE INTO users
        (user_id, first_name, last_name, username, phone, join_date, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$uid, $first, $last, $username, $phone, now_time(), now_time()]);

    $pdo->prepare("
        UPDATE users
        SET first_name=?,
            last_name=?,
            username=CASE WHEN ?<>'' THEN ? ELSE username END,
            phone=CASE WHEN ?<>'' THEN ? ELSE phone END,
            last_seen=?
        WHERE user_id=?
    ")->execute([$first, $last, $username, $username, $phone, $phone, now_time(), $uid]);

    return $isNew;
}

function is_maint(): bool
{
    global $pdo;
    return (bool)$pdo->query("SELECT enabled FROM maintenance WHERE id=1")->fetchColumn();
}

function ban_info(int $uid): ?array
{
    global $pdo;

    $s = $pdo->prepare("SELECT banned,banned_until,banned_reason FROM users WHERE user_id=?");
    $s->execute([$uid]);
    $u = $s->fetch();

    if (!$u) {
        return null;
    }

    if ((int)$u['banned'] === 1) {
        return $u;
    }

    $until = trim((string)$u['banned_until']);
    if ($until !== '' && strtotime($until) !== false && strtotime($until) > time()) {
        return $u;
    }

    return null;
}

function state_get(int $uid): ?array
{
    global $pdo;

    $s = $pdo->prepare("SELECT state,data FROM user_states WHERE user_id=?");
    $s->execute([$uid]);
    $r = $s->fetch();

    if (!$r || trim((string)$r['state']) === '') {
        return null;
    }

    $data = json_decode((string)$r['data'], true);

    return [
        'state' => (string)$r['state'],
        'data' => is_array($data) ? $data : [],
    ];
}

function state_set(int $uid, string $state, array $data = []): void
{
    global $pdo;

    $pdo->prepare("INSERT OR REPLACE INTO user_states (user_id,state,data) VALUES (?,?,?)")
        ->execute([$uid, $state, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function state_clear(int $uid): void
{
    global $pdo;
    $pdo->prepare("DELETE FROM user_states WHERE user_id=?")->execute([$uid]);
}

function msg_text(array $message): string
{
    $text = trim((string)($message['text'] ?? ''));
    if ($text !== '') {
        return $text;
    }
    return trim((string)($message['caption'] ?? ''));
}

function supported_message(array $message): bool
{
    return isset($message['text'])
        || isset($message['photo'])
        || isset($message['video'])
        || isset($message['document'])
        || isset($message['audio'])
        || isset($message['voice'])
        || isset($message['sticker']);
}

function message_preview(array $message): string
{
    if (!empty($message['text'])) {
        return (string)$message['text'];
    }
    if (!empty($message['caption'])) {
        return (string)$message['caption'];
    }
    if (isset($message['photo'])) {
        return '[PHOTO] ' . trim((string)($message['caption'] ?? ''));
    }
    if (isset($message['video'])) {
        return '[VIDEO] ' . trim((string)($message['caption'] ?? ''));
    }
    if (isset($message['document'])) {
        return '[FILE] ' . trim((string)($message['caption'] ?? ''));
    }
    if (isset($message['audio'])) {
        return '[AUDIO] ' . trim((string)($message['caption'] ?? ''));
    }
    if (isset($message['voice'])) {
        return '[VOICE]';
    }
    if (isset($message['sticker'])) {
        return '[STICKER]';
    }
    return '[MESSAGE]';
}

function log_admin(int $uid, string $action, string $details = ''): void
{
    global $pdo;
    $pdo->prepare("INSERT INTO admin_logs (admin_id,action,details,created_at) VALUES (?,?,?,?)")
        ->execute([$uid, $action, $details, now_time()]);
}

function home_kb(int $uid): array
{
    $rows = [];

    $row = [];
    if (enabled_button('btn_buy')) {
        $row[] = btn(get_setting('btn_buy'), 'u.buy');
    }
    if (enabled_button('btn_search')) {
        $row[] = btn(get_setting('btn_search'), 'u.search');
    }
    if ($row) {
        $rows[] = $row;
    }

    $row = [];
    if (enabled_button('btn_profile')) {
        $row[] = btn(get_setting('btn_profile'), 'u.profile');
    }
    if (enabled_button('btn_wallet')) {
        $row[] = btn(get_setting('btn_wallet'), 'u.wallet');
    }
    if ($row) {
        $rows[] = $row;
    }

    $row = [];
    if (enabled_button('btn_topup')) {
        $row[] = btn(get_setting('btn_topup'), 'u.topup');
    }
    if (enabled_button('btn_orders')) {
        $row[] = btn(get_setting('btn_orders'), 'u.orders');
    }
    if ($row) {
        $rows[] = $row;
    }

    $row = [];
    if (enabled_button('btn_games')) {
        $row[] = btn(get_setting('btn_games'), 'u.games');
    }
    if (enabled_button('btn_giveaway')) {
        $row[] = btn(get_setting('btn_giveaway'), 'u.give');
    }
    if ($row) {
        $rows[] = $row;
    }

    $row = [];
    if (enabled_button('btn_referral')) {
        $row[] = btn(get_setting('btn_referral'), 'u.ref');
    }
    if (enabled_button('btn_support')) {
        $row[] = btn(get_setting('btn_support'), 'u.support');
    }
    if ($row) {
        $rows[] = $row;
    }

    $row = [];
    if (enabled_button('btn_join')) {
        $row[] = btn(get_setting('btn_join'), 'u.join');
    }
    if (enabled_button('btn_help')) {
        $row[] = btn(get_setting('btn_help'), 'u.help');
    }
    if ($row) {
        $rows[] = $row;
    }

    if (is_admin($uid)) {
        $rows[] = [btn(get_setting('btn_admin'), 'admin.home')];
    }

    if (!$rows) {
        $rows[] = [btn('منو خالی است', 'home')];
    }

    return ikb($rows);
}

function admin_kb(): array
{
    return ikb([
        [btn('📦 محصولات', 'admin.products'), btn('🗂 دسته‌ها', 'admin.cats')],
        [btn('📥 مخزن', 'admin.stock'), btn('📋 سفارش‌ها', 'admin.orders')],
        [btn('💰 مالی', 'admin.fin'), btn('👥 کاربران', 'admin.users')],
        [btn('📢 همگانی', 'admin.broadcast'), btn('📢 عضویت اجباری', 'admin.force')],
        [btn('🎁 رفرال', 'admin.ref'), btn('🎛 مدیریت دکمه‌ها', 'admin.buttons')],
        [btn('📝 متن‌ها', 'admin.texts'), btn('🎮 چالش', 'admin.games')],
        [btn('🎁 قرعه‌کشی', 'admin.give'), btn('👨‍💼 ادمین‌ها', 'admin.admins')],
        [btn('🔛 نگهداری', 'admin.maint'), btn('📊 آمار', 'admin.stats')],
        [btn('🏠 منوی کاربر', 'home')],
    ]);
}

/* ===================== WALLET ===================== */

function user_balance(int $uid): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT balance FROM users WHERE user_id=?");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

function add_wallet_tx(int $uid, int $amount, string $type, string $description): void
{
    global $pdo;
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
        ->execute([$uid, $amount, $type, $description, now_time()]);
}

/* ===================== PRODUCTS / STOCK ===================== */

function product_by_id(int $id): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function order_by_id(int $id): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function stock_item_by_id(int $id): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM stock_items WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function available_stock_count(int $productId): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE product_id=? AND status='available'");
    $s->execute([$productId]);
    return (int)$s->fetchColumn();
}

function sold_stock_count(int $productId): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE product_id=? AND status='sold'");
    $s->execute([$productId]);
    return (int)$s->fetchColumn();
}

function avg_rating_text(int $productId): string
{
    global $pdo;
    $s = $pdo->prepare("SELECT ROUND(AVG(rating),2) a, COUNT(*) c FROM product_reviews WHERE product_id=?");
    $s->execute([$productId]);
    $r = $s->fetch();

    if ((int)($r['c'] ?? 0) <= 0) {
        return 'بدون امتیاز';
    }

    return $r['a'] . ' ⭐ (' . (int)$r['c'] . ' نظر)';
}

function extract_payload(array $message): ?array
{
    $caption = trim((string)($message['caption'] ?? ''));

    if (isset($message['text'])) {
        return ['type' => 'text', 'text' => (string)$message['text']];
    }

    if (isset($message['photo']) && is_array($message['photo'])) {
        $photos = $message['photo'];
        $last = end($photos);
        return [
            'type' => 'photo',
            'file_id' => (string)($last['file_id'] ?? ''),
            'caption' => $caption,
        ];
    }

    foreach (['video', 'document', 'audio', 'voice', 'sticker'] as $type) {
        if (isset($message[$type]['file_id'])) {
            $payload = [
                'type' => $type,
                'file_id' => (string)$message[$type]['file_id'],
            ];
            if ($caption !== '') {
                $payload['caption'] = $caption;
            }
            return $payload;
        }
    }

    return null;
}

function payload_preview(array $payload): string
{
    $type = (string)($payload['type'] ?? '');

    if ($type === 'text') {
        return cut((string)($payload['text'] ?? ''), 200);
    }

    if ($type === 'bundle') {
        return 'BUNDLE: ' . count((array)($payload['items'] ?? [])) . ' پیام';
    }

    return '[' . strtoupper($type ?: 'ITEM') . '] ' . trim((string)($payload['caption'] ?? ''));
}

function send_payload($chatId, array $payload): array
{
    $type = (string)($payload['type'] ?? '');

    if ($type === 'bundle') {
        $ok = true;
        foreach ((array)($payload['items'] ?? []) as $item) {
            if (is_array($item)) {
                $r = send_payload($chatId, $item);
                if (!($r['ok'] ?? false)) {
                    $ok = false;
                }
                usleep(150000);
            }
        }
        return ['ok' => $ok];
    }

    if ($type === 'text') {
        return send_msg($chatId, (string)($payload['text'] ?? ''));
    }

    $fileId = (string)($payload['file_id'] ?? '');
    $caption = cut((string)($payload['caption'] ?? ''), 1024);

    if ($fileId === '') {
        return ['ok' => false];
    }

    if ($type === 'photo') {
        return api('sendPhoto', ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => $caption]);
    }
    if ($type === 'video') {
        return api('sendVideo', ['chat_id' => $chatId, 'video' => $fileId, 'caption' => $caption]);
    }
    if ($type === 'document') {
        return api('sendDocument', ['chat_id' => $chatId, 'document' => $fileId, 'caption' => $caption]);
    }
    if ($type === 'audio') {
        return api('sendAudio', ['chat_id' => $chatId, 'audio' => $fileId, 'caption' => $caption]);
    }
    if ($type === 'voice') {
        return api('sendVoice', ['chat_id' => $chatId, 'voice' => $fileId, 'caption' => $caption]);
    }
    if ($type === 'sticker') {
        return api('sendSticker', ['chat_id' => $chatId, 'sticker' => $fileId]);
    }

    return ['ok' => false];
}

function add_stock_item(int $productId, array $payload, int $adminId): int
{
    global $pdo;

    $type = (string)($payload['type'] ?? 'text');
    $preview = payload_preview($payload);

    $pdo->prepare("
        INSERT INTO stock_items
        (product_id,item_type,payload_json,preview,status,added_by,created_at)
        VALUES (?,?,?,?, 'available', ?, ?)
    ")->execute([
        $productId,
        $type,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        cut($preview, 300),
        $adminId,
        now_time(),
    ]);

    return (int)$pdo->lastInsertId();
}

/* ===================== FORCE JOIN ===================== */

function force_items(bool $enabledOnly = true): array
{
    global $pdo;
    $sql = "SELECT * FROM force_items " . ($enabledOnly ? "WHERE enabled=1 " : "") . "ORDER BY id ASC";
    return $pdo->query($sql)->fetchAll();
}

function joined_chat(int $uid, string $chatId): bool
{
    $r = api('getChatMember', ['chat_id' => $chatId, 'user_id' => $uid]);
    if (!($r['ok'] ?? false)) {
        return false;
    }
    $status = (string)($r['result']['status'] ?? '');
    return in_array($status, ['creator', 'administrator', 'member', 'restricted'], true);
}

function missing_force_items(int $uid): array
{
    $missing = [];
    foreach (force_items(true) as $item) {
        if ((int)$item['checkable'] !== 1) {
            continue;
        }
        $chatId = trim((string)$item['chat_id']);
        if ($chatId === '') {
            continue;
        }
        if (!joined_chat($uid, $chatId)) {
            $missing[] = $item;
        }
    }
    return $missing;
}

function resolve_join_link(array $item): string
{
    $join = trim((string)($item['join_link'] ?? ''));
    if ($join !== '') {
        return $join;
    }

    $chatId = trim((string)($item['chat_id'] ?? ''));
    if ($chatId !== '' && sw($chatId, '@')) {
        return 'https://t.me/' . ltrim($chatId, '@');
    }

    if ($chatId !== '') {
        $chat = api('getChat', ['chat_id' => $chatId]);
        if ($chat['ok'] ?? false) {
            $username = trim((string)($chat['result']['username'] ?? ''));
            if ($username !== '') {
                return 'https://t.me/' . $username;
            }
            $invite = trim((string)($chat['result']['invite_link'] ?? ''));
            if ($invite !== '') {
                return $invite;
            }
        }
    }

    return '';
}

function force_text(int $uid): string
{
    $items = force_items(true);
    if (!$items) {
        return '✅ عضویت اجباری فعال نیست.';
    }

    $text = get_setting('force_join_msg') . "\n\n";
    foreach ($items as $i => $item) {
        $icon = '📢';
        if ($item['item_type'] === 'group') {
            $icon = '👥';
        } elseif ($item['item_type'] === 'folder') {
            $icon = '📁';
        } elseif ($item['item_type'] === 'link') {
            $icon = '🔗';
        }

        $text .= ($i + 1) . ". {$icon} {$item['title']}";
        if ((int)$item['checkable'] !== 1) {
            $text .= ' / نمایشی';
        }
        $text .= "\n";
    }

    $text .= "\nبعد از عضویت روی «" . get_setting('btn_confirm_join') . "» بزن.";
    return $text;
}

function force_kb(int $uid): array
{
    $rows = [];
    foreach (force_items(true) as $item) {
        $url = resolve_join_link($item);
        if ($url === '') {
            continue;
        }
        $icon = '📢';
        if ($item['item_type'] === 'group') {
            $icon = '👥';
        } elseif ($item['item_type'] === 'folder') {
            $icon = '📁';
        } elseif ($item['item_type'] === 'link') {
            $icon = '🔗';
        }
        $rows[] = [url_btn($icon . ' ' . (string)$item['title'], $url)];
    }
    $rows[] = [btn(get_setting('btn_confirm_join'), 'fj.confirm')];
    $rows[] = back_home();
    return ikb($rows);
}

/* ===================== REFERRAL ===================== */

function bot_username(): string
{
    if (trim((string)BOT_USERNAME) !== '') {
        return trim((string)BOT_USERNAME);
    }

    $cached = trim(get_setting('bot_username_cache'));
    if ($cached !== '') {
        return $cached;
    }

    $r = api('getMe');
    if (($r['ok'] ?? false) && !empty($r['result']['username'])) {
        $u = (string)$r['result']['username'];
        set_setting('bot_username_cache', $u);
        return $u;
    }

    return '';
}

function referral_count(int $uid): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id=?");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

function referral_earnings(int $uid): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_logs WHERE referrer_id=?");
    $s->execute([$uid]);
    return (int)$s->fetchColumn();
}

function referral_level(int $uid): string
{
    $count = referral_count($uid);

    if ($count >= (int)get_setting('ref_vip_count')) {
        return 'VIP';
    }
    if ($count >= (int)get_setting('ref_gold_count')) {
        return 'طلایی';
    }
    if ($count >= (int)get_setting('ref_silver_count')) {
        return 'نقره‌ای';
    }
    return 'عادی';
}

function referral_extra_percent(int $uid): int
{
    $level = referral_level($uid);
    if ($level === 'VIP') {
        return (int)get_setting('ref_vip_bonus_percent');
    }
    if ($level === 'طلایی') {
        return (int)get_setting('ref_gold_bonus_percent');
    }
    if ($level === 'نقره‌ای') {
        return (int)get_setting('ref_silver_bonus_percent');
    }
    return 0;
}

function handle_referral_start(int $uid, int $referrerId, bool $isNew): void
{
    global $pdo;

    if (!setting_bool('ref_enabled')) {
        return;
    }
    if (!$isNew) {
        return;
    }
    if ($referrerId <= 0 || $referrerId === $uid) {
        return;
    }

    $s = $pdo->prepare("SELECT 1 FROM users WHERE user_id=?");
    $s->execute([$referrerId]);
    if (!$s->fetchColumn()) {
        return;
    }

    try {
        $pdo->prepare("
            INSERT OR IGNORE INTO referrals
            (referrer_id,referred_id,rewarded_join,first_purchase_rewarded,created_at)
            VALUES (?,?,0,0,?)
        ")->execute([$referrerId, $uid, now_time()]);
    } catch (Throwable $e) {
        error_log('REF INSERT ERROR: ' . $e->getMessage());
    }
}

function try_reward_join_referral(int $referredId): void
{
    global $pdo;

    if (!setting_bool('ref_enabled')) {
        return;
    }

    $s = $pdo->prepare("SELECT * FROM referrals WHERE referred_id=? AND rewarded_join=0");
    $s->execute([$referredId]);
    $ref = $s->fetch();

    if (!$ref) {
        return;
    }

    if (setting_bool('ref_require_force_join') && missing_force_items($referredId)) {
        return;
    }

    $referrerId = (int)$ref['referrer_id'];
    $amount = max(0, (int)get_setting('ref_join_bonus'));

    try {
        $pdo->beginTransaction();

        if ($amount > 0) {
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE user_id=?")->execute([$amount, $referrerId]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
                ->execute([$referrerId, $amount, 'referral', 'join bonus from ' . $referredId, now_time()]);
            $pdo->prepare("INSERT INTO referral_logs (referrer_id,referred_id,amount,reward_type,order_id,created_at) VALUES (?,?,?,?,0,?)")
                ->execute([$referrerId, $referredId, $amount, 'join', now_time()]);
        }

        $pdo->prepare("UPDATE referrals SET rewarded_join=1 WHERE id=?")->execute([(int)$ref['id']]);

        $pdo->commit();

        if ($amount > 0) {
            send_msg($referrerId, "🎁 پاداش دعوت دوست اضافه شد.\n\n👤 زیرمجموعه: {$referredId}\n💰 پاداش: " . fmt($amount) . " تومان", home_kb($referrerId));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('REF JOIN ERROR: ' . $e->getMessage());
    }
}

function reward_referral_purchase(int $buyerId, int $price, int $orderId): void
{
    global $pdo;

    if (!setting_bool('ref_enabled')) {
        return;
    }

    $s = $pdo->prepare("SELECT * FROM referrals WHERE referred_id=?");
    $s->execute([$buyerId]);
    $ref = $s->fetch();

    if (!$ref) {
        return;
    }

    if (setting_bool('ref_first_purchase_only') && (int)$ref['first_purchase_rewarded'] === 1) {
        return;
    }

    $referrerId = (int)$ref['referrer_id'];
    $percent = max(0, (int)get_setting('ref_purchase_percent')) + referral_extra_percent($referrerId);

    if ($percent <= 0 || $price <= 0) {
        $pdo->prepare("UPDATE referrals SET first_purchase_rewarded=1 WHERE id=?")->execute([(int)$ref['id']]);
        return;
    }

    $amount = (int)floor(($price * $percent) / 100);
    if ($amount <= 0) {
        return;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE users SET balance=balance+? WHERE user_id=?")->execute([$amount, $referrerId]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
            ->execute([$referrerId, $amount, 'referral', "purchase bonus order #{$orderId}", now_time()]);

        $pdo->prepare("INSERT INTO referral_logs (referrer_id,referred_id,amount,reward_type,order_id,created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$referrerId, $buyerId, $amount, 'purchase', $orderId, now_time()]);

        $pdo->prepare("UPDATE referrals SET first_purchase_rewarded=1 WHERE id=?")->execute([(int)$ref['id']]);

        $pdo->commit();

        send_msg($referrerId, "🎁 پاداش خرید زیرمجموعه اضافه شد.\n\n👤 زیرمجموعه: {$buyerId}\n🧾 سفارش: #{$orderId}\n💰 پاداش: " . fmt($amount) . " تومان\n📊 درصد: {$percent}٪", home_kb($referrerId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('REF PURCHASE ERROR: ' . $e->getMessage());
    }
}

/* ===================== ORDER ENGINE ===================== */

function notify_admins(string $text, ?array $keyboard = null): void
{
    foreach (admin_ids() as $adminId) {
        send_msg($adminId, $text, $keyboard);
    }
}

function notify_new_order(array $order): void
{
    $orderId = (int)$order['id'];

    $text = "🆕 سفارش جدید\n\n";
    $text .= "🧾 شماره: #{$orderId}\n";
    $text .= "👤 کاربر: {$order['user_id']}\n";
    $text .= "📦 محصول: {$order['product_name']}\n";
    $text .= "💰 مبلغ: " . fmt((int)$order['price']) . " تومان\n";
    $text .= "📅 {$order['created_at']}";

    notify_admins($text, ikb([
        [btn('📤 ارسال سرویس', 'admin.order.deliver:' . $orderId)],
        [btn('👁 مشاهده', 'admin.order.view:' . $orderId), btn('❌ لغو و برگشت', 'admin.order.cancel:' . $orderId)],
    ]));
}

function stock_low_alert(array $product): void
{
    if (!in_array((string)$product['delivery_mode'], ['stock', 'hybrid'], true)) {
        return;
    }

    $left = available_stock_count((int)$product['id']);

    if ($left <= (int)$product['min_stock_alert']) {
        notify_admins("⚠️ هشدار کمبود مخزن\n\n📦 {$product['name']}\nموجودی: {$left}", ikb([
            [btn('📥 مدیریت مخزن', 'admin.stock.product:' . $product['id'])],
        ]));
    }
}

function create_order_after_payment(int $uid, int $productId): array
{
    global $pdo;

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->beginTransaction();

        $s = $pdo->prepare("SELECT * FROM products WHERE id=? AND active=1");
        $s->execute([$productId]);
        $product = $s->fetch();

        if (!$product) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_found'];
        }

        $limit = (int)$product['max_per_user_daily'];
        if ($limit > 0) {
            $d = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND product_id=? AND substr(created_at,1,10)=?");
            $d->execute([$uid, $productId, today_date()]);
            if ((int)$d->fetchColumn() >= $limit) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'daily_limit'];
            }
        }

        $b = $pdo->prepare("SELECT balance FROM users WHERE user_id=?");
        $b->execute([$uid]);
        $balance = $b->fetchColumn();

        if ($balance === false) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        $balance = (int)$balance;
        $price = (int)$product['price'];

        if ($balance < $price) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'low_balance'];
        }

        $mode = (string)$product['delivery_mode'];
        $stockItem = null;

        if ($mode === 'stock' || $mode === 'hybrid') {
            $si = $pdo->prepare("
                SELECT *
                FROM stock_items
                WHERE product_id=? AND status='available'
                ORDER BY id ASC
                LIMIT 1
            ");
            $si->execute([$productId]);
            $stockItem = $si->fetch() ?: null;

            if (!$stockItem && $mode === 'stock') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'out_of_stock'];
            }
        }

        $pdo->prepare("UPDATE users SET balance=balance-? WHERE user_id=?")->execute([$price, $uid]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
            ->execute([$uid, -$price, 'purchase', 'buy product #' . $productId, now_time()]);

        $status = $stockItem ? 'auto_delivered' : 'pending';
        $delivery = $stockItem ? 'stock' : 'manual';
        $stockId = $stockItem ? (int)$stockItem['id'] : 0;
        $preview = $stockItem ? cut((string)$stockItem['preview'], 1000) : '';

        $pdo->prepare("
            INSERT INTO orders
            (user_id, product_id, product_name, price, status, delivery_mode, payment_method, stock_item_id, service_preview, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'wallet', ?, ?, ?)
        ")->execute([
            $uid,
            $productId,
            (string)$product['name'],
            $price,
            $status,
            $delivery,
            $stockId,
            $preview,
            now_time(),
        ]);

        $orderId = (int)$pdo->lastInsertId();

        if ($stockItem) {
            $u = $pdo->prepare("
                UPDATE stock_items
                SET status='sold', sold_to=?, sold_order_id=?, sold_at=?
                WHERE id=? AND status='available'
            ");
            $u->execute([$uid, $orderId, now_time(), $stockId]);

            if ($u->rowCount() < 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'out_of_stock'];
            }
        }

        $pdo->commit();

        $order = order_by_id($orderId);

        if ($stockItem) {
            send_msg($uid, "📦 سرویس سفارش #{$orderId}:");

            $payload = json_decode((string)$stockItem['payload_json'], true);
            if (is_array($payload)) {
                $send = send_payload($uid, $payload);
                if (!($send['ok'] ?? false)) {
                    notify_admins("⚠️ پرداخت انجام شد ولی ارسال آیتم شاید ناموفق بود.\n\nسفارش: #{$orderId}\nکاربر: {$uid}");
                }
            }

            send_msg($uid, get_setting('order_auto_msg') . "\n\n🧾 شماره سفارش: #{$orderId}", home_kb($uid));

            if (trim((string)LOG_CHANNEL) !== '') {
                send_msg(LOG_CHANNEL, "✅ تحویل خودکار\n\nسفارش: #{$orderId}\nکاربر: {$uid}\nمحصول: {$product['name']}");
            }

            stock_low_alert($product);
        } elseif ($order) {
            notify_new_order($order);
        }

        reward_referral_purchase($uid, $price, $orderId);

        return [
            'ok' => true,
            'order_id' => $orderId,
            'auto' => (bool)$stockItem,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('ORDER ERROR: ' . $e->getMessage());

        return [
            'ok' => false,
            'error' => 'exception',
            'debug' => $e->getMessage(),
        ];
    }
}

function deliver_order(int $adminId, int $adminChatId, int $messageId, array $message, int $orderId): array
{
    global $pdo;

    $order = order_by_id($orderId);

    if (!$order || (string)$order['status'] !== 'pending') {
        return ['ok' => false];
    }

    if (!supported_message($message)) {
        return ['ok' => false];
    }

    $uid = (int)$order['user_id'];

    send_msg($uid, "📦 سرویس سفارش #{$orderId} ارسال شد:");
    $copy = copy_msg($uid, $adminChatId, $messageId);

    if (!($copy['ok'] ?? false)) {
        return ['ok' => false];
    }

    $pdo->prepare("
        UPDATE orders
        SET status='delivered', delivered_by=?, delivered_at=?, service_preview=?
        WHERE id=? AND status='pending'
    ")->execute([$adminId, now_time(), cut(message_preview($message), 1000), $orderId]);

    send_msg($uid, "✅ سفارش شما تکمیل شد.\n🧾 شماره سفارش: #{$orderId}", home_kb($uid));

    if (trim((string)LOG_CHANNEL) !== '') {
        send_msg(LOG_CHANNEL, "🗂 سفارش دستی تحویل شد\n\nسفارش: #{$orderId}\nادمین: {$adminId}\nکاربر: {$uid}");
        copy_msg(LOG_CHANNEL, $adminChatId, $messageId);
    }

    log_admin($adminId, 'deliver_order', '#' . $orderId);

    return ['ok' => true];
}

function cancel_order_refund(int $adminId, int $orderId, string $reason = ''): array
{
    global $pdo;

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->beginTransaction();

        $order = order_by_id($orderId);

        if (!$order || (string)$order['status'] !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false];
        }

        $uid = (int)$order['user_id'];
        $price = (int)$order['price'];

        $pdo->prepare("UPDATE users SET balance=balance+? WHERE user_id=?")->execute([$price, $uid]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
            ->execute([$uid, $price, 'refund', 'refund order #' . $orderId, now_time()]);

        $pdo->prepare("
            UPDATE orders
            SET status='canceled', canceled_by=?, canceled_at=?, cancel_reason=?
            WHERE id=? AND status='pending'
        ")->execute([$adminId, now_time(), $reason, $orderId]);

        $pdo->commit();

        $text = "❌ سفارش شما لغو شد و مبلغ برگشت داده شد.\n\n";
        $text .= "🧾 سفارش: #{$orderId}\n";
        $text .= "💰 مبلغ برگشتی: " . fmt($price) . " تومان";

        if ($reason !== '') {
            $text .= "\n\nعلت:\n{$reason}";
        }

        send_msg($uid, $text, home_kb($uid));
        log_admin($adminId, 'cancel_order', '#' . $orderId . ' ' . $reason);

        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false];
    }
}

/* ===================== RENDERS ===================== */

function product_text(array $product): string
{
    $text = "🛍 {$product['name']}\n\n";

    if (trim((string)$product['description']) !== '') {
        $text .= "{$product['description']}\n\n";
    }

    $text .= "💰 قیمت: " . fmt((int)$product['price']) . " تومان\n";
    $text .= "🚚 نوع تحویل: " . mode_fa((string)$product['delivery_mode']) . "\n";

    if (in_array((string)$product['delivery_mode'], ['stock', 'hybrid'], true)) {
        $text .= "📥 موجودی مخزن: " . available_stock_count((int)$product['id']) . "\n";
    }

    $text .= "⭐ امتیاز: " . avg_rating_text((int)$product['id']);

    return $text;
}

function product_kb(int $productId): array
{
    return ikb([
        [btn('🧾 ادامه خرید', 'buy:' . $productId)],
        [btn('⭐ ثبت/مشاهده نظر', 'review:' . $productId)],
        [btn('🔙 دسته‌ها', 'u.buy'), btn(get_setting('btn_back'), 'home')],
    ]);
}

function order_user_text(array $order): string
{
    $text = "🧾 سفارش #{$order['id']}\n\n";
    $text .= "📦 محصول: {$order['product_name']}\n";
    $text .= "💰 مبلغ: " . fmt((int)$order['price']) . " تومان\n";
    $text .= "📌 وضعیت: " . status_fa((string)$order['status']) . "\n";
    $text .= "🚚 نوع: " . mode_fa((string)$order['delivery_mode']) . "\n";
    $text .= "📅 {$order['created_at']}\n";

    if (trim((string)$order['service_preview']) !== '') {
        $text .= "\n📄 پیش‌نمایش:\n{$order['service_preview']}";
    }

    return $text;
}

function order_admin_text(array $order): string
{
    $text = "🧾 سفارش #{$order['id']}\n\n";
    $text .= "👤 کاربر: {$order['user_id']}\n";
    $text .= "📦 محصول: {$order['product_name']}\n";
    $text .= "💰 مبلغ: " . fmt((int)$order['price']) . " تومان\n";
    $text .= "📌 وضعیت: " . status_fa((string)$order['status']) . "\n";
    $text .= "🚚 نوع: " . mode_fa((string)$order['delivery_mode']) . "\n";
    $text .= "📅 ثبت: {$order['created_at']}\n";

    if (trim((string)$order['delivered_at']) !== '') {
        $text .= "📤 ارسال: {$order['delivered_at']} توسط {$order['delivered_by']}\n";
    }

    if (trim((string)$order['canceled_at']) !== '') {
        $text .= "❌ لغو: {$order['canceled_at']} توسط {$order['canceled_by']}\n";
        $text .= "علت: {$order['cancel_reason']}\n";
    }

    if (trim((string)$order['service_preview']) !== '') {
        $text .= "\n📄 پیش‌نمایش:\n{$order['service_preview']}";
    }

    return $text;
}

/* ===================== USER SCREENS ===================== */

function show_home(int $chatId, int $uid): void
{
    send_msg($chatId, get_setting('welcome_msg'), home_kb($uid));
}

function show_categories(int $chatId): void
{
    global $pdo;

    $cats = $pdo->query("SELECT * FROM categories WHERE active=1 ORDER BY id DESC")->fetchAll();

    if (!$cats) {
        send_msg($chatId, '❌ هنوز دسته‌ای ثبت نشده.', ikb([back_home()]));
        return;
    }

    $kb = [];
    foreach ($cats as $cat) {
        $kb[] = [btn('🗂 ' . $cat['name'], 'cat:' . $cat['id'])];
    }
    $kb[] = back_home();

    send_msg($chatId, '🗂 دسته‌بندی را انتخاب کن:', ikb($kb));
}

function show_products(int $chatId, int $catId): void
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM products WHERE cat_id=? AND active=1 ORDER BY id DESC");
    $s->execute([$catId]);
    $products = $s->fetchAll();

    if (!$products) {
        send_msg($chatId, '❌ این دسته محصول فعالی ندارد.', ikb([[btn('🔙 دسته‌ها', 'u.buy')], back_home()]));
        return;
    }

    $kb = [];
    foreach ($products as $p) {
        $stock = '';
        if (in_array((string)$p['delivery_mode'], ['stock', 'hybrid'], true)) {
            $stock = ' | موجودی: ' . available_stock_count((int)$p['id']);
        }
        $kb[] = [btn('📦 ' . $p['name'] . ' | ' . fmt((int)$p['price']) . ' تومان' . $stock, 'prd:' . $p['id'])];
    }

    $kb[] = [btn('🔙 دسته‌ها', 'u.buy')];
    $kb[] = back_home();

    send_msg($chatId, '📦 محصول را انتخاب کن:', ikb($kb));
}

function show_product(int $chatId, int $productId): void
{
    $product = product_by_id($productId);

    if (!$product || (int)$product['active'] !== 1) {
        send_msg($chatId, '❌ محصول در دسترس نیست.', ikb([back_home()]));
        return;
    }

    if (trim((string)$product['photo_file_id']) !== '') {
        send_photo_msg($chatId, (string)$product['photo_file_id'], product_text($product), product_kb($productId));
    } else {
        send_msg($chatId, product_text($product), product_kb($productId));
    }
}

function show_profile(int $chatId, int $uid): void
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
    $s->execute([$uid]);
    $u = $s->fetch() ?: [];

    $oc = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?");
    $oc->execute([$uid]);

    $spent = $pdo->prepare("SELECT COALESCE(SUM(price),0) FROM orders WHERE user_id=? AND status IN ('pending','delivered','auto_delivered')");
    $spent->execute([$uid]);

    $text = "👤 پروفایل\n\n";
    $text .= "🆔 آیدی: {$uid}\n";
    $text .= "🔗 یوزرنیم: " . (!empty($u['username']) ? '@' . $u['username'] : '-') . "\n";
    $text .= "💰 موجودی: " . fmt((int)($u['balance'] ?? 0)) . " تومان\n";
    $text .= "📦 سفارش‌ها: " . (int)$oc->fetchColumn() . "\n";
    $text .= "💳 خرید کل: " . fmt((int)$spent->fetchColumn()) . " تومان\n";
    $text .= "🏅 وضعیت: " . ((int)($u['vip'] ?? 0) ? 'VIP' : 'عادی') . "\n";
    $text .= "🎁 سطح رفرال: " . referral_level($uid) . "\n";
    $text .= "📅 عضویت: " . (string)($u['join_date'] ?? '-');

    send_msg($chatId, $text, ikb([back_home()]));
}

function show_wallet(int $chatId, int $uid): void
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 10");
    $s->execute([$uid]);
    $rows = $s->fetchAll();

    $text = "💰 کیف پول\n\n";
    $text .= "موجودی: " . fmt(user_balance($uid)) . " تومان\n\n";
    $text .= "📜 ۱۰ تراکنش آخر:\n";

    if (!$rows) {
        $text .= 'تراکنشی ثبت نشده.';
    }

    foreach ($rows as $r) {
        $sign = (int)$r['amount'] >= 0 ? '+' : '-';
        $text .= $sign . fmt(abs((int)$r['amount'])) . " | {$r['type']} | {$r['created_at']}\n";
    }

    send_msg($chatId, $text, ikb([[btn('➕ شارژ کیف پول', 'u.topup')], back_home()]));
}

function show_user_orders(int $chatId, int $uid): void
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 20");
    $s->execute([$uid]);
    $orders = $s->fetchAll();

    if (!$orders) {
        send_msg($chatId, '📦 هنوز سفارشی نداری.', ikb([back_home()]));
        return;
    }

    $text = "📦 سفارش‌های شما\n\n";
    $kb = [];

    foreach ($orders as $o) {
        $text .= "#{$o['id']} | {$o['product_name']} | " . fmt((int)$o['price']) . " | " . status_fa((string)$o['status']) . "\n";
        $kb[] = [btn('👁 مشاهده #' . $o['id'], 'myord:' . $o['id'])];

        if ((int)$o['stock_item_id'] > 0) {
            $kb[] = [btn('📥 دریافت مجدد سرویس #' . $o['id'], 'resend:' . $o['id'])];
        }
    }

    $kb[] = back_home();

    send_msg($chatId, cut($text, 3900), ikb($kb));
}

function show_referral(int $chatId, int $uid): void
{
    global $pdo;

    $username = bot_username();
    $link = $username !== '' ? "https://t.me/{$username}?start=ref_{$uid}" : 'یوزرنیم ربات پیدا نشد. BOT_USERNAME را تنظیم کن.';

    $count = referral_count($uid);
    $earn = referral_earnings($uid);
    $level = referral_level($uid);
    $basePercent = (int)get_setting('ref_purchase_percent');
    $extraPercent = referral_extra_percent($uid);
    $joinBonus = (int)get_setting('ref_join_bonus');

    $s = $pdo->prepare("SELECT referred_id, created_at FROM referrals WHERE referrer_id=? ORDER BY id DESC LIMIT 10");
    $s->execute([$uid]);
    $refs = $s->fetchAll();

    $text = "🎁 دعوت دوستان\n\n";
    $text .= "🔗 لینک اختصاصی شما:\n{$link}\n\n";
    $text .= "👥 تعداد دعوت‌ها: {$count}\n";
    $text .= "💰 درآمد رفرال: " . fmt($earn) . " تومان\n";
    $text .= "🏅 سطح شما: {$level}\n";
    $text .= "🎁 پاداش عضویت: " . fmt($joinBonus) . " تومان\n";
    $text .= "📊 درصد خرید پایه: {$basePercent}٪\n";
    $text .= "⚡ درصد اضافه سطح: {$extraPercent}٪\n\n";
    $text .= "📋 آخرین دعوت‌ها:\n";

    if (!$refs) {
        $text .= 'هنوز کسی با لینک شما عضو نشده.';
    } else {
        foreach ($refs as $ref) {
            $text .= "👤 {$ref['referred_id']} | {$ref['created_at']}\n";
        }
    }

    send_msg($chatId, $text, ikb([
        [btn('📜 تاریخچه پاداش‌ها', 'u.ref.logs')],
        back_home(),
    ]));
}

/* ===================== TOPUP ===================== */

function active_cards(): array
{
    global $pdo;
    return $pdo->query("SELECT * FROM payment_cards WHERE enabled=1 ORDER BY id DESC")->fetchAll();
}

function card_by_id(int $id): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM payment_cards WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function topup_by_id(int $id): ?array
{
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM topup_requests WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function card_text(array $card): string
{
    $text = "💳 {$card['title']}\n\n";
    $text .= "شماره کارت:\n{$card['card_number']}\n\n";
    $text .= "صاحب کارت:\n{$card['card_holder']}";

    if (trim((string)$card['extra_text']) !== '') {
        $text .= "\n\nتوضیحات:\n{$card['extra_text']}";
    }

    return $text;
}

function notify_topup(array $request): void
{
    $requestId = (int)$request['id'];
    $card = card_by_id((int)$request['card_id']);

    $text = "➕ درخواست افزایش موجودی\n\n";
    $text .= "🧾 شماره: #{$requestId}\n";
    $text .= "👤 کاربر: {$request['user_id']}\n";
    $text .= "💰 مبلغ: " . fmt((int)$request['amount']) . " تومان\n";
    $text .= "💳 کارت: " . ($card ? $card['title'] : '-') . "\n";
    $text .= "📅 {$request['created_at']}";

    notify_admins($text, ikb([
        [btn('✅ تایید', 'admin.topup.ok:' . $requestId)],
        [btn('❌ رد', 'admin.topup.reject:' . $requestId), btn('👁 مشاهده', 'admin.topup.view:' . $requestId)],
    ]));
}

function approve_topup(int $adminId, int $requestId): bool
{
    global $pdo;

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->beginTransaction();

        $r = topup_by_id($requestId);

        if (!$r || (string)$r['status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        $uid = (int)$r['user_id'];
        $amount = (int)$r['amount'];

        $pdo->prepare("UPDATE users SET balance=balance+? WHERE user_id=?")->execute([$amount, $uid]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id,amount,type,description,created_at) VALUES (?,?,?,?,?)")
            ->execute([$uid, $amount, 'topup', 'approved topup #' . $requestId, now_time()]);

        $pdo->prepare("UPDATE topup_requests SET status='approved', handled_by=?, handled_at=? WHERE id=? AND status='pending'")
            ->execute([$adminId, now_time(), $requestId]);

        $pdo->commit();

        send_msg($uid, "✅ شارژ شما تایید شد.\n💰 مبلغ: " . fmt($amount) . " تومان\n🧾 درخواست: #{$requestId}", home_kb($uid));

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function reject_topup(int $adminId, int $requestId, string $reason = ''): bool
{
    global $pdo;

    $r = topup_by_id($requestId);

    if (!$r || (string)$r['status'] !== 'pending') {
        return false;
    }

    $pdo->prepare("UPDATE topup_requests SET status='rejected', handled_by=?, handled_at=?, reject_reason=? WHERE id=? AND status='pending'")
        ->execute([$adminId, now_time(), $reason, $requestId]);

    $text = "❌ درخواست شارژ شما رد شد.\n\n";
    $text .= "🧾 درخواست: #{$requestId}\n";
    $text .= "💰 مبلغ: " . fmt((int)$r['amount']) . " تومان";

    if ($reason !== '') {
        $text .= "\n\nعلت:\n{$reason}";
    }

    send_msg((int)$r['user_id'], $text, home_kb((int)$r['user_id']));

    return true;
}

/* ===================== ADMIN SCREENS ===================== */

function show_admin(int $chatId): void
{
    send_msg($chatId, '🛠 پنل مدیریت', admin_kb());
}

function admin_cats(int $chatId): void
{
    global $pdo;

    $cats = $pdo->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll();

    $text = "🗂 مدیریت دسته‌ها\n\n";
    if (!$cats) {
        $text .= 'دسته‌ای ثبت نشده.';
    }

    $kb = [[btn('➕ افزودن دسته', 'admin.cat.add')]];

    foreach ($cats as $cat) {
        $text .= "#{$cat['id']} | {$cat['name']} | " . ((int)$cat['active'] ? 'فعال' : 'غیرفعال') . "\n";
        $kb[] = [
            btn('🔄 ' . $cat['name'], 'admin.cat.toggle:' . $cat['id']),
            btn('🗑 حذف', 'admin.cat.del:' . $cat['id']),
        ];
    }

    $kb[] = back_admin();

    send_msg($chatId, $text, ikb($kb));
}

function admin_products(int $chatId): void
{
    send_msg($chatId, '📦 مدیریت محصولات', ikb([
        [btn('➕ افزودن محصول', 'admin.prod.add'), btn('📋 لیست محصولات', 'admin.prod.list')],
        [btn('🔍 جستجوی محصول', 'admin.prod.search')],
        back_admin(),
    ]));
}

function admin_stock_products(int $chatId): void
{
    global $pdo;

    $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();

    if (!$products) {
        send_msg($chatId, '❌ محصولی وجود ندارد.', ikb([back_admin()]));
        return;
    }

    $kb = [];
    foreach ($products as $p) {
        $kb[] = [
            btn(
                '📥 ' . $p['name'] . ' | ' . mode_fa((string)$p['delivery_mode']) . ' | موجود: ' . available_stock_count((int)$p['id']),
                'admin.stock.product:' . $p['id']
            ),
        ];
    }
    $kb[] = back_admin();

    send_msg($chatId, '📥 محصول مورد نظر برای مخزن را انتخاب کن:', ikb($kb));
}

function admin_stock_menu(int $chatId, int $productId): void
{
    $p = product_by_id($productId);

    if (!$p) {
        send_msg($chatId, '❌ محصول پیدا نشد.', ikb([back_admin()]));
        return;
    }

    $text = "📥 مدیریت مخزن\n\n";
    $text .= "📦 {$p['name']}\n";
    $text .= "🚚 نوع: " . mode_fa((string)$p['delivery_mode']) . "\n";
    $text .= "✅ موجود: " . available_stock_count($productId) . "\n";
    $text .= "🛒 فروخته‌شده: " . sold_stock_count($productId);

    send_msg($chatId, $text, ikb([
        [btn('➕ آیتم متنی', 'admin.stock.addtext:' . $productId), btn('➕ گروهی متن', 'admin.stock.addbulk:' . $productId)],
        [btn('📷 عکس/فیلم/فایل/ویس', 'admin.stock.addmedia:' . $productId), btn('🧾 آیتم چندپیامی', 'admin.stock.addbundle:' . $productId)],
        [btn('📋 لیست موجودها', 'admin.stock.list:' . $productId . ':0'), btn('🛒 فروخته‌شده‌ها', 'admin.stock.sold:' . $productId . ':0')],
        [btn('🔙 انتخاب محصول', 'admin.stock'), btn('🔙 مدیریت', 'admin.home')],
    ]));
}

function admin_orders(int $chatId): void
{
    send_msg($chatId, '📋 سفارش‌ها', ikb([
        [btn('⏳ سفارش‌های باز', 'admin.orders.pending'), btn('📦 آخرین سفارش‌ها', 'admin.orders.all')],
        [btn('🔍 جستجوی سفارش/کاربر', 'admin.orders.search')],
        back_admin(),
    ]));
}

function admin_orders_list(int $chatId, string $mode): void
{
    global $pdo;

    if ($mode === 'pending') {
        $orders = $pdo->query("SELECT * FROM orders WHERE status='pending' ORDER BY id ASC LIMIT 30")->fetchAll();
    } else {
        $orders = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 30")->fetchAll();
    }

    if (!$orders) {
        send_msg($chatId, '❌ سفارشی وجود ندارد.', ikb([back_admin()]));
        return;
    }

    $text = $mode === 'pending' ? "⏳ سفارش‌های باز\n\n" : "📦 آخرین سفارش‌ها\n\n";
    $kb = [];

    foreach ($orders as $o) {
        $text .= "#{$o['id']} | {$o['user_id']} | {$o['product_name']} | " . fmt((int)$o['price']) . " | " . status_fa((string)$o['status']) . "\n";
        $row = [btn('👁 #' . $o['id'], 'admin.order.view:' . $o['id'])];
        if ((string)$o['status'] === 'pending') {
            $row[] = btn('📤 ارسال', 'admin.order.deliver:' . $o['id']);
            $row[] = btn('❌ لغو', 'admin.order.cancel:' . $o['id']);
        }
        $kb[] = $row;
    }

    $kb[] = [btn('🔙 سفارش‌ها', 'admin.orders')];

    send_msg($chatId, cut($text, 3900), ikb($kb));
}

function admin_fin(int $chatId): void
{
    send_msg($chatId, '💰 مدیریت مالی', ikb([
        [btn('💳 کارت‌ها', 'admin.cards'), btn('➕ شارژهای باز', 'admin.topups')],
        [btn('💰 تغییر موجودی کاربر', 'admin.balance.user'), btn('📜 آخرین تراکنش‌ها', 'admin.txs')],
        back_admin(),
    ]));
}

function admin_users(int $chatId): void
{
    send_msg($chatId, '👥 مدیریت کاربران', ikb([
        [btn('🔍 جستجوی کاربر', 'admin.user.search'), btn('👥 لیست کاربران', 'admin.user.list:0')],
        [btn('⛔ بن‌شده‌ها', 'admin.user.banned:0'), btn('🏅 VIP ها', 'admin.user.vip:0')],
        back_admin(),
    ]));
}

function admin_force(int $chatId): void
{
    $items = force_items(false);

    $text = "📢 عضویت اجباری\n\n";
    if (!$items) {
        $text .= 'موردی ثبت نشده.';
    }

    $kb = [
        [btn('➕ کانال', 'admin.force.add:channel'), btn('➕ گپ/گروه', 'admin.force.add:group')],
        [btn('📁 فولدر', 'admin.force.add:folder'), btn('🔗 لینک نمایشی', 'admin.force.add:link')],
    ];

    foreach ($items as $it) {
        $text .= "#{$it['id']} | {$it['item_type']} | {$it['title']} | ";
        $text .= ((int)$it['enabled'] ? 'روشن' : 'خاموش') . ' | ';
        $text .= ((int)$it['checkable'] ? 'قابل بررسی' : 'نمایشی') . "\n";

        $kb[] = [
            btn('🔄 #' . $it['id'], 'admin.force.toggle:' . $it['id']),
            btn('🗑 حذف', 'admin.force.del:' . $it['id']),
        ];
    }

    $kb[] = back_admin();

    send_msg($chatId, $text, ikb($kb));
}

function admin_texts(int $chatId, int $page = 0): void
{
    $meta = text_meta();
    $keys = array_keys($meta);
    $per = 10;
    $slice = array_slice($keys, $page * $per, $per, true);

    $kb = [];
    foreach ($slice as $idx => $key) {
        $kb[] = [btn('📝 ' . $meta[$key], 'admin.text.edit:' . $idx)];
    }

    $nav = [];
    if ($page > 0) {
        $nav[] = btn('⬅️ قبلی', 'admin.text.page:' . ($page - 1));
    }
    if (($page + 1) * $per < count($keys)) {
        $nav[] = btn('➡️ بعدی', 'admin.text.page:' . ($page + 1));
    }
    if ($nav) {
        $kb[] = $nav;
    }

    $kb[] = back_admin();

    send_msg($chatId, '📝 مدیریت کامل متن‌ها:', ikb($kb));
}

function admin_buttons(int $chatId): void
{
    $meta = button_meta();

    $text = "🎛 مدیریت دکمه‌ها\n\n";
    $kb = [];

    foreach ($meta as $key => $label) {
        $current = get_setting($key);
        $canToggle = in_array($key, [
            'btn_buy', 'btn_search', 'btn_profile', 'btn_wallet', 'btn_topup', 'btn_orders',
            'btn_support', 'btn_join', 'btn_help', 'btn_games', 'btn_giveaway', 'btn_referral'
        ], true);

        $text .= "{$label}: {$current}";
        if ($canToggle) {
            $text .= ' | ' . (enabled_button($key) ? 'روشن' : 'خاموش');
        }
        $text .= "\n";

        $row = [btn('✏️ ' . $label, 'admin.btn.edit:' . $key)];
        if ($canToggle) {
            $row[] = btn(enabled_button($key) ? '🔴 خاموش' : '🟢 روشن', 'admin.btn.toggle:' . $key);
        }
        $kb[] = $row;
    }

    $kb[] = back_admin();

    send_msg($chatId, cut($text, 3900), ikb($kb));
}

function admin_referral(int $chatId): void
{
    global $pdo;

    $totalRefs = (int)$pdo->query("SELECT COUNT(*) FROM referrals")->fetchColumn();
    $totalEarn = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM referral_logs")->fetchColumn();

    $text = "🎁 مدیریت رفرال\n\n";
    $text .= "وضعیت: " . (setting_bool('ref_enabled') ? 'روشن' : 'خاموش') . "\n";
    $text .= "پاداش عضویت: " . fmt((int)get_setting('ref_join_bonus')) . " تومان\n";
    $text .= "درصد خرید پایه: " . (int)get_setting('ref_purchase_percent') . "٪\n";
    $text .= "فقط اولین خرید: " . (setting_bool('ref_first_purchase_only') ? 'بله' : 'خیر') . "\n";
    $text .= "پاداش بعد عضویت اجباری: " . (setting_bool('ref_require_force_join') ? 'بله' : 'خیر') . "\n\n";
    $text .= "نقره‌ای: " . get_setting('ref_silver_count') . " دعوت | +" . get_setting('ref_silver_bonus_percent') . "٪\n";
    $text .= "طلایی: " . get_setting('ref_gold_count') . " دعوت | +" . get_setting('ref_gold_bonus_percent') . "٪\n";
    $text .= "VIP: " . get_setting('ref_vip_count') . " دعوت | +" . get_setting('ref_vip_bonus_percent') . "٪\n\n";
    $text .= "کل رفرال‌ها: {$totalRefs}\n";
    $text .= "کل پاداش‌ها: " . fmt($totalEarn) . " تومان";

    send_msg($chatId, $text, ikb([
        [btn(setting_bool('ref_enabled') ? '🔴 خاموش کردن' : '🟢 روشن کردن', 'admin.ref.toggle.enabled')],
        [btn('💰 پاداش عضویت', 'admin.ref.edit:ref_join_bonus'), btn('📊 درصد خرید', 'admin.ref.edit:ref_purchase_percent')],
        [btn('🔁 فقط اولین خرید', 'admin.ref.toggle.first'), btn('🔒 بعد عضویت اجباری', 'admin.ref.toggle.force')],
        [btn('🥈 حد نقره‌ای', 'admin.ref.edit:ref_silver_count'), btn('🥈 درصد نقره‌ای', 'admin.ref.edit:ref_silver_bonus_percent')],
        [btn('🥇 حد طلایی', 'admin.ref.edit:ref_gold_count'), btn('🥇 درصد طلایی', 'admin.ref.edit:ref_gold_bonus_percent')],
        [btn('💎 حد VIP', 'admin.ref.edit:ref_vip_count'), btn('💎 درصد VIP', 'admin.ref.edit:ref_vip_bonus_percent')],
        [btn('📋 آخرین رفرال‌ها', 'admin.ref.last')],
        back_admin(),
    ]));
}

function show_admin_user(int $chatId, int $target): void
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
    $s->execute([$target]);
    $u = $s->fetch();

    if (!$u) {
        send_msg($chatId, '❌ کاربر پیدا نشد.', ikb([[btn('🔙 کاربران', 'admin.users')]]));
        return;
    }

    $oc = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?");
    $oc->execute([$target]);

    $spent = $pdo->prepare("SELECT COALESCE(SUM(price),0) FROM orders WHERE user_id=? AND status IN ('pending','delivered','auto_delivered')");
    $spent->execute([$target]);

    $ban = ban_info($target);
    $name = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']) ?: '-';

    $text = "👤 اطلاعات کاربر\n\n";
    $text .= "🆔 {$target}\n";
    $text .= "👤 {$name}\n";
    $text .= "🔗 " . ($u['username'] ? '@' . $u['username'] : '-') . "\n";
    $text .= "📞 " . ($u['phone'] ?: '-') . "\n";
    $text .= "💰 موجودی: " . fmt((int)$u['balance']) . " تومان\n";
    $text .= "📦 سفارش‌ها: " . (int)$oc->fetchColumn() . "\n";
    $text .= "💳 خرید کل: " . fmt((int)$spent->fetchColumn()) . " تومان\n";
    $text .= "🏅 VIP: " . ((int)$u['vip'] ? 'بله' : 'خیر') . "\n";
    $text .= "🎁 دعوت‌ها: " . referral_count($target) . "\n";
    $text .= "💰 درآمد رفرال: " . fmt(referral_earnings($target)) . " تومان\n";
    $text .= "🚫 بن: " . ($ban ? 'بله' : 'خیر') . "\n";
    $text .= "📅 عضویت: {$u['join_date']}\n";
    $text .= "⏱ آخرین فعالیت: {$u['last_seen']}";

    send_msg($chatId, $text, ikb([
        [btn($ban ? '✅ آن‌بن' : '⛔ بن دائم', $ban ? 'admin.user.unban:' . $target : 'admin.user.ban:' . $target), btn('🟡 بن ۲۴ساعته', 'admin.user.tban:' . $target)],
        [btn('💰 تغییر موجودی', 'admin.user.balance:' . $target), btn(((int)$u['vip'] ? '❌ حذف VIP' : '🏅 VIP کردن'), 'admin.user.vip.toggle:' . $target)],
        [btn('📦 سفارش‌ها', 'admin.user.orders:' . $target), btn('📜 تراکنش‌ها', 'admin.user.txs:' . $target)],
        [btn('🎁 رفرال‌های کاربر', 'admin.user.refs:' . $target), btn('📩 پیام مستقیم', 'admin.user.msg:' . $target)],
        [btn('🔙 کاربران', 'admin.users')],
    ]));
}

/* ===================== BROADCAST ===================== */

function broadcast_users(string $target): array
{
    global $pdo;

    if ($target === 'buyers') {
        return array_map('intval', $pdo->query("SELECT DISTINCT user_id FROM orders")->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($target === 'nobuy') {
        return array_map('intval', $pdo->query("SELECT user_id FROM users WHERE banned=0 AND user_id NOT IN (SELECT DISTINCT user_id FROM orders)")->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($target === 'vip') {
        return array_map('intval', $pdo->query("SELECT user_id FROM users WHERE vip=1 AND banned=0")->fetchAll(PDO::FETCH_COLUMN));
    }

    return array_map('intval', $pdo->query("SELECT user_id FROM users WHERE banned=0")->fetchAll(PDO::FETCH_COLUMN));
}

function broadcast_step(int $broadcastId, int $limit = 25): array
{
    global $pdo;

    $s = $pdo->prepare("SELECT * FROM broadcasts WHERE id=?");
    $s->execute([$broadcastId]);
    $job = $s->fetch();

    if (!$job) {
        return ['text' => '❌ همگانی پیدا نشد.', 'done' => true];
    }

    $users = json_decode((string)$job['users_json'], true);
    if (!is_array($users)) {
        $users = [];
    }

    $offset = (int)$job['offset_pos'];
    $sent = (int)$job['sent'];
    $failed = (int)$job['failed'];
    $total = count($users);

    $end = min($offset + $limit, $total);

    for ($i = $offset; $i < $end; $i++) {
        $target = (int)$users[$i];

        if ((string)$job['mode'] === 'forward') {
            $r = forward_msg($target, (int)$job['from_chat_id'], (int)$job['message_id']);
        } else {
            $r = copy_msg($target, (int)$job['from_chat_id'], (int)$job['message_id']);
        }

        if ($r['ok'] ?? false) {
            $sent++;
        } else {
            $failed++;
        }

        usleep(80000);
    }

    $newOffset = $end;
    $done = $newOffset >= $total;

    $pdo->prepare("UPDATE broadcasts SET offset_pos=?, sent=?, failed=?, status=?, finished_at=? WHERE id=?")
        ->execute([
            $newOffset,
            $sent,
            $failed,
            $done ? 'done' : 'running',
            $done ? now_time() : '',
            $broadcastId,
        ]);

    $text = "📢 گزارش همگانی #{$broadcastId}\n\n";
    $text .= "کل: {$total}\n";
    $text .= "ارسال موفق: {$sent}\n";
    $text .= "ناموفق: {$failed}\n";
    $text .= "پیشرفت: {$newOffset}/{$total}\n";
    $text .= $done ? "\n✅ تمام شد." : "\nبرای ادامه دکمه زیر را بزن.";

    return ['text' => $text, 'done' => $done];
}

/* ===================== GAMES / GIVEAWAY ===================== */

function game_win(string $key, int $value): bool
{
    if ($key === 'dice') {
        return $value >= 5;
    }
    if ($key === 'dart') {
        return $value === 6;
    }
    if ($key === 'slot') {
        return $value >= 50;
    }
    if ($key === 'football' || $key === 'basketball') {
        return $value >= 4;
    }
    if ($key === 'bowling') {
        return $value >= 5;
    }
    return false;
}

function giveaway_count(int $giveawayId): int
{
    global $pdo;
    $s = $pdo->prepare("SELECT COUNT(*) FROM giveaway_entries WHERE giveaway_id=?");
    $s->execute([$giveawayId]);
    return (int)$s->fetchColumn();
}

/* ===================== STATE HANDLER ===================== */

function handle_state(int $uid, int $chatId, array $message, array $state): void
{
    global $pdo;

    $name = (string)$state['state'];
    $data = (array)($state['data'] ?? []);
    $text = msg_text($message);

    if ($name === 'user_search') {
        $q = '%' . trim($text) . '%';
        $s = $pdo->prepare("SELECT * FROM products WHERE active=1 AND (name LIKE ? OR description LIKE ?) ORDER BY id DESC LIMIT 30");
        $s->execute([$q, $q]);
        $products = $s->fetchAll();

        if (!$products) {
            send_msg($chatId, '❌ محصولی پیدا نشد.', ikb([back_home()]));
        } else {
            $kb = [];
            foreach ($products as $p) {
                $kb[] = [btn('📦 ' . $p['name'] . ' | ' . fmt((int)$p['price']) . ' تومان', 'prd:' . $p['id'])];
            }
            $kb[] = back_home();
            send_msg($chatId, '🔍 نتایج جستجو:', ikb($kb));
        }

        state_clear($uid);
        return;
    }

    if ($name === 'user_support') {
        if (!supported_message($message)) {
            send_msg($chatId, '❌ پیام معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        foreach (admin_ids() as $aid) {
            send_msg($aid, "📞 پیام پشتیبانی از کاربر {$uid}", ikb([
                [btn('👤 اطلاعات کاربر', 'admin.user.view:' . $uid), btn('📩 پاسخ', 'admin.user.msg:' . $uid)],
            ]));

            if (isset($message['text'])) {
                send_msg($aid, (string)$message['text']);
            } else {
                copy_msg($aid, $chatId, (int)$message['message_id']);
            }
        }

        send_msg($chatId, get_setting('support_sent_msg'), home_kb($uid));
        state_clear($uid);
        return;
    }

    if ($name === 'topup_amount') {
        $amount = to_amount($text);

        if ($amount <= 0) {
            send_msg($chatId, '❌ مبلغ معتبر نیست. دوباره بفرست:', ikb([cancel_btn()]));
            return;
        }

        state_set($uid, 'topup_receipt', [
            'card_id' => (int)$data['card_id'],
            'amount' => $amount,
        ]);

        send_msg($chatId, get_setting('topup_receipt_msg'), ikb([cancel_btn()]));
        return;
    }

    if ($name === 'topup_receipt') {
        if (!supported_message($message)) {
            send_msg($chatId, '❌ رسید معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $pdo->prepare("
            INSERT INTO topup_requests (user_id,card_id,amount,status,receipt_preview,created_at)
            VALUES (?, ?, ?, 'pending', ?, ?)
        ")->execute([
            $uid,
            (int)$data['card_id'],
            (int)$data['amount'],
            cut(message_preview($message), 1000),
            now_time(),
        ]);

        $requestId = (int)$pdo->lastInsertId();
        $request = topup_by_id($requestId);

        if ($request) {
            notify_topup($request);
            foreach (admin_ids() as $aid) {
                copy_msg($aid, $chatId, (int)$message['message_id']);
            }
        }

        send_msg($chatId, get_setting('topup_sent_msg') . "\n\n🧾 شماره درخواست: #{$requestId}", home_kb($uid));
        state_clear($uid);
        return;
    }

    if ($name === 'review_text') {
        $productId = (int)$data['product_id'];
        $rating = (int)$data['rating'];
        $review = trim($text) === '.' ? '' : $text;

        $pdo->prepare("
            INSERT OR REPLACE INTO product_reviews
            (id,user_id,product_id,rating,review_text,created_at)
            VALUES ((SELECT id FROM product_reviews WHERE user_id=? AND product_id=?), ?, ?, ?, ?, ?)
        ")->execute([$uid, $productId, $uid, $productId, $rating, $review, now_time()]);

        send_msg($chatId, '✅ نظر شما ثبت شد.', home_kb($uid));
        state_clear($uid);
        return;
    }

    if (!is_admin($uid)) {
        state_clear($uid);
        send_msg($chatId, get_setting('invalid_cmd_msg'), home_kb($uid));
        return;
    }

    if ($name === 'admin_cat_add') {
        if ($text === '') {
            send_msg($chatId, '❌ نام دسته خالی است.', ikb([cancel_btn()]));
            return;
        }

        try {
            $pdo->prepare("INSERT INTO categories (name,active,created_at) VALUES (?,1,?)")
                ->execute([$text, now_time()]);

            send_msg($chatId, '✅ دسته اضافه شد.', ikb([[btn('🗂 دسته‌ها', 'admin.cats')]]));
            log_admin($uid, 'add_category', $text);
        } catch (Throwable $e) {
            send_msg($chatId, '❌ این دسته قبلاً وجود دارد.', ikb([[btn('🗂 دسته‌ها', 'admin.cats')]]));
        }

        state_clear($uid);
        return;
    }

    if ($name === 'admin_prod_search') {
        $q = '%' . trim($text) . '%';
        $s = $pdo->prepare("SELECT * FROM products WHERE name LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 30");
        $s->execute([$q, $q]);
        $products = $s->fetchAll();

        $out = "🔍 نتیجه جستجوی محصول\n\n";
        $kb = [];

        if (!$products) {
            $out .= 'محصولی پیدا نشد.';
        }

        foreach ($products as $p) {
            $out .= "#{$p['id']} | {$p['name']} | " . fmt((int)$p['price']) . "\n";
            $kb[] = [btn('✏️ ' . $p['name'], 'admin.prod.edit:' . $p['id'])];
        }

        $kb[] = [btn('🔙 محصولات', 'admin.products')];

        send_msg($chatId, cut($out, 3900), ikb($kb));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_prod_name') {
        if ($text === '') {
            send_msg($chatId, '❌ نام خالی است.', ikb([cancel_btn()]));
            return;
        }

        $data['name'] = $text;
        state_set($uid, 'admin_prod_price', $data);
        send_msg($chatId, '💰 قیمت را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_prod_price') {
        $price = to_amount($text);

        if ($price < 0) {
            send_msg($chatId, '❌ قیمت معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $data['price'] = $price;
        state_set($uid, 'admin_prod_desc', $data);
        send_msg($chatId, '📝 توضیحات محصول را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_prod_desc') {
        $data['description'] = $text;
        state_set($uid, 'admin_prod_photo', $data);
        send_msg($chatId, "📷 عکس محصول را بفرست.\nاگر عکس نمی‌خواهی نقطه . بفرست", ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_prod_photo') {
        $photo = '';

        if (trim($text) !== '.') {
            if (!isset($message['photo'])) {
                send_msg($chatId, '❌ عکس بفرست یا نقطه . بفرست.', ikb([cancel_btn()]));
                return;
            }
            $photos = $message['photo'];
            $last = end($photos);
            $photo = (string)($last['file_id'] ?? '');
        }

        $data['photo_file_id'] = $photo;
        state_set($uid, 'admin_prod_mode_wait', $data);

        send_msg($chatId, '🚚 نوع فروش محصول را انتخاب کن:', ikb([
            [btn('📥 مخزنی / خودکار', 'admin.prod.newmode:stock')],
            [btn('🧾 ثبت سفارش / دستی', 'admin.prod.newmode:manual')],
            [btn('🔀 ترکیبی', 'admin.prod.newmode:hybrid')],
            [btn('❌ لغو', 'cancel')],
        ]));
        return;
    }

    foreach ([
        'admin_prod_edit_name' => 'name',
        'admin_prod_edit_price' => 'price',
        'admin_prod_edit_desc' => 'description',
        'admin_prod_edit_limit' => 'max_per_user_daily',
        'admin_prod_edit_alert' => 'min_stock_alert',
    ] as $stateName => $field) {
        if ($name === $stateName) {
            $pid = (int)$data['product_id'];
            $value = in_array($field, ['price', 'max_per_user_daily', 'min_stock_alert'], true) ? to_amount($text) : $text;

            $pdo->prepare("UPDATE products SET {$field}=? WHERE id=?")->execute([$value, $pid]);

            send_msg($chatId, '✅ محصول ویرایش شد.', ikb([[btn('✏️ محصول', 'admin.prod.edit:' . $pid)]]));
            state_clear($uid);
            return;
        }
    }

    if ($name === 'admin_prod_edit_photo') {
        $pid = (int)$data['product_id'];
        $photo = '';

        if (trim($text) !== '.') {
            if (!isset($message['photo'])) {
                send_msg($chatId, '❌ عکس بفرست یا نقطه . برای حذف عکس.', ikb([cancel_btn()]));
                return;
            }
            $photos = $message['photo'];
            $last = end($photos);
            $photo = (string)($last['file_id'] ?? '');
        }

        $pdo->prepare("UPDATE products SET photo_file_id=? WHERE id=?")->execute([$photo, $pid]);
        send_msg($chatId, '✅ عکس محصول تغییر کرد.', ikb([[btn('✏️ محصول', 'admin.prod.edit:' . $pid)]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_stock_add_text') {
        $pid = (int)$data['product_id'];

        if ($text === '') {
            send_msg($chatId, '❌ متن آیتم خالی است.', ikb([cancel_btn()]));
            return;
        }

        add_stock_item($pid, ['type' => 'text', 'text' => $text], $uid);
        send_msg($chatId, '✅ آیتم متنی به مخزن اضافه شد.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . $pid)]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_stock_add_bulk') {
        $pid = (int)$data['product_id'];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                add_stock_item($pid, ['type' => 'text', 'text' => $line], $uid);
                $count++;
            }
        }

        send_msg($chatId, "✅ {$count} آیتم اضافه شد.", ikb([[btn('📥 مخزن', 'admin.stock.product:' . $pid)]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_stock_add_media') {
        $pid = (int)$data['product_id'];
        $payload = extract_payload($message);

        if (!$payload || ($payload['type'] ?? '') === 'text') {
            send_msg($chatId, '❌ عکس/فیلم/فایل/ویس/موزیک/استیکر بفرست.', ikb([cancel_btn()]));
            return;
        }

        add_stock_item($pid, $payload, $uid);
        send_msg($chatId, '✅ آیتم مدیا به مخزن اضافه شد.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . $pid)]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_stock_bundle') {
        $pid = (int)$data['product_id'];
        $payload = extract_payload($message);

        if (!$payload) {
            send_msg($chatId, '❌ این پیام قابل ذخیره نیست.', ikb([[btn('✅ پایان و ذخیره', 'admin.stock.bundle.finish')], cancel_btn()]));
            return;
        }

        $items = (array)($data['items'] ?? []);
        $items[] = $payload;
        $data['items'] = $items;

        state_set($uid, 'admin_stock_bundle', $data);

        send_msg($chatId, '✅ پیام به آیتم چندپیامی اضافه شد. تعداد: ' . count($items), ikb([
            [btn('✅ پایان و ذخیره', 'admin.stock.bundle.finish')],
            [btn('❌ لغو', 'cancel')],
        ]));
        return;
    }

    if ($name === 'admin_deliver_order') {
        $orderId = (int)$data['order_id'];
        $result = deliver_order($uid, $chatId, (int)$message['message_id'], $message, $orderId);

        send_msg($chatId, ($result['ok'] ?? false) ? "✅ سفارش #{$orderId} ارسال شد." : '❌ ارسال ناموفق بود.', ikb([[btn('📋 سفارش‌ها', 'admin.orders')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_cancel_order') {
        $orderId = (int)$data['order_id'];
        $reason = trim($text) === '.' ? '' : trim($text);
        $result = cancel_order_refund($uid, $orderId, $reason);

        send_msg($chatId, ($result['ok'] ?? false) ? "✅ سفارش #{$orderId} لغو شد." : '❌ لغو ناموفق بود.', ikb([[btn('📋 سفارش‌ها', 'admin.orders')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_order_search') {
        $number = to_amount($text);

        if ($number <= 0) {
            send_msg($chatId, '❌ عدد معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $s = $pdo->prepare("SELECT * FROM orders WHERE id=? OR user_id=? ORDER BY id DESC LIMIT 30");
        $s->execute([$number, $number]);
        $orders = $s->fetchAll();

        $out = "🔍 نتیجه جستجو\n\n";
        $kb = [];

        if (!$orders) {
            $out .= 'موردی پیدا نشد.';
        }

        foreach ($orders as $o) {
            $out .= "#{$o['id']} | {$o['user_id']} | {$o['product_name']} | " . status_fa((string)$o['status']) . "\n";
            $kb[] = [btn('👁 #' . $o['id'], 'admin.order.view:' . $o['id'])];
        }

        $kb[] = [btn('🔙 سفارش‌ها', 'admin.orders')];

        send_msg($chatId, cut($out, 3900), ikb($kb));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_card_title') {
        if ($text === '') {
            send_msg($chatId, '❌ عنوان خالی است.', ikb([cancel_btn()]));
            return;
        }
        state_set($uid, 'admin_card_number', ['title' => $text]);
        send_msg($chatId, 'شماره کارت را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_card_number') {
        if ($text === '') {
            send_msg($chatId, '❌ شماره کارت خالی است.', ikb([cancel_btn()]));
            return;
        }
        $data['card_number'] = $text;
        state_set($uid, 'admin_card_holder', $data);
        send_msg($chatId, 'نام صاحب کارت را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_card_holder') {
        if ($text === '') {
            send_msg($chatId, '❌ نام صاحب کارت خالی است.', ikb([cancel_btn()]));
            return;
        }
        $data['card_holder'] = $text;
        state_set($uid, 'admin_card_extra', $data);
        send_msg($chatId, "توضیحات کارت را بفرست.\nاگر نمی‌خواهی نقطه . بفرست", ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_card_extra') {
        $extra = trim($text) === '.' ? '' : $text;

        $pdo->prepare("INSERT INTO payment_cards (title,card_number,card_holder,extra_text,enabled,created_at) VALUES (?,?,?,?,1,?)")
            ->execute([$data['title'], $data['card_number'], $data['card_holder'], $extra, now_time()]);

        send_msg($chatId, '✅ کارت اضافه شد.', ikb([[btn('💳 کارت‌ها', 'admin.cards')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_topup_reject') {
        $requestId = (int)$data['request_id'];
        $reason = trim($text) === '.' ? '' : trim($text);
        $ok = reject_topup($uid, $requestId, $reason);

        send_msg($chatId, $ok ? "✅ درخواست #{$requestId} رد شد." : '❌ رد ناموفق بود.', ikb([[btn('➕ شارژها', 'admin.topups')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_balance_user') {
        $target = to_amount($text);

        if ($target <= 0) {
            send_msg($chatId, '❌ آیدی معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        state_set($uid, 'admin_balance_amount', ['target' => $target]);
        send_msg($chatId, 'مبلغ مثبت یا منفی را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_balance_amount') {
        $raw = norm_digits(trim($text));
        if (!preg_match('/^-?\d+$/', $raw)) {
            send_msg($chatId, '❌ عدد معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $delta = (int)$raw;
        $target = (int)$data['target'];

        $pdo->prepare("INSERT OR IGNORE INTO users (user_id,join_date,last_seen) VALUES (?,?,?)")
            ->execute([$target, now_time(), now_time()]);

        $pdo->prepare("UPDATE users SET balance=balance+? WHERE user_id=?")->execute([$delta, $target]);

        add_wallet_tx($target, $delta, 'admin', 'manual balance change');

        send_msg($chatId, '✅ موجودی تغییر کرد.', ikb([[btn('👤 کاربر', 'admin.user.view:' . $target)]]));
        send_msg($target, '💰 موجودی شما تغییر کرد: ' . ($delta >= 0 ? '+' : '-') . fmt(abs($delta)) . ' تومان', home_kb($target));

        state_clear($uid);
        return;
    }

    if ($name === 'admin_user_search') {
        $q = '%' . trim($text) . '%';

        $s = $pdo->prepare("
            SELECT * FROM users
            WHERE CAST(user_id AS TEXT) LIKE ?
               OR first_name LIKE ?
               OR last_name LIKE ?
               OR username LIKE ?
               OR phone LIKE ?
            ORDER BY last_seen DESC
            LIMIT 30
        ");
        $s->execute([$q, $q, $q, $q, $q]);
        $users = $s->fetchAll();

        $out = "🔍 نتایج جستجوی کاربر\n\n";
        $kb = [];

        if (!$users) {
            $out .= 'کاربری پیدا نشد.';
        }

        foreach ($users as $u) {
            $out .= "{$u['user_id']} | " . ($u['username'] ? '@' . $u['username'] : '-') . " | " . fmt((int)$u['balance']) . "\n";
            $kb[] = [btn('👤 ' . $u['user_id'], 'admin.user.view:' . $u['user_id'])];
        }

        $kb[] = [btn('🔙 کاربران', 'admin.users')];

        send_msg($chatId, cut($out, 3900), ikb($kb));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_user_msg') {
        $target = (int)$data['target'];

        if (!supported_message($message)) {
            send_msg($chatId, '❌ پیام معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $r = isset($message['text'])
            ? send_msg($target, (string)$message['text'])
            : copy_msg($target, $chatId, (int)$message['message_id']);

        send_msg($chatId, ($r['ok'] ?? false) ? '✅ پیام ارسال شد.' : '❌ ارسال ناموفق بود.', ikb([[btn('👤 کاربر', 'admin.user.view:' . $target)]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_broadcast_wait') {
        if (!supported_message($message)) {
            send_msg($chatId, '❌ پیام معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $users = broadcast_users((string)$data['target']);

        $pdo->prepare("
            INSERT INTO broadcasts
            (mode,target,users_json,from_chat_id,message_id,offset_pos,total,sent,failed,status,created_by,created_at)
            VALUES (?,?,?,?,?,0,?,0,0,'running',?,?)
        ")->execute([
            (string)$data['mode'],
            (string)$data['target'],
            json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $chatId,
            (int)$message['message_id'],
            count($users),
            $uid,
            now_time(),
        ]);

        $bid = (int)$pdo->lastInsertId();
        $res = broadcast_step($bid, 25);

        $kb = $res['done']
            ? [[btn('🔙 مدیریت', 'admin.home')]]
            : [[btn('▶️ ادامه ارسال', 'admin.bc.next:' . $bid)], [btn('🔙 مدیریت', 'admin.home')]];

        send_msg($chatId, $res['text'], ikb($kb));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_force_chat') {
        $chatIdInput = trim($text);

        if ($chatIdInput === '') {
            send_msg($chatId, '❌ آیدی خالی است.', ikb([cancel_btn()]));
            return;
        }

        $data['chat_id'] = $chatIdInput;
        $title = $chatIdInput;
        $join = '';

        $chat = api('getChat', ['chat_id' => $chatIdInput]);

        if ($chat['ok'] ?? false) {
            $title = trim((string)($chat['result']['title'] ?? $chatIdInput));
            $username = trim((string)($chat['result']['username'] ?? ''));

            if ($username !== '') {
                $join = 'https://t.me/' . $username;
            } else {
                $join = trim((string)($chat['result']['invite_link'] ?? ''));
            }
        }

        $data['title'] = $title;
        $data['join_link'] = $join;

        state_set($uid, 'admin_force_title', $data);
        send_msg($chatId, "عنوان نمایشی را بفرست.\nاگر همین خوب است نقطه . بفرست\n\n{$title}", ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_force_title') {
        if (trim($text) !== '.') {
            $data['title'] = trim($text);
        }

        state_set($uid, 'admin_force_link', $data);
        send_msg($chatId, "لینک عضویت را بفرست.\nاگر لینک فعلی خوب است نقطه . بفرست", ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_force_link') {
        if (trim($text) !== '.') {
            $data['join_link'] = trim($text);
        }

        $pdo->prepare("
            INSERT INTO force_items (item_type,chat_id,title,join_link,checkable,enabled,created_at)
            VALUES (?,?,?,?,1,1,?)
        ")->execute([
            $data['type'],
            $data['chat_id'],
            $data['title'],
            $data['join_link'],
            now_time(),
        ]);

        send_msg($chatId, '✅ مورد عضویت اجباری اضافه شد.', ikb([[btn('📢 عضویت اجباری', 'admin.force')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_force_display_title') {
        if ($text === '') {
            send_msg($chatId, '❌ عنوان خالی است.', ikb([cancel_btn()]));
            return;
        }

        $data['title'] = $text;
        state_set($uid, 'admin_force_display_link', $data);
        send_msg($chatId, 'لینک را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_force_display_link') {
        if ($text === '') {
            send_msg($chatId, '❌ لینک خالی است.', ikb([cancel_btn()]));
            return;
        }

        $pdo->prepare("
            INSERT INTO force_items (item_type,chat_id,title,join_link,checkable,enabled,created_at)
            VALUES (?, '', ?, ?, 0, 1, ?)
        ")->execute([
            $data['type'],
            $data['title'],
            $text,
            now_time(),
        ]);

        send_msg($chatId, '✅ مورد نمایشی اضافه شد.', ikb([[btn('📢 عضویت اجباری', 'admin.force')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_text_edit') {
        $key = (string)$data['key'];
        set_setting($key, $text);

        send_msg($chatId, "✅ متن تغییر کرد.\nکلید: {$key}", ikb([[btn('📝 متن‌ها', 'admin.texts')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_btn_edit') {
        $key = (string)$data['key'];
        set_setting($key, $text);

        send_msg($chatId, "✅ دکمه تغییر کرد.\nکلید: {$key}", ikb([[btn('🎛 مدیریت دکمه‌ها', 'admin.buttons')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_ref_edit') {
        $key = (string)$data['key'];
        $value = to_amount($text);
        set_setting($key, (string)$value);

        send_msg($chatId, "✅ تنظیم رفرال تغییر کرد.\n{$key} = {$value}", ikb([[btn('🎁 رفرال', 'admin.ref')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_give_title') {
        if ($text === '') {
            send_msg($chatId, '❌ عنوان خالی است.', ikb([cancel_btn()]));
            return;
        }

        state_set($uid, 'admin_give_desc', ['title' => $text]);
        send_msg($chatId, 'توضیح قرعه‌کشی را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_give_desc') {
        $data['description'] = $text;
        state_set($uid, 'admin_give_winners', $data);
        send_msg($chatId, 'تعداد برنده‌ها را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($name === 'admin_give_winners') {
        $count = max(1, to_amount($text));

        $pdo->prepare("INSERT INTO giveaways (title,description,winners_count,active,created_by,created_at) VALUES (?,?,?,1,?,?)")
            ->execute([$data['title'], $data['description'], $count, $uid, now_time()]);

        send_msg($chatId, '✅ قرعه‌کشی ساخته شد.', ikb([[btn('🎁 قرعه‌کشی', 'admin.give')]]));
        state_clear($uid);
        return;
    }

    if ($name === 'admin_add_admin') {
        $target = to_amount($text);

        if ($target <= 0) {
            send_msg($chatId, '❌ آیدی معتبر نیست.', ikb([cancel_btn()]));
            return;
        }

        $pdo->prepare("INSERT OR IGNORE INTO admins (user_id,role,created_at) VALUES (?,'admin',?)")
            ->execute([$target, now_time()]);

        send_msg($chatId, '✅ ادمین اضافه شد.', ikb([[btn('👨‍💼 ادمین‌ها', 'admin.admins')]]));
        state_clear($uid);
        return;
    }

    state_clear($uid);
    send_msg($chatId, '✅ عملیات لغو شد.', admin_kb());
}

/* ===================== CALLBACK HANDLER ===================== */

function handle_callback(array $callback): void
{
    global $pdo;

    $cbid = (string)($callback['id'] ?? '');

    if (!isset($callback['from']['id'], $callback['message']['chat']['id'])) {
        return;
    }

    $uid = (int)$callback['from']['id'];
    $chatId = (int)$callback['message']['chat']['id'];
    $data = (string)($callback['data'] ?? '');

    ensure_user($callback['from']);
    answer_cb($cbid);

    if ($data === 'cancel') {
        state_clear($uid);
        send_msg($chatId, '✅ عملیات لغو شد.', is_admin($uid) ? admin_kb() : home_kb($uid));
        return;
    }

    if ($data === 'home') {
        state_clear($uid);
        show_home($chatId, $uid);
        return;
    }

    if ($data === 'admin.home') {
        if (!is_admin($uid)) {
            send_msg($chatId, '❌ دسترسی نداری.', home_kb($uid));
            return;
        }
        state_clear($uid);
        show_admin($chatId);
        return;
    }

    if (is_maint() && !is_admin($uid)) {
        send_msg($chatId, get_setting('maintenance_msg'));
        return;
    }

    if (ban_info($uid) && !is_admin($uid)) {
        send_msg($chatId, '⛔ شما مسدود هستید.');
        return;
    }

    if ($data === 'fj.confirm') {
        if (missing_force_items($uid)) {
            send_msg($chatId, "❌ هنوز عضویت کامل نیست.\n\n" . force_text($uid), force_kb($uid));
        } else {
            try_reward_join_referral($uid);
            send_msg($chatId, '✅ عضویت تایید شد.', home_kb($uid));
        }
        return;
    }

    if (!is_admin($uid) && missing_force_items($uid) && !in_array($data, ['u.join', 'fj.confirm'], true)) {
        send_msg($chatId, force_text($uid), force_kb($uid));
        return;
    }

    if ($data === 'u.buy') {
        show_categories($chatId);
        return;
    }

    if (preg_match('/^cat:(\d+)$/', $data, $m)) {
        show_products($chatId, (int)$m[1]);
        return;
    }

    if (preg_match('/^prd:(\d+)$/', $data, $m)) {
        show_product($chatId, (int)$m[1]);
        return;
    }

    if ($data === 'u.search') {
        state_set($uid, 'user_search');
        send_msg($chatId, '🔍 نام محصول را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($data === 'u.profile') {
        show_profile($chatId, $uid);
        return;
    }

    if ($data === 'u.wallet') {
        show_wallet($chatId, $uid);
        return;
    }

    if ($data === 'u.orders') {
        show_user_orders($chatId, $uid);
        return;
    }

    if ($data === 'u.help') {
        send_msg($chatId, get_setting('help_msg'), ikb([back_home()]));
        return;
    }

    if ($data === 'u.join') {
        send_msg($chatId, force_text($uid), force_kb($uid));
        return;
    }

    if ($data === 'u.support') {
        state_set($uid, 'user_support');
        send_msg($chatId, get_setting('support_msg'), ikb([cancel_btn()]));
        return;
    }

    if ($data === 'u.ref') {
        show_referral($chatId, $uid);
        return;
    }

    if ($data === 'u.ref.logs') {
        $s = $pdo->prepare("SELECT * FROM referral_logs WHERE referrer_id=? ORDER BY id DESC LIMIT 20");
        $s->execute([$uid]);
        $logs = $s->fetchAll();

        $text = "📜 تاریخچه پاداش رفرال\n\n";
        if (!$logs) {
            $text .= 'پاداشی ثبت نشده.';
        }
        foreach ($logs as $log) {
            $text .= "#{$log['id']} | {$log['reward_type']} | زیرمجموعه: {$log['referred_id']} | +" . fmt((int)$log['amount']) . " | {$log['created_at']}\n";
        }

        send_msg($chatId, cut($text, 3900), ikb([[btn('🔙 دعوت دوستان', 'u.ref')], back_home()]));
        return;
    }

    if ($data === 'u.topup') {
        $cards = active_cards();
        if (!$cards) {
            send_msg($chatId, get_setting('topup_no_card_msg'), ikb([back_home()]));
            return;
        }

        $kb = [];
        foreach ($cards as $card) {
            $kb[] = [btn('💳 ' . $card['title'], 'topcard:' . $card['id'])];
        }
        $kb[] = back_home();

        send_msg($chatId, get_setting('topup_start_msg'), ikb($kb));
        return;
    }

    if (preg_match('/^topcard:(\d+)$/', $data, $m)) {
        $card = card_by_id((int)$m[1]);

        if (!$card || (int)$card['enabled'] !== 1) {
            send_msg($chatId, '❌ کارت در دسترس نیست.', ikb([back_home()]));
            return;
        }

        state_set($uid, 'topup_amount', ['card_id' => (int)$card['id']]);
        send_msg($chatId, card_text($card) . "\n\n" . get_setting('topup_amount_msg'), ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^buy:(\d+)$/', $data, $m)) {
        $productId = (int)$m[1];
        $product = product_by_id($productId);

        if (!$product || (int)$product['active'] !== 1) {
            send_msg($chatId, '❌ محصول در دسترس نیست.', ikb([back_home()]));
            return;
        }

        if ((string)$product['delivery_mode'] === 'stock' && available_stock_count($productId) <= 0) {
            send_msg($chatId, get_setting('out_stock_msg'), ikb([back_home()]));
            return;
        }

        $text = "🧾 تایید خرید\n\n";
        $text .= "📦 محصول: {$product['name']}\n";
        $text .= "💰 قیمت: " . fmt((int)$product['price']) . " تومان\n";
        $text .= "💳 موجودی شما: " . fmt(user_balance($uid)) . " تومان\n";
        $text .= "🚚 نوع: " . mode_fa((string)$product['delivery_mode']);

        send_msg($chatId, $text, ikb([
            [btn(get_setting('btn_pay'), 'pay:' . $productId)],
            [btn(get_setting('btn_cancel'), 'prd:' . $productId)],
            back_home(),
        ]));
        return;
    }

    if (preg_match('/^pay:(\d+)$/', $data, $m)) {
        $productId = (int)$m[1];
        $result = create_order_after_payment($uid, $productId);

        if ($result['ok']) {
            $orderId = (int)$result['order_id'];

            if (!($result['auto'] ?? false)) {
                send_msg($chatId, get_setting('order_pending_msg') . "\n\n🧾 شماره سفارش: #{$orderId}", ikb([
                    [btn('📦 مشاهده سفارش', 'myord:' . $orderId)],
                    back_home(),
                ]));
            }
            return;
        }

        $error = (string)($result['error'] ?? '');
        $msg = '❌ ثبت سفارش ناموفق بود.';

        if ($error === 'low_balance') {
            $msg = get_setting('low_balance_msg');
        } elseif ($error === 'out_of_stock') {
            $msg = get_setting('out_stock_msg');
        } elseif ($error === 'daily_limit') {
            $msg = '❌ محدودیت خرید روزانه این محصول پر شده است.';
        } elseif ($error === 'not_found') {
            $msg = '❌ محصول پیدا نشد یا غیرفعال است.';
        } elseif ($error === 'user_not_found') {
            $msg = '❌ کاربر در دیتابیس پیدا نشد. یکبار /start بزن.';
        } elseif ($error === 'exception' && is_admin($uid)) {
            $msg .= "\n\nDEBUG:\n" . ($result['debug'] ?? 'no debug');
        }

        send_msg($chatId, $msg, ikb([[btn('➕ شارژ کیف پول', 'u.topup')], back_home()]));
        return;
    }

    if (preg_match('/^myord:(\d+)$/', $data, $m)) {
        $order = order_by_id((int)$m[1]);

        if (!$order || (int)$order['user_id'] !== $uid) {
            send_msg($chatId, '❌ سفارش پیدا نشد.', ikb([back_home()]));
            return;
        }

        $kb = [];
        if ((int)$order['stock_item_id'] > 0) {
            $kb[] = [btn('📥 دریافت مجدد سرویس', 'resend:' . $order['id'])];
        }
        $kb[] = back_home();

        send_msg($chatId, order_user_text($order), ikb($kb));
        return;
    }

    if (preg_match('/^resend:(\d+)$/', $data, $m)) {
        $order = order_by_id((int)$m[1]);

        if (!$order || (int)$order['user_id'] !== $uid || (int)$order['stock_item_id'] <= 0) {
            send_msg($chatId, '❌ سرویس قابل ارسال مجدد نیست.', ikb([back_home()]));
            return;
        }

        $item = stock_item_by_id((int)$order['stock_item_id']);
        if (!$item) {
            send_msg($chatId, '❌ آیتم مخزن پیدا نشد.', ikb([back_home()]));
            return;
        }

        $payload = json_decode((string)$item['payload_json'], true);
        send_msg($chatId, "📥 ارسال مجدد سرویس سفارش #{$order['id']}:");
        if (is_array($payload)) {
            send_payload($chatId, $payload);
        }
        return;
    }

    if (preg_match('/^review:(\d+)$/', $data, $m)) {
        $productId = (int)$m[1];

        $s = $pdo->prepare("SELECT 1 FROM orders WHERE user_id=? AND product_id=? AND status IN ('delivered','auto_delivered') LIMIT 1");
        $s->execute([$uid, $productId]);

        if (!$s->fetchColumn()) {
            send_msg($chatId, '⭐ امتیاز: ' . avg_rating_text($productId) . "\n\nبرای ثبت نظر باید سفارش تحویل‌شده داشته باشی.", ikb([back_home()]));
            return;
        }

        send_msg($chatId, '⭐ امتیاز خود را انتخاب کن:', ikb([
            [
                btn('1⭐', 'rate:' . $productId . ':1'),
                btn('2⭐', 'rate:' . $productId . ':2'),
                btn('3⭐', 'rate:' . $productId . ':3'),
                btn('4⭐', 'rate:' . $productId . ':4'),
                btn('5⭐', 'rate:' . $productId . ':5'),
            ],
            back_home(),
        ]));
        return;
    }

    if (preg_match('/^rate:(\d+):([1-5])$/', $data, $m)) {
        state_set($uid, 'review_text', [
            'product_id' => (int)$m[1],
            'rating' => (int)$m[2],
        ]);

        send_msg($chatId, '📝 نظر خود را بنویس. اگر فقط امتیاز می‌دهی نقطه . بفرست.', ikb([cancel_btn()]));
        return;
    }

    if ($data === 'u.games') {
        $games = $pdo->query("SELECT * FROM game_settings WHERE enabled=1 ORDER BY rowid ASC")->fetchAll();
        $kb = [];

        foreach ($games as $game) {
            $kb[] = [btn((string)$game['title'], 'game:' . $game['game_key'])];
        }

        $kb[] = back_home();

        send_msg($chatId, '🎮 یک چالش انتخاب کن:', ikb($kb));
        return;
    }

    if (preg_match('/^game:([a-z_]+)$/', $data, $m)) {
        $key = $m[1];

        $s = $pdo->prepare("SELECT * FROM game_settings WHERE game_key=? AND enabled=1");
        $s->execute([$key]);
        $game = $s->fetch();

        if (!$game) {
            send_msg($chatId, '❌ بازی غیرفعال است.', ikb([back_home()]));
            return;
        }

        $r = api('sendDice', ['chat_id' => $chatId, 'emoji' => (string)$game['emoji']]);

        if (!($r['ok'] ?? false)) {
            send_msg($chatId, '❌ اجرای بازی ناموفق بود.', ikb([back_home()]));
            return;
        }

        $value = (int)($r['result']['dice']['value'] ?? 0);
        $win = game_win($key, $value) ? 1 : 0;

        $pdo->prepare("INSERT INTO game_logs (user_id,game_key,result_value,is_win,created_at) VALUES (?,?,?,?,?)")
            ->execute([$uid, $key, $value, $win, now_time()]);

        send_msg($chatId, "🎮 نتیجه {$game['title']}\n\n🎯 عدد: {$value}\n" . ($win ? '✅ بردی' : '❌ باختی'), home_kb($uid));
        return;
    }

    if ($data === 'u.give') {
        $gives = $pdo->query("SELECT * FROM giveaways WHERE active=1 ORDER BY id DESC")->fetchAll();

        if (!$gives) {
            send_msg($chatId, '🎁 قرعه‌کشی فعالی وجود ندارد.', ikb([back_home()]));
            return;
        }

        $kb = [];
        foreach ($gives as $g) {
            $kb[] = [btn('🎟 ' . $g['title'] . ' | شرکت‌کننده: ' . giveaway_count((int)$g['id']), 'give.join:' . $g['id'])];
        }
        $kb[] = back_home();

        send_msg($chatId, '🎁 قرعه‌کشی‌های فعال:', ikb($kb));
        return;
    }

    if (preg_match('/^give\.join:(\d+)$/', $data, $m)) {
        $gid = (int)$m[1];

        $s = $pdo->prepare("SELECT * FROM giveaways WHERE id=? AND active=1");
        $s->execute([$gid]);
        $g = $s->fetch();

        if (!$g) {
            send_msg($chatId, '❌ قرعه‌کشی فعال نیست.', ikb([back_home()]));
            return;
        }

        try {
            $pdo->prepare("INSERT INTO giveaway_entries (giveaway_id,user_id,created_at) VALUES (?,?,?)")
                ->execute([$gid, $uid, now_time()]);
            send_msg($chatId, "✅ در قرعه‌کشی «{$g['title']}» شرکت کردی.\n👥 تعداد شرکت‌کننده: " . giveaway_count($gid), home_kb($uid));
        } catch (Throwable $e) {
            send_msg($chatId, 'ℹ️ قبلاً شرکت کرده‌ای.', ikb([back_home()]));
        }
        return;
    }

    if (!is_admin($uid)) {
        return;
    }

    if ($data === 'admin.cats') {
        admin_cats($chatId);
        return;
    }

    if ($data === 'admin.products') {
        admin_products($chatId);
        return;
    }

    if ($data === 'admin.stock') {
        admin_stock_products($chatId);
        return;
    }

    if ($data === 'admin.orders') {
        admin_orders($chatId);
        return;
    }

    if ($data === 'admin.fin') {
        admin_fin($chatId);
        return;
    }

    if ($data === 'admin.users') {
        admin_users($chatId);
        return;
    }

    if ($data === 'admin.force') {
        admin_force($chatId);
        return;
    }

    if ($data === 'admin.texts') {
        admin_texts($chatId);
        return;
    }

    if ($data === 'admin.buttons') {
        admin_buttons($chatId);
        return;
    }

    if ($data === 'admin.ref') {
        admin_referral($chatId);
        return;
    }

    if ($data === 'admin.maint') {
        $enabled = is_maint() ? 0 : 1;
        $pdo->exec("UPDATE maintenance SET enabled={$enabled}");
        send_msg($chatId, $enabled ? '✅ حالت نگهداری فعال شد.' : '✅ حالت نگهداری غیرفعال شد.', admin_kb());
        return;
    }

    if ($data === 'admin.stats') {
        $users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $products = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $orders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
        $stock = (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE status='available'")->fetchColumn();
        $sold = (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE status='sold'")->fetchColumn();
        $sales = (int)$pdo->query("SELECT COALESCE(SUM(price),0) FROM orders WHERE status IN ('pending','delivered','auto_delivered')")->fetchColumn();
        $topups = (int)$pdo->query("SELECT COUNT(*) FROM topup_requests WHERE status='pending'")->fetchColumn();
        $refs = (int)$pdo->query("SELECT COUNT(*) FROM referrals")->fetchColumn();

        send_msg($chatId, "📊 آمار\n\n👥 کاربران: {$users}\n📦 محصولات: {$products}\n🛍 سفارش‌ها: {$orders}\n⏳ باز: {$pending}\n📥 موجودی مخزن: {$stock}\n🛒 فروخته‌شده مخزن: {$sold}\n➕ شارژهای باز: {$topups}\n🎁 رفرال‌ها: {$refs}\n💰 فروش ثبت‌شده: " . fmt($sales) . " تومان", ikb([back_admin()]));
        return;
    }

    if ($data === 'admin.cat.add') {
        state_set($uid, 'admin_cat_add');
        send_msg($chatId, 'نام دسته را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.cat\.toggle:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE categories SET active=CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([(int)$m[1]]);
        admin_cats($chatId);
        return;
    }

    if (preg_match('/^admin\.cat\.del:(\d+)$/', $data, $m)) {
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$m[1]]);
        admin_cats($chatId);
        return;
    }

    if ($data === 'admin.prod.add') {
        $cats = $pdo->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll();

        if (!$cats) {
            send_msg($chatId, '❌ اول یک دسته بساز.', ikb([[btn('➕ دسته', 'admin.cat.add')]]));
            return;
        }

        $kb = [];
        foreach ($cats as $cat) {
            $kb[] = [btn('🗂 ' . $cat['name'], 'admin.prod.cat:' . $cat['id'])];
        }
        $kb[] = [btn('🔙 محصولات', 'admin.products')];

        send_msg($chatId, 'دسته محصول را انتخاب کن:', ikb($kb));
        return;
    }

    if (preg_match('/^admin\.prod\.cat:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_prod_name', ['cat_id' => (int)$m[1]]);
        send_msg($chatId, 'نام محصول را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.prod\.newmode:(stock|manual|hybrid)$/', $data, $m)) {
        $state = state_get($uid);

        if (!$state || $state['state'] !== 'admin_prod_mode_wait') {
            send_msg($chatId, '❌ اطلاعات محصول پیدا نشد.', admin_kb());
            return;
        }

        $d = $state['data'];
        $mode = $m[1];

        $pdo->prepare("
            INSERT INTO products
            (cat_id,name,price,description,photo_file_id,active,delivery_mode,created_at)
            VALUES (?,?,?,?,?,1,?,?)
        ")->execute([
            (int)$d['cat_id'],
            (string)$d['name'],
            (int)$d['price'],
            (string)$d['description'],
            (string)$d['photo_file_id'],
            $mode,
            now_time(),
        ]);

        $pid = (int)$pdo->lastInsertId();
        state_clear($uid);

        send_msg($chatId, '✅ محصول اضافه شد.', ikb([
            [btn('📥 مدیریت مخزن این محصول', 'admin.stock.product:' . $pid)],
            [btn('📦 محصولات', 'admin.products')],
        ]));
        return;
    }

    if ($data === 'admin.prod.list') {
        $products = $pdo->query("SELECT * FROM products ORDER BY id DESC LIMIT 80")->fetchAll();

        if (!$products) {
            send_msg($chatId, '❌ محصولی وجود ندارد.', ikb([[btn('➕ افزودن', 'admin.prod.add')], back_admin()]));
            return;
        }

        $kb = [];
        foreach ($products as $p) {
            $kb[] = [btn('✏️ ' . $p['name'] . ' | ' . fmt((int)$p['price']) . ' | ' . mode_fa((string)$p['delivery_mode']), 'admin.prod.edit:' . $p['id'])];
        }
        $kb[] = [btn('🔙 محصولات', 'admin.products')];

        send_msg($chatId, '📋 لیست محصولات:', ikb($kb));
        return;
    }

    if ($data === 'admin.prod.search') {
        state_set($uid, 'admin_prod_search');
        send_msg($chatId, 'نام محصول را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.prod\.edit:(\d+)$/', $data, $m)) {
        $pid = (int)$m[1];
        $p = product_by_id($pid);

        if (!$p) {
            send_msg($chatId, '❌ محصول پیدا نشد.');
            return;
        }

        $text = "✏️ محصول #{$pid}\n\n";
        $text .= "📦 {$p['name']}\n";
        $text .= "💰 " . fmt((int)$p['price']) . " تومان\n";
        $text .= "🚚 " . mode_fa((string)$p['delivery_mode']) . "\n";
        $text .= "📥 موجودی مخزن: " . available_stock_count($pid) . "\n";
        $text .= "وضعیت: " . ((int)$p['active'] ? 'فعال' : 'غیرفعال');

        send_msg($chatId, $text, ikb([
            [btn('نام', 'admin.prod.edit.name:' . $pid), btn('قیمت', 'admin.prod.edit.price:' . $pid)],
            [btn('توضیحات', 'admin.prod.edit.desc:' . $pid), btn('عکس', 'admin.prod.edit.photo:' . $pid)],
            [btn('مخزنی', 'admin.prod.mode.stock:' . $pid), btn('دستی', 'admin.prod.mode.manual:' . $pid), btn('ترکیبی', 'admin.prod.mode.hybrid:' . $pid)],
            [btn('محدودیت روزانه', 'admin.prod.edit.limit:' . $pid), btn('حد هشدار مخزن', 'admin.prod.edit.alert:' . $pid)],
            [btn('📥 مخزن', 'admin.stock.product:' . $pid), btn('🔄 فعال/غیرفعال', 'admin.prod.toggle:' . $pid)],
            [btn('🗑 حذف', 'admin.prod.del:' . $pid)],
            [btn('🔙 محصولات', 'admin.products')],
        ]));
        return;
    }

    $editMap = [
        'name' => 'admin_prod_edit_name',
        'price' => 'admin_prod_edit_price',
        'desc' => 'admin_prod_edit_desc',
        'photo' => 'admin_prod_edit_photo',
        'limit' => 'admin_prod_edit_limit',
        'alert' => 'admin_prod_edit_alert',
    ];

    if (preg_match('/^admin\.prod\.edit\.(name|price|desc|photo|limit|alert):(\d+)$/', $data, $m)) {
        state_set($uid, $editMap[$m[1]], ['product_id' => (int)$m[2]]);
        send_msg($chatId, 'مقدار جدید را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.prod\.mode\.(stock|manual|hybrid):(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE products SET delivery_mode=? WHERE id=?")->execute([$m[1], (int)$m[2]]);
        send_msg($chatId, '✅ نوع فروش تغییر کرد.', ikb([[btn('✏️ محصول', 'admin.prod.edit:' . (int)$m[2])]]));
        return;
    }

    if (preg_match('/^admin\.prod\.toggle:(\d+)$/', $data, $m)) {
        $pid = (int)$m[1];
        $pdo->prepare("UPDATE products SET active=CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$pid]);
        send_msg($chatId, '✅ وضعیت تغییر کرد.', ikb([[btn('✏️ محصول', 'admin.prod.edit:' . $pid)]]));
        return;
    }

    if (preg_match('/^admin\.prod\.del:(\d+)$/', $data, $m)) {
        $pid = (int)$m[1];
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
        send_msg($chatId, '✅ محصول حذف شد.', ikb([[btn('📦 محصولات', 'admin.products')]]));
        return;
    }

    if (preg_match('/^admin\.stock\.product:(\d+)$/', $data, $m)) {
        admin_stock_menu($chatId, (int)$m[1]);
        return;
    }

    if (preg_match('/^admin\.stock\.addtext:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_stock_add_text', ['product_id' => (int)$m[1]]);
        send_msg($chatId, 'متن آیتم مخزن را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.stock\.addbulk:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_stock_add_bulk', ['product_id' => (int)$m[1]]);
        send_msg($chatId, "لیست آیتم‌ها را بفرست.\nهر خط = یک آیتم", ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.stock\.addmedia:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_stock_add_media', ['product_id' => (int)$m[1]]);
        send_msg($chatId, 'عکس/فیلم/فایل/ویس/موزیک/استیکر را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.stock\.addbundle:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_stock_bundle', ['product_id' => (int)$m[1], 'items' => []]);
        send_msg($chatId, "🧾 آیتم چندپیامی شروع شد.\nهر پیام بفرستی به همین آیتم اضافه می‌شود.", ikb([
            [btn('✅ پایان و ذخیره', 'admin.stock.bundle.finish')],
            [btn('❌ لغو', 'cancel')],
        ]));
        return;
    }

    if ($data === 'admin.stock.bundle.finish') {
        $state = state_get($uid);

        if (!$state || $state['state'] !== 'admin_stock_bundle') {
            send_msg($chatId, '❌ آیتمی در حال ساخت نیست.');
            return;
        }

        $d = $state['data'];
        $items = (array)($d['items'] ?? []);
        $pid = (int)$d['product_id'];

        if (!$items) {
            state_clear($uid);
            send_msg($chatId, '❌ هیچ پیامی اضافه نشده.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . $pid)]]));
            return;
        }

        add_stock_item($pid, ['type' => 'bundle', 'items' => $items], $uid);
        state_clear($uid);
        send_msg($chatId, '✅ آیتم چندپیامی ذخیره شد.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . $pid)]]));
        return;
    }

    if (preg_match('/^admin\.stock\.(list|sold):(\d+):(\d+)$/', $data, $m)) {
        $kind = $m[1];
        $pid = (int)$m[2];
        $page = (int)$m[3];
        $status = $kind === 'sold' ? 'sold' : 'available';
        $offset = $page * 10;

        $s = $pdo->prepare("SELECT * FROM stock_items WHERE product_id=? AND status=? ORDER BY id DESC LIMIT 10 OFFSET ?");
        $s->bindValue(1, $pid, PDO::PARAM_INT);
        $s->bindValue(2, $status);
        $s->bindValue(3, $offset, PDO::PARAM_INT);
        $s->execute();
        $items = $s->fetchAll();

        $text = ($status === 'sold' ? '🛒 فروخته‌شده‌ها' : '📋 موجودهای مخزن') . "\n\n";
        $kb = [];

        if (!$items) {
            $text .= 'موردی نیست.';
        }

        foreach ($items as $item) {
            $text .= "#{$item['id']} | {$item['item_type']} | {$item['preview']}\n";
            $kb[] = [btn('👁 #' . $item['id'], 'admin.stock.view:' . $item['id'])];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = btn('⬅️ قبلی', 'admin.stock.' . $kind . ':' . $pid . ':' . ($page - 1));
        }
        if (count($items) === 10) {
            $nav[] = btn('➡️ بعدی', 'admin.stock.' . $kind . ':' . $pid . ':' . ($page + 1));
        }
        if ($nav) {
            $kb[] = $nav;
        }

        $kb[] = [btn('🔙 مخزن محصول', 'admin.stock.product:' . $pid)];

        send_msg($chatId, cut($text, 3900), ikb($kb));
        return;
    }

    if (preg_match('/^admin\.stock\.view:(\d+)$/', $data, $m)) {
        $item = stock_item_by_id((int)$m[1]);
        if (!$item) {
            send_msg($chatId, '❌ آیتم پیدا نشد.');
            return;
        }

        $text = "👁 آیتم مخزن #{$item['id']}\n\n";
        $text .= "📦 محصول: {$item['product_id']}\n";
        $text .= "نوع: {$item['item_type']}\n";
        $text .= "وضعیت: " . status_fa((string)$item['status']) . "\n";
        $text .= "فروخته به: " . ($item['sold_to'] ?: '-') . "\n";
        $text .= "زمان فروش: " . ($item['sold_at'] ?: '-') . "\n\n";
        $text .= "پیش‌نمایش:\n{$item['preview']}";

        $kb = [[btn('📤 ارسال تست به من', 'admin.stock.test:' . $item['id'])]];
        if ($item['status'] !== 'sold') {
            $kb[] = [btn('🗑 حذف', 'admin.stock.del:' . $item['id']), btn('🔄 فعال/غیرفعال', 'admin.stock.toggle:' . $item['id'])];
        }
        $kb[] = [btn('🔙 مخزن محصول', 'admin.stock.product:' . $item['product_id'])];

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.stock\.test:(\d+)$/', $data, $m)) {
        $item = stock_item_by_id((int)$m[1]);
        if (!$item) {
            return;
        }

        $payload = json_decode((string)$item['payload_json'], true);
        send_msg($chatId, '📤 تست آیتم:');

        if (is_array($payload)) {
            send_payload($chatId, $payload);
        }
        return;
    }

    if (preg_match('/^admin\.stock\.del:(\d+)$/', $data, $m)) {
        $item = stock_item_by_id((int)$m[1]);
        if ($item && $item['status'] !== 'sold') {
            $pdo->prepare("UPDATE stock_items SET status='deleted' WHERE id=?")->execute([(int)$m[1]]);
        }
        send_msg($chatId, '✅ آیتم حذف شد.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . ($item['product_id'] ?? 0))]]));
        return;
    }

    if (preg_match('/^admin\.stock\.toggle:(\d+)$/', $data, $m)) {
        $item = stock_item_by_id((int)$m[1]);
        if ($item && $item['status'] !== 'sold') {
            $new = $item['status'] === 'available' ? 'disabled' : 'available';
            $pdo->prepare("UPDATE stock_items SET status=? WHERE id=?")->execute([$new, (int)$m[1]]);
        }
        send_msg($chatId, '✅ وضعیت آیتم تغییر کرد.', ikb([[btn('📥 مخزن', 'admin.stock.product:' . ($item['product_id'] ?? 0))]]));
        return;
    }

    if ($data === 'admin.orders.pending') {
        admin_orders_list($chatId, 'pending');
        return;
    }

    if ($data === 'admin.orders.all') {
        admin_orders_list($chatId, 'all');
        return;
    }

    if ($data === 'admin.orders.search') {
        state_set($uid, 'admin_order_search');
        send_msg($chatId, 'شماره سفارش یا آیدی کاربر را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.order\.view:(\d+)$/', $data, $m)) {
        $order = order_by_id((int)$m[1]);
        if (!$order) {
            send_msg($chatId, '❌ سفارش پیدا نشد.');
            return;
        }

        $kb = [];
        if ($order['status'] === 'pending') {
            $kb[] = [btn('📤 ارسال سرویس', 'admin.order.deliver:' . $order['id'])];
            $kb[] = [btn('❌ لغو و برگشت وجه', 'admin.order.cancel:' . $order['id'])];
        }
        if ((int)$order['stock_item_id'] > 0) {
            $kb[] = [btn('📤 ارسال تست آیتم به ادمین', 'admin.stock.test:' . $order['stock_item_id'])];
        }
        $kb[] = [btn('🔙 سفارش‌ها', 'admin.orders')];

        send_msg($chatId, order_admin_text($order), ikb($kb));
        return;
    }

    if (preg_match('/^admin\.order\.deliver:(\d+)$/', $data, $m)) {
        $order = order_by_id((int)$m[1]);
        if (!$order || $order['status'] !== 'pending') {
            send_msg($chatId, '❌ سفارش قابل ارسال نیست.');
            return;
        }

        state_set($uid, 'admin_deliver_order', ['order_id' => (int)$m[1]]);
        send_msg($chatId, "📤 سرویس سفارش #{$m[1]} را بفرست:", ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.order\.cancel:(\d+)$/', $data, $m)) {
        $order = order_by_id((int)$m[1]);
        if (!$order || $order['status'] !== 'pending') {
            send_msg($chatId, '❌ سفارش قابل لغو نیست.');
            return;
        }

        state_set($uid, 'admin_cancel_order', ['order_id' => (int)$m[1]]);
        send_msg($chatId, "علت لغو سفارش #{$m[1]} را بفرست. اگر علت نمی‌خواهی نقطه . بفرست", ikb([cancel_btn()]));
        return;
    }

    if ($data === 'admin.cards') {
        $cards = $pdo->query("SELECT * FROM payment_cards ORDER BY id DESC")->fetchAll();

        $text = "💳 کارت‌ها\n\n";
        $kb = [[btn('➕ افزودن کارت', 'admin.card.add')]];

        if (!$cards) {
            $text .= 'کارتی ثبت نشده.';
        }

        foreach ($cards as $card) {
            $text .= "#{$card['id']} | {$card['title']} | {$card['card_number']} | " . ((int)$card['enabled'] ? 'روشن' : 'خاموش') . "\n";
            $kb[] = [
                btn('🔄 ' . $card['title'], 'admin.card.toggle:' . $card['id']),
                btn('🗑 حذف', 'admin.card.del:' . $card['id']),
            ];
        }

        $kb[] = [btn('🔙 مالی', 'admin.fin')];

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if ($data === 'admin.card.add') {
        state_set($uid, 'admin_card_title');
        send_msg($chatId, 'عنوان کارت را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.card\.toggle:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE payment_cards SET enabled=CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")
            ->execute([(int)$m[1]]);
        send_msg($chatId, '✅ وضعیت کارت تغییر کرد.', ikb([[btn('💳 کارت‌ها', 'admin.cards')]]));
        return;
    }

    if (preg_match('/^admin\.card\.del:(\d+)$/', $data, $m)) {
        $pdo->prepare("DELETE FROM payment_cards WHERE id=?")->execute([(int)$m[1]]);
        send_msg($chatId, '✅ کارت حذف شد.', ikb([[btn('💳 کارت‌ها', 'admin.cards')]]));
        return;
    }

    if ($data === 'admin.topups') {
        $requests = $pdo->query("SELECT * FROM topup_requests WHERE status='pending' ORDER BY id ASC LIMIT 30")->fetchAll();

        $text = "➕ شارژهای باز\n\n";
        $kb = [];

        if (!$requests) {
            $text .= 'درخواست بازی نیست.';
        }

        foreach ($requests as $r) {
            $text .= "#{$r['id']} | {$r['user_id']} | " . fmt((int)$r['amount']) . " تومان\n";
            $kb[] = [
                btn('👁 #' . $r['id'], 'admin.topup.view:' . $r['id']),
                btn('✅ تایید', 'admin.topup.ok:' . $r['id']),
                btn('❌ رد', 'admin.topup.reject:' . $r['id']),
            ];
        }

        $kb[] = [btn('🔙 مالی', 'admin.fin')];

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.topup\.view:(\d+)$/', $data, $m)) {
        $r = topup_by_id((int)$m[1]);

        if (!$r) {
            send_msg($chatId, '❌ درخواست پیدا نشد.');
            return;
        }

        $card = card_by_id((int)$r['card_id']);

        $text = "➕ درخواست شارژ #{$r['id']}\n\n";
        $text .= "👤 کاربر: {$r['user_id']}\n";
        $text .= "💰 مبلغ: " . fmt((int)$r['amount']) . " تومان\n";
        $text .= "💳 کارت: " . ($card ? $card['title'] : '-') . "\n";
        $text .= "📌 وضعیت: " . status_fa((string)$r['status']) . "\n";
        $text .= "📅 {$r['created_at']}\n\n";
        $text .= "📄 پیش‌نمایش رسید:\n{$r['receipt_preview']}";

        $kb = [];
        if ($r['status'] === 'pending') {
            $kb[] = [
                btn('✅ تایید', 'admin.topup.ok:' . $r['id']),
                btn('❌ رد', 'admin.topup.reject:' . $r['id']),
            ];
        }
        $kb[] = [btn('🔙 شارژها', 'admin.topups')];

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.topup\.ok:(\d+)$/', $data, $m)) {
        $ok = approve_topup($uid, (int)$m[1]);
        send_msg($chatId, $ok ? "✅ درخواست #{$m[1]} تایید شد." : '❌ قابل تایید نیست.', ikb([[btn('➕ شارژها', 'admin.topups')]]));
        return;
    }

    if (preg_match('/^admin\.topup\.reject:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_topup_reject', ['request_id' => (int)$m[1]]);
        send_msg($chatId, "علت رد درخواست #{$m[1]} را بفرست. اگر علت نمی‌خواهی نقطه . بفرست", ikb([cancel_btn()]));
        return;
    }

    if ($data === 'admin.balance.user') {
        state_set($uid, 'admin_balance_user');
        send_msg($chatId, 'آیدی کاربر را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if ($data === 'admin.txs') {
        $rows = $pdo->query("SELECT * FROM wallet_transactions ORDER BY id DESC LIMIT 30")->fetchAll();
        $text = "📜 آخرین تراکنش‌ها\n\n";
        foreach ($rows as $r) {
            $text .= "#{$r['id']} | {$r['user_id']} | " . fmt((int)$r['amount']) . " | {$r['type']} | {$r['created_at']}\n";
        }
        send_msg($chatId, cut($text, 3900), ikb([[btn('🔙 مالی', 'admin.fin')]]));
        return;
    }

    if ($data === 'admin.user.search') {
        state_set($uid, 'admin_user_search');
        send_msg($chatId, 'آیدی، نام، یوزرنیم یا شماره کاربر را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.user\.(list|banned|vip):(\d+)$/', $data, $m)) {
        $kind = $m[1];
        $page = (int)$m[2];
        $offset = $page * 10;

        $where = '';
        if ($kind === 'banned') {
            $where = "WHERE banned=1 OR banned_until<>''";
        } elseif ($kind === 'vip') {
            $where = "WHERE vip=1";
        }

        $users = $pdo->query("SELECT * FROM users {$where} ORDER BY last_seen DESC LIMIT 10 OFFSET {$offset}")->fetchAll();

        $text = "👥 کاربران\n\n";
        $kb = [];

        if (!$users) {
            $text .= 'موردی نیست.';
        }

        foreach ($users as $u) {
            $text .= "{$u['user_id']} | " . ($u['username'] ? '@' . $u['username'] : '-') . " | " . fmt((int)$u['balance']) . "\n";
            $kb[] = [btn('👤 ' . $u['user_id'], 'admin.user.view:' . $u['user_id'])];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = btn('⬅️ قبلی', 'admin.user.' . $kind . ':' . ($page - 1));
        }
        if (count($users) === 10) {
            $nav[] = btn('➡️ بعدی', 'admin.user.' . $kind . ':' . ($page + 1));
        }
        if ($nav) {
            $kb[] = $nav;
        }
        $kb[] = [btn('🔙 کاربران', 'admin.users')];

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.user\.view:(\d+)$/', $data, $m)) {
        show_admin_user($chatId, (int)$m[1]);
        return;
    }

    if (preg_match('/^admin\.user\.ban:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE users SET banned=1,banned_reason='بن توسط مدیریت',banned_until='' WHERE user_id=?")->execute([(int)$m[1]]);
        send_msg($chatId, '✅ کاربر بن شد.', ikb([[btn('👤 کاربر', 'admin.user.view:' . (int)$m[1])]]));
        return;
    }

    if (preg_match('/^admin\.user\.tban:(\d+)$/', $data, $m)) {
        $until = date('Y-m-d H:i:s', time() + 86400);
        $pdo->prepare("UPDATE users SET banned=0,banned_until=?,banned_reason='بن موقت ۲۴ ساعته' WHERE user_id=?")
            ->execute([$until, (int)$m[1]]);
        send_msg($chatId, '✅ کاربر ۲۴ ساعت بن شد.', ikb([[btn('👤 کاربر', 'admin.user.view:' . (int)$m[1])]]));
        return;
    }

    if (preg_match('/^admin\.user\.unban:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE users SET banned=0,banned_until='',banned_reason='' WHERE user_id=?")->execute([(int)$m[1]]);
        send_msg($chatId, '✅ کاربر آن‌بن شد.', ikb([[btn('👤 کاربر', 'admin.user.view:' . (int)$m[1])]]));
        return;
    }

    if (preg_match('/^admin\.user\.vip\.toggle:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE users SET vip=CASE WHEN vip=1 THEN 0 ELSE 1 END WHERE user_id=?")->execute([(int)$m[1]]);
        send_msg($chatId, '✅ وضعیت VIP تغییر کرد.', ikb([[btn('👤 کاربر', 'admin.user.view:' . (int)$m[1])]]));
        return;
    }

    if (preg_match('/^admin\.user\.balance:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_balance_amount', ['target' => (int)$m[1]]);
        send_msg($chatId, 'مبلغ مثبت یا منفی را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.user\.msg:(\d+)$/', $data, $m)) {
        state_set($uid, 'admin_user_msg', ['target' => (int)$m[1]]);
        send_msg($chatId, 'پیام مستقیم را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.user\.orders:(\d+)$/', $data, $m)) {
        $target = (int)$m[1];
        $s = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 20");
        $s->execute([$target]);
        $orders = $s->fetchAll();

        $text = "📦 سفارش‌های کاربر {$target}\n\n";
        $kb = [];

        if (!$orders) {
            $text .= 'سفارشی ندارد.';
        }

        foreach ($orders as $o) {
            $text .= "#{$o['id']} | {$o['product_name']} | " . status_fa((string)$o['status']) . "\n";
            $kb[] = [btn('👁 #' . $o['id'], 'admin.order.view:' . $o['id'])];
        }

        $kb[] = [btn('🔙 کاربر', 'admin.user.view:' . $target)];
        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.user\.txs:(\d+)$/', $data, $m)) {
        $target = (int)$m[1];
        $s = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 20");
        $s->execute([$target]);
        $rows = $s->fetchAll();

        $text = "📜 تراکنش‌های {$target}\n\n";
        foreach ($rows as $r) {
            $text .= "#{$r['id']} | " . fmt((int)$r['amount']) . " | {$r['type']} | {$r['created_at']}\n";
        }

        send_msg($chatId, cut($text, 3900), ikb([[btn('🔙 کاربر', 'admin.user.view:' . $target)]]));
        return;
    }

    if (preg_match('/^admin\.user\.refs:(\d+)$/', $data, $m)) {
        $target = (int)$m[1];
        $s = $pdo->prepare("SELECT * FROM referrals WHERE referrer_id=? ORDER BY id DESC LIMIT 30");
        $s->execute([$target]);
        $refs = $s->fetchAll();

        $text = "🎁 رفرال‌های کاربر {$target}\n\n";
        if (!$refs) {
            $text .= 'رفرالی ندارد.';
        }
        foreach ($refs as $ref) {
            $text .= "👤 {$ref['referred_id']} | join_reward: {$ref['rewarded_join']} | first_buy: {$ref['first_purchase_rewarded']} | {$ref['created_at']}\n";
        }

        send_msg($chatId, cut($text, 3900), ikb([[btn('🔙 کاربر', 'admin.user.view:' . $target)]]));
        return;
    }

    if ($data === 'admin.broadcast') {
        send_msg($chatId, "📢 همگانی مرحله‌ای\n\nکپی بدون اسم فرستنده است.\nفوروارد واقعی با اسم منبع ارسال می‌شود.", ikb([
            [btn('📋 Copy همه', 'admin.bc.copy:all'), btn('🔁 Forward همه', 'admin.bc.forward:all')],
            [btn('📋 Copy خریداران', 'admin.bc.copy:buyers'), btn('📋 Copy بدون خرید', 'admin.bc.copy:nobuy')],
            [btn('📋 Copy VIP', 'admin.bc.copy:vip')],
            back_admin(),
        ]));
        return;
    }

    if (preg_match('/^admin\.bc\.(copy|forward):(all|buyers|nobuy|vip)$/', $data, $m)) {
        state_set($uid, 'admin_broadcast_wait', [
            'mode' => $m[1],
            'target' => $m[2],
        ]);

        send_msg($chatId, "📢 پیام همگانی را بفرست.\nحالت: {$m[1]}\nمخاطب: {$m[2]}", ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.bc\.next:(\d+)$/', $data, $m)) {
        $res = broadcast_step((int)$m[1], 25);
        $kb = $res['done']
            ? [[btn('🔙 مدیریت', 'admin.home')]]
            : [[btn('▶️ ادامه ارسال', 'admin.bc.next:' . (int)$m[1])], [btn('🔙 مدیریت', 'admin.home')]];

        send_msg($chatId, $res['text'], ikb($kb));
        return;
    }

    if (preg_match('/^admin\.force\.add:(channel|group|folder|link)$/', $data, $m)) {
        $type = $m[1];

        if ($type === 'channel' || $type === 'group') {
            state_set($uid, 'admin_force_chat', ['type' => $type]);
            send_msg($chatId, "آیدی یا یوزرنیم را بفرست:\n@username\nیا\n-1001234567890", ikb([cancel_btn()]));
        } else {
            state_set($uid, 'admin_force_display_title', ['type' => $type]);
            send_msg($chatId, $type === 'folder' ? 'عنوان فولدر را بفرست:' : 'عنوان لینک را بفرست:', ikb([cancel_btn()]));
        }
        return;
    }

    if (preg_match('/^admin\.force\.toggle:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE force_items SET enabled=CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")->execute([(int)$m[1]]);
        admin_force($chatId);
        return;
    }

    if (preg_match('/^admin\.force\.del:(\d+)$/', $data, $m)) {
        $pdo->prepare("DELETE FROM force_items WHERE id=?")->execute([(int)$m[1]]);
        admin_force($chatId);
        return;
    }

    if (preg_match('/^admin\.text\.page:(\d+)$/', $data, $m)) {
        admin_texts($chatId, (int)$m[1]);
        return;
    }

    if (preg_match('/^admin\.text\.edit:(\d+)$/', $data, $m)) {
        $keys = array_keys(text_meta());
        $idx = (int)$m[1];

        if (!isset($keys[$idx])) {
            return;
        }

        $key = $keys[$idx];
        state_set($uid, 'admin_text_edit', ['key' => $key]);

        send_msg($chatId, "📝 تغییر متن\n\nکلید: {$key}\nعنوان: " . text_meta()[$key] . "\n\nمقدار فعلی:\n" . get_setting($key) . "\n\nمقدار جدید را بفرست:", ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.btn\.edit:(.+)$/', $data, $m)) {
        $key = $m[1];

        if (!array_key_exists($key, button_meta())) {
            return;
        }

        state_set($uid, 'admin_btn_edit', ['key' => $key]);

        send_msg($chatId, "✏️ متن جدید دکمه «" . button_meta()[$key] . "» را بفرست:\n\nفعلی:\n" . get_setting($key), ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.btn\.toggle:(.+)$/', $data, $m)) {
        $key = $m[1];
        $settingKey = 'enabled_' . $key;
        set_setting($settingKey, get_setting($settingKey) === '1' ? '0' : '1');
        admin_buttons($chatId);
        return;
    }

    if ($data === 'admin.ref.toggle.enabled') {
        set_setting('ref_enabled', setting_bool('ref_enabled') ? '0' : '1');
        admin_referral($chatId);
        return;
    }

    if ($data === 'admin.ref.toggle.first') {
        set_setting('ref_first_purchase_only', setting_bool('ref_first_purchase_only') ? '0' : '1');
        admin_referral($chatId);
        return;
    }

    if ($data === 'admin.ref.toggle.force') {
        set_setting('ref_require_force_join', setting_bool('ref_require_force_join') ? '0' : '1');
        admin_referral($chatId);
        return;
    }

    if (preg_match('/^admin\.ref\.edit:(.+)$/', $data, $m)) {
        $key = $m[1];
        $allowed = [
            'ref_join_bonus', 'ref_purchase_percent', 'ref_silver_count', 'ref_silver_bonus_percent',
            'ref_gold_count', 'ref_gold_bonus_percent', 'ref_vip_count', 'ref_vip_bonus_percent'
        ];

        if (!in_array($key, $allowed, true)) {
            return;
        }

        state_set($uid, 'admin_ref_edit', ['key' => $key]);
        send_msg($chatId, "مقدار جدید را بفرست:\n\n{$key}\nفعلی: " . get_setting($key), ikb([cancel_btn()]));
        return;
    }

    if ($data === 'admin.ref.last') {
        $refs = $pdo->query("SELECT * FROM referrals ORDER BY id DESC LIMIT 30")->fetchAll();

        $text = "📋 آخرین رفرال‌ها\n\n";
        if (!$refs) {
            $text .= 'موردی نیست.';
        }
        foreach ($refs as $ref) {
            $text .= "#{$ref['id']} | معرف: {$ref['referrer_id']} | کاربر: {$ref['referred_id']} | {$ref['created_at']}\n";
        }

        send_msg($chatId, cut($text, 3900), ikb([[btn('🔙 رفرال', 'admin.ref')]]));
        return;
    }

    if ($data === 'admin.games') {
        $games = $pdo->query("SELECT * FROM game_settings ORDER BY rowid ASC")->fetchAll();

        $text = "🎮 مدیریت چالش\n\n";
        $kb = [];

        foreach ($games as $g) {
            $text .= "{$g['title']} | " . ((int)$g['enabled'] ? 'روشن' : 'خاموش') . "\n";
            $kb[] = [btn(((int)$g['enabled'] ? 'خاموش ' : 'روشن ') . $g['title'], 'admin.game.toggle:' . $g['game_key'])];
        }

        $kb[] = back_admin();

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if (preg_match('/^admin\.game\.toggle:([a-z_]+)$/', $data, $m)) {
        $pdo->prepare("UPDATE game_settings SET enabled=CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE game_key=?")
            ->execute([$m[1]]);
        send_msg($chatId, '✅ وضعیت بازی تغییر کرد.', ikb([[btn('🎮 چالش', 'admin.games')]]));
        return;
    }

    if ($data === 'admin.give') {
        $gives = $pdo->query("SELECT * FROM giveaways ORDER BY id DESC LIMIT 30")->fetchAll();

        $text = "🎁 مدیریت قرعه‌کشی\n\n";
        $kb = [[btn('➕ قرعه جدید', 'admin.give.new')]];

        if (!$gives) {
            $text .= 'قرعه‌ای ثبت نشده.';
        }

        foreach ($gives as $g) {
            $text .= "#{$g['id']} | {$g['title']} | شرکت‌کننده: " . giveaway_count((int)$g['id']) . " | " . ((int)$g['active'] ? 'فعال' : 'بسته') . "\n";

            if ((int)$g['active']) {
                $kb[] = [
                    btn('🏆 انتخاب برنده #' . $g['id'], 'admin.give.pick:' . $g['id']),
                    btn('❌ بستن', 'admin.give.close:' . $g['id']),
                ];
            }
        }

        $kb[] = back_admin();

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if ($data === 'admin.give.new') {
        state_set($uid, 'admin_give_title');
        send_msg($chatId, 'عنوان قرعه‌کشی را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.give\.close:(\d+)$/', $data, $m)) {
        $pdo->prepare("UPDATE giveaways SET active=0 WHERE id=?")->execute([(int)$m[1]]);
        send_msg($chatId, '✅ قرعه بسته شد.', ikb([[btn('🎁 قرعه', 'admin.give')]]));
        return;
    }

    if (preg_match('/^admin\.give\.pick:(\d+)$/', $data, $m)) {
        $gid = (int)$m[1];

        $s = $pdo->prepare("SELECT * FROM giveaways WHERE id=? AND active=1");
        $s->execute([$gid]);
        $give = $s->fetch();

        if (!$give) {
            send_msg($chatId, '❌ قرعه معتبر نیست.');
            return;
        }

        $e = $pdo->prepare("SELECT user_id FROM giveaway_entries WHERE giveaway_id=?");
        $e->execute([$gid]);
        $users = array_map('intval', $e->fetchAll(PDO::FETCH_COLUMN));

        if (!$users) {
            send_msg($chatId, '❌ شرکت‌کننده‌ای ندارد.');
            return;
        }

        shuffle($users);
        $winners = array_slice($users, 0, max(1, (int)$give['winners_count']));

        $pdo->prepare("UPDATE giveaways SET active=0,winners_json=? WHERE id=?")
            ->execute([json_encode($winners, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $gid]);

        $text = "🏆 برنده‌های «{$give['title']}»:\n\n";
        foreach ($winners as $w) {
            $text .= "👤 {$w}\n";
            send_msg($w, "🎉 تبریک! شما برنده قرعه‌کشی «{$give['title']}» شدی.", home_kb($w));
        }

        send_msg($chatId, $text, ikb([[btn('🎁 قرعه', 'admin.give')]]));
        return;
    }

    if ($data === 'admin.admins') {
        $admins = $pdo->query("SELECT * FROM admins ORDER BY user_id ASC")->fetchAll();

        $text = "👨‍💼 ادمین‌ها\n\n";
        $kb = [[btn('➕ افزودن ادمین', 'admin.admin.add')]];

        foreach ($admins as $a) {
            $text .= "{$a['user_id']} | {$a['role']}\n";
            if (!in_array((int)$a['user_id'], ADMIN_IDS, true)) {
                $kb[] = [btn('🗑 حذف ' . $a['user_id'], 'admin.admin.del:' . $a['user_id'])];
            }
        }

        $kb[] = back_admin();

        send_msg($chatId, $text, ikb($kb));
        return;
    }

    if ($data === 'admin.admin.add') {
        state_set($uid, 'admin_add_admin');
        send_msg($chatId, 'آیدی عددی ادمین را بفرست:', ikb([cancel_btn()]));
        return;
    }

    if (preg_match('/^admin\.admin\.del:(\d+)$/', $data, $m)) {
        $target = (int)$m[1];
        if (!in_array($target, ADMIN_IDS, true)) {
            $pdo->prepare("DELETE FROM admins WHERE user_id=?")->execute([$target]);
        }
        send_msg($chatId, '✅ ادمین حذف شد.', ikb([[btn('👨‍💼 ادمین‌ها', 'admin.admins')]]));
        return;
    }
}

/* ===================== MESSAGE HANDLER ===================== */

function handle_message(array $message): void
{
    $chatId = (int)($message['chat']['id'] ?? 0);
    $uid = (int)($message['from']['id'] ?? 0);

    if ($chatId === 0 || $uid === 0) {
        return;
    }

    $text = msg_text($message);
    $isNew = ensure_user($message['from'], $message['contact'] ?? null);

    if (is_maint() && !is_admin($uid)) {
        send_msg($chatId, get_setting('maintenance_msg'));
        return;
    }

    if (ban_info($uid) && !is_admin($uid)) {
        send_msg($chatId, '⛔ شما مسدود هستید.');
        return;
    }

    if (preg_match('/^\/start(?:\s+(\S+))?$/u', $text, $m)) {
        state_clear($uid);

        $payload = (string)($m[1] ?? '');
        if (sw($payload, 'ref_')) {
            handle_referral_start($uid, (int)substr($payload, 4), $isNew);
        }

        if (!is_admin($uid) && missing_force_items($uid)) {
            send_msg($chatId, force_text($uid), force_kb($uid));
            return;
        }

        try_reward_join_referral($uid);
        show_home($chatId, $uid);
        return;
    }

    if (!is_admin($uid) && missing_force_items($uid)) {
        send_msg($chatId, force_text($uid), force_kb($uid));
        return;
    }

    $state = state_get($uid);
    if ($state) {
        handle_state($uid, $chatId, $message, $state);
        return;
    }

    send_msg($chatId, get_setting('invalid_cmd_msg'), home_kb($uid));
}

/* ===================== ENTRY ===================== */

if (WEBHOOK_SECRET !== '') {
    $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secret !== WEBHOOK_SECRET) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$input = file_get_contents('php://input');

if (!$input) {
    echo 'Star Shop Final Webhook Active';
    exit;
}

$update = json_decode($input, true);

if (!is_array($update)) {
    echo 'Invalid JSON';
    exit;
}

try {
    if (isset($update['message'])) {
        handle_message($update['message']);
    } elseif (isset($update['callback_query'])) {
        handle_callback($update['callback_query']);
    }

    echo 'OK';
} catch (Throwable $e) {
    error_log('PROCESS ERROR: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
    echo 'ERR';
}
