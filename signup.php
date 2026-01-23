<?php
session_start();

/**
 * ✅ 필드(메뉴)별 에러
 */
$errors = [
  "name" => [],
  "username" => [],
  "password" => [],
  "password_confirm" => [],
  "admin_code" => []
];

$signupDone = false;

/**
 * ✅ 관리자 가입 코드: soldesk (소문자만 정확히 허용)
 */
define("ADMIN_SIGNUP_CODE", "soldesk");

/**
 * ✅ 아이디(username) 정책 (요구사항 반영)
 * - 8~16자리
 * - 영문 반드시 포함(한글 불가)
 * - 숫자/특수문자 가능(필수 아님)
 * - 사용 가능 특수문자: . _ - @ $ !
 * - 허용 문자: 영문/숫자/._-@$!
 */
function validate_username(string $u, array &$fieldErrors): void {
  if ($u === "") { $fieldErrors[] = "아이디를 입력해 주세요."; return; }

  $len = strlen($u);
  if ($len < 8 || $len > 16) {
    $fieldErrors[] = "아이디는 8자 이상 16자 이내로 입력해 주세요.";
    return;
  }

  // ✅ 허용 문자 제한 (한글/공백 등 전부 불가)
  if (!preg_match('/^[A-Za-z0-9._\-@$!]+$/', $u)) {
    $fieldErrors[] = "아이디는 영문/숫자 및 특수문자(._-@$!)만 사용할 수 있습니다.";
    return;
  }
}

/**
 * ✅ 비밀번호 정책 (요구사항 반영)
 * - 8~16자리
 * - 영문 반드시 포함(한글 불가)
 * - 숫자 1개 이상 포함
 * - 특수문자 1개 이상 포함 (._-@$!)
 * - 허용 문자: 영문/숫자/._-@$!
 */
function validate_password(string $pw, array &$fieldErrors): void {
  if ($pw === "") { $fieldErrors[] = "비밀번호를 입력해 주세요."; return; }

  $len = strlen($pw);
  if ($len < 8 || $len > 16) {
    $fieldErrors[] = "비밀번호는 8자 이상 16자 이내로 설정해 주세요.";
    return;
  }

  // ✅ 허용 문자 제한 (한글/공백 등 전부 불가)
  if (!preg_match('/^[A-Za-z0-9._\-@$!]+$/', $pw)) {
    $fieldErrors[] = "비밀번호는 영문/숫자 및 특수문자(._-@$!)만 사용할 수 있습니다.";
    return;
  }

  // ✅ 구성 조건
  if (!preg_match('/[0-9]/', $pw))     $fieldErrors[] = "비밀번호에 숫자가 1개 이상 포함되어야 합니다.";
  if (!preg_match('/[._\-@$!]/', $pw)) $fieldErrors[] = "비밀번호에 특수문자(._-@$!)가 1개 이상 포함되어야 합니다.";
}

/** ✅ 에러 존재 여부 */
function has_any_errors(array $errors): bool {
  foreach ($errors as $arr) {
    if (!empty($arr)) return true;
  }
  return false;
}

/**
 * ✅ 가입 완료 모달에서 "확인" 눌렀을 때
 */
if (isset($_GET["done"]) && $_GET["done"] === "1") {
  unset($_SESSION["signup_done"]);
  header("Location: login.php");
  exit;
}

