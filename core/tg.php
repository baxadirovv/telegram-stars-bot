<?php
/**
 * =============================================================================
 *  Telegram Bot API bilan ishlash + klaviaturalar
 * =============================================================================
 */

// -----------------------------------------------------------------------------
//  TEST REJIMI (faqat avtomatik testlar uchun — productionda ishlatilmaydi,
//  haqiqiy Telegram API'siga HECH QACHON so'rov yubormaydi)
// -----------------------------------------------------------------------------
function &tg_test_state()
{
    static $state = array('on' => false, 'log' => array(), 'overrides' => array());
    return $state;
}

function tgEnableTestMode()
{
    $s = &tg_test_state();
    $s['on'] = true; $s['log'] = array(); $s['overrides'] = array();
}

function tgDisableTestMode()
{
    $s = &tg_test_state();
    $s['on'] = false;
}

/** Ma'lum bir metod uchun bir martalik/doimiy soxta javobni belgilaydi. */
function tgTestSetResponse($method, $response)
{
    $s = &tg_test_state();
    $s['overrides'][$method] = $response;
}

/** Test rejimida yuborilgan barcha chaqiriqlar jurnali (tekshirish uchun). */
function tgTestLog()
{
    $s = &tg_test_state();
    return $s['log'];
}

function tg($method, $params = array())
{
    $s = &tg_test_state();
    if ($s['on']) {
        $s['log'][] = array('method' => $method, 'params' => $params);
        if (isset($s['overrides'][$method])) { return $s['overrides'][$method]; }
        if ($method === 'getMe') {
            return array('ok' => true, 'result' => array('id' => 999000111, 'is_bot' => true, 'username' => 'test_bot'));
        }
        if ($method === 'getChatMember') {
            return array('ok' => true, 'result' => array('status' => 'member'));
        }
        if ($method === 'getChat') {
            return array('ok' => true, 'result' => array('title' => 'Test Channel'));
        }
        return array('ok' => true, 'result' => array());
    }

    if (!defined('BOT_TOKEN') || BOT_TOKEN === '') {
        logg('BOT_TOKEN config.php da bo\'sh');
        return array('ok' => false);
    }
    // Ba'zi serverlarda Telegram to'g'ridan ochilmaydi — proksi manzilini
    // config.php da TG_API_BASE orqali belgilash mumkin.
    $base = (defined('TG_API_BASE') && TG_API_BASE !== '') ? rtrim(TG_API_BASE, '/') : 'https://api.telegram.org';
    $ch = curl_init($base . '/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $raw = curl_exec($ch);
    if ($raw === false) {
        logg('cURL (' . $method . '): ' . curl_error($ch));
        curl_close($ch);
        return array('ok' => false);
    }
    curl_close($ch);
    $res = json_decode($raw, true);
    if (!is_array($res)) { return array('ok' => false); }
    if (empty($res['ok'])) { logg('TG ' . $method . ': ' . substr($raw, 0, 250)); }
    return $res;
}

function say($chat_id, $text, $kb = null, $parse = 'HTML')
{
    $p = array(
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse,
        'disable_web_page_preview' => true,
    );
    if ($kb !== null) { $p['reply_markup'] = $kb; }
    return tg('sendMessage', $p);
}

function edit($chat_id, $message_id, $text, $kb = null)
{
    $p = array(
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    );
    if ($kb !== null) { $p['reply_markup'] = $kb; }
    $r = tg('editMessageText', $p);
    if (empty($r['ok'])) { return say($chat_id, $text, $kb); }
    return $r;
}

function answer_cb($id, $text = '', $alert = false)
{
    return tg('answerCallbackQuery', array(
        'callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert,
    ));
}

function esc($s)
{
    return htmlspecialchars((string)$s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -----------------------------------------------------------------------------
//  KLAVIATURALAR
// -----------------------------------------------------------------------------
function kb_rows($rows, $resize = true)
{
    return array('keyboard' => $rows, 'resize_keyboard' => $resize, 'is_persistent' => true);
}

function ikb($rows) { return array('inline_keyboard' => $rows); }

function btn($text, $data) { return array('text' => $text, 'callback_data' => $data); }

function url_btn($text, $url) { return array('text' => $text, 'url' => $url); }

/** Foydalanuvchi asosiy menyusi */
function kb_main($tg_id = 0)
{
    $rows = array(
        array(array('text' => '⭐️ Stars olish'), array('text' => '🌟 Premium olish')),
        array(array('text' => '🎁 Giftlar olish'), array('text' => '🛒 Buyurtmalarim')),
        array(array('text' => "💰 Hisobim"), array('text' => "💳 Balans To'ldirish")),
        array(array('text' => "📚 Qo'llanma"), array('text' => '☎️ Yordam markazi')),
        array(array('text' => '🔑 Hamkorlik ( API )')),
    );
    if ($tg_id && is_admin($tg_id)) {
        $rows[] = array(array('text' => '🧑‍💻 Admin panel'));
    }
    return kb_rows($rows);
}

/** Admin panel menyusi */
function kb_admin()
{
    return kb_rows(array(
        array(array('text' => '📊 Statistika'), array('text' => '💰 Narxlarni sozlash')),
        array(array('text' => "💳 To'lov tizimlari"), array('text' => '🛍 Gift sozlamalari')),
        array(array('text' => '⚙️ Userni boshqarish'), array('text' => '📣 Kanal sozlamalari')),
        array(array('text' => '🔑 Api sozlamalasi'), array('text' => '✉️ Reklama yuborish')),
        array(array('text' => "🛠 Qo'shimcha sozlama"), array('text' => "🔖 Qo'llanma sozlash")),
        array(array('text' => '🧑‍💻 Admin sozlamalari'), array('text' => '🟢 Bot holati')),
        array(array('text' => '🔙 Asosiy menyu')),
    ));
}

function kb_cancel()
{
    return kb_rows(array(array(array('text' => '❌ Bekor qilish'))));
}
