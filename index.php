<?php
/**
 * =============================================================================
 *  WEBHOOK — barcha so'rovlar shu yerga keladi
 *  Webhook o'rnatish:
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://domen.uz/index.php
 * =============================================================================
 */
@ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tashkent');

// STARSBOT_TESTING konstantasi avtomatik testlar uchun: tests/run.php o'zining
// sandbox config.test.php faylini ulaydi va bu yerda config.php qayta ulanib,
// test qiymatlarini ustidan yozib yubormasligi uchun shu tekshiruv kerak.
if (!defined('STARSBOT_TESTING')) {
    require __DIR__ . '/config.php';
}
require __DIR__ . '/core/db.php';
require __DIR__ . '/core/tg.php';
require __DIR__ . '/core/service.php';
require __DIR__ . '/handlers/user.php';
require __DIR__ . '/handlers/admin.php';

/** Bot joylashgan domen (API havolalari uchun). */
function bot_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $url = ($https ? 'https://' : 'http://') . $host . $dir;
    // Baza hali tayyor bo'lmasa ham manzil qaytariladi
    try {
        $saved = cfg('base_url');
        if ($saved === '' || $saved !== $url) { cfg_set('base_url', $url); }
    } catch (Exception $e) {}
    return $url;
}

function answer_cb_safe($chat, $text) { say($chat, "⚠️ " . $text); }

