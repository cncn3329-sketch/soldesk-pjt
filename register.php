<?php
// register.php
require_once "config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

$status = 'error';   // 'success' | 'error'
$title  = '회원가입 실패';
$msg    = '요청을 처리할 수 없습니다.';
$fieldErrors = [];

// 유효성 검사 함수
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

if ($method === 'POST') {
    // 서버측 유효성 검사
    if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
        $fieldErrors['username'] = '아이디는 3~30자의 영문/숫자/언더스코어만 가능합니다.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = '유효한 이메일 주소를 입력해 주세요.';
    }
    if ($password === '' || mb_strlen($password, 'UTF-8') < 8) {
        $fieldErrors['password'] = '비밀번호는 8자 이상이어야 합니다.';
    }

    if (!$fieldErrors) {
        // DB 처리
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            if (method_exists($conn, 'begin_transaction')) {
                $conn->begin_transaction();
            }

            // 중복 확인
            $sql = "SELECT id FROM members WHERE username = ? OR email = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $stmt->close();
                if (method_exists($conn, 'rollback')) $conn->rollback();
                $status = 'error';
                $title  = '회원가입 실패';
                $msg    = '이미 존재하는 아이디 또는 이메일입니다.';
            } else {
                $stmt->close();

                // 비밀번호 해시 & 삽입
                $pw_hash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO members (username, email, password_hash) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $username, $email, $pw_hash);
                $stmt->execute();
                $stmt->close();

                if (method_exists($conn, 'commit')) $conn->commit();

                $status = 'success';
                $title  = '회원가입 성공';
                $msg    = '회원가입이 완료되었습니다. 이제 로그인해 주세요.';
            }
        } catch (mysqli_sql_exception $e) {
            if (method_exists($conn, 'rollback')) $conn->rollback();

            // 중복(고유키 제약) 에러 코드: 1062
            if ((int)$e->getCode() === 1062) {
                $status = 'error';
                $title  = '회원가입 실패';
                $msg    = '이미 존재하는 아이디 또는 이메일입니다.';
            } else {
                // 내부 에러는 로깅
                error_log("register.php DB error: " . $e->getMessage());
                $status = 'error';
                $title  = '회원가입 실패';
                $msg    = '서버 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
            }
        } finally {
            if (isset($conn) && $conn instanceof mysqli) {
                $conn->close();
            }
        }
    } else {
        $status = 'error';
        $title  = '입력값을 확인해 주세요';
        $msg    = '일부 항목에 오류가 있습니다.';
    }
} else {
    $status = 'error';
    $title  = '잘못된 요청';
    $msg    = '올바른 경로로 접근해 주세요.';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>회원가입 결과 | My Web App</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap');

    :root {
      --primary:#0984e3;
      --primary-soft:#74b9ff;
      --text:#2d3436;
      --muted:#636e72;
      --border:#dcdde1;
      --bg:#ffffff;
      --ok:#2ecc71;
      --err:#e74c3c;
      --ok-bg:rgba(46, 204, 113, .12);
      --err-bg:rgba(231, 76, 60, .12);
    }

    * { box-sizing: border-box; }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #74b9ff, #0984e3);
      min-height: 100vh;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text);
    }

    .container {
      background: var(--bg);
      width: 560px;
      max-width: 94%;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0,0,0,.15);
      padding: 28px;
      text-align: center;
    }

    h1 {
      font-size: 24px;
      margin: 0 0 10px;
    }
    p.lead { color: var(--muted); margin: 0 0 16px; }

    .alert {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px;
      text-align: left;
      margin: 14px 0 18px;
      display: grid;
      grid-template-columns: 36px 1fr;
      gap: 12px;
      align-items: start;
    }
    .alert .icon {
      width: 36px; height: 36px; border-radius: 50%;
      display: grid; place-items: center;
      font-weight: 700; color: #fff;
    }
    .alert.success { background: var(--ok-bg); border-color: rgba(46,204,113,.35); }
    .alert.success .icon { background: var(--ok); }
    .alert.error   { background: var(--err-bg); border-color: rgba(231,76,60,.35); }
    .alert.error .icon { background: var(--err); }

    .errors {
      margin-top: 8px;
      color: var(--err);
      font-size: 14px;
    }
    .errors ul { margin: 6px 0 0 18px; }

    .actions {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-top: 8px;
    }

    .btn {
      display: inline-block;
      text-align: center;
      padding: 12px 14px;
      border-radius: 10px;
      background: var(--primary);
      color: #fff;
      text-decoration: none;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: .25s;
      width: 100%;
    }
    .btn:hover {
      background: var(--primary-soft);
      color: var(--text);
    }
    .btn-outline {
      background: #fff;
      color: var(--text);
      border: 1px solid var(--border);
    }
    .btn-outline:hover {
      border-color: var(--primary);
      color: var(--text);
    }

    @media (max-width: 640px) {
      .actions { grid-template-columns: 1fr; }
    }

    a:focus, button:focus {
      outline: 3px solid rgba(9,132,227,.35);
      outline-offset: 2px;
    }
  </style>
</head>
<body>
  <main class="container" role="main" aria-labelledby="pageTitle">
    <h1 id="pageTitle"><?php echo h($title); ?></h1>
    <p class="lead">회원가입 처리 결과</p>

    <div class="alert <?php echo $status === 'success' ? 'success' : 'error'; ?>">
      <div class="icon"><?php echo $status === 'success' ? '✓' : '!'; ?></div>
      <div>
        <strong><?php echo h($msg); ?></strong>

        <?php if (!empty($fieldErrors)): ?>
          <div class="errors">
            <ul>
              <?php foreach ($fieldErrors as $k => $v): ?>
                <li><?php echo h($v); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($status === 'success'): ?>
          <p style="margin-top:8px;color:var(--muted);">
            아이디: <strong><?php echo h($username); ?></strong> / 이메일: <strong><?php echo h($email); ?></strong>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="actions" aria-label="다음 작업">
      <a class="btn" href="login_form.php">로그인</a>
      <a class="btn btn-outline" href="register_form.php">회원가입 다시</a>
      <a class="btn btn-outline" href="index.html">홈으로</a>
    </div>

    <p style="margin-top:18px;color:#b2bec3;font-size:12px">&copy; 2025 My Web App</p>
  </main>
</body>
</html>