/**
 * ✅ POST 처리
 */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_SESSION["signup_done"])) {
  $name  = trim($_POST["name"] ?? "");
  $username = trim($_POST["username"] ?? "");
  $pw    = $_POST["password"] ?? "";
  $pw2   = $_POST["password_confirm"] ?? "";
  $admin_code = trim($_POST["admin_code"] ?? "");

  if ($name === "") $errors["name"][] = "이름을 입력해 주세요.";

  validate_username($username, $errors["username"]);
  validate_password($pw, $errors["password"]);

  if ($pw2 === "") {
    $errors["password_confirm"][] = "비밀번호 확인을 입력해 주세요.";
  } elseif ($pw !== $pw2) {
    $errors["password_confirm"][] = "비밀번호와 비밀번호 확인이 일치하지 않습니다.";
  }

  if ($admin_code !== "" && $admin_code !== ADMIN_SIGNUP_CODE) {
    $errors["admin_code"][] = "관리자 가입 코드가 올바르지 않습니다.";
  }

  if (!has_any_errors($errors)) {
    try {
      require_once "db.php";

      $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
      $stmt->execute([$username]);
      if ($stmt->fetch()) {
        $errors["username"][] = "이미 사용 중인 아이디입니다.";
      } else {
        $role = ($admin_code === ADMIN_SIGNUP_CODE) ? "admin" : "worker";
        $hash = password_hash($pw, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role) VALUES (?,?,?,?)");
        $stmt->execute([$name, $username, $hash, $role]);

        $_SESSION["signup_done"] = true;
      }
    } catch (Throwable $e) {
      $errors["username"][] = "DB 처리 중 오류가 발생했습니다. (MySQL 실행/DB 설정 확인 필요)";
    }
  }
}