// Haqiqiy webhook so'rovini qayta ishlash — faqat ishlab chiqarishda (CLI test
// rejimida STARSBOT_TESTING belgilangan bo'lib, bu butun blok o'tkazib
// yuboriladi, lekin pastdagi barcha funksiyalar baribir e'lon qilinadi).
if (!defined('STARSBOT_TESTING')) {

// --- O'rnatish tekshiruvi (brauzerdan ochilganda) ---------------------------
if (php_sapi_name() !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['nothing'])) {
    header('Content-Type: application/json; charset=utf-8');
    $muammo = array();
    if (BOT_TOKEN === '') { $muammo[] = 'config.php da BOT_TOKEN bo\'sh.'; }
    if (!ADMIN_ID)        { $muammo[] = 'config.php da ADMIN_ID bo\'sh.'; }
    $db_ok = false; $db_err = '';
    try { db_ensure(); db()->query("SELECT 1 FROM settings LIMIT 1"); $db_ok = true; }
    catch (Exception $e) { $db_err = $e->getMessage(); $muammo[] = 'Baza: ' . $db_err; }
    if (!extension_loaded('curl')) { $muammo[] = 'PHP curl kengaytmasi yo\'q.'; }
    echo json_encode(array(
        'ok' => count($muammo) === 0,
        'muammolar' => $muammo ? $muammo : array('Hammasi joyida. Webhook o\'rnatishingiz mumkin.'),
        'php' => PHP_VERSION,
        'baza' => $db_ok ? 'ulandi' : 'xato',
        'jadvallar' => $db_ok ? (int)col("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?", array(DB_NAME)) : 0,
        'webhook_url' => bot_base_url() . '/index.php',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- Webhook maxfiy so'zi ---------------------------------------------------
if (WEBHOOK_SECRET !== '') {
    $got = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
    if (!hash_equals(WEBHOOK_SECRET, $got)) { http_response_code(403); exit; }
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

$raw = file_get_contents('php://input');
if (!$raw) { exit; }
$update = json_decode($raw, true);
if (!is_array($update)) { exit; }

try {
    db_ensure();
    route($update);
} catch (Exception $e) {
    logg('XATO: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} catch (Throwable $e) {
    logg('FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

} // !defined('STARSBOT_TESTING')

// =============================================================================
//  MARSHRUTLASH
// =============================================================================
function route($update)
{
    if (isset($update['callback_query'])) { on_callback($update['callback_query']); return; }
    $msg = isset($update['message']) ? $update['message'] : null;
    if (!$msg || !isset($msg['chat']['id'])) { return; }
    if (($msg['chat']['type'] ?? 'private') !== 'private') { return; }
    on_message($msg);
}

/** Foydalanuvchi botdan foydalana oladimi? (blok, ta'mir, obuna) */
function gate($chat, $user, $silent = false)
{
    if ($user['status'] === 'blocked') {
        if (!$silent) {
            say($chat, "🚫 <b>Siz bloklangansiz</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "Iltimos, administrator bilan bog'laning.");
        }
        return false;
    }
    if (is_admin($chat)) { return true; }

    if (cfg('maintenance') === '1') {
        if (!$silent) {
            say($chat, "🔧 <b>Botimiz ta'mirlanmoqda</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "Odatda bu 1 daqiqadan bir necha soatgacha cho'ziladi.\n"
                     . "Iltimos, keyinroq urinib ko'ring.");
        }
        return false;
    }
    $not = unsubscribed($chat);
    if ($not) { if (!$silent) { ask_subscribe($chat, $not); } return false; }
    return true;
}

// =============================================================================
//  MATNLI XABARLAR
// =============================================================================
function on_message($msg)
{
    $chat = (int)$msg['chat']['id'];
    $from = isset($msg['from']) ? $msg['from'] : array('id' => $chat);
    $text = isset($msg['text']) ? trim($msg['text']) : '';
    $user = user_touch($from);

    if ($text === '/start') {
        if (!gate($chat, $user)) { return; }
        u_start($chat, $user);
        return;
    }
    if ($text === '❌ Bekor qilish') {
        state_clear($chat);
        say($chat, "❌ Bekor qilindi.", is_admin($chat) ? kb_admin() : kb_main($chat));
        return;
    }

    $st = state_get($chat);

    // --- Holatga bog'liq kutilayotgan javoblar --------------------------------
    if ($st['state'] !== '') {
        if (handle_state($chat, $msg, $text, $st, $user)) { return; }
    }

    if (!gate($chat, $user)) { return; }

    // --- Admin tugmalari -----------------------------------------------------
    if (is_admin($chat) && handle_admin_button($chat, $text)) { return; }

    // --- Foydalanuvchi tugmalari --------------------------------------------
    switch ($text) {
        case '⭐️ Stars olish':        u_stars_menu($chat); return;
        case '🌟 Premium olish':      u_premium_menu($chat); return;
        case '🎁 Giftlar olish':      u_gift_sections($chat); return;
        case '🛒 Buyurtmalarim':      u_orders_menu($chat); return;
        case '💰 Hisobim':            u_account($chat); return;
        case "💳 Balans To'ldirish":  u_topup_menu($chat); return;
        case "📚 Qo'llanma":          u_guides($chat); return;
        case '☎️ Yordam markazi':     u_support($chat); return;
        case '🔑 Hamkorlik ( API )':  u_partner($chat); return;
        case '🧑‍💻 Admin panel':
            if (is_admin($chat)) { a_panel($chat); return; }
            break;
        case '🔙 Asosiy menyu':       u_start($chat, $user); return;
    }

    say($chat, "🤔 Bunday buyruq yo'q. Menyudan tanlang 👇", kb_main($chat));
}

function handle_admin_button($chat, $text)
{
    $map = array(
        '📊 Statistika'          => array('stats',    'a_stats'),
        '💰 Narxlarni sozlash'   => array('prices',   'a_prices'),
        "💳 To'lov tizimlari"    => array('pay',      'a_pay_menu'),
        '🛍 Gift sozlamalari'    => array('gifts',    'a_gift_menu'),
        '⚙️ Userni boshqarish'   => array('users',    'a_user_ask'),
        '📣 Kanal sozlamalari'   => array('channels', 'a_channels'),
        '🔑 Api sozlamalasi'     => array('api',      'a_api'),
        '✉️ Reklama yuborish'    => array('ads',      'a_ads'),
        "🛠 Qo'shimcha sozlama"  => array('extra',    'a_extra'),
        "🔖 Qo'llanma sozlash"   => array('guides',   'a_guides'),
        '🧑‍💻 Admin sozlamalari' => array('admins',   'a_admins'),
        '🟢 Bot holati'          => array('status',   'a_status'),
    );
    if (!isset($map[$text])) { return false; }
    list($perm, $fn) = $map[$text];
    if (!admin_can($chat, $perm)) {
        say($chat, "🚫 Sizda bu bo'limga ruxsat yo'q.");
        return true;
    }
    $fn($chat);
    return true;
}

/**
 * Inline (xabar ichidagi) tugma orqali keladigan admin amali uchun qaysi
 * ruxsat (perm_list() kalitlaridan) kerakligini aniqlaydi.
 *
 * Asosiy menyudagi tugmalar handle_admin_button() orqali admin_can() bilan
 * tekshiriladi, lekin inline tugmalarning callback_data'si handle_admin_button()
 * ni chetlab o'tadi — shuning uchun bu yerda ALOHIDA tekshiriladi. null qaytsa,
 * faqat umumiy admin bo'lish kifoya (bu ro'yxatga kiritilmagan, maxsus ruxsat
 * talab qilinmaydigan amal).
 */
function admin_action_perm($data)
{
    static $exact = array(
        'a_stats' => 'stats', 'a_top10' => 'stats',
        'gift_root' => 'gifts', 'sec_menu' => 'gifts', 'gift_menu' => 'gifts',
        'sec_add' => 'gifts', 'sec_edit' => 'gifts', 'sec_del' => 'gifts',
        'gift_add' => 'gifts', 'gift_edit' => 'gifts', 'gift_del' => 'gifts',
        'pay_root' => 'pay', 'ap_menu' => 'pay', 'pm_menu' => 'pay', 'ap_toggle' => 'pay',
        'ap_card' => 'pay', 'ap_holder' => 'pay', 'pm_add' => 'pay', 'pm_edit' => 'pay', 'pm_del' => 'pay',
        'ch_add' => 'channels', 'ch_del' => 'channels', 'ch_root' => 'channels',
        'api_root' => 'api', 'api_set' => 'api', 'api_bal' => 'api', 'bal_ton' => 'api', 'bal_stars' => 'api',
        'gu_add' => 'guides', 'gu_del' => 'guides', 'gu_root' => 'guides',
        'ad_root' => 'admins', 'ad_add' => 'admins', 'ad_cfg' => 'admins', 'ad_del' => 'admins',
        'bs_refresh' => 'status', 'bs_maint' => 'status',
    );
    if (isset($exact[$data])) { return $exact[$data]; }

    static $prefix = array(
        'pay_ok_' => 'pay', 'pay_no_' => 'pay',
        'pz_' => 'prices',
        'sece_' => 'gifts', 'secd_' => 'gifts', 'secdy_' => 'gifts',
        'gadd_' => 'gifts', 'gedit_' => 'gifts', 'gset_' => 'gifts', 'gdel_' => 'gifts', 'gdely_' => 'gifts',
        'pme_' => 'pay', 'pmset_' => 'pay', 'pmd_' => 'pay', 'pmdy_' => 'pay',
        'uu_' => 'users',
        'chd_' => 'channels', 'chdy_' => 'channels',
        'ex_' => 'extra',
        'gud_' => 'guides', 'gudy_' => 'guides',
        'adc_' => 'admins', 'apx_' => 'admins', 'add_' => 'admins',
    );
    foreach ($prefix as $p => $perm) {
        if (str_starts_with($data, $p)) { return $perm; }
    }
    return null;
}

// =============================================================================
//  HOLATLAR (FSM)
// =============================================================================
function handle_state($chat, $msg, $text, $st, $user)
{
    $s = $st['state'];
    $d = $st['data'];

    // Bloklangan/ta'mirdagi/obuna bo'lmagan foydalanuvchi davom etayotgan
    // buyurtma holatini ham to'ldira olmasin — faqat yakuniy tasdiqlash
    // tugmasida emas, shu yerda to'xtatamiz (on_message keyin gate() ni
    // o'zi chaqirib, tegishli xabarni ko'rsatadi).
    if (!is_admin($chat) && !gate($chat, $user, true)) { return false; }

    // --- Foydalanuvchi ---
    switch ($s) {
        case 'stars_amount':        u_stars_amount($chat, $text, $st); return true;
        case 'stars_target':        u_stars_target($chat, $text, $st); return true;
        case 'premium_target':      u_premium_target($chat, $text, $st); return true;
        case 'gift_target':         u_gift_target($chat, $text, $st); return true;
        case 'topup_auto_amount':   u_topup_auto_amount($chat, $text); return true;
        case 'topup_manual_amount': u_topup_amount($chat, $text, $st); return true;
        case 'topup_receipt':       u_topup_receipt($chat, $msg, $st); return true;
    }

    if (!is_admin($chat)) { return false; }

    // --- Admin ---
    switch ($s) {
        case 'a_price':
            a_price_save($chat, $text, $st); return true;

        case 'a_ads':
            a_ads_save($chat, $msg); return true;

        case 'a_user_id':
            $tg = (int)preg_replace('/[^\d]/', '', $text);
            if (!$tg) { say($chat, "⚠️ Faqat raqamli ID yuboring:", kb_cancel()); return true; }
            state_clear($chat);
            a_user_card($chat, $tg);
            return true;

        case 'a_user_add':
        case 'a_user_sub':
            $n = (int)preg_replace('/[^\d]/', '', $text);
            if ($n <= 0) { say($chat, "⚠️ Musbat raqam yuboring:", kb_cancel()); return true; }
            $tg = (int)$d['tg'];
            $delta = $s === 'a_user_add' ? $n : -$n;
            balance_add($tg, $delta, $s === 'a_user_add');
            state_clear($chat);
            $u2 = user_get($tg);
            say($chat, "✅ Bajarildi. Yangi balans: <b>" . money($u2['balance']) . " so'm</b>", kb_admin());
            say($tg, ($delta > 0 ? "➕ Balansingizga <b>" . money($n) . " so'm</b> qo'shildi."
                                 : "➖ Balansingizdan <b>" . money($n) . " so'm</b> yechildi.")
                     . "\n\n💰 Joriy balans: <b>" . money($u2['balance']) . " so'm</b>");
            a_user_card($chat, $tg);
            return true;

        // --- Bo'limlar ---
        case 'sec_add':
            $name = mb_substr(trim($text), 0, 100);
            if ($name === '') { say($chat, "⚠️ Nom yuboring:", kb_cancel()); return true; }
            q("INSERT INTO gift_sections (name, created_at) VALUES (?, ?)", array($name, now()));
            state_clear($chat);
            say($chat, "✅ Bo'lim saqlandi: <b>" . esc($name) . "</b>", kb_admin());
            return true;

        case 'sec_edit_name':
            $name = mb_substr(trim($text), 0, 100);
            if ($name === '') { say($chat, "⚠️ Nom yuboring:", kb_cancel()); return true; }
            q("UPDATE gift_sections SET name = ? WHERE id = ?", array($name, (int)$d['id']));
            state_clear($chat);
            say($chat, "✅ Bo'lim nomi yangilandi: <b>" . esc($name) . "</b>", kb_admin());
            return true;

        // --- Gift qo'shish ---
        case 'gift_add_name':
            $d['name'] = mb_substr(trim($text), 0, 100);
            state_set($chat, 'gift_add_price', $d);
            say($chat, "💵 <b>Gift narxini yuboring</b> (so'mda).\n\nMasalan: <code>3000</code>", kb_cancel());
            return true;

        case 'gift_add_price':
            $p = (int)preg_replace('/[^\d]/', '', $text);
            if ($p <= 0) { say($chat, "⚠️ Musbat raqam yuboring:", kb_cancel()); return true; }
            $d['price'] = $p;
            state_set($chat, 'gift_add_service', $d);
            say($chat, "🆔 <b>giftapi.uz dagi gift service_id sini kiriting</b>\n\nMasalan: <code>1</code>", kb_cancel());
            return true;

        case 'gift_add_service':
            $d['service_id'] = mb_substr(trim($text), 0, 30);
            state_set($chat, 'gift_add_desc', $d);
            say($chat, "📝 <b>Tavsif kiriting</b>\n\nMasalan: <i>Ushbu gift oddiy 15 ta starsga "
                     . "yuboriladigan ayiqcha hadiyasi bo'lib, balansingizdan 15 ta starsga yuboriladi!</i>",
                kb_cancel());
            return true;

        case 'gift_add_desc':
            q("INSERT INTO gifts (section_id, name, price, service_id, description, created_at)
               VALUES (?, ?, ?, ?, ?, ?)",
              array((int)$d['section_id'], $d['name'], $d['price'], $d['service_id'],
                    mb_substr(trim($text), 0, 500), now()));
            state_clear($chat);
            say($chat, "✅ <b>Gift qo'shildi</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "🎁 " . esc($d['name']) . "\n💵 " . money($d['price']) . " so'm\n"
                     . "🆔 service_id = " . esc($d['service_id']), kb_admin());
            return true;

        // --- Gift tahrirlash ---
        case 'gift_edit_value':
            $f = $d['field']; $id = (int)$d['id'];
            if ($f === 'price') {
                $v = (int)preg_replace('/[^\d]/', '', $text);
                if ($v <= 0) { say($chat, "⚠️ Musbat raqam yuboring:", kb_cancel()); return true; }
            } else {
                $v = mb_substr(trim($text), 0, $f === 'description' ? 500 : 100);
                if ($v === '') { say($chat, "⚠️ Qiymat yuboring:", kb_cancel()); return true; }
            }
            q("UPDATE gifts SET $f = ? WHERE id = ?", array($v, $id));
            state_clear($chat);
            say($chat, "✅ Yangilandi.", kb_admin());
            return true;

        // --- To'lov usullari ---
        case 'pm_add_name':
            $d['name'] = mb_substr(trim($text), 0, 60);
            state_set($chat, 'pm_add_card', $d);
            say($chat, "💳 <b>Karta raqamini yuboring</b>\n\nMasalan: <code>8600 1234 5678 9012</code>", kb_cancel());
            return true;

        case 'pm_add_card':
            $d['card'] = mb_substr(trim($text), 0, 60);
            state_set($chat, 'pm_add_holder', $d);
            say($chat, "👤 <b>Karta egasining ism-familyasini yuboring</b>\n\nMasalan: <code>ALISHER VALIYEV</code>", kb_cancel());
            return true;

        case 'pm_add_holder':
            q("INSERT INTO payment_methods (name, card_number, holder, created_at) VALUES (?, ?, ?, ?)",
              array($d['name'], $d['card'], mb_substr(trim($text), 0, 120), now()));
            state_clear($chat);
            say($chat, "✅ <b>To'lov usuli qo'shildi</b>\n\n💳 " . esc($d['name']), kb_admin());
            return true;

        case 'pm_edit_value':
            $f = $d['field'];
            $v = mb_substr(trim($text), 0, 120);
            if ($v === '') { say($chat, "⚠️ Qiymat yuboring:", kb_cancel()); return true; }
            q("UPDATE payment_methods SET $f = ? WHERE id = ?", array($v, (int)$d['id']));
            state_clear($chat);
            say($chat, "✅ Yangilandi.", kb_admin());
            return true;

        // --- Avto pay ---
        case 'ap_card':
            cfg_set('autopay_card', mb_substr(trim($text), 0, 60));
            state_clear($chat);
            say($chat, "✅ Karta raqami saqlandi.", kb_admin());
            return true;

        case 'ap_holder':
            cfg_set('autopay_holder', mb_substr(trim($text), 0, 120));
            state_clear($chat);
            say($chat, "✅ Karta egasi saqlandi.", kb_admin());
            return true;

        // --- Kanal ---
        case 'ch_add':
            $un = ltrim(trim($text), '@');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $un)) {
                say($chat, "⚠️ Username noto'g'ri. Masalan: <code>@mychannel</code>", kb_cancel());
                return true;
            }
            $me = tg('getMe');
            $bot_id = isset($me['result']['id']) ? $me['result']['id'] : 0;
            $r = tg('getChatMember', array('chat_id' => '@' . $un, 'user_id' => $bot_id));
            $stt = isset($r['result']['status']) ? $r['result']['status'] : '';
            if (!in_array($stt, array('administrator', 'creator'), true)) {
                say($chat, "❌ <b>Bot bu kanalda admin emas</b>\n\nAvval botni <b>@" . esc($un)
                         . "</b> kanaliga admin qiling, so'ng qaytadan yuboring.", kb_cancel());
                return true;
            }
            $info = tg('getChat', array('chat_id' => '@' . $un));
            $title = isset($info['result']['title']) ? $info['result']['title'] : '';
            q("INSERT INTO channels (username, title, created_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE title = VALUES(title)", array($un, $title, now()));
            state_clear($chat);
            say($chat, "✅ <b>Kanal majburiy azolikka qo'shildi</b>\n\n📢 @" . esc($un), kb_admin());
            return true;

        // --- API kalit ---
        case 'api_set':
            $k = trim($text);
            if (mb_strlen($k) < 8) { say($chat, "⚠️ Kalit juda qisqa. Qaytadan yuboring:", kb_cancel()); return true; }
            cfg_set('api_key', $k);
            state_clear($chat);
            say($chat, "✅ API kalit saqlandi.", kb_admin());
            a_api($chat);
            return true;

        // --- Qo'shimcha sozlamalar ---
        case 'ex_set':
            $v = ltrim(trim($text), '@');
            cfg_set($d['key'], $v);
            state_clear($chat);
            say($chat, "✅ Saqlandi: <b>@" . esc($v) . "</b>", kb_admin());
            a_extra($chat);
            return true;

        // --- Qo'llanma ---
        case 'gu_add_title':
            $d['title'] = mb_substr(trim($text), 0, 150);
            state_set($chat, 'gu_add_body', $d);
            say($chat, "📝 <b>Endi qo'llanma matnini yozing</b>\n\n"
                     . "<i>Nima qilish kerakligini bosqichma-bosqich tushuntiring.</i>", kb_cancel());
            return true;

        case 'gu_add_body':
            q("INSERT INTO guides (title, body, created_at) VALUES (?, ?, ?)",
              array($d['title'], mb_substr(trim($text), 0, 3500), now()));
            state_clear($chat);
            say($chat, "✅ <b>Qo'llanma qabul qilindi</b>\n\n📖 " . esc($d['title']), kb_admin());
            return true;

        // --- Admin qo'shish ---
        case 'ad_add':
            $tg = (int)preg_replace('/[^\d]/', '', $text);
            if (!$tg) { say($chat, "⚠️ Faqat raqamli ID yuboring:", kb_cancel()); return true; }
            if (!user_get($tg)) {
                say($chat, "❌ Bu foydalanuvchi hali botga kirmagan. Avval unga /start bosishni ayting.", kb_admin());
                state_clear($chat);
                return true;
            }
            q("INSERT IGNORE INTO admins (tg_id, perms, created_at) VALUES (?, 'all', ?)", array($tg, now()));
            state_clear($chat);
            say($chat, "✅ <b>Admin qilindi</b>\n\n🆔 <code>" . $tg . "</code>\n\n"
                     . "Bu adminning ruxsatnomalarini <b>«⚙️ Sozlash»</b> tugmasi orqali "
                     . "sozlashingiz mumkin.", kb_admin());
            say($tg, "🎉 <b>Sizga admin huquqi berildi!</b>\n\n/start bosing.");
            return true;
    }
    return false;
}

// =============================================================================
//  INLINE TUGMALAR
// =============================================================================
function on_callback($cb)
{
    $chat = (int)$cb['message']['chat']['id'];
    $mid  = (int)$cb['message']['message_id'];
    $data = isset($cb['data']) ? $cb['data'] : '';
    $user = user_touch($cb['from']);
    answer_cb($cb['id']);

    // --- Obunani tekshirish ---
    if ($data === 'check_sub') {
        $not = unsubscribed($chat);
        if ($not) { answer_cb($cb['id'], "❗️ Hali hamma kanalga obuna bo'lmadingiz.", true); return; }
        tg('deleteMessage', array('chat_id' => $chat, 'message_id' => $mid));
        u_start($chat, $user);
        return;
    }
    if ($data === 'cancel') {
        state_clear($chat);
        edit($chat, $mid, "❌ Bekor qilindi.");
        say($chat, "Asosiy menyu 👇", kb_main($chat));
        return;
    }

    $is_admin = is_admin($chat);
    if (!$is_admin && !gate($chat, $user)) { return; }

    // =========================== FOYDALANUVCHI ==============================
    if ($data === 'st_post')    { u_stars_pick($chat, $mid, 'post'); return; }
    if ($data === 'st_profile') { u_stars_pick($chat, $mid, 'profile'); return; }
    if ($data === 'ok_stars')   { u_do_order($chat, $mid, 'stars'); return; }
    if ($data === 'ok_premium') { u_do_order($chat, $mid, 'premium'); return; }
    if ($data === 'ok_gift')    { u_do_order($chat, $mid, 'gift'); return; }
    if (str_starts_with($data, 'pr_')) { u_premium_pick($chat, $mid, (int)substr($data, 3)); return; }
    if ($data === 'gs_back')    { u_gift_sections($chat, $mid); return; }
    if (str_starts_with($data, 'gs_')) { u_gift_list($chat, $mid, (int)substr($data, 3)); return; }
    if (str_starts_with($data, 'gf_')) { u_gift_pick($chat, $mid, (int)substr($data, 3)); return; }
    if ($data === 'my_back')    { tg('deleteMessage', array('chat_id'=>$chat,'message_id'=>$mid)); u_orders_menu($chat); return; }
    if (str_starts_with($data, 'my_')) { u_orders_list($chat, $mid, substr($data, 3)); return; }
    if ($data === 'tp_auto')    { u_topup_auto($chat, $mid); return; }
    if (str_starts_with($data, 'tp_paid_')) { u_topup_paid($chat, $mid, (int)substr($data, 8)); return; }
    if (str_starts_with($data, 'tp_m_'))    { u_topup_manual($chat, $mid, (int)substr($data, 5)); return; }
    if ($data === 'gd_back')    { tg('deleteMessage', array('chat_id'=>$chat,'message_id'=>$mid)); u_guides($chat); return; }
    if (str_starts_with($data, 'gd_')) { u_guide_show($chat, $mid, (int)substr($data, 3)); return; }
    if ($data === 'api_new')    { u_api_new($chat, $mid); return; }

    // ============================== ADMIN ===================================
    if (!$is_admin) { return; }

    // Har bir admin amali uchun aniq ruxsatni qayta tekshiramiz — chunki inline
    // tugmalar handle_admin_button() dagi admin_can() tekshiruvini chetlab o'tadi.
    $perm_needed = admin_action_perm($data);
    if ($perm_needed !== null && !admin_can($chat, $perm_needed)) {
        answer_cb($cb['id'], "🚫 Sizda bu amal uchun ruxsat yo'q.", true);
        return;
    }

    // --- To'lovni tasdiqlash/rad etish ---
    if (str_starts_with($data, 'pay_ok_') || str_starts_with($data, 'pay_no_')) {
        $ok = str_starts_with($data, 'pay_ok_');
        $pid = (int)substr($data, 7);
        $p = one("SELECT * FROM payments WHERE id = ?", array($pid));
        if (!$p) { answer_cb($cb['id'], "Ariza topilmadi.", true); return; }
        if ($p['status'] !== 'pending') { answer_cb($cb['id'], "Bu ariza allaqachon ko'rib chiqilgan.", true); return; }

        if ($ok) {
            q("UPDATE payments SET status='approved' WHERE id = ?", array($pid));
            balance_add($p['user_id'], $p['amount'], true);
            $u = user_get($p['user_id']);
            say($p['user_id'],
                "✅ <b>TO'LOV TASDIQLANDI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                . "💵 Qo'shildi: <b>" . money($p['amount']) . " so'm</b>\n"
                . "💰 Joriy balans: <b>" . money($u['balance']) . " so'm</b>");
            tg('editMessageCaption', array('chat_id' => $chat, 'message_id' => $mid,
                'caption' => "✅ <b>TASDIQLANDI</b> — #" . $pid . " · " . money($p['amount']) . " so'm",
                'parse_mode' => 'HTML'));
        } else {
            q("UPDATE payments SET status='rejected' WHERE id = ?", array($pid));
            say($p['user_id'],
                "❌ <b>TO'LOV RAD ETILDI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                . "Qalbaki yoki xato chek yuborgansiz.\n"
                . "Savol bo'lsa administrator bilan bog'laning.");
            tg('editMessageCaption', array('chat_id' => $chat, 'message_id' => $mid,
                'caption' => "❌ <b>RAD ETILDI</b> — #" . $pid,
                'parse_mode' => 'HTML'));
        }
        return;
    }

    // --- Statistika ---
    if ($data === 'a_stats') { a_stats($chat, $mid); return; }
    if ($data === 'a_top10') { a_top10($chat, $mid); return; }

    // --- Narxlar ---
    if (str_starts_with($data, 'pz_')) { a_price_ask($chat, $mid, substr($data, 3)); return; }

    // --- Giftlar ---
    if ($data === 'gift_root') { a_gift_menu($chat, $mid); return; }
    if ($data === 'sec_menu')  { a_sec_menu($chat, $mid); return; }
    if ($data === 'gift_menu') { a_gift_list_menu($chat, $mid); return; }

    if ($data === 'sec_add') {
        state_set($chat, 'sec_add', array());
        edit($chat, $mid, "➕ <b>Yangi bo'lim</b>");
        say($chat, "📂 <b>Bo'lim nomini kiriting</b>\n\nMasalan: <code>🧸 Yumshoq o'yinchoqlar</code>", kb_cancel());
        return;
    }
    if ($data === 'sec_edit' || $data === 'sec_del') {
        $list = all_rows("SELECT * FROM gift_sections ORDER BY id");
        if (!$list) { answer_cb($cb['id'], "Bo'limlar yo'q.", true); return; }
        $rows = array();
        foreach ($list as $s) {
            $rows[] = array(btn($s['name'], ($data === 'sec_edit' ? 'sece_' : 'secd_') . $s['id']));
        }
        $rows[] = array(btn('🔙 Orqaga', 'sec_menu'));
        edit($chat, $mid, ($data === 'sec_edit' ? "✏️ Qaysi bo'limni tahrirlaymiz?" : "🗑 Qaysi bo'limni o'chiramiz?"), ikb($rows));
        return;
    }
    if (str_starts_with($data, 'sece_')) {
        $id = (int)substr($data, 5);
        state_set($chat, 'sec_edit_name', array('id' => $id));
        edit($chat, $mid, "✏️ Bo'lim tahrirlanmoqda");
        say($chat, "📂 <b>Yangi nomni kiriting:</b>", kb_cancel());
        return;
    }
    if (str_starts_with($data, 'secd_')) {
        $id = (int)substr($data, 5);
        $s = one("SELECT * FROM gift_sections WHERE id = ?", array($id));
        if (!$s) { return; }
        $c = (int)col("SELECT COUNT(*) FROM gifts WHERE section_id = ?", array($id));
        edit($chat, $mid, "⚠️ <b>Haqiqatdan ham o'chirmoqchimisiz?</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                        . "📂 <b>" . esc($s['name']) . "</b>\n"
                        . "🎁 Ichidagi giftlar: <b>" . $c . "</b> ta (ular ham o'chadi)",
            ikb(array(array(btn("✅ Ha, o'chirilsin", 'secdy_' . $id), btn('❌ Yo\'q', 'sec_menu')))));
        return;
    }
    if (str_starts_with($data, 'secdy_')) {
        $id = (int)substr($data, 6);
        q("DELETE FROM gifts WHERE section_id = ?", array($id));
        q("DELETE FROM gift_sections WHERE id = ?", array($id));
        edit($chat, $mid, "🗑 Bo'lim o'chirildi.");
        a_sec_menu($chat, $mid);
        return;
    }

    if ($data === 'gift_add') {
        $list = all_rows("SELECT * FROM gift_sections ORDER BY id");
        if (!$list) { answer_cb($cb['id'], "Avval bo'lim qo'shing!", true); return; }
        $rows = array();
        foreach ($list as $s) { $rows[] = array(btn($s['name'], 'gadd_' . $s['id'])); }
        $rows[] = array(btn('🔙 Orqaga', 'gift_menu'));
        edit($chat, $mid, "📂 <b>Qaysi bo'limga qo'shmoqchisiz?</b>", ikb($rows));
        return;
    }
    if (str_starts_with($data, 'gadd_')) {
        state_set($chat, 'gift_add_name', array('section_id' => (int)substr($data, 5)));
        edit($chat, $mid, "➕ Yangi gift qo'shilmoqda");
        say($chat, "🎁 <b>Gift nomini kiriting</b>\n\nMasalan: <code>🧸 Ayiqcha Hadiyasi</code>", kb_cancel());
        return;
    }
    if ($data === 'gift_edit' || $data === 'gift_del') {
        $list = all_rows("SELECT * FROM gifts ORDER BY id");
        if (!$list) { answer_cb($cb['id'], "Giftlar yo'q.", true); return; }
        $rows = array();
        foreach ($list as $g) {
            $rows[] = array(btn($g['name'] . ' — ' . money($g['price']),
                                ($data === 'gift_edit' ? 'gedit_' : 'gdel_') . $g['id']));
        }
        $rows[] = array(btn('🔙 Orqaga', 'gift_menu'));
        edit($chat, $mid, ($data === 'gift_edit' ? "✏️ Qaysi giftni tahrirlaymiz?" : "🗑 Qaysi giftni o'chiramiz?"), ikb($rows));
        return;
    }
    if (str_starts_with($data, 'gedit_')) {
        $id = (int)substr($data, 6);
        $g = one("SELECT * FROM gifts WHERE id = ?", array($id));
        if (!$g) { return; }
        edit($chat, $mid,
            "✏️ <b>" . esc($g['name']) . "</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
            . "💵 Narxi: " . money($g['price']) . " so'm\n"
            . "🆔 service_id: " . esc($g['service_id']) . "\n"
            . "📝 " . esc($g['description']) . "\n\nNimani o'zgartiramiz?",
            ikb(array(
                array(btn('🏷 Nomi', 'gset_' . $id . '_name'), btn('💵 Narxi', 'gset_' . $id . '_price')),
                array(btn('🆔 service_id', 'gset_' . $id . '_service_id'), btn('📝 Tavsif', 'gset_' . $id . '_description')),
                array(btn('🔙 Orqaga', 'gift_edit')),
            )));
        return;
    }
    if (str_starts_with($data, 'gset_')) {
        $p = explode('_', substr($data, 5), 2);
        $id = (int)$p[0]; $field = isset($p[1]) ? $p[1] : '';
        if (!in_array($field, array('name', 'price', 'service_id', 'description'), true)) { return; }
        state_set($chat, 'gift_edit_value', array('id' => $id, 'field' => $field));
        $t = array('name' => 'yangi nomni', 'price' => 'yangi narxni (so\'m)',
                   'service_id' => 'yangi service_id ni', 'description' => 'yangi tavsifni');
        edit($chat, $mid, "✏️ Tahrirlanmoqda");
        say($chat, "✍️ <b>Gift uchun " . $t[$field] . " yuboring:</b>", kb_cancel());
        return;
    }
    if (str_starts_with($data, 'gdel_')) {
        $id = (int)substr($data, 5);
        $g = one("SELECT * FROM gifts WHERE id = ?", array($id));
        if (!$g) { return; }
        edit($chat, $mid, "⚠️ <b>Haqiqatdan ham o'chirmoqchimisiz?</b>\n\n🎁 <b>" . esc($g['name']) . "</b>",
            ikb(array(array(btn("✅ Ha, o'chirilsin", 'gdely_' . $id), btn("❌ Yo'q", 'gift_menu')))));
        return;
    }
    if (str_starts_with($data, 'gdely_')) {
        q("DELETE FROM gifts WHERE id = ?", array((int)substr($data, 6)));
        edit($chat, $mid, "🗑 Gift o'chirildi.");
        a_gift_list_menu($chat, $mid);
        return;
    }

    // --- To'lov tizimlari ---
    if ($data === 'pay_root') { a_pay_menu($chat, $mid); return; }
    if ($data === 'ap_menu')  { a_autopay_menu($chat, $mid); return; }
    if ($data === 'pm_menu')  { a_manual_menu($chat, $mid); return; }
    if ($data === 'ap_toggle') {
        cfg_set('autopay_enabled', cfg('autopay_enabled') === '1' ? '0' : '1');
        a_autopay_menu($chat, $mid);
        return;
    }
    if ($data === 'ap_card' || $data === 'ap_holder') {
        state_set($chat, $data, array());
        edit($chat, $mid, "✏️ Sozlanmoqda");
        say($chat, $data === 'ap_card'
            ? "💳 <b>Avto to'lov karta raqamini yuboring:</b>"
            : "👤 <b>Karta egasining ism-familyasini yuboring:</b>", kb_cancel());
        return;
    }
    if ($data === 'pm_add') {
        state_set($chat, 'pm_add_name', array());
        edit($chat, $mid, "➕ Yangi to'lov usuli");
        say($chat, "🏷 <b>To'lov usuli nomini yuboring</b>\n\nMasalan: <code>Humo</code>", kb_cancel());
        return;
    }
    if ($data === 'pm_edit' || $data === 'pm_del') {
        $list = all_rows("SELECT * FROM payment_methods ORDER BY id");
        if (!$list) { answer_cb($cb['id'], "Usullar yo'q.", true); return; }
        $rows = array();
        foreach ($list as $m) {
            $rows[] = array(btn($m['name'], ($data === 'pm_edit' ? 'pme_' : 'pmd_') . $m['id']));
        }
        $rows[] = array(btn('🔙 Orqaga', 'pm_menu'));
        edit($chat, $mid, ($data === 'pm_edit' ? "✏️ Qaysi usulni tahrirlaymiz?" : "🗑 Qaysi usulni o'chiramiz?"), ikb($rows));
        return;
    }
    if (str_starts_with($data, 'pme_')) {
        $id = (int)substr($data, 4);
        edit($chat, $mid, "✏️ Nimani o'zgartiramiz?", ikb(array(
            array(btn('🏷 Nomi', 'pmset_' . $id . '_name')),
            array(btn('💳 Karta raqami', 'pmset_' . $id . '_card_number')),
            array(btn('👤 Karta egasi', 'pmset_' . $id . '_holder')),
            array(btn('🔙 Orqaga', 'pm_menu')),
        )));
        return;
    }
    if (str_starts_with($data, 'pmset_')) {
        $p = explode('_', substr($data, 6), 2);
        $id = (int)$p[0]; $field = isset($p[1]) ? $p[1] : '';
        if (!in_array($field, array('name', 'card_number', 'holder'), true)) { return; }
        state_set($chat, 'pm_edit_value', array('id' => $id, 'field' => $field));
        edit($chat, $mid, "✏️ Tahrirlanmoqda");
        say($chat, "✍️ <b>Yangi qiymatni yuboring:</b>", kb_cancel());
        return;
    }
    if (str_starts_with($data, 'pmd_')) {
        $id = (int)substr($data, 4);
        $m = one("SELECT * FROM payment_methods WHERE id = ?", array($id));
        if (!$m) { return; }
        edit($chat, $mid, "⚠️ <b>Haqiqatdan ham o'chirmoqchimisiz?</b>\n\n💳 <b>" . esc($m['name']) . "</b>",
            ikb(array(array(btn("✅ Ha", 'pmdy_' . $id), btn("❌ Yo'q", 'pm_menu')))));
        return;
    }
    if (str_starts_with($data, 'pmdy_')) {
        q("DELETE FROM payment_methods WHERE id = ?", array((int)substr($data, 5)));
        edit($chat, $mid, "🗑 O'chirildi.");
        a_manual_menu($chat, $mid);
        return;
    }

    // --- Userni boshqarish ---
    if (str_starts_with($data, 'uu_')) {
        $p = explode('_', $data);          // uu_add_123
        $act = $p[1]; $tg = (int)$p[2];
        if ($act === 'blk') {
            $u = user_get($tg);
            if (!$u) { return; }
            $new = $u['status'] === 'active' ? 'blocked' : 'active';
            q("UPDATE users SET status = ? WHERE tg_id = ?", array($new, $tg));
            say($tg, $new === 'blocked'
                ? "🚫 <b>Siz bloklangansiz</b>\n\nIltimos, administrator bilan bog'laning."
                : "✅ <b>Blokdan chiqarildingiz</b>\n\nBotdan yana foydalanishingiz mumkin. /start");
            a_user_card($chat, $tg, $mid);
            return;
        }
        state_set($chat, $act === 'add' ? 'a_user_add' : 'a_user_sub', array('tg' => $tg));
        edit($chat, $mid, $act === 'add' ? "➕ Pul qo'shish" : "➖ Pul ayrish");
        say($chat, "🔢 <b>Miqdorni yuboring</b> (so'mda):", kb_cancel());
        return;
    }

    // --- Kanallar ---
    if ($data === 'ch_add') {
        state_set($chat, 'ch_add', array());
        edit($chat, $mid, "➕ Yangi kanal");
        say($chat, "📢 <b>Kanal @username sini kiriting</b>\n\nMasalan: <code>@mychannel</code>\n\n"
                 . "⚠️ Bot o'sha kanalda <b>admin</b> bo'lishi shart.", kb_cancel());
        return;
    }
    if ($data === 'ch_del') {
        $list = required_channels();
        if (!$list) { answer_cb($cb['id'], "Kanallar yo'q.", true); return; }
        $rows = array();
        foreach ($list as $c) { $rows[] = array(btn('@' . $c['username'], 'chd_' . $c['id'])); }
        $rows[] = array(btn('🔙 Orqaga', 'ch_root'));
        edit($chat, $mid, "🗑 Qaysi kanalni o'chiramiz?", ikb($rows));
        return;
    }
    if ($data === 'ch_root') { a_channels($chat, $mid); return; }
    if (str_starts_with($data, 'chd_')) {
        $id = (int)substr($data, 4);
        $c = one("SELECT * FROM channels WHERE id = ?", array($id));
        if (!$c) { return; }
        edit($chat, $mid, "⚠️ <b>Haqiqatdan ham o'chirmoqchimisiz?</b>\n\n📢 @" . esc($c['username']),
            ikb(array(array(btn("✅ Ha", 'chdy_' . $id), btn("❌ Yo'q", 'ch_root')))));
        return;
    }
    if (str_starts_with($data, 'chdy_')) {
        q("DELETE FROM channels WHERE id = ?", array((int)substr($data, 5)));
        edit($chat, $mid, "🗑 Kanal o'chirildi.");
        a_channels($chat, $mid);
        return;
    }

    // --- API ---
    if ($data === 'api_root') { a_api($chat, $mid); return; }
    if ($data === 'api_set') {
        state_set($chat, 'api_set', array());
        edit($chat, $mid, "♻️ API kalitni yangilash");
        say($chat, "🔑 <b>giftapi.uz dan olingan loyiha API kalitini kiriting:</b>", kb_cancel());
        return;
    }
    if ($data === 'api_bal') { a_api_balance($chat, $mid); return; }
    if ($data === 'bal_ton' || $data === 'bal_stars') {
        edit($chat, $mid, "⏳ Tekshirilmoqda…");
        $r = api_call($data === 'bal_ton' ? 'GetTonBalance' : 'GetStarsBalance');
        if (!empty($r['ok'])) {
            if ($data === 'bal_ton') {
                $txt = "💎 <b>FRAGMENT (TON) BALANSI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "💠 TON: <b>" . esc(isset($r['ton']) ? $r['ton'] : '—') . "</b>\n"
                     . "💵 USDT: <b>" . esc(isset($r['usdt']) ? $r['usdt'] : '—') . "</b>";
                if (!empty($r['address'])) { $txt .= "\n\n<code>" . esc($r['address']) . "</code>"; }
            } else {
                $txt = "⭐️ <b>USERBOT STARS BALANSI</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "⭐️ Balans: <b>" . esc(isset($r['balance']) ? $r['balance'] : '—') . "</b>"
                     . (!empty($r['account']) ? "\n👤 @" . esc($r['account']) : '');
            }
        } else {
            $txt = "❌ <b>Balans olinmadi</b>\n━━━━━━━━━━━━━━━━━━━━\n\n"
                 . esc(isset($r['message']) ? $r['message'] : 'Nomaʼlum xatolik');
        }
        edit($chat, $mid, $txt, ikb(array(array(btn('🔙 Orqaga', 'api_bal')))));
        return;
    }

    // --- Qo'shimcha sozlama ---
    if (str_starts_with($data, 'ex_')) {
        $key = substr($data, 3);
        if (!in_array($key, array('orders_channel', 'support_username', 'news_channel'), true)) { return; }
        state_set($chat, 'ex_set', array('key' => $key));
        $n = array('orders_channel' => 'buyurtmalar kanali', 'support_username' => 'admin username',
                   'news_channel' => 'yangiliklar kanali');
        edit($chat, $mid, "✏️ Sozlanmoqda");
        say($chat, "✍️ <b>Yangi " . $n[$key] . " @username sini yuboring:</b>\n\n"
                 . "Masalan: <code>@myusername</code>", kb_cancel());
        return;
    }

    // --- Qo'llanma ---
    if ($data === 'gu_add') {
        state_set($chat, 'gu_add_title', array());
        edit($chat, $mid, "➕ Yangi qo'llanma");
        say($chat, "📖 <b>Qo'llanma nomini kiriting</b>\n\nMasalan: <code>Hisob to'ldirish</code>", kb_cancel());
        return;
    }
    if ($data === 'gu_del') {
        $list = all_rows("SELECT * FROM guides ORDER BY id");
        if (!$list) { answer_cb($cb['id'], "Qo'llanmalar yo'q.", true); return; }
        $rows = array();
        foreach ($list as $g) { $rows[] = array(btn($g['title'], 'gud_' . $g['id'])); }
        edit($chat, $mid, "🗑 Qaysi qo'llanmani o'chiramiz?", ikb($rows));
        return;
    }
    if (str_starts_with($data, 'gud_')) {
        $id = (int)substr($data, 4);
        $g = one("SELECT * FROM guides WHERE id = ?", array($id));
        if (!$g) { return; }
        edit($chat, $mid, "⚠️ <b>Haqiqatdan ham o'chirmoqchimisiz?</b>\n\n📖 <b>" . esc($g['title']) . "</b>",
            ikb(array(array(btn("✅ Ha", 'gudy_' . $id), btn("❌ Yo'q", 'gu_root')))));
        return;
    }
    if (str_starts_with($data, 'gudy_')) {
        q("DELETE FROM guides WHERE id = ?", array((int)substr($data, 5)));
        edit($chat, $mid, "🗑 O'chirildi.");
        a_guides($chat, $mid);
        return;
    }
    if ($data === 'gu_root') { a_guides($chat, $mid); return; }

    // --- Adminlar ---
    if ($data === 'ad_root') { a_admins($chat, $mid); return; }
    if ($data === 'ad_add') {
        if (!is_owner($chat)) { answer_cb($cb['id'], "Faqat asosiy admin qo'sha oladi.", true); return; }
        state_set($chat, 'ad_add', array());
        edit($chat, $mid, "➕ Yangi admin");
        say($chat, "🆔 <b>Yangi adminning Telegram ID sini yuboring:</b>", kb_cancel());
        return;
    }
    if ($data === 'ad_cfg' || $data === 'ad_del') {
        $list = all_rows("SELECT * FROM admins ORDER BY created_at");
        $rows = array();
        foreach ($list as $a) {
            if (is_owner($a['tg_id'])) { continue; }
            $u = user_get($a['tg_id']);
            $nm = $u && $u['username'] !== '' ? '@' . $u['username'] : (string)$a['tg_id'];
            $rows[] = array(btn($nm, ($data === 'ad_cfg' ? 'adc_' : 'add_') . $a['tg_id']));
        }
        if (!$rows) { answer_cb($cb['id'], "Boshqariladigan admin yo'q.", true); return; }
        $rows[] = array(btn('🔙 Orqaga', 'ad_root'));
        edit($chat, $mid, ($data === 'ad_cfg' ? "⚙️ Qaysi adminni sozlaymiz?" : "🗑 Qaysi adminni o'chiramiz?"), ikb($rows));
        return;
    }
    if (str_starts_with($data, 'adc_')) { a_admin_perms($chat, $mid, (int)substr($data, 4)); return; }
    if (str_starts_with($data, 'apx_')) {
        $p = explode('_', substr($data, 4), 2);
        $tg = (int)$p[0]; $perm = isset($p[1]) ? $p[1] : '';
        $a = one("SELECT * FROM admins WHERE tg_id = ?", array($tg));
        if (!$a || !isset(perm_list()[$perm])) { return; }
        $cur = ($a['perms'] === 'all' || $a['perms'] === '') ? array_keys(perm_list()) : (json_decode($a['perms'], true) ?: array());
        if (in_array($perm, $cur, true)) { $cur = array_values(array_diff($cur, array($perm))); }
        else { $cur[] = $perm; }
        q("UPDATE admins SET perms = ? WHERE tg_id = ?", array(json_encode(array_values($cur)), $tg));
        a_admin_perms($chat, $mid, $tg);
        return;
    }
    if (str_starts_with($data, 'add_')) {
        $tg = (int)substr($data, 4);
        if (is_owner($tg)) { answer_cb($cb['id'], "Asosiy adminni o'chirib bo'lmaydi.", true); return; }
        q("DELETE FROM admins WHERE tg_id = ?", array($tg));
        edit($chat, $mid, "🗑 Admin o'chirildi.");
        a_admins($chat, $mid);
        return;
    }

    // --- Bot holati ---
    if ($data === 'bs_refresh') { a_status($chat, $mid); return; }
    if ($data === 'bs_maint') {
        cfg_set('maintenance', cfg('maintenance') === '1' ? '0' : '1');
        a_status($chat, $mid);
        return;
    }
}
