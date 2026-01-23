<?php
require_once "auth_guard.php";

/* ✅ 캐시 방지 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

/* ✅ 보안 헤더 */
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: same-origin");

require_once "db.php";

require_once "s3_upload.php";


/* ✅ 로그인 사용자 이름 */
$userName = $_SESSION["user_name"] ?? "";
$userNameSafe = htmlspecialchars($userName !== "" ? $userName : "사용자", ENT_QUOTES, "UTF-8");

/* ✅ 관리자 여부 */
$role = $_SESSION["role"] ?? ($_SESSION["user_role"] ?? "worker");
$isAdmin = ($role === "admin");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function json_arr($raw): array {
  if (!$raw) return [];
  $a = json_decode($raw, true);
  return is_array($a) ? $a : [];
}

function first_photo(array $arr): string {
  if (count($arr) > 0 && is_string($arr[0]) && $arr[0] !== "") return $arr[0];
  return "";
}

/* ✅ 날짜 검증 */
function is_ymd($s): bool {
  if (!is_string($s)) return false;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $s);
  return $dt && $dt->format('Y-m-d') === $s;
}
function week_start_monday(DateTime $d): DateTime {
  $x = clone $d;
  $x->setTime(0,0,0);
  $dow = (int)$x->format('N'); // 1(Mon)~7(Sun)
  $x->modify('-'.($dow-1).' days');
  return $x;
}

/* ✅ 보기 모드(view=week|all)
   - 기본: week (주간 보기)
   - all: 전체 보기
   - (하위호환) wf=1 => week / wf=0 => all */
function resolve_view_mode(): string {
  if (isset($_GET["view"])) {
    return ($_GET["view"] === "all") ? "all" : "week";
  }
  if (isset($_GET["wf"])) {
    return ((string)$_GET["wf"] === "0") ? "all" : "week";
  }
  return "week"; // ✅ 기본은 주간 보기
}

/* =========================
   ✅ POST 액션 처리
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  try {
    if ($action === "create_task") {
      if (!$isAdmin) throw new Exception("권한이 없습니다.");

      $title = trim($_POST["title"] ?? "");
      $desc  = trim($_POST["description"] ?? "");
      $date  = trim($_POST["task_date"] ?? "");

      if ($title === "" || $desc === "" || $date === "") throw new Exception("필수값 누락");

      $adminPhotos = [];
      if (isset($_FILES["admin_photos"])) {
        $adminPhotos = upload_images_to_s3($_FILES["admin_photos"], "tasks");
      }

      $stmt = $pdo->prepare("
        INSERT INTO tasks (title, description, task_date, status, admin_photos, worker_description, worker_photos, rejected_flag, rejected_at)
        VALUES (:t, :d, :dt, 'assigned', :ap, '', :wp, 0, NULL)
      ");
      $stmt->execute([
        ":t"  => $title,
        ":d"  => $desc,
        ":dt" => $date,
        ":ap" => json_encode($adminPhotos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ":wp" => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]);

      header("Location: dashboard.php?tab=assigned");
      exit;
    }

    if ($action === "start_task") {
      $id = (int)($_POST["task_id"] ?? 0);
      if ($id <= 0) throw new Exception("잘못된 요청");

      $stmt = $pdo->prepare("UPDATE tasks SET status='in_progress' WHERE id=:id AND status='assigned'");
      $stmt->execute([":id"=>$id]);

      header("Location: dashboard.php?tab=in_progress");
      exit;
    }

    if ($action === "submit_result") {
      $id = (int)($_POST["task_id"] ?? 0);
      if ($id <= 0) throw new Exception("잘못된 요청");

      $wdesc = trim($_POST["worker_description"] ?? "");
      if ($wdesc === "") throw new Exception("작업 결과 내용을 입력해 주세요.");

      $stmt = $pdo->prepare("SELECT worker_photos FROM tasks WHERE id=:id");
      $stmt->execute([":id"=>$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $oldPhotos = $row ? json_arr($row["worker_photos"] ?? "") : [];

      $newPhotos = [];
      if (isset($_FILES["worker_photos"])) {
        $newPhotos = upload_images_to_s3($_FILES["worker_photos"], "results");
      }
      $merged = array_values(array_filter(array_merge($oldPhotos, $newPhotos)));

      $stmt = $pdo->prepare("
        UPDATE tasks
        SET worker_description=:wd,
            worker_photos=:wp,
            status='pending'
        WHERE id=:id AND status='in_progress'
      ");
      $stmt->execute([
        ":wd"=>$wdesc,
        ":wp"=>json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ":id"=>$id
      ]);

      header("Location: dashboard.php?tab=pending");
      exit;
    }

    if ($action === "review_task") {
      if (!$isAdmin) throw new Exception("권한이 없습니다.");

      $id = (int)($_POST["task_id"] ?? 0);
      $decision = $_POST["decision"] ?? "";
      if ($id <= 0) throw new Exception("잘못된 요청");

      if ($decision === "approve") {
        $stmt = $pdo->prepare("UPDATE tasks SET status='approved', rejected_flag=0, rejected_at=NULL WHERE id=:id AND status='pending'");
        $stmt->execute([":id"=>$id]);
        header("Location: dashboard.php?tab=approved");
        exit;
      }

      if ($decision === "reject") {
        $stmt = $pdo->prepare("UPDATE tasks SET status='in_progress', rejected_flag=1, rejected_at=NOW() WHERE id=:id AND status='pending'");
        $stmt->execute([":id"=>$id]);
        header("Location: dashboard.php?tab=in_progress");
        exit;
      }

      throw new Exception("잘못된 decision");
    }

    /* ✅ 관리자: 작업 삭제(전체 상태 포함) */
    if ($action === "delete_task") {
      if (!$isAdmin) throw new Exception("권한이 없습니다.");

      $id = (int)($_POST["task_id"] ?? 0);
      if ($id <= 0) throw new Exception("잘못된 요청");

      $stmt = $pdo->prepare("SELECT status, admin_photos, worker_photos, photo_url FROM tasks WHERE id=:id");
      $stmt->execute([":id"=>$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new Exception("대상이 존재하지 않습니다.");

      $status = (string)($row["status"] ?? "");
      $allow = ["assigned","in_progress","pending","approved"];
      if (!in_array($status, $allow, true)) throw new Exception("삭제 가능한 상태가 아닙니다.");

      // 파일 삭제 (S3 + 하위호환 photo_url)
      $ap = json_arr($row["admin_photos"] ?? "");
      $wp = json_arr($row["worker_photos"] ?? "");
      
      // ✅ S3 삭제 호출 (s3_upload.php에 delete_images_from_s3() 있어야 함)
      delete_images_from_s3(array_merge($ap, $wp, $legacy));

      // DB 삭제
      $del = $pdo->prepare("DELETE FROM tasks WHERE id=:id");
      $del->execute([":id"=>$id]);
      if ($del->rowCount() <= 0) throw new Exception("삭제 실패");

      // 예전 컬럼(photo_url)도 같이 지우기
      $legacy = [];
      if (!empty($row["photo_url"]) && is_string($row["photo_url"])) {
        $legacy[] = (string)$row["photo_url"];
      }

      // (옵션) 로컬 업로드 폴더도 같이 쓰는 구조라면 유지
      foreach ($ap as $p) if (is_string($p)) safe_unlink_uploads_path($p);
      foreach ($wp as $p) if (is_string($p)) safe_unlink_uploads_path($p);
      foreach ($legacy as $p) if (is_string($p)) safe_unlink_uploads_path($p);

      // ✅ 삭제 후: 현재 화면(탭/보기모드/주/페이지) 유지
      $backTab = $_POST["back_tab"] ?? "assigned";
      $backTab = in_array($backTab, ["assigned","in_progress","pending","approved"], true) ? $backTab : "assigned";

      $backView = ($_POST["back_view"] ?? "week") === "all" ? "all" : "week";
      $backWs = (string)($_POST["back_ws"] ?? "");
      $page = (int)($_POST["back_page"] ?? 1);
      if ($page < 1) $page = 1;

      $qs = "tab=".$backTab."&view=".$backView;
      if (is_ymd($backWs)) $qs .= "&ws=".urlencode($backWs);
      if ($page > 1) $qs .= "&page=".$page;

      header("Location: dashboard.php?".$qs);
      exit;
    }

  } catch (Throwable $e) {
    $msg = urlencode($e->getMessage());
    header("Location: dashboard.php?err={$msg}");
    exit;
  }
}

/* =========================
   ✅ 데이터 조회
   ========================= */
$tab = $_GET["tab"] ?? "assigned";
$allowedTabs = ["assigned","in_progress","pending","approved"];
if (!in_array($tab, $allowedTabs)) $tab = "assigned";

$errMsg = isset($_GET["err"]) ? h($_GET["err"]) : "";

/* ✅ 보기 모드 + 주(week start) */
$view = resolve_view_mode();                // week | all
$isWeekView = ($view === "week");