if (isset($_SESSION["signup_done"])) {
  $signupDone = true;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>회원가입 | 작업 관리 시스템</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
  :root{
    --bg1:#eef2ff;
    --bg2:#e9edf8;
    --card:#ffffff;
    --text:#0f172a;
    --muted:#6b7280;
    --line:#e5e7eb;
    --line2: rgba(203,213,225,0.65);
    --primary:#2f66d1;
    --primary2:#295dc0;
    --shadow: 0 18px 50px rgba(15, 23, 42, 0.14);
    --radius: 14px;
  }

  *{ box-sizing:border-box; }
  html, body{ height:100%; }
  body{
    margin:0;
    font-family:"Noto Sans KR", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    background: radial-gradient(1200px 700px at 30% 10%, #f3f6ff, transparent),
                linear-gradient(180deg, var(--bg1), var(--bg2));
    color:var(--text);
  }

  .shell{ max-width: 980px; margin: 24px auto; padding: 0 16px; }

  .frame{
    background: rgba(255,255,255,0.78);
    border: 1px solid var(--line2);
    border-radius: 12px;
    box-shadow: 0 26px 60px rgba(15,23,42,0.14);
    overflow:hidden;
    backdrop-filter: blur(10px);
  }

  .topbar{
    height: 56px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding: 0 18px;
    background: linear-gradient(180deg, rgba(255,255,255,0.75), rgba(255,255,255,0.45));
    border-bottom: 1px solid rgba(203,213,225,0.55);
  }
  .brand{
    font-weight: 900;
    color:#1e3a8a;
    letter-spacing:-0.3px;
    font-size: 18px;
  }
  .btn-top{
    background: var(--primary);
    color:#fff;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 800;
    text-decoration:none;
    box-shadow: 0 10px 22px rgba(47,102,209,0.22);
  }
  .btn-top:hover{ background: var(--primary2); }

  .hero{
    position:relative;
    height: 210px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    background:
      radial-gradient(700px 260px at 50% 40%, rgba(255,255,255,0.9), rgba(255,255,255,0.2)),
      linear-gradient(180deg, rgba(231,238,255,0.8), rgba(238,242,255,0.0));
    border-bottom: 1px solid rgba(203,213,225,0.45);
    overflow:hidden;
  }
  .hero::before{
    content:"";
    position:absolute;
    inset:-20px;
    background:
      url("https://images.unsplash.com/photo-1480714378408-67cf0d13bc1b?q=80&w=1600&auto=format&fit=crop")
      center/cover no-repeat;
    filter: blur(1.5px) grayscale(30%);
    opacity: 0.22;
    transform: scale(1.08);
  }
  .hero::after{
    content:"";
    position:absolute;
    inset:0;
    background: linear-gradient(180deg, rgba(238,242,255,0.9), rgba(238,242,255,0.55), rgba(238,242,255,0.1));
  }

  .hero-inner{
    position:relative;
    z-index:2;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap: 10px;
    padding: 12px;
  }

  .hero-illust{
    width: 120px;
    height: 120px;
    display:flex;
    align-items:center;
    justify-content:center;
  }
  .illust-fallback{
    width:120px;
    height:120px;
    border-radius: 999px;
    background: rgba(59,130,246,0.12);
    border: 1px solid rgba(59,130,246,0.22);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#2563eb;
    font-size: 54px;
  }

  .content{ padding: 26px 18px 34px; }

  .title{
    margin: 0;
    text-align:center;
    font-size: 40px;
    font-weight: 900;
    letter-spacing:-0.8px;
    color:#111827;
  }
  .desc{
    margin: 10px auto 18px;
    text-align:center;
    color: var(--muted);
    font-weight: 700;
    max-width: 520px;
  }

  .form-card{
    max-width: 560px;
    margin: 0 auto;
    background: rgba(255,255,255,0.85);
    border: 1px solid rgba(203,213,225,0.6);
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15,23,42,0.10);
    padding: 22px 22px 18px;
    overflow: visible;
  }

  .field{ margin: 14px 0; }

  .input{
    display:flex;
    align-items:center;
    border: 1px solid var(--line);
    border-radius: 8px;
    overflow:hidden;
    background:#fff;
  }
  .input__icon{
    width: 54px;
    height: 48px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#94a3b8;
    background: #f8fafc;
    border-right: 1px solid var(--line);
    font-size: 18px;
  }
  .input input{
    border:0;
    outline:0;
    width:100%;
    height:48px;
    padding: 0 14px;
    font-size: 15px;
    background:#fff;
  }
  .input input::placeholder{ color:#9ca3af; }

  .row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 12px;
  }

  .showpw{
    display:flex;
    align-items:center;
    gap: 8px;
    color:#6b7280;
    font-weight: 800;
    font-size: 13px;
    user-select:none;
    white-space: nowrap;
  }
  .showpw input{ width:16px; height:16px; }

  .btn{
    width:100%;
    height: 56px;
    border:0;
    border-radius: 8px;
    background: var(--primary);
    color:#fff;
    font-size: 22px;
    font-weight: 900;
    cursor:pointer;
    margin-top: 10px;
    box-shadow: 0 16px 28px rgba(47,102,209,0.22);
  }
  .btn:hover{ background: var(--primary2); }

  .btn:disabled{
    opacity: .55;
    cursor: not-allowed;
    box-shadow: none;
  }

  .divider{
    margin: 18px 0 10px;
    border-top: 1px solid rgba(203,213,225,0.65);
  }

  .bottom{
    text-align:center;
    padding: 10px 0 2px;
    color:#6b7280;
    font-weight: 800;
  }
  .bottom a{
    color:#2563eb;
    text-decoration:none;
    font-weight: 900;
  }
  .bottom a:hover{ text-decoration:underline; }

  .error{
    margin: 8px 0 0;
    padding: 10px 12px;
    background:#fee2e2;
    border:1px solid #fecaca;
    color:#991b1b;
    font-weight: 900;
    border-radius: 10px;
    text-align:left;
    line-height: 1.5;
  }

  /* ===== ✅ 노란 말풍선(정책 툴팁) ===== */
  .policy-wrap{ position: relative; }
  .policy-btn{
    border: none;
    background: transparent;
    cursor: pointer;
    margin-left: 6px;
    display:flex;
    align-items:center;
    justify-content:center;
    width: 44px;
    height: 48px;
  }
  .policy-icon{
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #facc15;
    color: #7c2d12;
    font-weight: 900;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size: 14px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
  }
  .policy-bubble{
    position: absolute;
    top: calc(100% + 8px);
    right: 6px;
    width: 320px;
    max-width: calc(100vw - 80px);
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 12px 14px;
    color: #78350f;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.5;
    box-shadow: 0 16px 40px rgba(0,0,0,0.18);
    display: none;
    z-index: 9999;
  }
  .policy-bubble::before{
    content:"";
    position:absolute;
    top: -8px;
    right: 18px;
    border-width: 0 8px 8px 8px;
    border-style: solid;
    border-color: transparent transparent #fde68a transparent;
  }
  .policy-bubble.show{ display:block; }

  /* ===== ✅ 가입 완료 모달 ===== */
  .modal-backdrop{
    position:fixed;
    inset:0;
    background: rgba(0,0,0,.45);
    z-index: 2000;
    display:none;
  }
  .modal-backdrop.show{ display:block; }

  .signup-modal{
    position:fixed;
    inset:0;
    z-index: 2100;
    display:none;
    align-items:center;
    justify-content:center;
    padding: 18px;
  }
  .signup-modal.show{ display:flex; }

  .signup-card{
    width: 360px;
    max-width: 92vw;
    background:#fff;
    border-radius: 14px;
    border: 1px solid rgba(203,213,225,.9);
    box-shadow: 0 30px 70px rgba(0,0,0,.35);
    overflow:hidden;
  }
  .signup-card .head{
    padding: 18px 18px 12px;
    background: rgba(248,250,252,.9);
    border-bottom: 1px solid rgba(203,213,225,.65);
    text-align:center;
    font-weight: 950;
    font-size: 18px;
    color:#0f172a;
  }
  .signup-card .body{
    padding: 18px;
    text-align:center;
    color:#475569;
    font-weight: 800;
    line-height: 1.55;
  }
  .signup-card .foot{
    padding: 14px 18px 18px;
    display:flex;
    justify-content:center;
  }
  .signup-card .okbtn{
    width: 100%;
    height: 48px;
    border:0;
    border-radius: 12px;
    background: var(--primary);
    color:#fff;
    font-size: 18px;
    font-weight: 950;
    cursor:pointer;
    box-shadow: 0 14px 28px rgba(47,102,209,0.24);
  }
  .signup-card .okbtn:hover{ background: var(--primary2); }

  @media (max-width: 600px){
    .title{ font-size: 30px; }
    .form-card{ padding: 18px 14px 14px; }
    .row{ flex-direction:column; align-items:flex-start; }
  }
</style>
</head>

<body>
  <div class="shell">
    <div class="frame">

      <div class="topbar">
        <div class="brand">작업 관리 시스템</div>
        <a class="btn-top" href="login.php">로그인</a>
      </div>

      <div class="hero">
        <div class="hero-inner">
          <div class="hero-illust">
            <div class="illust-fallback"><i class="bi bi-clipboard2-plus"></i></div>
          </div>
        </div>
      </div>

      <div class="content">
        <h1 class="title">회원가입</h1>
        <p class="desc">간편하게 회원가입하고 작업 관리 시스템을 이용해보세요.</p>

        <div class="form-card">
          <form id="signupForm" method="post" autocomplete="off">

            <!-- ✅ 이름 -->
            <div class="field">
              <div class="input">
                <div class="input__icon"><i class="bi bi-person-fill"></i></div>
                <input id="name" type="text" name="name" placeholder="이름"
                       value="<?= htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES, "UTF-8") ?>">
              </div>
              <?php if (!empty($errors["name"])): ?>
                <div class="error"><?= implode("<br>", array_map(fn($m)=>htmlspecialchars($m, ENT_QUOTES, "UTF-8"), $errors["name"])) ?></div>
              <?php endif; ?>
              <div class="error" id="err-name" style="display:none;"></div>
            </div>

            <!-- ✅ 아이디(username) + 말풍선 -->
            <div class="field policy-wrap">
              <div class="input">
                <div class="input__icon"><i class="bi bi-person-badge-fill"></i></div>
                <input id="username" type="text" name="username" placeholder="아이디"
                       value="<?= htmlspecialchars($_POST["username"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                <button type="button" class="policy-btn" data-target="userPolicy" aria-label="아이디 정책">
                  <span class="policy-icon">!</span>
                </button>
              </div>

              <div id="userPolicy" class="policy-bubble">
                <b>아이디 정책</b>
                <ul style="margin:6px 0 0 18px;">
                  <li>8~16자</li>
                  <li>영문 입력</li>
                  <li>사용 가능 특수문자: . _ - @ $ !</li>
                </ul>
              </div>

              <?php if (!empty($errors["username"])): ?>
                <div class="error"><?= implode("<br>", array_map(fn($m)=>htmlspecialchars($m, ENT_QUOTES, "UTF-8"), $errors["username"])) ?></div>
              <?php endif; ?>
              <div class="error" id="err-username" style="display:none;"></div>
            </div>

            <!-- ✅ 비밀번호 + 말풍선 -->
            <div class="field policy-wrap">
              <div class="input">
                <div class="input__icon"><i class="bi bi-lock-fill"></i></div>
                <div style="flex:1;">
                  <div class="row" style="padding:0 14px; height:48px;">
                    <input id="pw" type="password" name="password" placeholder="비밀번호"
                           value="<?= htmlspecialchars($_POST["password"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                           style="flex:1; height:48px; padding:0; font-size:15px;">
                    <label class="showpw">
                      <input id="togglePw" type="checkbox">
                      비밀번호 표시
                    </label>
                  </div>
                </div>
                <button type="button" class="policy-btn" data-target="pwPolicy" aria-label="비밀번호 정책">
                  <span class="policy-icon">!</span>
                </button>
              </div>

              <div id="pwPolicy" class="policy-bubble">
                <b>비밀번호 정책</b>
                <ul style="margin:6px 0 0 18px;">
                  <li>8~16자</li>
                  <li>영문 입력</li>
                  <li>숫자 1개 이상 포함</li>
                  <li>특수문자(._-@$!) 1개 이상 포함</li>
                  <li>사용 가능 특수 문자: ._-@$!</li>
                </ul>
              </div>

              <?php if (!empty($errors["password"])): ?>
                <div class="error"><?= implode("<br>", array_map(fn($m)=>htmlspecialchars($m, ENT_QUOTES, "UTF-8"), $errors["password"])) ?></div>
              <?php endif; ?>
              <div class="error" id="err-password" style="display:none;"></div>
            </div>

            <!-- ✅ 비밀번호 확인 -->
            <div class="field">
              <div class="input">
                <div class="input__icon"><i class="bi bi-lock-fill"></i></div>
                <input id="pw2" type="password" name="password_confirm" placeholder="비밀번호 확인"
                       value="<?= htmlspecialchars($_POST["password_confirm"] ?? "", ENT_QUOTES, "UTF-8") ?>">
              </div>
              <?php if (!empty($errors["password_confirm"])): ?>
                <div class="error"><?= implode("<br>", array_map(fn($m)=>htmlspecialchars($m, ENT_QUOTES, "UTF-8"), $errors["password_confirm"])) ?></div>
              <?php endif; ?>
              <div class="error" id="err-password_confirm" style="display:none;"></div>
            </div>

            <!-- ✅ 관리자 코드 -->
            <div class="field">
              <div class="input">
                <div class="input__icon"><i class="bi bi-key-fill"></i></div>
                <input id="admin_code" type="text" name="admin_code" placeholder="가입 코드 (관리자)"
                       value="<?= htmlspecialchars($_POST["admin_code"] ?? "", ENT_QUOTES, "UTF-8") ?>">
              </div>
              <?php if (!empty($errors["admin_code"])): ?>
                <div class="error"><?= implode("<br>", array_map(fn($m)=>htmlspecialchars($m, ENT_QUOTES, "UTF-8"), $errors["admin_code"])) ?></div>
              <?php endif; ?>
              <div class="error" id="err-admin_code" style="display:none;"></div>
            </div>

            <button id="signupBtn" class="btn" type="submit">회원가입</button>

            <div class="divider"></div>
            <div class="bottom">
              이미 계정이 있으신가요? <a href="login.php">로그인</a>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>

  <!-- ✅ 가입 완료 모달 -->
  <?php if ($signupDone): ?>
    <div class="modal-backdrop show"></div>
    <div class="signup-modal show" role="dialog" aria-modal="true" aria-hidden="false">
      <div class="signup-card">
        <div class="head"> 회원가입 완료</div>
        <div class="body">
          회원가입이 정상적으로 완료되었습니다.<br>
          확인을 누르면 로그인 페이지로 이동합니다.
        </div>
        <div class="foot">
          <button class="okbtn" type="button" id="goLoginBtn">확인</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

<script>
  // 비밀번호 표시 토글
  const toggle = document.getElementById('togglePw');
  const pw = document.getElementById('pw');
  const pw2 = document.getElementById('pw2');

  toggle?.addEventListener('change', () => {
    const type = toggle.checked ? 'text' : 'password';
    if (pw) pw.type = type;
    if (pw2) pw2.type = type;
  });

  // ✅ 말풍선 토글
  function closeAll(exceptId){
    document.querySelectorAll('.policy-bubble').forEach(b => {
      if (!exceptId || b.id !== exceptId) b.classList.remove('show');
    });
  }
  document.querySelectorAll('.policy-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = btn.dataset.target;
      const bubble = document.getElementById(id);
      const willShow = !bubble.classList.contains('show');
      closeAll(id);
      if (willShow) bubble.classList.add('show');
      else bubble.classList.remove('show');
    });
  });
  document.addEventListener('click', () => closeAll());
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

  // ✅ 가입 완료 모달 확인 버튼
  const goLoginBtn = document.getElementById("goLoginBtn");
  goLoginBtn?.addEventListener("click", () => {
    location.replace("signup.php?done=1");
  });

  // ✅ bfcache 새로고침
  window.addEventListener('pageshow', function(e){
    if (e.persisted) location.reload();
  });

  /* =========================================================
     ✅ 실시간(blur) 검증 + 오류 1개라도 있으면 가입버튼 비활성화
     ✅ "비밀번호 확인"은 pw2를 건드린 뒤에만 에러 노출
     ✅ 아이디 정책 변경(8~16, 영문 포함 필수, 숫자/특수문자 가능)
     ========================================================= */
  const form = document.getElementById('signupForm');
  const signupBtn = document.getElementById('signupBtn');

  const liveErrors = {
    name: [],
    username: [],
    password: [],
    password_confirm: [],
    admin_code: []
  };

  let pw2Touched = false;

  function escapeHtml(str){
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function setBox(id, messages){
    const box = document.getElementById(id);
    if (!box) return;

    if (!messages || messages.length === 0){
      box.style.display = 'none';
      box.innerHTML = '';
    } else {
      box.style.display = 'block';
      box.innerHTML = messages.map(m => escapeHtml(m)).join('<br>');
    }
  }

  function updateButton(){
    const hasErr = Object.values(liveErrors).some(arr => arr.length > 0);
    if (signupBtn) signupBtn.disabled = hasErr;
  }

  function validateName(){
    const v = (document.getElementById('name')?.value ?? '').trim();
    const errs = [];
    if (v === '') errs.push('이름을 입력해 주세요.');
    liveErrors.name = errs;
    setBox('err-name', errs);
    updateButton();
  }

  // ✅ 아이디: 8~16 + 허용문자 + 영문 포함 필수(한글 불가)
  function validateUsername(){
    const v = (document.getElementById('username')?.value ?? '').trim();
    const errs = [];

    if (v === '') errs.push('아이디를 입력해 주세요.');
    if (v && (v.length < 8 || v.length > 16)) errs.push('아이디는 8자 이상 16자 이내로 입력해 주세요.');
    if (v && !/^[A-Za-z0-9._\-@$!]+$/.test(v)) errs.push('아이디는 영문/숫자 및 특수문자(._-@$!)만 사용할 수 있습니다.');

    liveErrors.username = errs;
    setBox('err-username', errs);
    updateButton();
  }

  // ✅ 비밀번호: 8~16 + 허용문자 + 영문 포함 + 숫자1+ + 특수1+
  function validatePassword(){
    const v = (document.getElementById('pw')?.value ?? '');
    const errs = [];

    if (v === '') errs.push('비밀번호를 입력해 주세요.');
    if (v && (v.length < 8 || v.length > 16)) errs.push('비밀번호는 8자 이상 16자 이내로 설정해 주세요.');
    if (v && !/^[A-Za-z0-9._\-@$!]+$/.test(v)) errs.push('비밀번호는 영문/숫자 및 특수문자(._-@$!)만 사용할 수 있습니다.');
    if (v && !/[0-9]/.test(v)) errs.push('비밀번호에 숫자가 1개 이상 포함되어야 합니다.');
    if (v && !/[._\-@$!]/.test(v)) errs.push('비밀번호에 특수문자(._-@$!)가 1개 이상 포함되어야 합니다.');

    liveErrors.password = errs;
    setBox('err-password', errs);

    const pw2Val = (document.getElementById('pw2')?.value ?? '');
    if (pw2Touched || pw2Val !== '') {
      validatePasswordConfirm(false);
    }

    updateButton();
  }

  function validatePasswordConfirm(updateBtn = true){
    const p1 = (document.getElementById('pw')?.value ?? '');
    const p2v = (document.getElementById('pw2')?.value ?? '');
    const errs = [];

    if (!pw2Touched && p2v === '') {
      liveErrors.password_confirm = [];
      setBox('err-password_confirm', []);
      if (updateBtn) updateButton();
      return;
    }

    if (p2v === '') errs.push('비밀번호 확인을 입력해 주세요.');
    else if (p1 !== p2v) errs.push('비밀번호와 비밀번호 확인이 일치하지 않습니다.');

    liveErrors.password_confirm = errs;
    setBox('err-password_confirm', errs);
    if (updateBtn) updateButton();
  }

  function validateAdminCode(){
    const v = (document.getElementById('admin_code')?.value ?? '').trim();
    const errs = [];
    if (v !== '' && v !== 'soldesk') errs.push('관리자 가입 코드가 올바르지 않습니다.');
    liveErrors.admin_code = errs;
    setBox('err-admin_code', errs);
    updateButton();
  }

  // ✅ blur에서 즉시 검증
  document.getElementById('name')?.addEventListener('blur', validateName);
  document.getElementById('username')?.addEventListener('blur', validateUsername);
  document.getElementById('pw')?.addEventListener('blur', validatePassword);

  document.getElementById('pw2')?.addEventListener('blur', () => {
    pw2Touched = true;
    validatePasswordConfirm(true);
  });

  document.getElementById('admin_code')?.addEventListener('blur', validateAdminCode);

  // ✅ submit 시에도 한번 더 막기
  form?.addEventListener('submit', (e) => {
    pw2Touched = true;

    validateName();
    validateUsername();
    validatePassword();
    validatePasswordConfirm(true);
    validateAdminCode();

    const hasErr = Object.values(liveErrors).some(arr => arr.length > 0);
    if (hasErr){
      e.preventDefault();

      const order = ['name','username','password','password_confirm','admin_code'];
      const map = { name:'name', username:'username', password:'pw', password_confirm:'pw2', admin_code:'admin_code' };
      for (const key of order){
        if (liveErrors[key] && liveErrors[key].length){
          document.getElementById(map[key])?.focus();
          break;
        }
      }
    }
  });

  updateButton();
</script>

</body>
</html>
