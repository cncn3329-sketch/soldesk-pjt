<?php
// register_form.php
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>회원가입 | My Web App</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap');

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #74b9ff, #0984e3);
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
      color: #2d3436;
    }

    .container {
      background: #fff;
      padding: 40px 50px;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
      width: 380px;
      max-width: 90%;
      text-align: center;
    }

    h1 {
      font-size: 26px;
      margin-bottom: 20px;
    }

    form {
      display: flex;
      flex-direction: column;
      align-items: stretch;
    }

    label {
      text-align: left;
      margin-bottom: 6px;
      font-weight: 500;
      color: #636e72;
    }

    input {
      padding: 12px;
      border: 1px solid #dcdde1;
      border-radius: 8px;
      margin-bottom: 18px;
      font-size: 15px;
      transition: 0.2s;
    }

    input:focus {
      border-color: #0984e3;
      outline: none;
      box-shadow: 0 0 5px rgba(9, 132, 227, 0.3);
    }

    button {
      padding: 12px;
      background-color: #0984e3;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: 0.3s;
    }

    button:hover {
      background-color: #74b9ff;
      color: #2d3436;
    }

    .link {
      margin-top: 20px;
      font-size: 14px;
      color: #636e72;
    }

    .link a {
      color: #0984e3;
      text-decoration: none;
      font-weight: 600;
    }

    .link a:hover {
      text-decoration: underline;
    }

    footer {
      margin-top: 25px;
      font-size: 13px;
      color: #b2bec3;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>회원가입</h1>
    <form action="register.php" method="POST">
      <label for="username">아이디 (username)</label>
      <input type="text" id="username" name="username" required>

      <label for="email">이메일 (email)</label>
      <input type="email" id="email" name="email" required>

      <label for="password">비밀번호 (password)</label>
      <input type="password" id="password" name="password" required>

      <button type="submit">가입하기</button>
    </form>

    <div class="link">
      이미 계정이 있나요? <a href="login_form.php">로그인</a>
    </div>

    <footer>&copy; 2025 NETID WEB SITE</footer>
  </div>
</body>
</html>
