<?php
/**
 * =============================================================================
 *  Xizmat qatlami: giftapi.uz bilan aloqa, majburiy obuna, buyurtmalar
 * =============================================================================
 */

// -----------------------------------------------------------------------------
//  GIFTAPI.UZ MIJOZI
// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
//  TEST REJIMI (faqat avtomatik testlar uchun — productionda ishlatilmaydi,
//  haqiqiy giftapi.uz'ga HECH QACHON so'rov yubormaydi va haqiqiy pul sarflamaydi)
// -----------------------------------------------------------------------------
function &api_test_state()
{
    static $state = array('on' => false, 'log' => array(), 'responder' => null);
    return $state;
}

/** $responder($action, $params) — har bir chaqiriq uchun soxta javob qaytaradi. */
function apiEnableTestMode($responder = null)
{
    $s = &api_test_state();
    $s['on'] = true; $s['log'] = array(); $s['responder'] = $responder;
}

function apiDisableTestMode()
{
    $s = &api_test_state();
    $s['on'] = false; $s['responder'] = null;
}

function apiTestLog()
{
    $s = &api_test_state();
    return $s['log'];
}

function api_call($action, $params = array())
{
    $ts = &api_test_state();
    if ($ts['on']) {
        $ts['log'][] = array('action' => $action, 'params' => $params);
        $fn = $ts['responder'];
        return $fn ? $fn($action, $params) : array('ok' => true, 'message' => 'test-mode OK');
    }

    $key = trim(cfg('api_key'));
    if ($key === '') {
        return array('ok' => false, 'error' => 'no_api_key',
                     'message' => 'API kalit sozlanmagan. Admin: 🔑 Api sozlamalasi.');
    }
    $domain = rtrim(cfg('api_domain', 'https://giftapi.uz'), '/');
    $params['shop_key'] = $key;
    $params['action'] = $action;
    $url = $domain . '/api/?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 200);        // Fragment xaridi uzoq davom etadi
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logg('API cURL: ' . $err);
        return array('ok' => false, 'error' => 'network', 'message' => 'Tarmoq xatosi: ' . $err);
    }
    curl_close($ch);
    $res = json_decode($raw, true);
    if (!is_array($res)) {
        logg('API javobi tushunarsiz: ' . substr($raw, 0, 200));
        return array('ok' => false, 'error' => 'bad_response', 'message' => 'Server javobi tushunarsiz.');
    }
    return $res;
}

/** Har bir buyurtmaga unikal raqam — takrorlanish himoyasi chalkashmasligi uchun. */
function api_request_id($order_id)
{
    return 'bot-' . $order_id . '-' . substr(md5($order_id . '|' . microtime(true)), 0, 8);
}

// -----------------------------------------------------------------------------
//  NARXLAR
// -----------------------------------------------------------------------------
function star_price() { return (float)cfg('star_price', 250); }

function premium_plans()
{
    return array(
        3  => array('months' => 3,  'price' => (float)cfg('premium_3_price', 0)),
        6  => array('months' => 6,  'price' => (float)cfg('premium_6_price', 0)),
        12 => array('months' => 12, 'price' => (float)cfg('premium_12_price', 0)),
    );
}

/** Profil uchun chegara — o'zgarmas. */
function profile_limits() { return array(50, 10000000); }

function post_limits()
{
    return array((int)cfg('post_stars_min', 1), (int)cfg('post_stars_max', 10000));
}

// -----------------------------------------------------------------------------
//  MAJBURIY OBUNA
// -----------------------------------------------------------------------------
function required_channels() { return all_rows("SELECT * FROM channels ORDER BY id"); }

/** Obuna bo'lmagan kanallar ro'yxatini qaytaradi. */
function unsubscribed($tg_id)
{
    $out = array();
    foreach (required_channels() as $ch) {
        $r = tg('getChatMember', array('chat_id' => '@' . $ch['username'], 'user_id' => $tg_id));
        if (empty($r['ok'])) { continue; }   // bot kanaldan chiqarilgan bo'lsa — o'tkazamiz
        $st = isset($r['result']['status']) ? $r['result']['status'] : 'left';
        if (in_array($st, array('left', 'kicked'), true)) { $out[] = $ch; }
    }
    return $out;
}

function ask_subscribe($chat_id, $list)
{
    $rows = array();
    foreach ($list as $ch) {
        $rows[] = array(url_btn('📢 ' . ($ch['title'] !== '' ? $ch['title'] : '@' . $ch['username']),
                                'https://t.me/' . $ch['username']));
    }
    $rows[] = array(btn('✅ Obuna bo\'ldim', 'check_sub'));
    say($chat_id,
        "🔒 <b>Botdan foydalanish uchun obuna bo'ling</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Quyidagi kanallarga obuna bo'lgach, <b>«Obuna bo'ldim»</b> tugmasini bosing.",
        ikb($rows));
}

