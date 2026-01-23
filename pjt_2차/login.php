<?php
session_start();

/* ✅ 캐시 방지 (뒤로가기/캐시로 화면 복원 최소화) */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$error = "";

/**
 * ✅ 핵심 기능 (유지):
 * - 로그인 상태(user_id 존재)로 login.php에 들어오면(뒤로가기 포함)
 *   즉시 세션 파기 = 자동 로그아웃
 * - 단, POST(로그인 시도) 중에는 이 로직이 실행되면 안 됨
 *
 * ❌ 변경점:
 * - 자동 로그아웃 안내 문구 관련 로직 완전 제거
 */
if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_SESSION["user_id"])) {
  $_SESSION = [];

  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
    );
  }

  session_destroy();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = $_POST["password"] ?? "";

  if ($username === "" || $password === "") {
    $error = "아이디와 비밀번호를 입력해 주세요.";
  } else {
    try {
      require_once "db.php"; // ✅ 로그인 시도할 때만 DB 연결

      $stmt = $pdo->prepare("SELECT id, name, role, password_hash FROM users WHERE username = ? LIMIT 1");
      $stmt->execute([$username]);
      $user = $stmt->fetch();

      if ($user && password_verify($password, $user["password_hash"])) {
        /* ✅ 로그인 성공 시 세션 재발급 */
        session_regenerate_id(true);

        $_SESSION["user_id"]   = (int)$user["id"];
        $_SESSION["user_name"] = $user["name"] ?? "";
        $_SESSION["user_role"] = $user["role"] ?? "worker";

        header("Location: dashboard.php");
        exit;
      } else {
        $error = "아이디 또는 비밀번호가 올바르지 않습니다.";
      }
    } catch (Throwable $e) {
      $error = "DB 연결에 실패했습니다. (MySQL 실행/DB 설정 확인 필요)";
    }
  }
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>로그인 | 작업 관리 시스템</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    :root{
      --bg1:#eef2ff;
      --bg2:#e9edf8;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#6b7280;
      --line:#e5e7eb;
      --primary:#2f66d1;
      --primary2:#295dc0;
      --shadow: 0 18px 50px rgba(15, 23, 42, 0.14);
      --radius: 14px;
    }

    *{ box-sizing:border-box; }
    html, body{ height:100%; }

    body{
      margin:0;
      font-family: "Noto Sans KR", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(1200px 700px at 30% 10%, #f3f6ff, transparent),
                  linear-gradient(180deg, var(--bg1), var(--bg2));
      color:var(--text);
    }

    .viewport{
      min-height: 100svh;
      display: grid;
      place-items: center;
      padding: 28px 16px;
    }

    .wrap{
      width: 520px;
      max-width: 100%;
    }

    .card{
      background: rgba(255,255,255,0.86);
      border: 1px solid rgba(203,213,225,0.65);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow:hidden;
      backdrop-filter: blur(10px);
    }

    .card__top{
      padding: 22px 26px;
      text-align:center;
      border-bottom: 1px solid rgba(203,213,225,0.6);
      background: rgba(255,255,255,0.55);
    }
    .card__top .title{
      margin:0;
      font-size: 34px;
      font-weight: 900;
      color:#1d4ed8;
      letter-spacing:-0.6px;
    }

    .card__body{
      padding: 26px 34px 28px;
      background: rgba(248,250,252,0.7);
    }

    .h-login{
      margin: 0 0 18px;
      font-size: 34px;
      font-weight: 900;
      text-align:center;
      color:#111827;
      letter-spacing:-0.6px;
    }

    .field{ margin: 14px 0 18px; }
    .label{
      display:block;
      font-size: 18px;
      font-weight: 800;
      margin: 0 0 10px;
      color:#374151;
    }

    .input{
      display:flex;
      align-items:center;
      border: 1px solid var(--line);
      border-radius: 6px;
      overflow:hidden;
      background:#fff;
      box-shadow: 0 6px 16px rgba(15,23,42,0.06);
    }
    .input__icon{
      width: 58px;
      height: 48px;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#94a3b8;
      border-right: 1px solid var(--line);
      background: #f8fafc;
      font-size: 20px;
      flex: 0 0 auto;
    }
    .input input{
      border:0;
      outline:0;
      width:100%;
      height:48px;
      padding: 0 14px;
      font-size: 16px;
      color:#111827;
      background:#fff;
    }

    .error{
      margin: 0 0 14px;
      padding: 12px 14px;
      background:#fee2e2;
      border:1px solid #fecaca;
      color:#991b1b;
      font-weight: 900;
      border-radius: 6px;
      text-align:center;
    }

    .btn{
      width:100%;
      height: 54px;
      border:0;
      border-radius: 6px;
      background: var(--primary);
      color:#fff;
      font-size: 22px;
      font-weight: 900;
      cursor:pointer;
      box-shadow: 0 14px 28px rgba(47,102,209,0.24);
    }
    .btn:hover{ background: var(--primary2); }

    .bottom{
      margin-top: 18px;
      text-align:center;
      font-weight: 700;
    }
    .bottom a{
      color:#2563eb;
      font-weight: 900;
      text-decoration:none;
    }
  </style>
</head>

<body>
  <div class="viewport">
    <div class="wrap">
      <div class="card">
        <div class="card__top">
          <h1 class="title">작업 관리 시스템</h1>
        </div>

        <div class="card__body">
          <h2 class="h-login">로그인</h2>

          <form method="post" autocomplete="off">
            <?php if ($error): ?>
              <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <div class="field">
              <label class="label">아이디</label>
              <div class="input">
                <div class="input__icon"><i class="bi bi-person-badge-fill"></i></div>
                <input type="text" name="username" placeholder="아이디를 입력하세요"
                  value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, "UTF-8") ?>" />
              </div>
            </div>

            <div class="field">
              <label class="label">비밀번호</label>
              <div class="input">
                <div class="input__icon"><i class="bi bi-lock-fill"></i></div>
                <input type="password" name="password" placeholder="••••••••••" />
              </div>
            </div>

            <button class="btn" type="submit">로그인</button>

            <div class="bottom">
              계정이 없으신가요? <a href="signup.php">회원가입 ›</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ✅ bfcache(뒤로가기 화면 복원) 방지 -->
  <script>
    window.addEventListener('pageshow', function(e){
      if (e.persisted) location.reload();
    });
  </script>
</body>
</html>
