<?php /* landing only */ ?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>작업 관리 시스템</title>

  <!-- 아이콘(원하면 유지), 없어도 됨 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    :root{
      --bg: #e9eef7;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #64748b;

      --primary: #1d4ed8;
      --primary-2: #2563eb;

      --shadow: 0 12px 40px rgba(15, 23, 42, 0.12);
      --radius: 18px;
      --radius2: 22px;
    }
    *{ box-sizing: border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Sans KR", Arial, sans-serif;
      background: linear-gradient(#eef3fb, #e6ecf8);
      color: var(--text);
    }
    a{ text-decoration:none; color:inherit; }

    /* Top Bar */
    .topbar{
      position: sticky;
      top:0;
      z-index: 50;
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(148,163,184,0.35);
    }
    .topbar__inner{
      max-width: 1040px;
      margin: 0 auto;
      padding: 14px 18px;
      display:flex;
      align-items:center;
      justify-content:space-between;
    }
    .brand{
      font-weight: 800;
      color:#1e3a8a;
      letter-spacing: -0.2px;
      font-size: 18px;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding: 10px 16px;
      border-radius: 10px;
      border: 1px solid transparent;
      font-weight: 700;
      cursor:pointer;
      transition: transform .08s ease, background .15s ease, border-color .15s ease;
      user-select:none;
    }
    .btn:active{ transform: translateY(1px); }
    .btn--primary{
      background: var(--primary);
      color:#fff;
      box-shadow: 0 10px 24px rgba(37, 99, 235, 0.28);
    }
    .btn--primary:hover{ background: var(--primary-2); }

    /* Page */
    .page{
      max-width: 1040px;
      margin: 0 auto;
      padding: 18px 18px 40px;
    }

    /* Hero Card Container */
    .hero-card{
      background: rgba(255,255,255,0.55);
      border: 1px solid rgba(148,163,184,0.35);
      border-radius: var(--radius2);
      box-shadow: var(--shadow);
      overflow:hidden;
    }

    /* Hero */
    .hero{
      position:relative;
      height: 320px;
      border-bottom: 1px solid rgba(148,163,184,0.25);
      background:
        linear-gradient(90deg, rgba(2,6,23,0.15), rgba(2,6,23,0.12)),
        url("https://images.unsplash.com/photo-1503387762-592deb58ef4e?q=80&w=2000&auto=format&fit=crop")
        center/cover no-repeat;
    }
    .hero__content{
      position:absolute;
      inset:0;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding: 24px;
      color:#fff;
    }
    .hero__content h1{
      margin:0;
      font-size: clamp(28px, 4.3vw, 54px);
      font-weight: 900;
      letter-spacing: -0.7px;
      text-shadow: 0 10px 22px rgba(2,6,23,0.35);
    }
    .hero__content p{
      margin: 10px 0 18px;
      max-width: 760px;
      font-size: 15.5px;
      color: rgba(255,255,255,0.88);
      text-shadow: 0 8px 18px rgba(2,6,23,0.25);
    }
    .btn--hero{
      background: rgba(29, 78, 216, 0.92);
      color:#fff;
      padding: 12px 28px;
      border-radius: 12px;
      box-shadow: 0 14px 30px rgba(29, 78, 216, 0.35);
    }
    .btn--hero:hover{ background: rgba(37, 99, 235, 0.95); }

    /* Features */
    .features{
      padding: 26px 22px 26px;
      background: rgba(239, 244, 252, 0.75);
    }
    .features h2{
      margin: 6px 0 18px;
      text-align:center;
      font-size: 22px;
      letter-spacing: -0.3px;
    }
    .cards{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }
    .card{
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(148,163,184,0.35);
      border-radius: 14px;
      padding: 18px 16px 16px;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
      min-height: 220px;
      display:flex;
      flex-direction:column;
      align-items:center;
      text-align:center;
    }
    .card__icon{
      position:relative;
      width: 76px;
      height: 76px;
      border-radius: 18px;
      background: rgba(219, 234, 254, 0.9);
      display:flex;
      align-items:center;
      justify-content:center;
      margin-bottom: 10px;
      border: 1px solid rgba(147,197,253,0.8);
    }
    .card__icon i{
      font-size: 40px;
      color: #2563eb;
    }
    .badge{
      position:absolute;
      width: 26px;
      height: 26px;
      border-radius: 999px;
      display:flex;
      align-items:center;
      justify-content:center;
      bottom: -6px;
      right: -6px;
      box-shadow: 0 8px 18px rgba(2,6,23,0.18);
      border: 2px solid #fff;
    }
    .badge--plus{ background:#1d4ed8; }
    .badge--paper{ background:#38bdf8; }
    .badge--check{ background:#22c55e; }
    .badge i{ font-size: 14px; color:#fff; }

    .card h3{
      margin: 6px 0 8px;
      font-size: 18px;
      font-weight: 900;
      letter-spacing: -0.2px;
    }
    .card p{
      margin:0;
      color: var(--muted);
      font-size: 13.5px;
      line-height: 1.6;
    }

    /* Footer */
    .footer{
      padding: 18px 0 26px;
      color: rgba(15,23,42,0.55);
    }
    .footer__inner{
      max-width: 1040px;
      margin: 0 auto;
      padding: 0 18px;
      display:flex;
      justify-content:center;
    }

    /* Responsive */
    @media (max-width: 900px){
      .hero{ height: 300px; }
      .cards{ grid-template-columns: 1fr; }
      .card{ min-height: auto; }
    }
  </style>
</head>

<body>
  <!-- 상단바 -->
  <header class="topbar">
    <div class="topbar__inner">
      <div class="brand">작업 관리 시스템</div>
      <a class="btn btn--primary" href="login.php">로그인</a>
    </div>
  </header>

  <main class="page">
    <section class="hero-card">
      <!-- 히어로 -->
      <div class="hero">
        <div class="hero__content">
          <h1>건설 프로젝트를 손쉽게 관리하세요!</h1>
          <p>효율적으로 작업을 관리하고, 할당량 및 작업 결과를 간편하게 확인하세요.</p>
          <a class="btn btn--hero" href="login.php">로그인</a>
        </div>
      </div>

      <!-- 기능 소개 -->
      <div class="features">
        <h2>이 시스템에서 할 수 있는 일</h2>

        <div class="cards">
          <article class="card">
            <div class="card__icon">
              <i class="bi bi-clipboard2-check"></i>
              <span class="badge badge--plus"><i class="bi bi-plus-lg"></i></span>
            </div>
            <h3>작업 할당</h3>
            <p>관리자는 작업 내용과 현장 사진을 등록하여 작업자에게 할당합니다.</p>
          </article>

          <article class="card">
            <div class="card__icon">
              <i class="bi bi-camera"></i>
              <span class="badge badge--paper"><i class="bi bi-file-earmark-text"></i></span>
            </div>
            <h3>작업 결과 제출</h3>
            <p>작업자는 할당된 작업을 확인한 후 결과 사진과 작업 결과 내용을 시스템에 제출할 수 있습니다.</p>
          </article>

          <article class="card">
            <div class="card__icon">
              <i class="bi bi-bell"></i>
              <span class="badge badge--check"><i class="bi bi-check-lg"></i></span>
            </div>
            <h3>작업 승인 및 현황 관리</h3>
            <p>관리자는 제출된 결과를 검토하고 승인함으로써 작업 진행 상황과 실적을 관리합니다.</p>
          </article>
        </div>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="footer__inner">© <?= date('Y') ?> 작업 관리 시스템</div>
  </footer>
</body>
</html>
