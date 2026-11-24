<?php
/*
 * Koelony — shared data API
 * Put this file in the SAME folder as index.html on any PHP host (PHP 7.4+).
 * It creates a protected "colony-data" folder next to itself on first use.
 * First login: admin / admin123  (you will be asked to change it).
 */
declare(strict_types=1);

// Keep login sessions inside our own protected folder (fixes hosts like iPage where the default session folder is not writable)
$SESS = __DIR__ . '/colony-data/sessions';
if (!is_dir($SESS)) { @mkdir($SESS, 0700, true); }
if (is_dir($SESS) && is_writable($SESS)) { session_save_path($SESS); }
ini_set('session.gc_maxlifetime', '43200');

session_set_cookie_params([
  'httponly' => true,
  'samesite' => 'Strict',
  'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$DIR = __DIR__ . '/colony-data';
if (!is_dir($DIR)) { @mkdir($DIR, 0750, true); }
if (!file_exists("$DIR/.htaccess")) { @file_put_contents("$DIR/.htaccess", "Require all denied\nDeny from all\n"); }
if (!file_exists("$DIR/index.html")) { @file_put_contents("$DIR/index.html", ""); }
$UF = "$DIR/users.php";
$DF = "$DIR/colony.php";
$GUARD = "<?php http_response_code(403); exit; ?>\n";

function out($d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function readj(string $f, $def) {
  if (!file_exists($f)) return $def;
  global $GUARD;
  $t = (string)file_get_contents($f);
  if (strncmp($t, $GUARD, strlen($GUARD)) === 0) $t = substr($t, strlen($GUARD));
  $j = json_decode($t, true);
  return is_array($j) ? $j : $def;
}
function writej(string $f, $d) {
  global $GUARD;
  $tmp = $f . '.tmp.php';
  if (file_put_contents($tmp, $GUARD . json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    out(['error' => 'Server cannot write to colony-data folder (check permissions)'], 500);
  }
  rename($tmp, $f);
}
function withLock(callable $fn) {
  global $DIR;
  $h = fopen("$DIR/.lock", 'c');
  flock($h, LOCK_EX);
  try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
}

$users = readj($UF, null);
if ($users === null) {
  $users = ['admin' => ['hash' => password_hash('admin123', PASSWORD_DEFAULT), 'role' => 'admin', 'mustChange' => true]];
  writej($UF, $users);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && ($_SERVER['HTTP_X_COLONY'] ?? '') !== '1') out(['error' => 'Bad request'], 400);
$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true) ?: [];

$me = $_SESSION['u'] ?? null;
if ($me !== null && !isset($users[$me])) { $me = null; unset($_SESSION['u']); }

/* trainee: practice account — can read everything, can never write (the app keeps its changes in the browser only) */
$RANK = ['viewer' => 1, 'trainee' => 1, 'editor' => 2, 'admin' => 3];
function need(string $role) {
  global $me, $users, $RANK;
  if (!$me) out(['error' => 'Not signed in'], 401);
  if ($RANK[$users[$me]['role']] < $RANK[$role]) out(['error' => 'Your account role does not allow this'], 403);
}
function meInfo() {
  global $me, $users;
  return $me ? ['user' => $me, 'role' => $users[$me]['role'], 'mustChange' => !empty($users[$me]['mustChange']), 'email' => $users[$me]['email'] ?? '', 'digest' => !empty($users[$me]['digest'])] : ['user' => null];
}
function userList() {
  global $users;
  $l = [];
  foreach ($users as $u => $x) $l[] = ['user' => $u, 'role' => $x['role'], 'email' => $x['email'] ?? '', 'digest' => !empty($x['digest'])];
  return $l;
}


/* ================= daily email report ================= */
$MF = "$DIR/mail.php";
function mail_cfg() { global $MF; return array_merge(['enabled' => false, 'host' => 'smtp.gmail.com', 'port' => 465, 'user' => '', 'pass' => '', 'fromName' => 'Koelony', 'hour' => 7, 'tz' => 'America/Los_Angeles', 'lastSent' => '', 'lastTs' => 0, 'lastResult' => '', 'appUrl' => '', 'key' => ''], readj($MF, [])); }
function smtp_cmd($fp, $c) { fwrite($fp, $c . "\r\n"); }
function smtp_expect($fp, $code, &$err) { $resp = ''; while (($line = fgets($fp, 1024)) !== false) { $resp .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
  if ((int)substr($resp, 0, 3) !== $code) { $err = trim($resp) !== '' ? trim($resp) : 'No answer from the mail server'; return false; } return true; }
function smtp_open($cfg, &$err) {
  if (empty($cfg['user']) || empty($cfg['pass'])) { $err = 'Set the Gmail address and app password first'; return null; }
  $host = $cfg['host'] ?: 'smtp.gmail.com'; $port = (int)($cfg['port'] ?: 465); $ssl = $port === 465;
  $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 20);
  if (!$fp) { $err = "Cannot reach $host:$port ($es). The host may block outgoing email."; return null; }
  stream_set_timeout($fp, 25);
  if (!smtp_expect($fp, 220, $err)) return null;
  smtp_cmd($fp, 'EHLO koelony'); if (!smtp_expect($fp, 250, $err)) return null;
  if (!$ssl) { smtp_cmd($fp, 'STARTTLS'); if (!smtp_expect($fp, 220, $err)) return null;
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $err = 'Could not start TLS'; return null; }
    smtp_cmd($fp, 'EHLO koelony'); if (!smtp_expect($fp, 250, $err)) return null; }
  smtp_cmd($fp, 'AUTH LOGIN'); if (!smtp_expect($fp, 334, $err)) return null;
  smtp_cmd($fp, base64_encode($cfg['user'])); if (!smtp_expect($fp, 334, $err)) return null;
  smtp_cmd($fp, base64_encode(preg_replace('/\s+/', '', $cfg['pass']))); if (!smtp_expect($fp, 235, $err)) { $err = 'Gmail refused the login — check the address and the 16-letter app password. (' . $err . ')'; return null; }
  return $fp; }
function smtp_send($fp, $cfg, $to, $subj, $html, &$err) {
  $from = $cfg['user']; $fn = $cfg['fromName'] ?: 'Koelony';
  smtp_cmd($fp, "MAIL FROM:<$from>"); if (!smtp_expect($fp, 250, $err)) return false;
  smtp_cmd($fp, "RCPT TO:<$to>"); if (!smtp_expect($fp, 250, $err)) { smtp_cmd($fp, 'RSET'); $e2 = ''; smtp_expect($fp, 250, $e2); return false; }
  smtp_cmd($fp, 'DATA'); if (!smtp_expect($fp, 354, $err)) return false;
  $h = 'From: =?UTF-8?B?' . base64_encode($fn) . "?= <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subj) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@koelony>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
  fwrite($fp, $h . chunk_split(base64_encode($html)) . "\r\n.\r\n");
  return smtp_expect($fp, 250, $err); }
function smtp_close($fp) { smtp_cmd($fp, 'QUIT'); @fclose($fp); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dfmt($iso) { return $iso ? date('m/d', strtotime($iso)) : ''; }
function ddiff($a, $b) { return (int)round((strtotime($b . ' 12:00') - strtotime($a . ' 12:00')) / 86400); }
function digest_html($d, $cfg, $sinceMs, $name, $today) {
  $set = ['weanDays' => 21, 'biopsyDays' => 0, 'cageCap' => 5, 'dueSoon' => 3, 'labName' => 'Koelony'];
  foreach ($d['meta'] ?? [] as $m) if (($m['id'] ?? '') === 'settings') foreach ($set as $k => $v) if (isset($m[$k]) && $m[$k] !== '') $set[$k] = $m[$k];
  $idx = function ($a) { $o = []; foreach ($a as $x) if (isset($x['id'])) $o[$x['id']] = $x; return $o; };
  $ST = $idx($d['strains'] ?? []); $BR = $idx($d['breedings'] ?? []); $CG = $idx($d['cages'] ?? []);
  $soon = (int)$set['dueSoon']; $days = function ($sid, $k) use ($ST, $set) { $v = $ST[$sid][$k] ?? ''; return ($v !== '' && $v !== null) ? (int)$v : (int)$set[$k]; };
  $alive = array_values(array_filter($d['mice'] ?? [], function ($m) { return ($m['status'] ?? 'alive') === 'alive'; }));
  $perCage = []; foreach ($alive as $m) if (!empty($m['cage'])) $perCage[$m['cage']] = ($perCage[$m['cage']] ?? 0) + 1;
  $wean = []; $count = []; $biop = []; $nurse = 0;
  foreach ($d['litters'] ?? [] as $l) {
    if (!empty($l['weaned']) || !empty($l['inactive']) || empty($l['dob'])) continue; $nurse++;
    $b = $BR[$l['breeding'] ?? ''] ?? []; $sid = $b['strain'] ?? ''; $nm = h(($b['name'] ?? '?') . ' · Litter ' . ($l['num'] ?? ''));
    $wb = date('Y-m-d', strtotime($l['dob'] . ' +' . $days($sid, 'weanDays') . ' days')); $dd = ddiff($today, $wb);
    if ($dd <= $soon) $wean[] = [$dd, "<b>$nm</b> — " . ($dd < 0 ? '<span style="color:#c92a2a">overdue ' . (-$dd) . ' days</span>' : ($dd === 0 ? '<b>wean today</b>' : "wean by " . dfmt($wb))) . ' · born ' . dfmt($l['dob']) . (!empty($l['born']) ? ' · ' . (int)$l['born'] . ' pups' : '')];
    if (empty($l['born']) && ddiff($l['dob'], $today) >= 3) $count[] = "<b>$nm</b> — born " . dfmt($l['dob']) . ', no pup count yet';
    $bd = $days($sid, 'biopsyDays'); if ($bd > 0 && empty($l['biopsied'])) { $bb = date('Y-m-d', strtotime($l['dob'] . " +$bd days")); if (ddiff($today, $bb) <= $soon) $biop[] = "<b>$nm</b> — biopsy by " . dfmt($bb); }
  }
  usort($wean, function ($a, $b) { return $a[0] <=> $b[0]; }); $wean = array_map(function ($x) { return $x[1]; }, $wean);
  $tasks = [];
  foreach ($d['tasks'] ?? [] as $t) { if (($t['status'] ?? '') !== 'pending' || empty($t['due'])) continue; $dd = ddiff($today, $t['due']); if ($dd > $soon) continue;
    $tasks[] = '<b>' . h($t['title'] ?? 'Task') . '</b> — ' . ($dd < 0 ? '<span style="color:#c92a2a">overdue ' . (-$dd) . ' days</span>' : ($dd === 0 ? 'due today' : 'due ' . dfmt($t['due']))) . (!empty($t['assignee']) ? ' · ' . h($t['assignee']) : ''); }
  /* v123: sacrifice / BMDM requests and dated cage marks */
  $reqs = []; $PUR = ['sacrifice' => '&#128300; Sacrifice', 'bmdm' => '&#129460; BMDM harvest', 'tissue' => '&#129514; Tissue collection', 'experiment' => '&#129514; Experiment'];
  $MI = []; foreach ($d['mice'] ?? [] as $m) $MI[$m['id']] = $m;
  $CGL = []; foreach ($d['cages'] ?? [] as $c) $CGL[$c['id']] = $c;
  $rq = $d['requests'] ?? []; usort($rq, function ($a, $b) { return strcmp((string)($a['due'] ?? '9'), (string)($b['due'] ?? '9')); });
  foreach ($rq as $r) { $st = $r['status'] ?? 'requested'; if ($st === 'done' || $st === 'cancelled') continue; $due = (string)($r['due'] ?? ''); $dd = $due ? ddiff($today, $due) : 99; if ($dd > $soon) continue;
    $cl = []; foreach ($r['mice'] ?? [] as $mid) { $c = $CGL[$MI[$mid]['cage'] ?? ''] ?? null; if ($c) $cl[$c['label']] = $c['label'] . (!empty($c['rack']) && !empty($c['pos']) ? ' (' . $c['rack'] . ' ' . $c['pos'] . ')' : ''); }
    $reqs[] = '<b>' . ($PUR[$r['purpose'] ?? ''] ?? 'Request') . '</b> — ' . (int)($r['n'] ?? count($r['mice'] ?? [])) . ' mice'
      . ($cl ? ' · ' . h(implode(', ', $cl)) : ' · <i>mice not chosen yet</i>') . ' · ' . ($dd < 0 ? '<span style="color:#c92a2a">overdue ' . (-$dd) . ' d</span>' : ($dd === 0 ? '<b>today</b>' : 'due ' . dfmt($due)))
      . (!empty($r['assignee']) ? ' · ' . h($r['assignee']) : '') . (!empty($r['by']) ? ' <span style="color:#888">(for ' . h($r['by']) . ')</span>' : ''); }
  $marks = []; $MK = ['health' => '&#9888;&#65039; Check health', 'fight' => '&#129354; Fighting', 'nouse' => '&#128683; Do not use', 'preg' => '&#127868; Pregnant?', 'water' => '&#128167; Water / food'];
  foreach ($d['cages'] ?? [] as $c) foreach ($c['marks'] ?? [] as $mk) { if (empty($mk['due'])) continue; $dd = ddiff($today, $mk['due']); if ($dd > $soon) continue;
    $marks[] = '<b>' . h($c['label']) . '</b> — ' . ($MK[$mk['k'] ?? ''] ?? h($mk['label'] ?? 'Mark')) . (!empty($mk['note']) ? ': ' . h($mk['note']) : '') . ' · ' . ($dd < 0 ? '<span style="color:#c92a2a">overdue</span>' : ($dd === 0 ? 'today' : dfmt($mk['due']))); }
  $warn = []; foreach ($perCage as $cid => $n) if ($n > (int)$set['cageCap']) $warn[] = 'Cage <b>' . h($CG[$cid]['label'] ?? '?') . "</b> has $n mice (max " . (int)$set['cageCap'] . ')';
  $act = []; foreach ($d['audit'] ?? [] as $a) { if (($a['t'] ?? 0) < $sinceMs) break; $act[] = h($a['msg'] ?? '') . ' <span style="color:#888">— ' . h($a['user'] ?? '') . ', ' . date('D g:ia', (int)(($a['t'] ?? 0) / 1000)) . '</span>'; if (count($act) >= 60) break; }
  $sec = function ($title, $items, $col = '#3b5bdb') { if (!$items) return ''; return '<h3 style="margin:22px 0 6px;font-size:15px;color:' . $col . '">' . $title . ' (' . count($items) . ')</h3><ul style="margin:0;padding-left:18px">' . implode('', array_map(function ($i) { return '<li style="margin:3px 0">' . $i . '</li>'; }, $items)) . '</ul>'; };
  $lab = h($set['labName']); $url = $cfg['appUrl'] ?? '';
  $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1b2333;max-width:640px">'
    . '<h2 style="margin:0 0 4px">' . $lab . ' — daily report</h2><div style="color:#687389">' . date('l, F j, Y', strtotime($today)) . '</div>'
    . '<p>Hi ' . h($name) . ',</p>'
    . ($wean ? '<div style="background:#fff0f0;border:2px solid #ff8787;border-radius:10px;padding:10px 14px;margin:10px 0"><div style="font-size:16px;font-weight:bold;color:#c92a2a;margin-bottom:4px">&#127868; Weanings due (' . count($wean) . ')</div>' . implode('<br>', $wean) . '</div>' : '')
    . '<table cellpadding="8" style="border-collapse:collapse;margin:6px 0"><tr>'
    . '<td style="background:#f3f5f9;border-radius:8px"><b style="font-size:18px">' . count($alive) . '</b><br><span style="color:#687389;font-size:12px">mice alive</span></td><td></td>'
    . '<td style="background:#f3f5f9;border-radius:8px"><b style="font-size:18px">' . count(array_filter($d['breedings'] ?? [], function ($b) { return ($b['status'] ?? '') === 'active'; })) . '</b><br><span style="color:#687389;font-size:12px">active breedings</span></td><td></td>'
    . '<td style="background:#f3f5f9;border-radius:8px"><b style="font-size:18px">' . $nurse . '</b><br><span style="color:#687389;font-size:12px">litters nursing</span></td></tr></table>'
    . $sec('Pup counts missing', $count, '#d9480f') . $sec('Biopsy / ID due', $biop, '#d9480f') . $sec('Requests (sacrifice / BMDM)', $reqs, '#862e9c') . $sec('Marked cages', $marks, '#d9480f') . $sec('Tasks due', $tasks) . $sec('Needs attention', $warn, '#c92a2a')
    . $sec('Changes since the last report', $act, '#495057');
  if (!$wean && !$count && !$biop && !$tasks && !$warn && !$act && !$reqs && !$marks) $body .= '<p style="color:#2b8a3e"><b>All quiet — nothing due and no changes.</b></p>';
  $body .= ($url ? '<p style="margin-top:24px"><a href="' . h($url) . '" style="background:#3b5bdb;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Open the colony app</a></p>' : '')
    . '<p style="color:#999;font-size:12px;margin-top:20px">You get this because "daily report" is on for your account. Turn it off in the app: menu → My email &amp; daily report.</p></div>';
  return [($wean ? '🍼 ' . count($wean) . ' weaning' . (count($wean) > 1 ? 's' : '') . ' due — ' : '') . $lab . ' — daily report ' . date('M j', strtotime($today)), $body];
}
function digest_run($force) {
  global $DIR, $MF, $DF, $EMPTY, $users;
  $cfg = mail_cfg();
  if (!$force && (empty($cfg['enabled']) || empty($cfg['pass']))) return ['sent' => 0];
  try { $tz = new DateTimeZone($cfg['tz'] ?: 'America/Los_Angeles'); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
  date_default_timezone_set($tz->getName());
  $now = new DateTime('now', $tz); $today = $now->format('Y-m-d');
  if (!$force && ($cfg['lastSent'] === $today || (int)$now->format('G') < (int)$cfg['hour'])) return ['sent' => 0];
  $lk = fopen("$DIR/.mlock", 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return ['sent' => 0];
  try {
    $cfg = mail_cfg();
    if (!$force && $cfg['lastSent'] === $today) return ['sent' => 0];
    if (empty($cfg['appUrl'])) { $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; $cfg['appUrl'] = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/'; }
    $since = $cfg['lastTs'] ?: (int)((microtime(true) - 86400) * 1000);
    $cfg['lastSent'] = $today; $cfg['lastTs'] = (int)(microtime(true) * 1000); writej($MF, $cfg);
    $rcpt = []; foreach ($users as $u => $x) if (!empty($x['email']) && !empty($x['digest'])) $rcpt[$u] = $x['email'];
    if (!$rcpt) { $cfg['lastResult'] = 'no recipients (add emails on the Users page)'; writej($MF, $cfg); return $force ? ['error' => 'Nobody has an email address with the daily report turned on (Users page).'] : ['sent' => 0]; }
    $d = array_merge($EMPTY, readj($DF, $EMPTY));
    $err = ''; $fp = smtp_open($cfg, $err);
    if (!$fp) { $cfg['lastResult'] = 'failed: ' . $err; if (!$force) $cfg['lastSent'] = ''; writej($MF, $cfg); return $force ? ['error' => $err] : ['sent' => 0]; }
    $sent = 0; $errors = [];
    foreach ($rcpt as $u => $em) { [$subj, $html] = digest_html($d, $cfg, $since, $u, $today); $e = '';
      if (smtp_send($fp, $cfg, $em, $subj, $html, $e)) $sent++; else $errors[] = "$em: $e"; }
    smtp_close($fp);
    $cfg['lastResult'] = "sent to $sent" . ($errors ? ', ' . count($errors) . ' failed' : ''); writej($MF, $cfg);
    return ['sent' => $sent, 'errors' => $errors];
  } finally { flock($lk, LOCK_UN); fclose($lk); }
}

$COLLS = ['strains', 'cages', 'mice', 'breedings', 'litters', 'meta', 'mutations', 'rooms', 'tasks', 'requests'];
$EMPTY = ['strains' => [], 'cages' => [], 'mice' => [], 'breedings' => [], 'litters' => [], 'meta' => [], 'mutations' => [], 'rooms' => [], 'tasks' => [], 'requests' => [], 'audit' => [], 'version' => 0];

switch ($action) {
  /* Temporary PDF links so cage cards open in a separate tab from the home-screen app.
     Upload needs a signed-in user; the link is a random 32-character id that expires after 30 minutes. */
  case 'pdfput':
    /* sent in pieces (each well under the host's upload limit); pieces are joined, then the file is kept 30 minutes */
    need('viewer');
    $id = (string)($_GET['id'] ?? ''); $part = (int)($_GET['part'] ?? -1); $last = ($_GET['last'] ?? '') === '1';
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || $part < 0 || $part > 400 || strlen($raw) < 1 || strlen($raw) > 4000000) out(['error' => 'Bad PDF piece'], 400);
    $pd = "$DIR/pdf"; if (!is_dir($pd)) @mkdir($pd, 0750, true);
    if ($part === 0) {
      foreach (glob("$pd/*") ?: [] as $f) if (filemtime($f) < time() - 1800) @unlink($f);
      if (substr($raw, 0, 4) !== '%PDF') out(['error' => 'Not a PDF'], 400);
      @unlink("$pd/$id.part");
    } elseif (!is_file("$pd/$id.part")) out(['error' => 'Upload expired — try again'], 400);
    if (is_file("$pd/$id.part") && filesize("$pd/$id.part") + strlen($raw) > 80000000) out(['error' => 'PDF too large'], 400);
    if (file_put_contents("$pd/$id.part", $raw, FILE_APPEND | LOCK_EX) === false) out(['error' => 'Could not save the PDF (server storage full?)'], 500);
    if ($last) { if (!@rename("$pd/$id.part", "$pd/$id.pdf")) out(['error' => 'Could not finish the PDF'], 500); }
    out(['ok' => true, 'id' => $id, 'part' => $part]);
  case 'pdf':
    $id = (string)($_GET['id'] ?? '');
    $f = "$DIR/pdf/$id.pdf";
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($f) || filemtime($f) < time() - 1800) { http_response_code(404); header('Content-Type: text/plain'); echo 'This PDF link has expired. Make the cards again in the app.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="cage-cards.pdf"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=1800');
    readfile($f); exit;
  case 'ping':
    out(['ok' => true, 'colony' => 1]);

  case 'me':
    out(meInfo());

  case 'login':
    $u = strtolower(trim((string)($in['username'] ?? '')));
    $p = (string)($in['password'] ?? '');
    if (!isset($users[$u]) || !password_verify($p, $users[$u]['hash'])) { sleep(1); out(['error' => 'Wrong username or password'], 401); }
    session_regenerate_id(true);
    $_SESSION['u'] = $u; $me = $u;
    out(meInfo());

  case 'logout':
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);

  case 'state':
    need('viewer');
    out(readj($DF, $EMPTY));

  case 'auditop':
    need('viewer');
    $id = (string)($_GET['id'] ?? '');
    $f = "$DIR/undo/$id.json";
    if (!preg_match('/^[a-f0-9]{16}$/', $id) || !is_file($f)) out(['error' => 'This change can no longer be undone (too old)'], 404);
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) out(['error' => 'Undo data is damaged'], 500);
    out($j);

  case 'version':
    need('viewer');
    $d = readj($DF, $EMPTY);
    out(['version' => $d['version'] ?? 0]);

  case 'ops':
    need('editor');
    $ops = $in['ops'] ?? [];
    $msg = mb_substr((string)($in['msg'] ?? ''), 0, 300);
    if (!is_array($ops) || count($ops) > 5000) out(['error' => 'Bad request'], 400);
    $inv = $in['inv'] ?? null; $ref = preg_match('/^[a-f0-9]{16}$/', (string)($in['ref'] ?? '')) ? $in['ref'] : ''; $kind = in_array($in['kind'] ?? '', ['undo', 'redo'], true) ? $in['kind'] : '';
    $DIRV = $DIR;
    $d = withLock(function () use ($ops, $msg, $DF, $EMPTY, $COLLS, $me, $inv, $ref, $kind, $DIRV) {
      $d = array_merge($EMPTY, readj($DF, $EMPTY));
      foreach ($ops as $op) {
        $c = $op['c'] ?? '';
        if (!in_array($c, $COLLS, true)) continue;
        $t = $op['t'] ?? '';
        if ($t === 'put' && is_array($op['r'] ?? null) && isset($op['r']['id']) && is_string($op['r']['id'])) {
          $r = $op['r']; $found = false;
          foreach ($d[$c] as $i => $x) { if (($x['id'] ?? null) === $r['id']) { $d[$c][$i] = $r; $found = true; break; } }
          if (!$found) $d[$c][] = $r;
        } elseif ($t === 'del') {
          $id = $op['id'] ?? '';
          $d[$c] = array_values(array_filter($d[$c], function ($x) use ($id) { return ($x['id'] ?? null) !== $id; }));
        }
      }
      if ($msg !== '') {
        $e = ['t' => (int)(microtime(true) * 1000), 'user' => $me, 'msg' => $msg, 'id' => bin2hex(random_bytes(8))];
        if ($ref !== '') $e['ref'] = $ref;
        if ($kind !== '') $e['k'] = $kind;
        /* keep what is needed to undo / redo this change (stored beside the data, not inside it) */
        if (is_array($inv) && count($inv) > 0 && count($inv) <= 5000) {
          $ud = "$DIRV/undo"; if (!is_dir($ud)) @mkdir($ud, 0750, true);
          $js = json_encode(['ops' => $ops, 'inv' => $inv], JSON_UNESCAPED_UNICODE);
          if ($js !== false && strlen($js) < 4000000 && @file_put_contents("$ud/{$e['id']}.json", $js) !== false) $e['u'] = 1;
          if (mt_rand(1, 25) === 1) { $fs = glob("$ud/*.json") ?: []; if (count($fs) > 500) { usort($fs, function ($a, $b) { return filemtime($a) - filemtime($b); }); foreach (array_slice($fs, 0, count($fs) - 500) as $f) @unlink($f); } }
        }
        array_unshift($d['audit'], $e);
        $d['audit'] = array_slice($d['audit'], 0, 2000);
      }
      $d['version'] = ($d['version'] ?? 0) + 1;
      writej($DF, $d);
      return $d;
    });
    out($d);

  case 'import':
    need('admin');
    if (!isset($in['mice']) || !is_array($in['mice'])) out(['error' => 'Not a colony backup file'], 400);
    $d = withLock(function () use ($in, $DF, $EMPTY, $COLLS, $me) {
      $old = readj($DF, $EMPTY);
      $d = $EMPTY;
      foreach ($COLLS as $c) $d[$c] = (isset($in[$c]) && is_array($in[$c])) ? array_values($in[$c]) : [];
      $d['audit'] = array_slice(array_merge([['t' => (int)(microtime(true) * 1000), 'user' => $me, 'msg' => 'Imported a full colony backup']], $old['audit'] ?? []), 0, 2000);
      $d['version'] = ($old['version'] ?? 0) + 1;
      writej($DF, $d);
      return $d;
    });
    out($d);

  case 'users':
    need('admin');
    out(userList());

  case 'useradd':
    need('admin');
    $u = strtolower(trim((string)($in['username'] ?? '')));
    $p = (string)($in['password'] ?? '');
    $r = (string)($in['role'] ?? 'viewer');
    if (!preg_match('/^[a-z0-9._-]{2,32}$/', $u)) out(['error' => 'Username: 2–32 letters, numbers, . _ -'], 400);
    if (!isset($RANK[$r])) out(['error' => 'Bad role'], 400);
    if (!isset($users[$u]) && strlen($p) < 6) out(['error' => 'Password must be at least 6 characters'], 400);
    if ($u === $me && $r !== 'admin') out(['error' => 'You cannot remove your own admin role'], 400);
    $em = trim((string)($in['email'] ?? ($users[$u]['email'] ?? '')));
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) out(['error' => 'That email address does not look right'], 400);
    $users[$u] = [
      'hash' => $p !== '' ? password_hash($p, PASSWORD_DEFAULT) : $users[$u]['hash'],
      'role' => $r,
      'mustChange' => $r === 'trainee' ? false : ($p !== '' ? ($u !== $me) : ($users[$u]['mustChange'] ?? false)),
      'email' => $em,
      'digest' => array_key_exists('digest', $in) ? !empty($in['digest']) : !empty($users[$u]['digest']),
    ];
    writej($UF, $users);
    out(userList());

  case 'userdel':
    need('admin');
    $u = (string)($in['username'] ?? '');
    if ($u === $me) out(['error' => 'You cannot delete yourself'], 400);
    unset($users[$u]);
    writej($UF, $users);
    out(userList());

  case 'passwd':
    need('viewer');
    $old = (string)($in['old'] ?? '');
    $new = (string)($in['new'] ?? '');
    if (!password_verify($old, $users[$me]['hash'])) out(['error' => 'Current password is wrong'], 400);
    if (strlen($new) < 8) out(['error' => 'New password must be at least 8 characters'], 400);
    $users[$me]['hash'] = password_hash($new, PASSWORD_DEFAULT);
    $users[$me]['mustChange'] = false;
    writej($UF, $users);
    out(['ok' => true]);


  case 'myprefs':
    need('viewer');
    $em = trim((string)($in['email'] ?? ''));
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) out(['error' => 'That email address does not look right'], 400);
    $users[$me]['email'] = $em; $users[$me]['digest'] = !empty($in['digest']);
    writej($UF, $users);
    out(['email' => $em, 'digest' => $users[$me]['digest']]);

  case 'mailcfg':
    need('admin');
    $cfg = mail_cfg();
    if ($method === 'POST') {
      foreach (['enabled', 'user', 'fromName', 'hour', 'host', 'port', 'appUrl'] as $k) if (array_key_exists($k, $in)) $cfg[$k] = $in[$k];
      $cfg['enabled'] = !empty($cfg['enabled']); $cfg['hour'] = max(0, min(23, (int)$cfg['hour'])); $cfg['port'] = (int)$cfg['port'] ?: 465;
      $cfg['user'] = trim((string)$cfg['user']); $cfg['host'] = trim((string)$cfg['host']) ?: 'smtp.gmail.com';
      if (!empty($in['pass'])) $cfg['pass'] = (string)$in['pass'];
      if (empty($cfg['key'])) $cfg['key'] = bin2hex(random_bytes(12));
      writej($MF, $cfg);
    }
    $o = $cfg; $o['hasPass'] = !empty($cfg['pass']); unset($o['pass']);
    out($o);

  case 'mailtest':
    need('admin');
    $cfg = mail_cfg(); $to = $users[$me]['email'] ?? '';
    if ($to === '') out(['error' => 'Add your own email first (menu → My email & daily report)'], 400);
    $err = '';
    $fp = smtp_open($cfg, $err); if (!$fp) out(['error' => $err], 400);
    $ok = smtp_send($fp, $cfg, $to, 'Koelony — test email', '<p style="font-family:Arial">This is a test from your colony app. Email is working.</p>', $err);
    smtp_close($fp);
    if (!$ok) out(['error' => $err], 400);
    out(['ok' => true, 'to' => $to]);

  case 'digest_now':
    need('admin');
    $r = digest_run(true);
    if (isset($r['error'])) out(['error' => $r['error']], 400);
    out($r);

  case 'digest_check':
    need('viewer');
    out(digest_run(false));

  case 'cron':
    $cfg = mail_cfg();
    if (empty($cfg['key']) || !hash_equals($cfg['key'], (string)($_GET['key'] ?? ''))) out(['error' => 'Bad key'], 403);
    out(digest_run(false));

  default:
    out(['error' => 'Unknown action'], 404);
}
