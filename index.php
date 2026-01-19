<?php
// =================== REFER BOT + JOIN VERIFY + "VERIFY NOW" WEB VERIFY ===================
// Hosting: Render (Webhook)
// ENV (Render):
//   BOT_TOKEN, ADMIN_ID, FORCE_JOIN_1, FORCE_JOIN_2
//
// What it does:
// 1) User clicks /start (with or without referral payload)
// 2) Bot checks FORCE_JOIN_1 + FORCE_JOIN_2 (join verification) -> ✅ Verify button
// 3) Then shows "Verify Yourself" screen -> ✅ Verify Now (opens web) + ✅ Check Verification
// 4) ONLY after BOTH verifications -> main menu
// 5) Referral points: User A gets +1 ONLY after User B completes BOTH verifications (one-time)
// 6) Coupons: admin adds coupons -> stored in DB until redeemed -> when redeemed coupon is DELETED from stock
// 7) Admin panel visible ONLY to ADMIN_ID
//
// Notes:
// - Telegram bots cannot get true device ID. This uses a "device token" stored in Telegram in-app browser localStorage.
// - One device token can be bound to only one Telegram account.
// =========================================================================================

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {

$BOT_TOKEN    = trim((string)getenv("BOT_TOKEN"));
$ADMIN_ID     = intval(getenv("ADMIN_ID"));
$FORCE_JOIN_1 = trim((string)getenv("FORCE_JOIN_1"));  // @username or -100...
$FORCE_JOIN_2 = trim((string)getenv("FORCE_JOIN_2"));  // @username or -100...
$WITHDRAW_COST = 3;
$VERIFY_LINK_TTL = 1800; // 30 minutes

if ($BOT_TOKEN === "") { http_response_code(500); echo "Missing BOT_TOKEN"; exit; }
if ($ADMIN_ID <= 0)    { http_response_code(500); echo "Missing ADMIN_ID"; exit; }

$API = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$dbPath = __DIR__ . "/bot.sqlite";

// ---------------- DB ----------------
function db($dbPath) {
  $pdo = new PDO("sqlite:" . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  return $pdo;
}
function init_db($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS users(
    user_id INTEGER PRIMARY KEY,
    referred_by INTEGER,
    points INTEGER NOT NULL DEFAULT 0,
    join_verified INTEGER NOT NULL DEFAULT 0,
    device_verified INTEGER NOT NULL DEFAULT 0,
    awaiting_coupons INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");

  // lock: reward only once per new user
  $pdo->exec("CREATE TABLE IF NOT EXISTS referrals(
    new_user_id INTEGER PRIMARY KEY,
    referrer_id INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");

  // Coupon stock: rows exist until redeemed; redeemed coupon is deleted
  $pdo->exec("CREATE TABLE IF NOT EXISTS coupons(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");

  // cache (no fancy UPSERT to avoid old sqlite problems)
  $pdo->exec("CREATE TABLE IF NOT EXISTS kv(
    k TEXT PRIMARY KEY,
    v TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");

  // device lock
  $pdo->exec("CREATE TABLE IF NOT EXISTS device_registry(
    device_token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS user_device(
    user_id INTEGER PRIMARY KEY,
    device_token TEXT NOT NULL,
    verified_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
}
$pdo = db($dbPath);
init_db($pdo);

// ---------------- Helpers ----------------
function html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }

function baseUrl() {
  $proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'https';
  $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
  $path  = $_SERVER['SCRIPT_NAME'] ?? '/';
  return $proto . '://' . $host . $path;
}

function makeSig($uid, $ts) {
  global $BOT_TOKEN;
  return hash_hmac('sha256', $uid . "|" . $ts, $BOT_TOKEN);
}
function checkSig($uid, $ts, $sig) {
  return hash_equals(makeSig($uid, $ts), (string)$sig);
}

// ---------------- Telegram HTTP ----------------
function tg($method, $data) {
  global $API;
  $ch = curl_init($API . $method);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}
function answerCallback($callback_query_id) {
  tg("answerCallbackQuery", ["callback_query_id" => $callback_query_id]);
}
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "disable_web_page_preview" => true
  ];
  if ($parse_mode) $data["parse_mode"] = $parse_mode;
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}
function editMessage($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = null) {
  $data = [
    "chat_id" => $chat_id,
    "message_id" => $message_id,
    "text" => $text,
    "disable_web_page_preview" => true
  ];
  if ($parse_mode) $data["parse_mode"] = $parse_mode;
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("editMessageText", $data);
}
function getChatMember($chat_id, $user_id) {
  return tg("getChatMember", ["chat_id" => $chat_id, "user_id" => $user_id]);
}

// ---------------- KV cache ----------------
function kv_get($pdo, $k) {
  $st = $pdo->prepare("SELECT v FROM kv WHERE k=?");
  $st->execute([$k]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? $row["v"] : null;
}
function kv_set($pdo, $k, $v) {
  $st = $pdo->prepare("INSERT OR REPLACE INTO kv(k,v,updated_at) VALUES(?,?,CURRENT_TIMESTAMP)");
  $st->execute([$k, $v]);
}
function getBotUsername($pdo) {
  $cached = kv_get($pdo, "bot_username");
  if ($cached && trim($cached) !== "") return trim($cached);

  $me = tg("getMe", []);
  if ($me && !empty($me["ok"]) && !empty($me["result"]["username"])) {
    $u = trim($me["result"]["username"]);
    kv_set($pdo, "bot_username", $u);
    return $u;
  }
  return null;
}

// ---------------- Force Join ----------------
function isJoined($chat, $user_id) {
  $chat = trim((string)$chat);
  if ($chat === "") return true;

  $res = getChatMember($chat, $user_id);
  if (!$res || empty($res["ok"])) return false;

  $status = $res["result"]["status"] ?? "";
  return in_array($status, ["creator", "administrator", "member", "restricted"], true);
}
function checkForceJoin($user_id) {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  return isJoined($FORCE_JOIN_1, $user_id) && isJoined($FORCE_JOIN_2, $user_id);
}

// ---------------- DB helpers ----------------
function ensureUser($pdo, $user_id, $referred_by = null) {
  // Only set referred_by on first insert; do not overwrite later
  $st = $pdo->prepare("INSERT OR IGNORE INTO users(user_id, referred_by) VALUES(?, ?)");
  $st->execute([$user_id, $referred_by]);
}
function getUser($pdo, $user_id) {
  $st = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
  $st->execute([$user_id]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function setJoinVerified($pdo, $user_id, $v) {
  $st = $pdo->prepare("UPDATE users SET join_verified=? WHERE user_id=?");
  $st->execute([$v, $user_id]);
}
function setDeviceVerified($pdo, $user_id, $v) {
  $st = $pdo->prepare("UPDATE users SET device_verified=? WHERE user_id=?");
  $st->execute([$v, $user_id]);
}
function addPoints($pdo, $user_id, $n) {
  $st = $pdo->prepare("UPDATE users SET points = points + ? WHERE user_id=?");
  $st->execute([$n, $user_id]);
}
function deductPoints($pdo, $user_id, $n) {
  $u = getUser($pdo, $user_id);
  if (!$u) return false;
  if (intval($u["points"]) < $n) return false;
  $st = $pdo->prepare("UPDATE users SET points = points - ? WHERE user_id=?");
  $st->execute([$n, $user_id]);
  return true;
}
function recordReferralOnce($pdo, $new_user_id, $referrer_id) {
  try {
    $st = $pdo->prepare("INSERT INTO referrals(new_user_id, referrer_id) VALUES(?, ?)");
    $st->execute([$new_user_id, $referrer_id]);
    return true;
  } catch (Exception $e) {
    return false;
  }
}
function setAwaitingCoupons($pdo, $user_id, $v) {
  $st = $pdo->prepare("UPDATE users SET awaiting_coupons=? WHERE user_id=?");
  $st->execute([$v, $user_id]);
}
function addCoupon($pdo, $code) {
  $code = trim($code);
  if ($code === "") return false;
  try {
    $st = $pdo->prepare("INSERT INTO coupons(code) VALUES(?)");
    $st->execute([$code]);
    return true;
  } catch (Exception $e) {
    return false;
  }
}
function couponStock($pdo) {
  $st = $pdo->query("SELECT COUNT(*) FROM coupons");
  return intval($st->fetchColumn() ?: 0);
}
// Delete coupon from stock when redeemed
function takeCoupon($pdo) {
  $pdo->beginTransaction();
  $st = $pdo->query("SELECT id, code FROM coupons ORDER BY id ASC LIMIT 1");
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { $pdo->rollBack(); return null; }

  $id = intval($row["id"]);
  $code = $row["code"];

  $st2 = $pdo->prepare("DELETE FROM coupons WHERE id=?");
  $st2->execute([$id]);

  $pdo->commit();
  return $code;
}

// Reward referrer ONLY after both verifications (join + device)
function rewardReferrerIfEligible($pdo, $new_user_id) {
  $u = getUser($pdo, $new_user_id);
  if (!$u) return;

  if (intval($u["join_verified"]) !== 1) return;
  if (intval($u["device_verified"]) !== 1) return;

  $referrer_id = intval($u["referred_by"] ?? 0);
  if ($referrer_id <= 0) return;

  if (recordReferralOnce($pdo, $new_user_id, $referrer_id)) {
    addPoints($pdo, $referrer_id, 1);
  }
}

// Device binding
function bindDeviceToken($pdo, $user_id, $device_token) {
  $device_token = trim((string)$device_token);
  if ($device_token === "") return [false, "Empty device token"];

  // device already used by someone else?
  $st = $pdo->prepare("SELECT user_id FROM device_registry WHERE device_token=?");
  $st->execute([$device_token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row && intval($row["user_id"]) !== intval($user_id)) {
    return [false, "This device is already used on another Telegram account."];
  }

  // user already bound to another device?
  $st2 = $pdo->prepare("SELECT device_token FROM user_device WHERE user_id=?");
  $st2->execute([$user_id]);
  $row2 = $st2->fetch(PDO::FETCH_ASSOC);
  if ($row2 && $row2["device_token"] !== $device_token) {
    return [false, "This Telegram account is already verified on another device."];
  }

  // save
  $st3 = $pdo->prepare("INSERT OR REPLACE INTO device_registry(device_token, user_id, created_at) VALUES(?, ?, CURRENT_TIMESTAMP)");
  $st3->execute([$device_token, $user_id]);

  $st4 = $pdo->prepare("INSERT OR REPLACE INTO user_device(user_id, device_token, verified_at) VALUES(?, ?, CURRENT_TIMESTAMP)");
  $st4->execute([$user_id, $device_token]);

  setDeviceVerified($pdo, $user_id, 1);
  return [true, "OK"];
}

// ---------------- UI ----------------
function welcomeText() { return "🎉WELCOME TO VIP REFER BOT"; }

function buildJoinUrlFromChat($chat) {
  $chat = trim((string)$chat);
  if ($chat === "") return null;
  if ($chat[0] === "@") return "https://t.me/" . ltrim($chat, "@");
  return null;
}
function joinGateText() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $note = "";
  if (($FORCE_JOIN_1 && $FORCE_JOIN_1[0] !== "@") || ($FORCE_JOIN_2 && $FORCE_JOIN_2[0] !== "@")) {
    $note = "\n\nℹ️ Join links hidden (numeric chat id used). Join manually then click ✅ Verify.";
  }
  return "🔒 Join both chats to use the bot.\n\nAfter joining, click ✅ Verify." . $note;
}
function joinKeyboard() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $rows = [];
  $u1 = buildJoinUrlFromChat($FORCE_JOIN_1);
  if ($u1) $rows[] = [["text" => "📌 Join Group 1", "url" => $u1]];
  $u2 = buildJoinUrlFromChat($FORCE_JOIN_2);
  if ($u2) $rows[] = [["text" => "📌 Join Group 2", "url" => $u2]];
  $rows[] = [["text" => "✅ Verify", "callback_data" => "verify_join"]];
  return ["inline_keyboard" => $rows];
}
function deviceVerifyKeyboard($verifyUrl) {
  return ["inline_keyboard" => [
    [["text" => "✅ Verify Now", "url" => $verifyUrl]],
    [["text" => "✅ Check Verification", "callback_data" => "check_device"]],
  ]];
}
function mainMenu($user_id) {
  global $ADMIN_ID;
  $kb = [
    [
      ["text" => "📊 Stats", "callback_data" => "stats"],
      ["text" => "🎁 Withdraw", "callback_data" => "withdraw"]
    ],
    [
      ["text" => "🔗 My Link", "callback_data" => "mylink"],
      ["text" => "❓ Help", "callback_data" => "help"]
    ],
  ];
  if ($user_id == $ADMIN_ID) {
    $kb[] = [[ "text" => "🛠 Admin Panel", "callback_data" => "admin" ]];
  }
  return ["inline_keyboard" => $kb];
}
function adminMenu() {
  return ["inline_keyboard" => [
    [
      ["text" => "➕ Add Coupons", "callback_data" => "admin_add"],
      ["text" => "📦 Coupon Stock", "callback_data" => "admin_stock"]
    ],
    [["text" => "⬅️ Back", "callback_data" => "back"]],
  ]];
}

// =====================================================================
// WEB VERIFY ROUTES: handled on GET/POST using ?action=verify / verify_submit
// =====================================================================
$action = $_GET["action"] ?? null;

// Serve verify page
if ($_SERVER["REQUEST_METHOD"] === "GET" && $action === "verify") {
  $uid = intval($_GET["uid"] ?? 0);
  $ts  = intval($_GET["ts"] ?? 0);
  $sig = (string)($_GET["sig"] ?? "");

  if ($uid <= 0 || $ts <= 0 || $sig === "") { http_response_code(400); echo "Invalid link"; exit; }
  if (abs(time() - $ts) > $VERIFY_LINK_TTL) { http_response_code(400); echo "Link expired. Go back to bot and try again."; exit; }
  if (!checkSig($uid, $ts, $sig)) { http_response_code(403); echo "Bad signature"; exit; }

  $postUrl = baseUrl() . "?action=verify_submit";

  header("Content-Type: text/html; charset=utf-8");
  ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Verification</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1020;color:#fff;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
    .card{width:100%;max-width:520px;background:#141a33;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .btn{display:block;width:100%;border:0;border-radius:12px;padding:14px 16px;font-size:16px;font-weight:700;background:#2f6bff;color:#fff}
    .muted{opacity:.8;margin-top:10px}
    .ok{margin-top:12px;padding:10px 12px;border-radius:12px;background:#0f2a1a}
    .bad{margin-top:12px;padding:10px 12px;border-radius:12px;background:#3a1111}
  </style>
</head>
<body>
  <div class="card">
    <h2>🔐 Verification</h2>
    <p class="muted">Tap below to verify. This blocks fake referrals and keeps rewards fair.</p>
    <button class="btn" id="btn">✅ Verify Now</button>
    <div id="msg"></div>
    <p class="muted">After this becomes <b>Ready</b>, go back to Telegram and tap <b>Check Verification</b>.</p>
  </div>

<script>
function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    var r = Math.random()*16|0, v = c=='x' ? r : (r&0x3|0x8);
    return v.toString(16);
  });
}

const uid = <?php echo (int)$uid; ?>;
const ts  = <?php echo (int)$ts; ?>;
const sig = <?php echo json_encode($sig); ?>;
const postUrl = <?php echo json_encode($postUrl); ?>;

document.getElementById("btn").onclick = async () => {
  let token = localStorage.getItem("device_token");
  if (!token) {
    token = uuidv4() + "-" + Date.now();
    localStorage.setItem("device_token", token);
  }

  const form = new URLSearchParams();
  form.set("uid", uid);
  form.set("ts", ts);
  form.set("sig", sig);
  form.set("device_token", token);

  const msg = document.getElementById("msg");
  msg.innerHTML = "<div class='muted'>Verifying...</div>";

  try {
    const r = await fetch(postUrl, {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: form.toString()
    });
    const t = await r.text();
    if (r.ok) {
      msg.innerHTML = "<div class='ok'>✅ Ready. Now go back to Telegram and press <b>Check Verification</b>.</div>";
    } else {
      msg.innerHTML = "<div class='bad'>❌ " + t + "</div>";
    }
  } catch (e) {
    msg.innerHTML = "<div class='bad'>❌ Network error. Try again.</div>";
  }
};
</script>
</body>
</html>
  <?php
  exit;
}

// Receive verify submit
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "verify_submit") {
  $uid = intval($_POST["uid"] ?? 0);
  $ts  = intval($_POST["ts"] ?? 0);
  $sig = (string)($_POST["sig"] ?? "");
  $device_token = trim((string)($_POST["device_token"] ?? ""));

  if ($uid <= 0 || $ts <= 0 || $sig === "" || $device_token === "") { http_response_code(400); echo "Invalid data"; exit; }
  if (abs(time() - $ts) > $VERIFY_LINK_TTL) { http_response_code(400); echo "Link expired. Go back to bot and verify again."; exit; }
  if (!checkSig($uid, $ts, $sig)) { http_response_code(403); echo "Bad signature"; exit; }

  ensureUser($pdo, $uid, null);

  [$ok, $msg] = bindDeviceToken($pdo, $uid, $device_token);
  if (!$ok) { http_response_code(403); echo $msg; exit; }

  http_response_code(200);
  echo "OK";
  exit;
}

// =====================================================================
// TELEGRAM WEBHOOK (POST JSON)
// =====================================================================
$raw = file_get_contents("php://input");
$update = json_decode($raw, true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

// ---------------- MESSAGE ----------------
if (isset($update["message"])) {
  $msg = $update["message"];
  $chat_id = $msg["chat"]["id"];
  $user_id = $msg["from"]["id"];
  $text = $msg["text"] ?? "";

  // Robust /start parsing (fixes /start@BotName and payload)
  if (preg_match('/^\/start(?:@[\w_]+)?(?:\s+(.+))?$/', trim($text), $m)) {
    $payload = isset($m[1]) ? trim($m[1]) : "";
    $referrer_id = null;

    // accept only digits as referral id
    if ($payload !== "" && preg_match('/^\d+$/', $payload)) {
      $referrer_id = intval($payload);
      if ($referrer_id === $user_id) $referrer_id = null;
    }

    ensureUser($pdo, $user_id, $referrer_id);

    // Step 1: join verify
    if (!checkForceJoin($user_id)) {
      setJoinVerified($pdo, $user_id, 0);
      sendMessage($chat_id, joinGateText(), joinKeyboard());
      http_response_code(200); echo "OK"; exit;
    }
    setJoinVerified($pdo, $user_id, 1);

    // Step 2: device verify
    $u = getUser($pdo, $user_id);
    if (!$u || intval($u["device_verified"]) !== 1) {
      $ts = time();
      $sig = makeSig($user_id, $ts);
      $verifyUrl = baseUrl() . "?action=verify&uid={$user_id}&ts={$ts}&sig={$sig}";
      sendMessage($chat_id, "✅ Channel join verified!\n\nNext: *Verify Yourself*", deviceVerifyKeyboard($verifyUrl), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }

    // Both verified -> reward + menu
    rewardReferrerIfEligible($pdo, $user_id);
    sendMessage($chat_id, welcomeText(), mainMenu($user_id));
    http_response_code(200); echo "OK"; exit;
  }

  // /admin
  if (trim($text) === "/admin") {
    if ($user_id != $ADMIN_ID) {
      sendMessage($chat_id, "❌ You are not admin.", mainMenu($user_id));
      http_response_code(200); echo "OK"; exit;
    }
    sendMessage($chat_id, "🛠 Admin Panel", adminMenu());
    http_response_code(200); echo "OK"; exit;
  }

  // /cancel coupon add mode
  if (trim($text) === "/cancel") {
    setAwaitingCoupons($pdo, $user_id, 0);
    sendMessage($chat_id, "✅ Cancelled.", mainMenu($user_id));
    http_response_code(200); echo "OK"; exit;
  }

  // Admin adding coupons (awaiting mode)
  $u = getUser($pdo, $user_id);
  if ($u && intval($u["awaiting_coupons"]) === 1 && $user_id == $ADMIN_ID) {
    $lines = preg_split("/\r\n|\n|\r/", $text);
    $added = 0; $skipped = 0;
    foreach ($lines as $
