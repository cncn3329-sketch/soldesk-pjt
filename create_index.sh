#!/bin/bash
instance_id=$(ec2-metadata -i | cut -d ' ' -f 2)
private_ip=$(ec2-metadata -o | cut -d ' ' -f 2)
az_name=$(ec2-metadata -z | cut -d ' ' -f 2)

cat <<EOF > /var/www/html/index.html
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${private_ip} Web Server</title>
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
      color: #333;
    }

    .container {
      background: #fff;
      padding: 40px 50px;
      border-radius: 16px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
      text-align: center;
      width: 360px;
      max-width: 90%;
    }

    h1 {
      margin-bottom: 10px;
      font-size: 28px;
      color: #2d3436;
    }

    p {
      color: #636e72;
      margin-bottom: 20px;
    }

    .btn {
      display: block;
      text-decoration: none;
      margin: 10px 0;
      padding: 12px 0;
      border-radius: 8px;
      background: #0984e3;
      color: #fff;
      font-weight: 600;
      transition: 0.3s;
    }

    .btn:hover {
      background: #74b9ff;
      color: #2d3436;
    }

    .meta {
      background: #f1f2f6;
      padding: 15px;
      border-radius: 10px;
      margin-top: 10px;
      font-size: 16px;
      text-align: left;
      color: #2d3436;
      line-height: 1.6;
    }

    .meta strong {
      color: #0984e3;
    }

    footer {
      margin-top: 30px;
      font-size: 13px;
      color: #b2bec3;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Welcome!</h1>
    <div class="meta">
      <p><strong>Instance ID:</strong> ${instance_id}</p>
      <p><strong>Private IP:</strong> ${private_ip}</p>
      <p><strong>Availability Zone:</strong> ${az_name}</p>
    </div>
    <p>로그인하거나 회원가입하여 서비스를 이용하세요.</p>
    <a href="login_form.php" class="btn">로그인</a>
    <a href="register_form.php" class="btn">회원가입</a>
    <footer> &copy; 2025 NETID WEB SITE </footer>
  </div>
</body>
</html>
EOF
