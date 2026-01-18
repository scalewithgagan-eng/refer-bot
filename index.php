<?php
// =================== VIP REFER BOT (PHP + SQLite) ===================
// Render Web Service + Webhook
//
// ENV on Render:
// BOT_TOKEN, ADMIN_ID, FORCE_JOIN_1, FORCE_JOIN_2
//
// Referral link auto-detects bot username via getMe() and caches in DB.
// ====================================================================

// ---------- CONFIG ----------
$BOT_TOKEN    = trim((string)getenv("BOT_TOKEN"));
$ADMIN_ID     = intval(getenv("ADMIN_ID"));
$FORCE_JOIN_1 = trim((string)getenv("FORCE_JOIN_1"));  // @username or -100...
$FORCE_JOIN_2 = trim((string)getenv("FORCE_JOIN_2"));  // @username or -100...
$WITHDRAW_COST = 3;

if ($BOT_TOKEN === "") { http_response_code(500); echo "Missing BOT_TOKEN"; exit; }
if ($ADMIN_ID <= 0)    { http_response_code(500); echo "Missing ADMIN_ID"; exit; }

$API = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$dbPath = __DIR__ . "/bot.sqlite";

// ---------- DB ----------
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
    verified INTEGER NOT NULL DEFAULT 0,
    awaiting_coupons INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS referrals(
    new_user_id INTEGER PRIMARY KEY,
    referrer_id INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS coupons(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    used INTEGER NOT NULL DEFAULT 0,
    used_by INTEGER,
    used_at TEXT
  )");
  // cache table for bot username
  $pdo->exec("CREATE TABLE IF NOT EXISTS kv(
    k TEXT PRIMARY KEY,
    v TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
}

$pdo = db($dbPath);
init_db($pdo);

// ---------- Telegram HTTP ----------
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
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = "Markdown") {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => $parse_mode,
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}
function editMessage($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = "Markdown") {
  $data = [
    "chat_id" => $chat_id,
    "message_id" => $message_id,
    "text" => $text,
    "parse_mode" => $parse_mode,
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("editMessageText", $data);
}
function getChatMember($chat_id, $user_id) {
  return tg("getChatMember", ["chat_id" => $chat_id, "user_id" => $user_id]);
}

// ---------- Bot username cache ----------
function kv_get($pdo, $k) {
  $st = $pdo->prepare("SELECT v FROM kv WHERE k=?");
  $st->execute([$k]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? $row["v"] : null;
}
function kv_set($pdo, $k, $v) {
  $st = $pdo->prepare("INSERT INTO kv(k,v,updated_at) VALUES(?,?,CURRENT_TIMESTAMP)
                       ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=CURRENT_TIMESTAMP");
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

// ---------- Force-Join Check ----------
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

// ---------- UI ----------
function buildJoinUrlFromChat($chat) {
  $chat = trim((string)$chat);
  if ($chat === "") return null;
  if ($chat[0] === "@") return "https://t.me/" . ltrim($chat, "@");
  return null; // numeric -100 can't be linked
}
function joinKeyboard() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $rows = [];
  $u1 = buildJoinUrlFromChat($FORCE_JOIN_1);
  if ($u1) $rows[] = [["text" => "📌 Join Group 1", "url" => $u1]];
  $u2 = buildJoinUrlFromChat($FORCE_JOIN_2);
  if ($u2) $rows[] = [["text" => "📌 Join Group 2", "url" => $u2]];
  $rows[] = [["text" => "✅ Verify", "callback_data" => "verify"]];
  return ["inline_keyboard" => $rows];
}
function mainMenu() {
  return ["inline_keyboard" => [
    [
      ["text" => "📊 Stats", "callback_data" => "stats"],
      ["text" => "🎁 Withdraw", "callback_data" => "withdraw"]
    ],
    [
      ["text" => "🔗 My Link", "callback_data" => "mylink"],
      ["text" => "❓ Help", "callback_data" => "help"]
    ],
    [
      ["text" => "🛠 Admin", "callback_data" => "admin"]
    ],
  ]];
}
function adminMenu() {
  return ["inline_keyboard" => [
    [
      ["text" => "➕ Add Coupons", "callback_data" => "admin_add"],
      ["text" => "📦 Coupon Stock", "callback_data" => "admin_stock"]
    ],
    [
      ["text" => "⬅️ Back", "callback_data" => "back"]
    ],
  ]];
}

// ---------- DB helpers ----------
function ensureUser($pdo, $user_id, $referred_by = null) {
  $st = $pdo->prepare("INSERT OR IGNORE INTO users(user_id, referred_by) VALUES(?, ?)");
  $st->execute([$user_id, $referred_by]);
}
function getUser($pdo, $user_id) {
  $st = $pdo->prepare("SELECT user_id, referred_by, points, verified, awaiting_coupons FROM users WHERE user_id=?");
  $st->execute([$user_id]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function setVerified($pdo, $user_id, $v) {
  $st = $pdo->prepare("UPDATE users SET verified=? WHERE user_id=?");
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
function recordReferral($pdo, $new_user_id, $referrer_id) {
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
  $st = $pdo->query("SELECT COUNT(*) AS c FROM coupons WHERE used=0");
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return intval($row["c"] ?? 0);
}
function takeCoupon($pdo, $user_id) {
  $st = $pdo->query("SELECT id, code FROM coupons WHERE used=0 ORDER BY id ASC LIMIT 1");
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;

  $id = intval($row["id"]);
  $code = $row["code"];

  $st2 = $pdo->prepare("UPDATE coupons SET used=1, used_by=?, used_at=CURRENT_TIMESTAMP WHERE id=?");
  $st2->execute([$user_id, $id]);

  return $code;
}

// ---------- Text helpers ----------
function welcomeText() { return "🎉*WELCOME TO VIP REFER BOT*"; }
function joinGateText() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $note = "";
  if (($FORCE_JOIN_1 && $FORCE_JOIN_1[0] !== "@") || ($FORCE_JOIN_2 && $FORCE_JOIN_2[0] !== "@")) {
    $note = "\n\nℹ️ Join links are hidden because you used numeric chat IDs. Join manually, then click ✅ Verify.";
  }
  return "🔒 *Join both groups to use the bot.*\n\nAfter joining, click ✅ *Verify*." . $note;
}

// =================== WEBHOOK INPUT ===================
$raw = file_get_contents("php://input");
$update = json_decode($raw, true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

// =================== MESSAGE ===================
if (isset($update["message"])) {
  $msg = $update["message"];
  $chat_id = $msg["chat"]["id"];
  $user_id = $msg["from"]["id"];
  $text = $msg["text"] ?? "";

  // /start + referral
  if (strpos($text, "/start") === 0) {
    $referrer_id = null;
    $parts = explode(" ", $text, 2);
    if (count($parts) === 2 && ctype_digit(trim($parts[1]))) {
      $referrer_id = intval(trim($parts[1]));
      if ($referrer_id === $user_id) $referrer_id = null;
    }

    ensureUser($pdo, $user_id, $referrer_id);

    // +1 point only first time
    if ($referrer_id) {
      $recorded = recordReferral($pdo, $user_id, $referrer_id);
      if ($recorded) addPoints($pdo, $referrer_id, 1);
    }

    if (!checkForceJoin($user_id)) {
      setVerified($pdo, $user_id, 0);
      sendMessage($chat_id, joinGateText(), joinKeyboard());
      http_response_code(200); echo "OK"; exit;
    }

    setVerified($pdo, $user_id, 1);
    sendMessage($chat_id, welcomeText(), mainMenu());
    http_response_code(200); echo "OK"; exit;
  }

  // /admin
  if ($text === "/admin") {
    if ($user_id != $ADMIN_ID) {
      sendMessage($chat_id, "❌ You are not admin.", mainMenu());
      http_response_code(200); echo "OK"; exit;
    }
    sendMessage($chat_id, "🛠 Admin Panel", adminMenu());
    http_response_code(200); echo "OK"; exit;
  }

  // /cancel
  if ($text === "/cancel") {
    setAwaitingCoupons($pdo, $user_id, 0);
    sendMessage($chat_id, "✅ Cancelled.", mainMenu());
    http_response_code(200); echo "OK"; exit;
  }

  // Admin adding coupons
  $u = getUser($pdo, $user_id);
  if ($u && intval($u["awaiting_coupons"]) === 1 && $user_id == $ADMIN_ID) {
    $lines = preg_split("/\r\n|\n|\r/", $text);
    $added = 0; $skipped = 0;
    foreach ($lines as $line) {
      $code = trim($line);
      if ($code === "") continue;
      if (addCoupon($pdo, $code)) $added++; else $skipped++;
    }
    $stock = couponStock($pdo);
    sendMessage($chat_id, "✅ Added: *{$added}*\n⚠️ Skipped: *{$skipped}*\n📦 Stock: *{$stock}*", adminMenu());
    http_response_code(200); echo "OK"; exit;
  }

  // Default
  $u = getUser($pdo, $user_id);
  if (!$u || intval($u["verified"]) !== 1) {
    sendMessage($chat_id, joinGateText(), joinKeyboard());
  } else {
    sendMessage($chat_id, welcomeText(), mainMenu());
  }

  http_response_code(200); echo "OK"; exit;
}

// =================== CALLBACK QUERY ===================
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"];
  $user_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];
  $message_id = $cq["message"]["message_id"];
  $callback_id = $cq["id"];

  answerCallback($callback_id);

  $u = getUser($pdo, $user_id);
  $verified = $u ? intval($u["verified"]) : 0;

  if ($data === "verify") {
    if (!checkForceJoin($user_id)) {
      setVerified($pdo, $user_id, 0);
      editMessage($chat_id, $message_id, "❌ Not verified yet.\n\nJoin both groups, then click ✅ Verify again.", joinKeyboard(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }
    setVerified($pdo, $user_id, 1);
    editMessage($chat_id, $message_id, welcomeText(), mainMenu(), "Markdown");
    http_response_code(200); echo "OK"; exit;
  }

  if ($verified !== 1) {
    editMessage($chat_id, $message_id, "🔒 Please join both groups first, then click ✅ Verify.", joinKeyboard(), "Markdown");
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "back") {
    editMessage($chat_id, $message_id, welcomeText(), mainMenu(), "Markdown");
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "stats") {
    $points = $u ? intval($u["points"]) : 0;
    editMessage($chat_id, $message_id,
      "📊 *Your Stats*\n\n⭐ Points: *{$points}*\n🎁 Need *{$WITHDRAW_COST}* points for 1 code.",
      mainMenu(),
      "Markdown"
    );
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "mylink") {
    $botUsername = getBotUsername($pdo);
    if (!$botUsername) {
      $msg = "❌ Bot username not found.\n\nSet a username in @BotFather, then send /start again.";
      editMessage($chat_id, $message_id, $msg, mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }
    $link = "https://t.me/{$botUsername}?start={$user_id}";
    editMessage($chat_id, $message_id,
      "🔗 *Your Referral Link*\n\n{$link}\n\nShare this link. When someone starts the bot using it, you get *+1 point*.",
      mainMenu(),
      "Markdown"
    );
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "help") {
    editMessage($chat_id, $message_id,
      "❓ *Help*\n\n• Join both groups and click ✅ Verify\n• Get *{$WITHDRAW_COST}* points = 1 code\n• Share your referral link to earn points\n• Withdraw deducts only *{$WITHDRAW_COST}* points each time",
      mainMenu(),
      "Markdown"
    );
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "withdraw") {
    $points = $u ? intval($u["points"]) : 0;

    if ($points < $WITHDRAW_COST) {
      editMessage($chat_id, $message_id,
        "❌ Not enough points.\n\nYou have: *{$points}*\nNeed: *{$WITHDRAW_COST}*",
        mainMenu(),
        "Markdown"
      );
      http_response_code(200); echo "OK"; exit;
    }

    $stock = couponStock($pdo);
    if ($stock <= 0) {
      editMessage($chat_id, $message_id, "⚠️ No coupons available right now. Please try later.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }

    if (!deductPoints($pdo, $user_id, $WITHDRAW_COST)) {
      editMessage($chat_id, $message_id, "❌ Something went wrong. Try again.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }

    $code = takeCoupon($pdo, $user_id);
    if (!$code) {
      addPoints($pdo, $user_id, $WITHDRAW_COST);
      editMessage($chat_id, $message_id, "⚠️ No coupons available right now. Please try later.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }

    editMessage($chat_id, $message_id,
      "✅ Withdraw successful!\n\n🎁 Your Code: `{$code}`\n\n⭐ Deducted: *{$WITHDRAW_COST}* points",
      mainMenu(),
      "Markdown"
    );
    http_response_code(200); echo "OK"; exit;
  }

  // Admin
  if ($data === "admin") {
    if ($user_id != $ADMIN_ID) {
      editMessage($chat_id, $message_id, "❌ You are not admin.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }
    editMessage($chat_id, $message_id, "🛠 Admin Panel", adminMenu(), "Markdown");
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_stock") {
    if ($user_id != $ADMIN_ID) {
      editMessage($chat_id, $message_id, "❌ You are not admin.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }
    $stock = couponStock($pdo);
    editMessage($chat_id, $message_id, "📦 Unused coupons in stock: *{$stock}*", adminMenu(), "Markdown");
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_add") {
    if ($user_id != $ADMIN_ID) {
      editMessage($chat_id, $message_id, "❌ You are not admin.", mainMenu(), "Markdown");
      http_response_code(200); echo "OK"; exit;
    }
    setAwaitingCoupons($pdo, $user_id, 1);
    editMessage($chat_id, $message_id,
      "➕ Send me coupons now.\n\nSend multiple codes in one message, one per line.\nType /cancel to stop.",
      null,
      "Markdown"
    );
    http_response_code(200); echo "OK"; exit;
  }

  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