// -----------------------------------------------------------------------------
//  BUYURTMALAR
// -----------------------------------------------------------------------------
function order_create($user_id, $type, $service_id, $title, $target, $qty, $price)
{
    q("INSERT INTO orders (user_id, type, service_id, title, target, quantity, price, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
      array($user_id, $type, (string)$service_id, $title, $target, $qty, $price, now()));
    return (int)db()->lastInsertId();
}

function order_finish($order_id, $status, $response)
{
    q("UPDATE orders SET status = ?, api_response = ? WHERE id = ?",
      array($status, mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 2000), $order_id));
}

function type_label($type)
{
    $m = array(
        'stars_post'    => '💫 Postga Stars',
        'stars_profile' => '⭐️ Profilga Stars',
        'premium'       => '🌟 Telegram Premium',
        'gift'          => '🎁 Gift',
    );
    return isset($m[$type]) ? $m[$type] : $type;
}

/** Buyurtmalar kanaliga xabar (agar sozlangan bo'lsa). */
function notify_orders_channel($order, $user)
{
    $chan = trim(cfg('orders_channel'));
    if ($chan === '') { return; }
    $uname = $user['username'] !== '' ? '@' . $user['username'] : 'username yo\'q';
    say('@' . ltrim($chan, '@'),
        "🆕 <b>YANGI BUYURTMA</b>\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "📦 Xizmat: <b>" . esc(type_label($order['type'])) . "</b>\n"
        . "🏷 Nomi: <b>" . esc($order['title']) . "</b>\n"
        . "🎯 Manzil: <code>" . esc($order['target']) . "</code>\n"
        . "🔢 Miqdor: <b>" . (int)$order['quantity'] . "</b>\n"
        . "💵 Summa: <b>" . money($order['price']) . " so'm</b>\n\n"
        . "👤 Xaridor: " . esc($uname) . " (<code>" . (int)$user['tg_id'] . "</code>)\n"
        . "🆔 Buyurtma: <code>#" . (int)$order['id'] . "</code>");
}

/**
 * Buyurtmani bajaradi: giftapi.uz ga yuboradi, natijaga qarab
 * balansni yechadi yoki qaytaradi.
 */
function order_execute($user, $type, $service_id, $title, $target, $qty, $price)
{
    $tg = (int)$user['tg_id'];

    // 1) Pulni oldindan ushlab turamiz (poyga holatidan himoya)
    $upd = q("UPDATE users SET balance = balance - ? WHERE tg_id = ? AND balance >= ?",
             array($price, $tg, $price));
    if ($upd->rowCount() === 0) {
        return array('ok' => false, 'message' => "Balansingizda mablag' yetarli emas.");
    }

    $oid = order_create($tg, $type, $service_id, $title, $target, $qty, $price);
    $rid = api_request_id($oid);

    // 2) API ga yuboramiz
    switch ($type) {
        case 'stars_post':
            $res = api_call('BuyPostStars', array('username' => $target, 'amount' => $qty, 'request_id' => $rid));
            break;
        case 'stars_profile':
            $res = api_call('BuyProfilStars', array('username' => $target, 'amount' => $qty, 'request_id' => $rid));
            break;
        case 'premium':
            $res = api_call('BuyPremium', array('service_id' => $service_id, 'username' => $target, 'request_id' => $rid));
            break;
        case 'gift':
            $res = api_call('BuyGift', array('service_id' => $service_id, 'username' => $target, 'request_id' => $rid));
            break;
        default:
            $res = array('ok' => false, 'message' => 'Noma\'lum xizmat turi.');
    }

    $ok  = !empty($res['ok']);
    $dup = !empty($res['duplicate']);

    // 3) Takroriy so'rov — buyurtma bajarilmagan deb hisoblaymiz va pulni qaytaramiz
    if ($dup) {
        balance_add($tg, $price);
        order_finish($oid, 'refunded', $res);
        return array('ok' => false, 'duplicate' => true,
                     'message' => "Bu buyurtma yaqinda yuborilgan. Pul balansingizga qaytarildi.\n"
                                  . "⏳ 2 daqiqadan keyin qayta urinib ko'ring.");
    }

    if (!$ok) {
        balance_add($tg, $price);
        order_finish($oid, 'refunded', $res);
        $why = isset($res['message']) ? $res['message'] : 'Nomaʼlum xatolik';
        return array('ok' => false, 'message' => "Xatolik yuz berdi. Pul balansingizga qaytarildi.\n"
                                                 . "Sabab: " . $why . "\nKeyinroq urinib ko'ring.");
    }

    order_finish($oid, 'success', $res);
    $order = one("SELECT * FROM orders WHERE id = ?", array($oid));
    notify_orders_channel($order, $user);
    return array('ok' => true, 'order_id' => $oid,
                 'message' => isset($res['message']) ? $res['message'] : 'Bajarildi.');
}