$today = new DateTime("now");
$defaultWs = week_start_monday($today)->format("Y-m-d");

$ws = (string)($_GET["ws"] ?? "");
if (!is_ymd($ws)) $ws = $defaultWs;

$weekStart = DateTime::createFromFormat("Y-m-d", $ws);
$weekEnd = (clone $weekStart)->modify("+6 days");
$from = $weekStart->format("Y-m-d");
$to   = $weekEnd->format("Y-m-d");

/* ✅ 페이지네이션(현재 탭 리스트) */
$perPage = 12; // ✅ 한 페이지당 12개
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* ✅ 상태별 전체 카운트(항상 총 건수 유지) */
$countStmt = $pdo->query("
  SELECT status, COUNT(*) AS cnt
  FROM tasks
  GROUP BY status
");
$counts = ["assigned"=>0,"in_progress"=>0,"pending"=>0,"approved"=>0];
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $s = $r["status"] ?? "";
  if (isset($counts[$s])) $counts[$s] = (int)($r["cnt"] ?? 0);
}

/* ✅ 현재 탭: 보기 모드에 따른 전체 건수(페이지 계산용) */
$countSql = "SELECT COUNT(*) AS c FROM tasks WHERE status=:s";
$params = [":s"=>$tab];

if ($isWeekView) {
  $countSql .= " AND task_date BETWEEN :from AND :to";
  $params[":from"] = $from;
  $params[":to"] = $to;
}

