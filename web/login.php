<?php
// login.php
require_once "config.php";

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    die("아이디와 비밀번호를 모두 입력하세요. <a href='login_form.php'>돌아가기</a>");
}

$stmt = $conn->prepare("SELECT id, username, password_hash FROM members WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    if (password_verify($password, $row['password_hash'])) {
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['username'] = $row['username'];
        header("Location: dashboard.php");
        exit;
    } else {
        echo "비밀번호가 틀렸습니다. <a href='login_form.php'>다시 시도</a>";
    }
} else {
    echo "존재하지 않는 아이디입니다. <a href='register_form.php'>회원가입</a>";
}

$stmt->close();
$conn->close();
?>
