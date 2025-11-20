<?php
// change_password.php
require_once "config.php";

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
  header("Location: login_form.php");
  exit;
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// CSRF 토큰 준비
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$status = null;  // 'success' | 'error'
$title  = '비밀번호 변경';
$msg    = null;
$fieldErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  // CSRF
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $status = 'error';
    $msg = '요청이 올바르지 않습니다. 다시 시도해 주세요.';
  } else {
    $current = (string)($_POST['current_password'] ?? '');
    $new1    = (string)($_POST['new_password'] ?? '');
    $new2    = (string)($_POST['confirm_password'] ?? '');

    if ($current === '') $fieldErrors['current_password'] = '현재 비밀번호를 입력해 주세요.';
    if (mb_strlen($new1, 'UTF-8') < 8) $fieldErrors['new_password'] = '새 비밀번호는 8자 이상이어야 합니다.';
    if ($new1 !== $new2) $fieldErrors['confirm_password'] = '새 비밀번호가 일치하지 않습니다.';
    if (!$fieldErrors) {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      try {
        $uid = (int)$_SESSION['user_id'];

        // 1) 현재 해시 조회
        $sql = "SELECT password_hash FROM members WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($pw_hash);
        if (!$stmt->fetch()) {
          $stmt->close();
          throw new mysqli_sql_exception("User not found");
        }
        $stmt->close();

        // 2) 현재 비밀번호 검증
        if (!password_verify($current, $pw_hash)) {
          $status = 'error';
          $msg = '현재 비밀번호가 올바르지 않습니다.';
        } else {
          // 3) 동일 비밀번호 재사용 방지(선택)
          if (password_verify($new1, $pw_hash)) {
            $status = 'error';
            $msg = '이전과 동일한 비밀번호는 사용할 수 없습니다.';
          } else {
            // 4) 업데이트
            $new_hash = password_hash($new1, PASSWORD_DEFAULT);
            $sql = "UPDATE members SET password_hash = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $new_hash, $uid);
            $stmt->execute();
            $stmt->close();

            // (권장) 세션 재발급
            if (session_status() === PHP_SESSION_ACTIVE) {
              session_regenerate_id(true);
            }

            $status = 'success';
            $msg = '비밀번호가 안전하게 변경되었습니다.';
          }
        }
      } catch (mysqli_sql_exception $e) {
        error_log("change_password.php error: ".$e->getMessage());
        $status = 'error';
        $msg = '서버 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
      } finally {
        if (isset($conn) && $conn instanceof mysqli) $conn->close();
      }
    } else {
      $status = 'error';
      $msg = '입력값을 다시 확인해 주세요.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>비밀번호 변경 | My Web App</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap');
    :root{
      --primary:#0984e3; --primary-soft:#74b9ff; --text:#2d3436; --muted:#636e72;
      --border:#dcdde1; --bg:#fff; --ok:#2ecc71; --err:#e74c3c;
      --ok-bg:rgba(46,204,113,.12); --err-bg:rgba(231,76,60,.12);
    }
    *{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:linear-gradient(135deg,#74b9ff,#0984e3);min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;color:var(--text)}
    .container{background:var(--bg);width:480px;max-width:94%;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:28px}
    h1{font-size:24px;margin:0 0 16px}
    form{display:flex;flex-direction:column}
    label{margin:8px 0 6px;color:var(--muted);font-weight:500}
    input{padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;margin-bottom:10px}
    input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 5px rgba(9,132,227,.3)}
    .btn{margin-top:8px;padding:12px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:600;cursor:pointer;transition:.25s}
    .btn:hover{background:var(--primary-soft);color:var(--text)}
    .link{margin-top:14px;font-size:14px}
    .link a{color:var(--primary);text-decoration:none;font-weight:600}
    .link a:hover{text-decoration:underline}
    .alert{border:1px solid var(--border);border-radius:14px;padding:14px;display:grid;grid-template-columns:36px 1fr;gap:10px;align-items:start;margin-bottom:12px}
    .alert .icon{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700}
    .success{background:var(--ok-bg);border-color:rgba(46,204,113,.35)}
    .success .icon{background:var(--ok)}
    .error{background:var(--err-bg);border-color:rgba(231,76,60,.35)}
    .error .icon{background:var(--err)}
    .errors{color:var(--err);font-size:14px;margin-top:6px}
    a:focus,button:focus{outline:3px solid rgba(9,132,227,.35);outline-offset:2px}
  </style>
</head>
<body>
  <main class="container" role="main">
    <h1>비밀번호 변경</h1>

    <?php if ($status): ?>
      <div class="alert <?php echo $status === 'success' ? 'success' : 'error'; ?>">
        <div class="icon"><?php echo $status === 'success' ? '✓' : '!'; ?></div>
        <div>
          <strong><?php echo h($msg); ?></strong>
          <?php if (!empty($fieldErrors)): ?>
            <div class="errors">
              <?php foreach($fieldErrors as $e) echo "<div>".h($e)."</div>"; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <form action="" method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
      <label for="current_password">현재 비밀번호</label>
      <input type="password" id="current_password" name="current_password" required>

      <label for="new_password">새 비밀번호 (8자 이상)</label>
      <input type="password" id="new_password" name="new_password" minlength="8" required>

      <label for="confirm_password">새 비밀번호 확인</label>
      <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>

      <button class="btn" type="submit">비밀번호 변경</button>
    </form>

    <div class="link">
      <a href="dashboard.php">대시보드</a> · <a href="index.html">홈으로</a>
    </div>

    <p style="margin-top:14px;color:#b2bec3;font-size:12px">&copy; 2025 My Web App</p>
  </main>
</body>
</html>
