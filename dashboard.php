<?php
// dashboard.php
require_once "config.php";

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['user_id'])) {
  header("Location: login_form.php");
  exit;
}

$username = isset($_SESSION['username']) ? $_SESSION['username'] : '사용자';
$displayName = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

// 이니셜(아바타 대용)
$initial = mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>대시보드 | My Web App</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap');

    :root {
      --primary:#0984e3;
      --primary-soft:#74b9ff;
      --text:#2d3436;
      --muted:#636e72;
      --border:#dcdde1;
      --bg:#ffffff;
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
      width: 860px;
      max-width: 94%;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0,0,0,.15);
      padding: 28px 28px 24px;
    }

    .header {
      display: flex;
      align-items: center;
      gap: 16px;
      border-bottom: 1px solid var(--border);
      padding-bottom: 16px;
      margin-bottom: 20px;
    }

    .avatar {
      width: 54px; height: 54px;
      border-radius: 50%;
      background: linear-gradient(145deg, var(--primary), var(--primary-soft));
      color: #fff;
      display: grid; place-items: center;
      font-weight: 700; font-size: 22px;
      box-shadow: 0 6px 14px rgba(0,0,0,.12);
      user-select: none;
    }

    h1 {
      font-size: 22px;
      margin: 0;
    }
    .subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-top: 2px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 18px;
    }

    .card {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
    }

    .card h2 {
      font-size: 18px;
      margin: 0 0 12px;
      color: var(--text);
    }

    .meta {
      display: grid;
      grid-template-columns: 110px 1fr;
      row-gap: 10px;
      column-gap: 10px;
      font-size: 14px;
      color: var(--muted);
    }
    .meta strong { color: var(--text); font-weight: 600; }

    .actions {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-top: 6px;
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

    .logout-form { margin: 0; }

    .footer {
      text-align: center;
      color: #b2bec3;
      font-size: 12px;
      margin-top: 20px;
    }

    /* 반응형 */
    @media (max-width: 720px) {
      .grid { grid-template-columns: 1fr; }
      .actions { grid-template-columns: 1fr; }
    }

    /* 포커스 접근성 */
    a:focus, button:focus {
      outline: 3px solid rgba(9,132,227,.35);
      outline-offset: 2px;
    }
  </style>
</head>
<body>
  <main class="container" role="main" aria-labelledby="pageTitle">
    <!-- 헤더 -->
    <section class="header">
      <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
      <div>
        <h1 id="pageTitle">안녕하세요, <?php echo $displayName; ?> 님</h1>
        <p class="subtitle">대시보드에 오신 것을 환영합니다.</p>
      </div>
    </section>

    <!-- 본문 -->
    <section class="grid" aria-label="대시보드 내용">
      <!-- 좌측: 프로필 개요 -->
      <article class="card" aria-labelledby="profileHeading">
        <h2 id="profileHeading">내 정보</h2>
        <div class="meta">
          <span>사용자명</span><strong><?php echo $displayName; ?></strong>
          <span>상태</span><strong>로그인됨</strong>
          <span>세션</span><strong><?php echo htmlspecialchars(session_id(), ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>

        <div class="actions" style="margin-top:16px">
          <a class="btn btn-outline" href="index.html" aria-label="홈으로 이동">홈으로</a>

          <!-- (보안 권장) 로그아웃은 POST로 -->
          <form class="logout-form" action="logout.php" method="POST" aria-label="로그아웃">
            <button type="submit" class="btn">로그아웃</button>
          </form>

          <!-- 자리표시자: 추후 구현 예정 -->
          <a class="btn btn-outline" href="profile_edit.php" aria-label="프로필 수정">프로필 수정</a>
        </div>
      </article>

      <!-- 우측: 빠른 링크/알림 등 -->
      <aside class="card" aria-labelledby="quickHeading">
        <h2 id="quickHeading">빠른 작업</h2>
        <div class="actions">
          <a class="btn btn-outline" href="change_password.php">비밀번호 변경</a>
          <a class="btn btn-outline" href="register_form.php">새 사용자 등록</a>
          <a class="btn btn-outline" href="login_form.php">다른 계정 로그인</a>
        </div>
      </aside>
    </section>

    <p class="footer">&copy; 2025 NETID WEB SITE</p>
  </main>
</body>
</html>
