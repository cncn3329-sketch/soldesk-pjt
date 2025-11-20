<?php
// profile_edit.php
require_once "config.php";

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
  header("Location: login_form.php");
  exit;
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$uid = (int)$_SESSION['user_id'];

// CSRF 토큰
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$status = null; // 'success' | 'error'
$msg    = null;
$fieldErrors = [];
$username = '';
$email    = '';

// 현재 값 로드
try {
  $sql = "SELECT username, email FROM members WHERE id = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $stmt->bind_result($username, $email);
  if (!$stmt->fetch()) {
    $stmt->close();
    throw new mysqli_sql_exception("User not found");
  }
  $stmt->close();
} catch (mysqli_sql_exception $e) {
  error_log("profile_edit.php load error: ".$e->getMessage());
  // 복구 불가 → 로그인 페이지로 안내
  header("Location: logout.php");
  exit;
}

// 처리
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  // CSRF
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $status = 'error';
    $msg = '요청이 올바르지 않습니다. 다시 시도해 주세요.';
  } else {
    $new_username = trim($_POST['username'] ?? $username);
    $new_email    = trim($_POST['email'] ?? $email);

    // 유효성 검사
    if ($new_username === '' || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $new_username)) {
      $fieldErrors['username'] = '아이디는 3~30자의 영문/숫자/언더스코어만 가능합니다.';
    }
    if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $fieldErrors['email'] = '유효한 이메일 주소를 입력해 주세요.';
    }

    if (!$fieldErrors) {
      try {
        // 중복 체크 (다른 사용자와 충돌 금지)
        $sql = "SELECT id FROM members WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $new_username, $new_email, $uid);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
          $stmt->close();
          $status = 'error';
          $msg = '이미 사용 중인 아이디 또는 이메일입니다.';
        } else {
          $stmt->close();

          // 업데이트
          $sql = "UPDATE members SET username = ?, email = ? WHERE id = ?";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("ssi", $new_username, $new_email, $uid);
          $stmt->execute();
          $stmt->close();

          $status = 'success';
          $msg = '정보가 성공적으로 수정되었습니다.';

          // 폼에 최신값 반영 + 세션 표시명 갱신
          $username = $new_username;
          $email    = $new_email;
          $_SESSION['username'] = $username;
        }
      } catch (mysqli_sql_exception $e) {
        // 유니크 충돌 등
        if ((int)$e->getCode() === 1062) {
          $status = 'error';
          $msg = '이미 사용 중인 아이디 또는 이메일입니다.';
        } else {
          error_log("profile_edit.php save error: ".$e->getMessage());
          $status = 'error';
          $msg = '서버 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
        }
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
  <title>정보수정 | My Web App</title>
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
    .container{background:var(--bg);width:520px;max-width:94%;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.15);padding:28px}
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
    <h1>정보수정</h1>

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
      <label for="username">아이디 (username)</label>
      <input type="text" id="username" name="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" value="<?php echo h($username); ?>">

      <label for="email">이메일 (email)</label>
      <input type="email" id="email" name="email" required value="<?php echo h($email); ?>">

      <button class="btn" type="submit">정보 수정</button>
    </form>

    <div class="link">
      <a href="dashboard.php">대시보드</a> · <a href="change_password.php">비밀번호 변경</a> · <a href="index.html">홈으로</a>
    </div>

    <p style="margin-top:14px;color:#b2bec3;font-size:12px">&copy; 2025 My Web App</p>
  </main>
</body>
</html>
