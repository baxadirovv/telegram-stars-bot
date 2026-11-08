<?php
/**
 * =============================================================================
 *  HAMKORLAR UCHUN API
 *  Manzil:  https://domen.uz/api/?api_key=...&action=...
 * =============================================================================
 */
@ini_set('display_errors', '0');
date_default_timezone_set('Asia/Tashkent');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';
require __DIR__ . '/../core/db.php';
require __DIR__ . '/../core/tg.php';
require __DIR__ . '/../core/service.php';

function bot_base_url() { return rtrim(cfg('base_url', ''), '/'); }

function out($arr, $code = 200)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    db_ensure();
} catch (Exception $e) {
    out(array('ok' => false, 'error' => 'server', 'message' => 'Baza xatosi.'), 500);
}

$key    = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if ($key === '') { out(array('ok' => false, 'error' => 'no_key', 'message' => 'api_key ko\'rsatilmagan.'), 401); }

$k = one("SELECT * FROM api_keys WHERE api_key = ? AND is_active = 1", array($key));
if (!$k) { out(array('ok' => false, 'error' => 'invalid_key', 'message' => 'API kalit noto\'g\'ri.'), 401); }

$user = user_get($k['user_id']);
if (!$user) { out(array('ok' => false, 'error' => 'no_user', 'message' => 'Hisob topilmadi.'), 401); }
if ($user['status'] === 'blocked') {
    out(array('ok' => false, 'error' => 'blocked', 'message' => 'Hisobingiz bloklangan.'), 403);
}

q("UPDATE api_keys SET requests = requests + 1 WHERE id = ?", array($k['id']));

// --- Balans ---
if ($action === 'balance') {
    out(array('ok' => true, 'action' => 'balance',
        'balance' => (float)$user['balance'], 'currency' => 'UZS'));
}

// --- Xizmatlar ro'yxati ---
if ($action === 'services') {
    $list = array(
        array('service_id' => 100, 'name' => 'Profilga Stars', 'type' => 'stars_profile',
              'price' => star_price(), 'min' => 50, 'max' => 10000000),
        array('service_id' => 200, 'name' => 'Postga Stars', 'type' => 'stars_post',
              'price' => star_price(), 'min' => (int)cfg('post_stars_min', 1), 'max' => (int)cfg('post_stars_max', 10000)),
    );
    foreach (premium_plans() as $m => $p) {
        if ($p['price'] <= 0) { continue; }
        $list[] = array('service_id' => $m, 'name' => $m . ' oylik Premium', 'type' => 'premium',
                        'price' => $p['price'], 'min' => 1, 'max' => 1);
    }
    foreach (all_rows("SELECT * FROM gifts WHERE is_active = 1 ORDER BY id") as $g) {
        $list[] = array('service_id' => 'gift_' . (int)$g['id'], 'name' => $g['name'], 'type' => 'gift',
                        'price' => (float)$g['price'], 'min' => 1, 'max' => 1);
    }
    out(array('ok' => true, 'action' => 'services', 'services' => $list));
}

// --- Buyurtma holati ---
if ($action === 'status') {
    $oid = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    $o = one("SELECT * FROM orders WHERE id = ? AND user_id = ?", array($oid, $user['tg_id']));
    if (!$o) { out(array('ok' => false, 'error' => 'not_found', 'message' => 'Buyurtma topilmadi.'), 404); }
    out(array('ok' => true, 'action' => 'status', 'order_id' => (int)$o['id'],
        'type' => $o['type'], 'title' => $o['title'], 'target' => $o['target'],
        'quantity' => (int)$o['quantity'], 'price' => (float)$o['price'],
        'status' => $o['status'], 'created_at' => $o['created_at']));
}