$cStmt = $pdo->prepare($countSql);
$cStmt->execute($params);
$totalRows = (int)($cStmt->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

/* ✅ 현재 탭 목록(보기 모드 + 페이지 적용) */
$listSql = "
  SELECT id, title, description, task_date, status,
         admin_photos, worker_description, worker_photos,
         rejected_flag, rejected_at,
         photo_url
  FROM tasks
  WHERE status=:s
";
if ($isWeekView) $listSql .= " AND task_date BETWEEN :from AND :to";
$listSql .= " ORDER BY id DESC LIMIT ".$perPage." OFFSET ".$offset;

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$tasks = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ✅ JS용 데이터(현재 페이지/탭만) */
$tasksForJs = [];
foreach ($tasks as $t) {
  $id = (int)$t["id"];
  $tasksForJs[$id] = [
    "id" => $id,
    "title" => (string)($t["title"] ?? ""),
    "description" => (string)($t["description"] ?? ""),
    "task_date" => (string)($t["task_date"] ?? ""),
    "status" => (string)($t["status"] ?? ""),
    "admin_photos" => json_arr($t["admin_photos"] ?? ""),
    "worker_description" => (string)($t["worker_description"] ?? ""),
    "worker_photos" => json_arr($t["worker_photos"] ?? ""),
    "rejected_flag" => (int)($t["rejected_flag"] ?? 0),
    "rejected_at" => (string)($t["rejected_at"] ?? ""),
    "photo_url" => (string)($t["photo_url"] ?? ""),
  ];
}

/* ✅ 삭제 리스트용(모든 상태 포함) */
$deleteCandidates = [];
if ($isAdmin) {
  $delStmt = $pdo->query("
    SELECT id, title, task_date, status
    FROM tasks
    WHERE status IN ('assigned','in_progress','pending','approved')
    ORDER BY id DESC
    LIMIT 2000
  ");
  $deleteCandidates = $delStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ✅ 페이지 링크 생성(현재 화면 유지: tab/view/ws) */
function build_qs(array $over = []): string {
  $tab = $_GET["tab"] ?? "assigned";
  if (!in_array($tab, ["assigned","in_progress","pending","approved"], true)) $tab = "assigned";

  // view 우선, 없으면 wf 하위호환, 기본 week
  $view = "week";
  if (isset($_GET["view"])) $view = ($_GET["view"] === "all") ? "all" : "week";
  else if (isset($_GET["wf"])) $view = ((string)$_GET["wf"] === "0") ? "all" : "week";

  $ws = (string)($_GET["ws"] ?? "");
  if (!is_ymd($ws)) {
    $ws = week_start_monday(new DateTime("now"))->format("Y-m-d");
  }

  $base = [
    "tab"  => $tab,
    "view" => $view,
    "ws"   => $ws,
  ];

  $merged = array_merge($base, $over);

  // null 값 제거(예: page=>null)
  foreach ($merged as $k => $v) {
    if ($v === null) unset($merged[$k]);
  }

  // 과거 파라미터(wf) 제거
  unset($merged["wf"]);

  return http_build_query($merged);
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>작업 관리 시스템</title>

  <style>
    :root{
      --bg:#e9eefc;
      --card:#ffffff;
      --line:#dbe3ff;
      --text:#1d2b4a;
      --muted:#6b7aa6;

      --primary:#2b61d1;
      --primary2:#1f55c8;
      --danger:#c74e4e;

      --shadow:0 10px 25px rgba(22,45,90,.12);

      --padX:14px;
      --dashRadius:10px;
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, "Noto Sans KR", sans-serif;
      background: linear-gradient(180deg, #eef2ff 0%, var(--bg) 100%);
      color:var(--text);
    }

    .page{
      max-width:1100px;
      margin:24px auto 60px;
      padding:0 14px;
    }

    .page-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin: 18px 0 12px;
    }

    .page-header h1{
      margin:0;
      font-size:28px;
      font-weight:900;
    }

    .header-actions{
      display:flex;
      gap:10px;
      align-items:center;
      padding-right: var(--padX);
      margin-top: 6px;
      margin-bottom: -6px;
      z-index: 5;
    }

    .btn{
      border:0;
      border-radius:8px;
      padding:10px 12px;
      font-weight:900;
      cursor:pointer;
      color:#fff;
      display:inline-flex;
      align-items:center;
      gap:8px;
      line-height:1;
      white-space:nowrap;
      box-shadow: 0 8px 18px rgba(22,45,90,.18);
    }
    .btn.primary{background:var(--primary)}
    .btn.primary:hover{background:var(--primary2)}
    .btn.danger{background:var(--danger)}
    .btn.danger:hover{filter: brightness(.95)}
    .btn.ghost{
      background:#fff;
      color:var(--text);
      border:1px solid var(--line);
      box-shadow:none;
    }

    .dash{
      background:rgba(255,255,255,.35);
      border:1px solid var(--line);
      border-radius: var(--dashRadius);
      overflow:hidden;
      box-shadow:var(--shadow);
    }

    .dash-topbar{
      background:linear-gradient(90deg, #2b61d1, #224db0);
      padding: 12px var(--padX);
      color:#fff;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      min-height:52px;
      border-top-left-radius: var(--dashRadius);
      border-top-right-radius: var(--dashRadius);
    }

    .dash-topbar .title{
      font-weight:900;
      font-size:16px;
      letter-spacing:-0.2px;
    }

    /* ✅ 우측 사용자 드롭다운 */
    .user-dropdown{position:relative; display:flex; align-items:center; justify-content:flex-end; margin-left:auto;}
    .user-trigger{
      appearance:none;
      border:2px solid rgba(255,255,255,.85);
      background: rgba(255,255,255,.10);
      color:#fff;
      font-weight:900;
      font-size:13px;
      padding:8px 12px;
      border-radius:999px;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:10px;
      line-height:1;
      white-space:nowrap;
      box-shadow: 0 6px 14px rgba(10,20,60,.18);
    }
    .caret{width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:7px solid rgba(255,255,255,.95);}

    .dropdown-panel{
      position:absolute; top: calc(100% + 6px); right:0;
      min-width: 108px;
      background:#fff; color:var(--text);
      border:1px solid var(--line);
      border-radius:12px;
      box-shadow: 0 14px 28px rgba(22,45,90,.18);
      padding:6px;
      display:none;
      z-index: 50;
    }
    .dropdown-panel.open{display:block;}
    .dropdown-link{
      width:100%;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:6px;
      padding:9px 8px;
      border-radius:10px;
      text-decoration:none;
      font-weight:950;
      color:#c33c3c;
      white-space:nowrap;
    }
    .dropdown-link:hover{ background:#fff2f2; }

    .dash-body{padding:14px}

    /* ✅ 상태 버튼 메뉴 */
    .tabs{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:10px;
      margin-bottom:12px;
    }
    .tabbtn{
      width:100%;
      border:1px solid rgba(203,213,225,.85);
      background:#ffffff;
      color:#0f172a;
      padding:12px 12px;
      border-radius:14px;
      font-weight:950;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      box-shadow: 0 10px 22px rgba(15,23,42,.08);
      transition: transform .04s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease;
    }
    .tabbtn:hover{
      background:#f8fafc;
      border-color: rgba(43,97,209,.28);
      box-shadow: 0 14px 30px rgba(15,23,42,.10);
    }
    .tabbtn:active{ transform: translateY(1px); }

    .tabbtn.active{
      background:#eef2ff;
      color:#0f172a;
      border-color: rgba(43,97,209,.28);
      box-shadow: 0 14px 34px rgba(43,97,209,.16);
    }
    .tabbadge{
      min-width: 36px;
      height: 26px;
      padding: 0 10px;
      border-radius: 999px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight: 950;
      background:#f1f5ff;
      border: 1px solid rgba(43,97,209,.18);
      color:#1d4ed8;
    }
    .tabbtn.active .tabbadge{
      background:#ffffff;
      border-color: rgba(43,97,209,.22);
      color:#1d4ed8;
    }

    /* ✅ 주간 바 */
    .weekbar{
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:10px 12px;
      border:1px solid var(--line);
      border-radius:14px;
      background:#fff;
      margin-bottom:10px;
      font-weight:900;
      gap:12px;
    }
    .weekbar .range{display:flex; align-items:center; gap:10px; color:#2a3b64; flex-wrap:wrap;}
    .weekbar .nav{
      background: transparent;
      border:0;
      font-size: 20px;
      cursor:pointer;
      color:var(--primary);
      padding: 6px 10px;
      border-radius: 10px;
      font-weight: 950;
    }
    .weekbar .nav:hover{background:#eaf0ff}
    .week-date{color:#64748b; font-size:12px; font-weight:950;}

    /* ✅ (유지) 현재 탭 표시 건수 */
    .tab-count{
      font-size:12px;
      font-weight:950;
      color:#475569;
      white-space:nowrap;
    }
    .tab-count b{ color:#0f172a; }

    .cards{
      background:rgba(255,255,255,.4);
      border:1px solid var(--line);
      border-radius:14px;
      padding:14px;
    }
    .empty{
      background:#fff;
      border:1px dashed var(--line);
      border-radius:14px;
      padding:60px 20px;
      text-align:center;
      color:var(--muted);
      font-weight:900;
    }

    /* ✅ 작업 카드 */
    .task-grid{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
    }
    .task-card{
      background:#fff;
      border:1px solid rgba(203,213,225,.85);
      border-radius: 12px;
      box-shadow: 0 12px 26px rgba(15,23,42,.08);
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    .task-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding: 12px 12px 10px;
      font-weight: 950;
      color:#0f172a;
    }
    .task-title{
      font-size: 14px;
      font-weight: 950;
      letter-spacing: -.2px;
      line-height: 1.1;
      overflow:hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 72%;
    }
    .task-date{
      font-size: 11px;
      font-weight: 950;
      padding: 5px 8px;
      border-radius: 8px;
      background: #eef2ff;
      border: 1px solid rgba(43,97,209,.18);
      color: #1d4ed8;
      white-space: nowrap;
      flex: 0 0 auto;
    }
    .task-thumb{
      width:100%;
      aspect-ratio: 1.8 / 1;
      background:#f1f5ff;
      border-top:1px solid rgba(203,213,225,.65);
      border-bottom:1px solid rgba(203,213,225,.65);
      display:block;
      object-fit: cover;
      cursor: zoom-in;
    }
    .task-actions{
      padding: 10px 12px 12px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .task-btn{
      flex:1;
      border:0;
      border-radius: 10px;
      padding: 10px 12px;
      background: var(--primary);
      color:#fff;
      font-weight: 950;
      cursor:pointer;
      box-shadow: 0 10px 20px rgba(43,97,209,.20);
      white-space:nowrap;
    }
    .task-btn:hover{ background: var(--primary2); }

    /* ✅ 진행중 우측 "반려" 둥근 네모 */
    .reject-pill{
      flex:0 0 auto;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(148,163,184,.55);
      background: rgba(248,250,252,.95);
      color:#0f172a;
      font-weight: 950;
      font-size: 12px;
      white-space:nowrap;
      display:none;
      align-items:center;
      gap:8px;
    }
    .reject-pill.show{ display:inline-flex; }
    .dot{width:9px;height:9px;border-radius:999px;background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.14);}

    /* ✅ 페이지네이션(메인) - 하단 우측에 <> / 7개 이상이면 … 처리 */
    .pager{
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px solid rgba(203,213,225,.65);
      display:flex;
      justify-content:flex-end;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }
    .pager a, .pager span{
      border:1px solid rgba(203,213,225,.9);
      background:#fff;
      color:#0f172a;
      border-radius: 12px;
      height: 40px;
      padding: 0 12px;
      font-weight: 950;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width: 44px;
    }
    .pager a:hover{ background:#f8fafc; }
    .pager .active{
      background:#eef2ff;
      border-color: rgba(43,97,209,.28);
      color:#1d4ed8;
      cursor: default;
    }
    .pager .muted{
      opacity:.55;
      cursor: not-allowed;
      background:#fff;
      border:1px solid rgba(203,213,225,.9);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width: 44px;
      height: 40px;
      border-radius:12px;
    }
    /* ✅ … 전용 (2/2 같은 표시는 제거했음) */
    .pager .info{
      border:0;
      background: transparent;
      cursor: default;
      padding:0 2px;
      color:#94a3b8;
      font-weight: 950;
      min-width: auto;
      height:auto;
    }

    /* ===== 모달 공통 ===== */
    .modal-backdrop{
      position: fixed; inset: 0;
      background: rgba(10, 20, 40, .35);
      backdrop-filter: blur(2px);
      display:none;
      z-index: 999;
    }
    .modal-backdrop.show{ display:block; }

    .modal{
      position: fixed; inset: 0;
      display:none;
      z-index: 1000;
      overflow:auto;
      padding: 22px 14px;
    }
    .modal.show{ display:block; }

    .modal-card{
      max-width: 920px;
      margin: 0 auto;
      width: 100%;
      background: rgba(255,255,255,.95);
      border: 1px solid rgba(203,213,225,.75);
      border-radius: 14px;
      box-shadow: 0 24px 60px rgba(15,23,42,.22);
      overflow:hidden;
    }
    .modal-card.small{ max-width: 560px; }

    .modal-head{
      height: 56px;
      padding: 0 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      background: rgba(255,255,255,.75);
      border-bottom: 1px solid rgba(203,213,225,.65);
      gap:10px;
    }
    .modal-title{font-weight:950;font-size:18px;color:#0f172a; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
    .modal-close{
      width: 38px;height: 38px;border-radius: 10px;
      border: 1px solid rgba(203,213,225,.8);
      background:#fff;cursor:pointer;font-size: 18px;font-weight: 900;
    }
    .modal-body{padding: 16px;background: rgba(248,250,252,.75);}

    .modal-foot{
      padding: 14px 16px;
      display:flex;
      gap:10px;
      justify-content:flex-end;
      align-items:center;
      border-top: 1px solid rgba(203,213,225,.75);
      background:#ffffff;
      flex-wrap:wrap;
    }
    .btn2{
      border:0;
      border-radius:12px;
      padding:10px 14px;
      height:40px;
      font-weight:950;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:8px;
      white-space:nowrap;
    }
    .btn2.primary{ background: var(--primary); color:#fff; box-shadow: 0 10px 20px rgba(43,97,209,.18); }
    .btn2.primary:hover{ background: var(--primary2); }
    .btn2.ghost{ background:#fff; color:var(--text); border:1px solid rgba(203,213,225,.9); }
    .btn2.danger{ background: var(--danger); color:#fff; box-shadow: 0 10px 20px rgba(199,78,78,.16); }
    .btn2.danger:hover{ filter: brightness(.95); }

    .section{
      background:#fff;
      border: 1px solid rgba(203,213,225,.75);
      border-radius: 14px;
      padding: 14px;
      margin-bottom: 12px;
      box-shadow: 0 12px 26px rgba(15,23,42,.05);
    }
    .section-title{
      font-weight: 950;
      color:#0f172a;
      margin: 0 0 10px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .tiny{font-size:12px;font-weight:900;color:#64748b;}
    .desc{color:#0f172a;font-weight:850;line-height:1.55;font-size:14px;white-space: pre-wrap;}

    .photos{display:flex;gap:10px;flex-wrap:wrap;margin-top: 10px;}
    .pimg{
      width: 220px;
      height: 140px;
      border-radius: 12px;
      border: 1px solid rgba(203,213,225,.9);
      object-fit: cover;
      background:#f1f5ff;
      cursor: zoom-in;
    }

    /* ✅ 글/사진 분리 레이아웃 */
    .split{
      display:grid;
      grid-template-columns: 1fr;
      gap:12px;
    }
    .textbox{
      background: rgba(248,250,252,.9);
      border: 1px solid rgba(203,213,225,.85);
      border-radius: 12px;
      padding: 12px;
    }
    .textbox .desc{ margin:0; }
    .photobox{
      background:#fff;
      border: 1px solid rgba(203,213,225,.85);
      border-radius: 12px;
      padding: 12px;
    }
    .photobox .photos{ margin-top: 0; }
    .photobox .tiny{ margin-bottom: 10px; }

    .alert{
      margin: 0 0 12px;
      padding: 10px 12px;
      border-radius: 12px;
      background:#fff1f2;
      border:1px solid rgba(244,63,94,.25);
      color:#9f1239;
      font-weight: 950;
    }

    /* ✅ 라이트박스 */
    .lightbox{
      position: fixed;
      inset: 0;
      background: rgba(10,20,40,.72);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      padding: 18px;
    }
    .lightbox.show{ display:flex; }
    .lightbox-inner{
      max-width: min(980px, 96vw);
      max-height: 88vh;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .lightbox-img{
      width: 100%;
      height: auto;
      max-height: 80vh;
      object-fit: contain;
      border-radius: 14px;
      background: #fff;
      box-shadow: 0 24px 60px rgba(0,0,0,.35);
    }
    .lightbox-bar{
      display:flex;
      justify-content:flex-end;
      gap:10px;
    }
    .lb-btn{
      border:0;
      border-radius: 10px;
      padding: 10px 12px;
      font-weight: 950;
      cursor:pointer;
      background:#fff;
      color:#0f172a;
    }
    .lb-btn:hover{ background:#eef2ff; }

    /* ✅ 삭제 리스트(정렬/간격 깔끔하게 재정리) */
    .dlist{
      background:#fff;
      border:1px solid rgba(203,213,225,.85);
      border-radius: 14px;
      overflow:hidden;
    }
    .dhead, .drow{
      display:grid;
      grid-template-columns: 1fr 96px 120px 92px; /* 제목 / 상태 / 날짜 / 삭제 */
      gap:12px;
      align-items:center;
      padding: 12px 14px;
    }
    .dhead{
      background: rgba(248,250,252,.92);
      border-bottom:1px solid rgba(203,213,225,.65);
      font-weight: 950;
      color:#0f172a;
    }
    .drow{
      border-bottom:1px solid rgba(203,213,225,.55);
      font-weight: 850;
      color:#0f172a;
      min-height: 58px;
    }
    .drow:hover{ background:#fbfdff; }
    .drow:last-child{ border-bottom:0; }

    .dtitle{
      overflow:hidden;
      text-overflow: ellipsis;
      white-space:nowrap;
      padding-right: 6px;
    }
    .dstatus{ display:flex; justify-content:center; }
    .ddate{
      font-size:12px;
      font-weight:950;
      color:#475569;
      text-align:center;
      font-variant-numeric: tabular-nums;
    }
    .ddel{ display:flex; justify-content:flex-end; }

    .status-pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      height:26px;
      padding:0 10px;
      border-radius:999px;
      font-weight:950;
      font-size:12px;
      border:1px solid rgba(203,213,225,.9);
      background:#fff;
      color:#0f172a;
      width: fit-content;
      white-space:nowrap;
    }
    .status-assigned{ background:#eef2ff; border-color: rgba(43,97,209,.22); color:#1d4ed8; }
    .status-in_progress{ background:#fff7ed; border-color: rgba(251,146,60,.35); color:#9a3412; }
    .status-pending{ background:#fefce8; border-color: rgba(234,179,8,.35); color:#854d0e; }
    .status-approved{ background:#ecfdf5; border-color: rgba(16,185,129,.28); color:#065f46; }

    .d-delbtn{
      height:34px;
      padding:0 14px;
      border-radius: 12px;
      font-weight: 950;
    }

    .dempty{
      padding: 28px 16px;
      text-align:center;
      color:#64748b;
      font-weight:950;
      background:#fff;
      border:1px dashed rgba(203,213,225,.85);
      border-radius: 14px;
    }

    /* ✅ 삭제 모달 페이지네이션(하단 우측 / 7개 이상이면 …) */
    .dpager{
      margin-top: 12px;
      display:flex;
      justify-content:flex-end;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }
    .dpager button{
      border:1px solid rgba(203,213,225,.9);
      background:#fff;
      color:#0f172a;
      border-radius: 12px;
      height: 40px;
      padding: 0 12px;
      font-weight: 950;
      cursor:pointer;
      min-width: 44px;
    }
    .dpager button:hover{ background:#f8fafc; }
    .dpager .active{
      background:#eef2ff;
      border-color: rgba(43,97,209,.28);
      color:#1d4ed8;
      cursor: default;
    }
    .dpager .muted{ opacity:.55; cursor:not-allowed; }

    /* ✅ 사진 추가 UI */
    .photo-zone{
      border: 1px dashed rgba(203,213,225,.95);
      background:#fff;
      border-radius: 12px;
      padding: 12px;
    }
    .photo-row{
      display:flex;
      gap: 10px;
      flex-wrap:wrap;
      align-items:center;
    }
    .thumb{
      width: 118px;
      height: 78px;
      border-radius: 10px;
      border: 1px solid rgba(203,213,225,.9);
      object-fit: cover;
      background:#f1f5ff;
      cursor: zoom-in;
    }
    .add-tile{
      width: 118px;
      height: 78px;
      border-radius: 10px;
      border: 1px dashed rgba(203,213,225,.95);
      background: #f8fafc;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap: 6px;
      cursor:pointer;
      user-select:none;
      text-align:center;
    }
    .plus{
      width: 34px;
      height: 34px;
      border-radius: 10px;
      background: rgba(43,97,209,.10);
      border: 1px solid rgba(43,97,209,.18);
      display:grid;
      place-items:center;
      color:#1d4ed8;
      font-weight: 950;
      font-size: 22px;
      line-height: 1;
    }
    .add-text{
      font-size: 11px;
      font-weight: 900;
      color:#64748b;
      padding: 0 8px;
      line-height: 1.2;
    }
    .hidden-file{ display:none; }

    .form-grid{display:grid;grid-template-columns: 1fr;gap: 12px;}
    .flabel{font-weight: 950;color:#0f172a;font-size: 13px;margin: 0 0 6px;}
    .fctrl{
      width:100%;height: 44px;border: 1px solid rgba(203,213,225,.9);
      border-radius: 10px;padding: 0 12px;outline: none;background:#fff;font-weight: 800;
    }
    textarea.fctrl{height: 140px;padding: 10px 12px;resize: vertical;}

    @media (max-width:980px){
      .task-grid{ grid-template-columns: repeat(2, 1fr); }
      .tabs{ grid-template-columns: repeat(2, 1fr); }
      .dhead, .drow{ grid-template-columns: 1fr 92px 110px 86px; }
    }
    @media (max-width:640px){
      .dhead, .drow{ grid-template-columns: 1fr 84px 110px 80px; gap:10px; }
    }
    @media (max-width:520px){
      .page-header{flex-direction:column; align-items:flex-start;}
      .task-grid{ grid-template-columns: 1fr; }
      .tabs{ grid-template-columns: 1fr; }
      .pimg{ width: 100%; height: auto; }

      .dhead{ display:none; }
      .drow{ grid-template-columns: 1fr; gap:10px; }
      .dstatus{ justify-content:flex-start; }
      .ddate{ text-align:left; }
      .ddel{ justify-content:flex-start; }
      .d-delbtn{ width:100%; justify-content:center; }

      .pager{ justify-content:center; }
      .dpager{ justify-content:center; }
    }
  </style>
</head>

<body>
  <div class="page">
    <div class="page-header">
      <h1>작업 관리 시스템</h1>

      <?php if ($isAdmin): ?>
        <div class="header-actions">
          <button class="btn primary" type="button" id="openCreateModal">＋ 등록</button>
          <button class="btn danger" type="button" id="openDeleteModal">🗑 삭제</button>
        </div>
      <?php endif; ?>
    </div>

    <section class="dash">
      <div class="dash-topbar">
        <div class="title">작업 대시보드</div>

        <div class="user-dropdown" id="userDropdown">
          <button class="user-trigger" type="button" id="userTrigger" aria-haspopup="true" aria-expanded="false">
            <?= $userNameSafe ?>님 안녕하세요!
            <span class="caret" aria-hidden="true"></span>
          </button>

          <div class="dropdown-panel" id="dropdownPanel" role="menu" aria-label="사용자 메뉴">
            <a class="dropdown-link" href="logout.php" onclick="event.preventDefault(); location.replace('logout.php');">
              로그아웃
            </a>
          </div>
        </div>
      </div>

      <div class="dash-body">

        <?php if ($errMsg !== ""): ?>
          <div class="alert">⚠ <?= $errMsg ?></div>
        <?php endif; ?>

        <!-- ✅ 상태 버튼 메뉴 -->
        <div class="tabs">
          <button class="tabbtn <?= $tab==='assigned'?'active':'' ?>" type="button" onclick="location.href='dashboard.php?<?= h(build_qs(["tab"=>"assigned","page"=>null])) ?>'">
            <span>할당</span> <span class="tabbadge"><?= (int)$counts["assigned"] ?></span>
          </button>
          <button class="tabbtn <?= $tab==='in_progress'?'active':'' ?>" type="button" onclick="location.href='dashboard.php?<?= h(build_qs(["tab"=>"in_progress","page"=>null])) ?>'">
            <span>진행 중</span> <span class="tabbadge"><?= (int)$counts["in_progress"] ?></span>
          </button>
          <button class="tabbtn <?= $tab==='pending'?'active':'' ?>" type="button" onclick="location.href='dashboard.php?<?= h(build_qs(["tab"=>"pending","page"=>null])) ?>'">
            <span>승인 대기</span> <span class="tabbadge"><?= (int)$counts["pending"] ?></span>
          </button>
          <button class="tabbtn <?= $tab==='approved'?'active':'' ?>" type="button" onclick="location.href='dashboard.php?<?= h(build_qs(["tab"=>"approved","page"=>null])) ?>'">
            <span>승인 완료</span> <span class="tabbadge"><?= (int)$counts["approved"] ?></span>
          </button>
        </div>

        <!-- ✅ 주간 바 -->
        <div class="weekbar" data-view="<?= h($view) ?>" data-ws="<?= h($ws) ?>">
          <div class="range">
            <button class="nav" type="button" id="prevWeek" aria-label="이전 주">‹</button>
            <div>
              <div class="week-date" id="weekRangeText"><?= h($from) ?> ~ <?= h($to) ?></div>
            </div>
            <button class="nav" type="button" id="nextWeek" aria-label="다음 주">›</button>
          </div>

          <!-- ✅ "주간 모드/전체 모드" 표시 제거, 버튼은 유지 -->
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <span class="tab-count" id="tabCount">
              현재 탭 표시: <b><?= (int)$totalRows ?></b>건
            </span>

            <?php if ($isWeekView): ?>
              <button class="btn ghost" type="button" id="toggleView">전체</button>
            <?php else: ?>
              <button class="btn ghost" type="button" id="toggleView">주간</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="cards">
          <?php if (empty($tasks)) : ?>
            <div class="empty">등록된 작업이 없습니다.</div>
          <?php else: ?>
            <div class="task-grid">
              <?php foreach ($tasks as $t):
                $id    = (int)$t["id"];
                $title = h($t["title"] ?? "");
                $date  = h($t["task_date"] ?? "");

                $adminPhotos  = json_arr($t["admin_photos"] ?? "");
                $workerPhotos = json_arr($t["worker_photos"] ?? "");

                $thumb = first_photo($adminPhotos);
                if ($thumb === "") $thumb = first_photo($workerPhotos);
                if ($thumb === "" && !empty($t["photo_url"])) $thumb = (string)$t["photo_url"];

                if ($thumb === "") {
                  $thumb = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='450'%3E%3Crect width='100%25' height='100%25' fill='%23eef3ff'/%3E%3Ctext x='50%25' y='50%25' font-size='28' text-anchor='middle' fill='%2394a3b8' font-family='Arial'%3ENo Image%3C/text%3E%3C/svg%3E";
                }

                $rejectedFlag = (int)($t["rejected_flag"] ?? 0);
                $rejectedAt   = h($t["rejected_at"] ?? "");
              ?>
                <article class="task-card">
                  <div class="task-head">
                    <div class="task-title"><?= $title ?></div>
                    <div class="task-date"><?= $date ?></div>
                  </div>

                  <img class="task-thumb"
                      src="<?= h($thumb) ?>"
                      alt=""
                      onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22800%22 height=%22450%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23eef3ff%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2228%22 text-anchor=%22middle%22 fill=%22%2394a3b8%22 font-family=%22Arial%22%3ENo Image%3C/text%3E%3C/svg%3E';">

                  <div class="task-actions">
                    <?php if ($tab === "assigned"): ?>
                      <button class="task-btn" type="button" onclick="openTaskModal('assigned', <?= $id ?>)">확인</button>

                    <?php elseif ($tab === "in_progress"): ?>
                      <button class="task-btn" type="button" onclick="openTaskModal('in_progress', <?= $id ?>)">작업 결과</button>
                      <div class="reject-pill <?= $rejectedFlag ? 'show':'' ?>">
                        <span class="dot"></span>
                        반려<?= $rejectedAt ? "됨" : "" ?>
                      </div>

                    <?php elseif ($tab === "pending"): ?>
                      <?php if ($isAdmin): ?>
                        <button class="task-btn" type="button" onclick="openTaskModal('pending_admin', <?= $id ?>)">확인</button>
                      <?php else: ?>
                        <button class="task-btn" type="button" onclick="openTaskModal('pending_worker', <?= $id ?>)">내용 확인</button>
                      <?php endif; ?>

                    <?php else: ?>
                      <button class="task-btn" type="button" onclick="openTaskModal('approved', <?= $id ?>)">내용 확인</button>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>

            <!-- ✅ 메인 페이지네이션 (2/2 표시 제거 + 7개 이상이면 … 처리) -->
            <?php if ($totalPages > 1): ?>
              <div class="pager" aria-label="페이지네이션">
                <?php
                  $prevPage = $page - 1;
                  $nextPage = $page + 1;

                  $prevHref = "dashboard.php?" . build_qs(["page"=>$prevPage]);
                  $nextHref = "dashboard.php?" . build_qs(["page"=>$nextPage]);

                  // ✅ 7페이지 이하면 전부 표시 / 8페이지 이상이면 1 … (중간) … 마지막
                  if ($totalPages <= 7) {
                    $start = 1;
                    $end   = $totalPages;
                  } else {
                    $start = max(2, $page - 2);
                    $end   = min($totalPages - 1, $page + 2);

                    if ($page <= 4) { $start = 2; $end = 5; }
                    if ($page >= $totalPages - 3) { $start = $totalPages - 4; $end = $totalPages - 1; }
                  }
                ?>

                <?php if ($page <= 1): ?>
                  <span class="muted">&lt;</span>
                <?php else: ?>
                  <a href="<?= h($prevHref) ?>" aria-label="이전 페이지">&lt;</a>
                <?php endif; ?>

                <?php if ($totalPages <= 7): ?>
                  <?php for ($p=1; $p<=$totalPages; $p++): ?>
                    <?php if ($p === $page): ?>
                      <span class="active"><?= (int)$p ?></span>
                    <?php else: ?>
                      <a href="<?= h("dashboard.php?".build_qs(["page"=>$p])) ?>"><?= (int)$p ?></a>
                    <?php endif; ?>
                  <?php endfor; ?>
                <?php else: ?>
                  <!-- 1 -->
                  <?php if ($page === 1): ?>
                    <span class="active">1</span>
                  <?php else: ?>
                    <a href="<?= h("dashboard.php?".build_qs(["page"=>1])) ?>">1</a>
                  <?php endif; ?>

                  <!-- … left -->
                  <?php if ($start > 2): ?><span class="info">…</span><?php endif; ?>

                  <!-- middle -->
                  <?php for ($p=$start; $p<=$end; $p++): ?>
                    <?php if ($p === $page): ?>
                      <span class="active"><?= (int)$p ?></span>
                    <?php else: ?>
                      <a href="<?= h("dashboard.php?".build_qs(["page"=>$p])) ?>"><?= (int)$p ?></a>
                    <?php endif; ?>
                  <?php endfor; ?>

                  <!-- … right -->
                  <?php if ($end < $totalPages - 1): ?><span class="info">…</span><?php endif; ?>

                  <!-- last -->
                  <?php if ($page === $totalPages): ?>
                    <span class="active"><?= (int)$totalPages ?></span>
                  <?php else: ?>
                    <a href="<?= h("dashboard.php?".build_qs(["page"=>$totalPages])) ?>"><?= (int)$totalPages ?></a>
                  <?php endif; ?>
                <?php endif; ?>

                <?php if ($page >= $totalPages): ?>
                  <span class="muted">&gt;</span>
                <?php else: ?>
                  <a href="<?= h($nextHref) ?>" aria-label="다음 페이지">&gt;</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <!-- ✅ 공용 모달 -->
  <div class="modal-backdrop" id="backdrop" aria-hidden="true"></div>
  <div class="modal" id="modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-head">
        <div class="modal-title" id="modalTitle">상세</div>
        <button class="modal-close" type="button" onclick="closeModal()" aria-label="닫기">×</button>
      </div>
      <div class="modal-body" id="modalBody"></div>
      <div class="modal-foot" id="modalFoot"></div>
    </div>
  </div>

  <!-- ✅ 관리자 등록 모달 -->
  <?php if ($isAdmin): ?>
    <div class="modal-backdrop" id="createBackdrop" aria-hidden="true"></div>
    <div class="modal" id="createModal" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="modal-card">
        <div class="modal-head">
          <div class="modal-title">작업 등록 (할당)</div>
          <button class="modal-close" type="button" onclick="closeCreateModal()" aria-label="닫기">×</button>
        </div>

        <form class="modal-body" method="post" action="dashboard.php" enctype="multipart/form-data" id="createForm">
          <input type="hidden" name="action" value="create_task">

          <div class="form-grid">
            <div>
              <div class="flabel">작업 제목</div>
              <input class="fctrl" type="text" name="title" placeholder="예) 기초 공사" required>
            </div>

            <div>
              <div class="flabel">작업 설명</div>
              <textarea class="fctrl" name="description" placeholder="작업 내용을 입력하세요" required></textarea>
            </div>

            <div>
              <div class="flabel">작업 일자</div>
              <input class="fctrl" type="text" name="task_date" id="createTaskDate" placeholder="YYYY-MM-DD" inputmode="numeric" maxlength="10" required>
              <div class="tiny" style="margin-top:6px;">* 예: 2026-01-21</div>
            </div>

            <div>
              <div class="flabel">현장 사진</div>
              <div class="photo-zone" id="adminDropZone">
                <div class="photo-row" id="adminThumbRow">
                  <label class="add-tile" for="adminPhotos" title="사진 추가">
                    <div class="plus">＋</div>
                    <div class="add-text">사진 추가 및<br>드래그 앤 드롭</div>
                  </label>
                  <input class="hidden-file" type="file" id="adminPhotos" name="admin_photos[]" accept="image/*" multiple>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-foot">
            <button class="btn2 primary" type="submit">등록(할당)</button>
            <button class="btn2 ghost" type="button" onclick="closeCreateModal()">취소</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ✅ 관리자 삭제(리스트) 모달 -->
    <div class="modal-backdrop" id="deleteBackdrop" aria-hidden="true"></div>
    <div class="modal" id="deleteModal" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="modal-card">
        <div class="modal-head">
          <div class="modal-title">
            작업 삭제
            <span class="tiny" id="deleteCountBadge"></span>
          </div>
          <button class="modal-close" type="button" onclick="closeDeleteModal()" aria-label="닫기">×</button>
        </div>
        <div class="modal-body" id="deleteBody"></div>
        <div class="modal-foot">
          <button class="btn2 ghost" type="button" onclick="closeDeleteModal()">닫기</button>
        </div>
      </div>
    </div>

    <!-- ✅ 삭제 확인 모달 -->
    <div class="modal-backdrop" id="confirmBackdrop" aria-hidden="true" style="z-index:1100;"></div>
    <div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-hidden="true" style="z-index:1200;">
      <div class="modal-card small">
        <div class="modal-head">
          <div class="modal-title">삭제 확인</div>
          <button class="modal-close" type="button" onclick="closeConfirmDelete()" aria-label="닫기">×</button>
        </div>
        <div class="modal-body" id="confirmBody"></div>
        <div class="modal-foot">
          <button class="btn2 danger" type="button" id="confirmDeleteBtn">삭제</button>
          <button class="btn2 ghost" type="button" onclick="closeConfirmDelete()">취소</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ✅ 라이트박스 -->
  <div class="lightbox" id="lightbox" aria-hidden="true">
    <div class="lightbox-inner">
      <div class="lightbox-bar">
        <button class="lb-btn" type="button" id="lbCloseBtn">닫기 (Esc)</button>
      </div>
      <img class="lightbox-img" id="lbImg" src="" alt="">
    </div>
  </div>

  <script>
    // ===== 드롭다운 =====
    (function(){
      const trigger = document.getElementById('userTrigger');
      const panel   = document.getElementById('dropdownPanel');
      const wrap    = document.getElementById('userDropdown');
      function open(){ panel.classList.add('open'); trigger.setAttribute('aria-expanded', 'true'); }
      function close(){ panel.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); }
      function toggle(){ panel.classList.contains('open') ? close() : open(); }
      trigger?.addEventListener('click', (e)=>{ e.preventDefault(); toggle(); });
      document.addEventListener('click', (e)=>{ if (wrap && !wrap.contains(e.target)) close(); });
      document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') close(); });
    })();

    function escapeHtml(s){
      return String(s)
        .replaceAll("&","&amp;")
        .replaceAll("<","&lt;")
        .replaceAll(">","&gt;")
        .replaceAll('"',"&quot;")
        .replaceAll("'","&#039;");
    }

    // ===== 주간 바 =====
    (function(){
      const weekbar = document.querySelector(".weekbar");
      const prev = document.getElementById("prevWeek");
      const next = document.getElementById("nextWeek");
      const toggle = document.getElementById("toggleView");
      if (!weekbar) return;

      const initView = String(weekbar.dataset.view || "week"); // week|all
      const initWs   = String(weekbar.dataset.ws || "");

      function addDays(ymd, days){
        const [y,m,d] = ymd.split("-").map(Number);
        const dt = new Date(y, m-1, d);
        dt.setDate(dt.getDate() + days);
        const yy = dt.getFullYear();
        const mm = String(dt.getMonth()+1).padStart(2,"0");
        const dd = String(dt.getDate()).padStart(2,"0");
        return `${yy}-${mm}-${dd}`;
      }

      function getParams(){
        const u = new URL(location.href);
        return u.searchParams;
      }

      function setUrl(params){
        location.href = location.pathname + "?" + params.toString();
      }

      function ensureBase(params){
        if (!params.get("tab")) params.set("tab", "<?= h($tab) ?>");

        if (!params.get("view")) {
          const wf = params.get("wf");
          if (wf === "0") params.set("view","all");
          else if (wf === "1") params.set("view","week");
          else params.set("view", initView || "week");
        }

        if (!params.get("ws")) params.set("ws", initWs || "<?= h($ws) ?>");

        params.delete("wf");
        return params;
      }

      function currentWs(){
        const p = getParams();
        const ws = p.get("ws");
        if (ws && /^\d{4}-\d{2}-\d{2}$/.test(ws)) return ws;
        return initWs || "<?= h($ws) ?>";
      }

      function setWeek(ws){
        const p = ensureBase(getParams());
        p.set("ws", ws);
        p.delete("page");
        setUrl(p);
      }

      function toggleView(){
        const p = ensureBase(getParams());
        const v = p.get("view") === "all" ? "all" : "week";
        p.set("view", v === "week" ? "all" : "week");
        p.delete("page");
        setUrl(p);
      }

      prev?.addEventListener("click", ()=> setWeek(addDays(currentWs(), -7)));
      next?.addEventListener("click", ()=> setWeek(addDays(currentWs(), +7)));
      toggle?.addEventListener("click", toggleView);
    })();

    // ===== 관리자 등록 모달 =====
    (function(){
      const btn = document.getElementById("openCreateModal");
      const m = document.getElementById("createModal");
      const b = document.getElementById("createBackdrop");

      window.openCreateModal = function(){
        b?.classList.add("show");
        m?.classList.add("show");
        m?.setAttribute("aria-hidden","false");
      }
      window.closeCreateModal = function(){
        b?.classList.remove("show");
        m?.classList.remove("show");
        m?.setAttribute("aria-hidden","true");
      }

      btn?.addEventListener("click", openCreateModal);
      b?.addEventListener("click", closeCreateModal);

      const dateEl = document.getElementById("createTaskDate");
      dateEl?.addEventListener("input", ()=>{
        let v = (dateEl.value || "").replace(/\D/g,"").slice(0,8);
        if (v.length >= 5) v = v.slice(0,4)+"-"+v.slice(4);
        if (v.length >= 8) v = v.slice(0,7)+"-"+v.slice(7);
        dateEl.value = v;
      });
    })();

    // ===== 사진 추가 UI(썸네일 + DnD) =====
    function bindPhotoZone(dropZone, fileEl, thumbRow){
      if (!dropZone || !fileEl || !thumbRow) return;

      let previewUrls = [];

      function clearThumbs(){
        previewUrls.forEach(u => URL.revokeObjectURL(u));
        previewUrls = [];
        thumbRow.querySelectorAll('.thumb[data-gen="1"]').forEach(el => el.remove());
      }

      function addThumb(file){
        const url = URL.createObjectURL(file);
        previewUrls.push(url);
        const img = document.createElement("img");
        img.className = "thumb";
        img.alt = "첨부 사진";
        img.src = url;
        img.dataset.gen = "1";
        thumbRow.insertBefore(img, thumbRow.firstChild);
      }

      function handleFiles(files){
        if (!files || files.length === 0) return;
        clearThumbs();
        [...files].slice(0, 8).forEach(addThumb);
      }

      fileEl.addEventListener("change", (e)=> handleFiles(e.target.files));

      ['dragenter','dragover'].forEach(evt=>{
        dropZone.addEventListener(evt, (e)=>{
          e.preventDefault(); e.stopPropagation();
          dropZone.style.borderColor = 'rgba(43,97,209,.55)';
        });
      });
      ['dragleave','drop'].forEach(evt=>{
        dropZone.addEventListener(evt, (e)=>{
          e.preventDefault(); e.stopPropagation();
          dropZone.style.borderColor = '';
        });
      });
      dropZone.addEventListener("drop", (e)=>{
        const files = e.dataTransfer.files;
        try { fileEl.files = files; } catch(_) {}
        handleFiles(files);
      });
    }

    (function(){
      const dz = document.getElementById("adminDropZone");
      const fe = document.getElementById("adminPhotos");
      const tr = document.getElementById("adminThumbRow");
      bindPhotoZone(dz, fe, tr);
    })();

    // ===== 공용 모달 =====
    const TASKS = <?= json_encode($tasksForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const IS_ADMIN = <?= $isAdmin ? "true" : "false" ?>;

    const modal = document.getElementById("modal");
    const backdrop = document.getElementById("backdrop");
    const modalTitle = document.getElementById("modalTitle");
    const modalBody = document.getElementById("modalBody");
    const modalFoot = document.getElementById("modalFoot");

    function showModal(){
      backdrop.classList.add("show");
      modal.classList.add("show");
      modal.setAttribute("aria-hidden","false");
    }
    function closeModal(){
      backdrop.classList.remove("show");
      modal.classList.remove("show");
      modal.setAttribute("aria-hidden","true");
      modalBody.innerHTML = "";
      modalFoot.innerHTML = "";
    }
    window.closeModal = closeModal;

    backdrop.addEventListener("click", closeModal);
    document.addEventListener("keydown", (e)=>{ if (e.key === "Escape") closeModal(); });

    function renderPhotos(arr){
      if (!arr || arr.length === 0) return ``;
      return `
        <div class="photos">
          ${arr.map(src => `<img class="pimg" src="${escapeHtml(src)}" alt="" onerror="this.style.display='none'">`).join("")}
        </div>
      `;
    }

    function postForm(action, taskId, extra = {}){
      const f = document.createElement("form");
      f.method = "post";
      f.action = "dashboard.php";

      const a = document.createElement("input");
      a.type="hidden"; a.name="action"; a.value=action;
      f.appendChild(a);

      const id = document.createElement("input");
      id.type="hidden"; id.name="task_id"; id.value=taskId;
      f.appendChild(id);

      // ✅ 삭제 후 복귀용(탭/보기모드/주/페이지 유지)
      const bt = document.createElement("input");
      bt.type="hidden"; bt.name="back_tab"; bt.value="<?= h($tab) ?>";
      f.appendChild(bt);

      const bv = document.createElement("input");
      bv.type="hidden"; bv.name="back_view"; bv.value="<?= h($view) ?>";
      f.appendChild(bv);

      const bws = document.createElement("input");
      bws.type="hidden"; bws.name="back_ws"; bws.value="<?= h($ws) ?>";
      f.appendChild(bws);

      const bp = document.createElement("input");
      bp.type="hidden"; bp.name="back_page"; bp.value="<?= (int)$page ?>";
      f.appendChild(bp);

      Object.keys(extra).forEach(k=>{
        const i = document.createElement("input");
        i.type="hidden"; i.name=k; i.value=extra[k];
        f.appendChild(i);
      });

      document.body.appendChild(f);
      f.submit();
    }

    window.openTaskModal = function(mode, id){
      const t = TASKS[id];
      if (!t) return;

      modalTitle.textContent = t.title + " (" + t.task_date + ")";
      modalBody.innerHTML = "";
      modalFoot.innerHTML = "";

      const adminSection = `
        <div class="section">
          <div class="section-title">
            <span>관리자 등록 내용</span>
            <span class="tiny">현장 사진 + 작업 설명</span>
          </div>

          <div class="split">
            <div class="textbox">
              <div class="tiny">작업 설명</div>
              <div class="desc">${escapeHtml(t.description || "")}</div>
            </div>

            <div class="photobox">
              <div class="tiny">현장 사진</div>
              ${renderPhotos(t.admin_photos || [])}
            </div>
          </div>
        </div>
      `;

      if (mode === "assigned") {
        modalBody.innerHTML = adminSection;
        modalFoot.innerHTML = `
          <button class="btn2 primary" type="button" onclick="postForm('start_task', ${id})">확인</button>
          <button class="btn2 ghost" type="button" onclick="closeModal()">닫기</button>
        `;
        showModal();
        return;
      }

      if (mode === "in_progress") {
        modalBody.innerHTML = `
          ${adminSection}

          <div class="section">
            <div class="section-title">
              <span>작업자 결과 작성</span>
              <span class="tiny">작업 결과 사진 + 내용 작성</span>
            </div>

            <form id="resultForm" method="post" action="dashboard.php" enctype="multipart/form-data">
              <input type="hidden" name="action" value="submit_result">
              <input type="hidden" name="task_id" value="${id}">

              <div class="form-grid">
                <div>
                  <div class="flabel">작업 결과 내용</div>
                  <textarea class="fctrl" name="worker_description" placeholder="예) 철근 배근 완료, 안전 조치 완료 등" required>${escapeHtml(t.worker_description || "")}</textarea>
                </div>

                <div>
                  <div class="flabel">결과 사진</div>
                  <div class="photo-zone" id="workerDropZone">
                    <div class="photo-row" id="workerThumbRow">
                      <label class="add-tile" for="workerPhotos" title="사진 추가">
                        <div class="plus">＋</div>
                        <div class="add-text">사진 추가 및<br>드래그 앤 드롭</div>
                      </label>
                      <input class="hidden-file" type="file" id="workerPhotos" name="worker_photos[]" accept="image/*" multiple>
                    </div>
                  </div>
                </div>

                <div>
                  ${renderPhotos(t.worker_photos || [])}
                </div>
              </div>
            </form>
          </div>
        `;

        modalFoot.innerHTML = `
          <button class="btn2 primary" type="button" onclick="document.getElementById('resultForm').requestSubmit()">결과 제출</button>
          <button class="btn2 ghost" type="button" onclick="closeModal()">닫기</button>
        `;

        showModal();

        const dz = document.getElementById("workerDropZone");
        const fe = document.getElementById("workerPhotos");
        const tr = document.getElementById("workerThumbRow");
        bindPhotoZone(dz, fe, tr);

        return;
      }

      const workerSection = `
        <div class="section">
          <div class="section-title">
            <span>작업자 제출 내용</span>
            <span class="tiny">작업 결과 사진 + 내용</span>
          </div>

          <div class="split">
            <div class="textbox">
              <div class="tiny">작업 결과 내용</div>
              <div class="desc">${escapeHtml(t.worker_description || "")}</div>
            </div>

            <div class="photobox">
              <div class="tiny">결과 사진</div>
              ${renderPhotos(t.worker_photos || [])}
            </div>
          </div>
        </div>
      `;

      if (mode === "pending_admin") {
        modalBody.innerHTML = adminSection + workerSection;
        modalFoot.innerHTML = `
          <button class="btn2 primary" type="button" onclick="postForm('review_task', ${id}, {decision:'approve'})">승인</button>
          <button class="btn2 danger" type="button" onclick="postForm('review_task', ${id}, {decision:'reject'})">반려 → 진행 중</button>
          <button class="btn2 ghost" type="button" onclick="closeModal()">닫기</button>
        `;
        showModal();
        return;
      }

      if (mode === "pending_worker") {
        modalBody.innerHTML = adminSection + workerSection;
        modalFoot.innerHTML = `<button class="btn2 ghost" type="button" onclick="closeModal()">닫기</button>`;
        showModal();
        return;
      }

      if (mode === "approved") {
        modalBody.innerHTML = adminSection + workerSection;
        modalFoot.innerHTML = `<button class="btn2 ghost" type="button" onclick="closeModal()">닫기</button>`;
        showModal();
        return;
      }
    };

    // ✅ 뒤로가기 캐시 복원 방지
    window.addEventListener('pageshow', function(e){
      if (e.persisted) location.reload();
    });

    // ✅ 라이트박스(사진 클릭 확대)
    (function(){
      const lb = document.getElementById("lightbox");
      const lbImg = document.getElementById("lbImg");
      const closeBtn = document.getElementById("lbCloseBtn");

      function open(src){
        if (!src) return;
        lbImg.src = src;
        lb.classList.add("show");
        lb.setAttribute("aria-hidden", "false");
      }

      function close(){
        lb.classList.remove("show");
        lb.setAttribute("aria-hidden", "true");
        lbImg.src = "";
      }

      document.addEventListener("click", (e)=>{
        const img = e.target.closest("img");
        if (!img) return;

        const ok =
          img.classList.contains("pimg") ||
          img.classList.contains("task-thumb") ||
          img.classList.contains("thumb");

        if (!ok) return;

        const src = img.getAttribute("data-url") || img.currentSrc || img.src;
        open(src);
      });

      closeBtn?.addEventListener("click", close);

      lb?.addEventListener("click", (e)=>{
        if (e.target === lb) close();
      });

      document.addEventListener("keydown", (e)=>{
        if (e.key === "Escape") {
          if (lb.classList.contains("show")) close();
        }
      });
    })();

    /* =========================
       ✅ 관리자 삭제 모달 + 확인 모달 + (모달 하단 페이지네이션)
       - 2/2 같은 표시는 제거
       - 페이지 버튼 7개 이상일 때 … 처리
       - "· 현재 1~1번째" 문구 제거
       ========================= */
    const DELETE_TASKS = <?= json_encode($deleteCandidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function statusLabel(s){
      if (s === "assigned") return {t:"할당", cls:"status-assigned"};
      if (s === "in_progress") return {t:"진행 중", cls:"status-in_progress"};
      if (s === "pending") return {t:"승인 대기", cls:"status-pending"};
      if (s === "approved") return {t:"승인 완료", cls:"status-approved"};
      return {t:s, cls:""};
    }

    const deleteBtn = document.getElementById("openDeleteModal");
    const deleteModal = document.getElementById("deleteModal");
    const deleteBackdrop = document.getElementById("deleteBackdrop");
    const deleteBody = document.getElementById("deleteBody");
    const deleteCountBadge = document.getElementById("deleteCountBadge");

    let DELETE_PAGE = 1;
    const DELETE_PER_PAGE = 12;

    window.openDeleteModal = function(){
      if (!IS_ADMIN) return;

      deleteBackdrop?.classList.add("show");
      deleteModal?.classList.add("show");
      deleteModal?.setAttribute("aria-hidden","false");

      if (deleteCountBadge) {
        const total = (DELETE_TASKS && DELETE_TASKS.length) ? DELETE_TASKS.length : 0;
        deleteCountBadge.textContent = total ? `(총 ${total}건)` : ``;
      }

      DELETE_PAGE = 1;
      renderDeleteList();
    }
    window.closeDeleteModal = function(){
      deleteBackdrop?.classList.remove("show");
      deleteModal?.classList.remove("show");
      deleteModal?.setAttribute("aria-hidden","true");
      if (deleteBody) deleteBody.innerHTML = "";
    }

    deleteBtn?.addEventListener("click", openDeleteModal);
    deleteBackdrop?.addEventListener("click", closeDeleteModal);

    function renderDeletePager(total){
      const totalPages = Math.max(1, Math.ceil(total / DELETE_PER_PAGE));
      if (totalPages <= 1) return ``;

      const prevDisabled = DELETE_PAGE <= 1;
      const nextDisabled = DELETE_PAGE >= totalPages;

      // ✅ 7페이지 이하면 전부 표시 / 8페이지 이상이면 1 … (중간) … 마지막
      let buttons = [];

      if (totalPages <= 7) {
        for (let p=1; p<=totalPages; p++){
          if (p === DELETE_PAGE) buttons.push(`<button type="button" class="active" disabled>${p}</button>`);
          else buttons.push(`<button type="button" onclick="gotoDeletePage(${p})">${p}</button>`);
        }
      } else {
        const page = DELETE_PAGE;

        // 1
        if (page === 1) buttons.push(`<button type="button" class="active" disabled>1</button>`);
        else buttons.push(`<button type="button" onclick="gotoDeletePage(1)">1</button>`);

        // middle range
        let start = Math.max(2, page - 2);
        let end   = Math.min(totalPages - 1, page + 2);

        if (page <= 4) { start = 2; end = 5; }
        if (page >= totalPages - 3) { start = totalPages - 4; end = totalPages - 1; }

        if (start > 2) buttons.push(`<button type="button" class="muted" disabled>…</button>`);

        for (let p=start; p<=end; p++){
          if (p === page) buttons.push(`<button type="button" class="active" disabled>${p}</button>`);
          else buttons.push(`<button type="button" onclick="gotoDeletePage(${p})">${p}</button>`);
        }

        if (end < totalPages - 1) buttons.push(`<button type="button" class="muted" disabled>…</button>`);

        // last
        if (page === totalPages) buttons.push(`<button type="button" class="active" disabled>${totalPages}</button>`);
        else buttons.push(`<button type="button" onclick="gotoDeletePage(${totalPages})">${totalPages}</button>`);
      }

      return `
        <div class="dpager">
          <button type="button" class="${prevDisabled ? "muted" : ""}" ${prevDisabled ? "disabled" : ""} onclick="gotoDeletePage(${Math.max(1, DELETE_PAGE-1)})">&lt;</button>
          ${buttons.join("")}
          <button type="button" class="${nextDisabled ? "muted" : ""}" ${nextDisabled ? "disabled" : ""} onclick="gotoDeletePage(${Math.min(totalPages, DELETE_PAGE+1)})">&gt;</button>
        </div>
      `;
    }

    window.gotoDeletePage = function(p){
      DELETE_PAGE = Number(p || 1);
      if (DELETE_PAGE < 1) DELETE_PAGE = 1;
      renderDeleteList();
    }

    function renderDeleteList(){
      if (!deleteBody) return;

      if (!DELETE_TASKS || DELETE_TASKS.length === 0) {
        deleteBody.innerHTML = `<div class="dempty">삭제 가능한 작업이 없습니다.</div>`;
        return;
      }

      const total = DELETE_TASKS.length;
      const totalPages = Math.max(1, Math.ceil(total / DELETE_PER_PAGE));
      if (DELETE_PAGE > totalPages) DELETE_PAGE = totalPages;

      const startIdx = (DELETE_PAGE - 1) * DELETE_PER_PAGE;
      const pageItems = DELETE_TASKS.slice(startIdx, startIdx + DELETE_PER_PAGE);

      const rows = pageItems.map(r=>{
        const id = Number(r.id || 0);
        const title = escapeHtml(r.title || "");
        const date = escapeHtml(r.task_date || "");
        const s = String(r.status || "");
        const sl = statusLabel(s);

        return `
          <div class="drow">
            <div class="dtitle" title="${title}">${title}</div>
            <div class="dstatus"><span class="status-pill ${sl.cls}">${sl.t}</span></div>
            <div class="ddate">${date}</div>
            <div class="ddel">
              <button class="btn2 danger d-delbtn" type="button"
                onclick="openConfirmDelete(${id}, '${title}', '${sl.t}', '${date}')">삭제</button>
            </div>
          </div>
        `;
      }).join("");

      deleteBody.innerHTML = `
        <div class="tiny" style="margin-bottom:10px; line-height:1.6;">
          * 아래 목록은 <b>할당 / 진행 중 / 승인 대기 / 승인 완료</b> 작업이 모두 표시됩니다.<br>
          <b>등록 건수</b>: 총 <b>${total}</b>건 · <b>${DELETE_PER_PAGE}</b>개씩 표시
        </div>

        <div class="dlist">
          <div class="dhead">
            <div>제목</div>
            <div style="text-align:center;">상태</div>
            <div style="text-align:center;">날짜</div>
            <div style="text-align:right;">삭제</div>
          </div>
          ${rows}
        </div>

        ${renderDeletePager(total)}
      `;
    }

    const confirmModal = document.getElementById("confirmModal");
    const confirmBackdrop = document.getElementById("confirmBackdrop");
    const confirmBody = document.getElementById("confirmBody");
    const confirmDeleteBtn = document.getElementById("confirmDeleteBtn");

    let PENDING_DELETE_ID = 0;

    window.openConfirmDelete = function(id, title, statusText, date){
      PENDING_DELETE_ID = Number(id || 0);
      if (!PENDING_DELETE_ID) return;

      if (confirmBody) {
        confirmBody.innerHTML = `
          <div style="font-weight:950; color:#0f172a; margin-bottom:8px;">정말 삭제하시겠습니까?</div>
          <div class="tiny" style="line-height:1.6;">
            아래 작업이 <b>완전히 삭제</b>됩니다. (되돌릴 수 없음)
            <div style="margin-top:10px; padding:12px; border-radius:12px; border:1px solid rgba(203,213,225,.85); background:#fff;">
              <div><b>제목</b>: ${escapeHtml(title || "")}</div>
              <div style="margin-top:6px;"><b>상태</b>: ${escapeHtml(statusText || "")}</div>
              <div style="margin-top:6px;"><b>날짜</b>: ${escapeHtml(date || "")}</div>
            </div>
          </div>
        `;
      }

      confirmBackdrop?.classList.add("show");
      confirmModal?.classList.add("show");
      confirmModal?.setAttribute("aria-hidden","false");
    }

    window.closeConfirmDelete = function(){
      PENDING_DELETE_ID = 0;
      confirmBackdrop?.classList.remove("show");
      confirmModal?.classList.remove("show");
      confirmModal?.setAttribute("aria-hidden","true");
      if (confirmBody) confirmBody.innerHTML = "";
    }

    confirmBackdrop?.addEventListener("click", closeConfirmDelete);

    confirmDeleteBtn?.addEventListener("click", function(){
      if (!PENDING_DELETE_ID) return;
      postForm("delete_task", PENDING_DELETE_ID);
    });

    document.addEventListener("keydown", (e)=>{
      if (e.key === "Escape") {
        if (confirmModal?.classList.contains("show")) {
          closeConfirmDelete();
          return;
        }
        if (deleteModal?.classList.contains("show")) {
          closeDeleteModal();
        }
      }
    });
  </script>
</body>
</html>