// --- Buyurtma yaratish ---
if ($action === 'order') {
    $sid    = isset($_GET['service_id']) ? trim($_GET['service_id']) : '';
    $target = isset($_GET['target']) ? trim($_GET['target']) : '';
    $qty    = isset($_GET['amount']) ? (int)$_GET['amount'] : 1;

    if ($sid === '')    { out(array('ok' => false, 'error' => 'bad_request', 'message' => 'service_id ko\'rsatilmagan.'), 400); }
    if ($target === '') { out(array('ok' => false, 'error' => 'bad_request', 'message' => 'target ko\'rsatilmagan.'), 400); }

    // --- Xizmatni aniqlaymiz ---
    if ($sid === '100' || $sid === '200') {
        $type = $sid === '100' ? 'stars_profile' : 'stars_post';
        if ($type === 'stars_profile') { $min = 50; $max = 10000000; }
        else { $min = (int)cfg('post_stars_min', 1); $max = (int)cfg('post_stars_max', 10000); }
        if ($qty < $min || $qty > $max) {
            out(array('ok' => false, 'error' => 'bad_amount',
                'message' => "amount $min dan $max gacha bo'lishi kerak."), 400);
        }
        $price = $qty * star_price();
        $title = $type === 'stars_profile' ? 'Profilga Stars' : 'Postga Stars';
        $api_sid = $sid;
    } elseif (str_starts_with($sid, 'gift_')) {
        $g = one("SELECT * FROM gifts WHERE id = ? AND is_active = 1", array((int)substr($sid, 5)));
        if (!$g) { out(array('ok' => false, 'error' => 'not_found', 'message' => 'Gift topilmadi.'), 404); }
        $type = 'gift'; $qty = 1; $price = (float)$g['price'];
        $title = $g['name']; $api_sid = $g['service_id'];
    } elseif (in_array($sid, array('3', '6', '12'), true)) {
        $plans = premium_plans();
        $p = $plans[(int)$sid];
        if ($p['price'] <= 0) { out(array('ok' => false, 'error' => 'not_found', 'message' => 'Tarif mavjud emas.'), 404); }
        $type = 'premium'; $qty = 1; $price = $p['price'];
        $title = $sid . ' oylik Premium'; $api_sid = $sid;
    } else {
        out(array('ok' => false, 'error' => 'not_found', 'message' => 'Bunday service_id yo\'q.'), 404);
    }

    // --- Manzilni tekshiramiz ---
    if ($type === 'stars_post') {
        if (!preg_match('~^https?://t\.me/[^/\s]+/\d+~i', $target)) {
            out(array('ok' => false, 'error' => 'bad_target',
                'message' => 'target post havolasi bo\'lishi kerak: https://t.me/kanal/348'), 400);
        }
    } else {
        if (!preg_match('/^@?[A-Za-z][A-Za-z0-9_]{4,31}$/', $target)) {
            out(array('ok' => false, 'error' => 'bad_target', 'message' => 'target username bo\'lishi kerak: @durov'), 400);
        }
        if ($target[0] !== '@') { $target = '@' . $target; }
    }

    if ((float)$user['balance'] < $price) {
        out(array('ok' => false, 'error' => 'insufficient_funds',
            'message' => 'Balans yetarli emas.', 'need' => $price, 'balance' => (float)$user['balance']), 402);
    }

    $r = order_execute($user, $type, $api_sid, $title, $target, $qty, $price);
    $u2 = user_get($user['tg_id']);

    if (!empty($r['ok'])) {
        out(array('ok' => true, 'action' => 'order', 'order_id' => (int)$r['order_id'],
            'status' => 'success', 'charged' => $price, 'balance' => (float)$u2['balance'],
            'message' => $r['message']));
    }
    out(array('ok' => false, 'action' => 'order', 'status' => 'failed',
        'error' => !empty($r['duplicate']) ? 'duplicate' : 'failed',
        'balance' => (float)$u2['balance'], 'message' => $r['message']));
}

out(array('ok' => false, 'error' => 'unknown_action',
    'message' => 'Noma\'lum action. Ruxsat etilgan: balance, services, order, status.'), 400);
