<?php
// ============================================================
//  index.php  –  Login page
//  If already logged in, redirect straight to dashboard
// ============================================================
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login – ListaHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #f4f5f7; --sidebar: #4a4a4a; --card: #5a5a5a;
      --input-bg: #7a7a7a; --input-icon: #a0a0a0; --logo-box: #d0d0d0;
      --btn-bg: #e8e8e8; --btn-text: #111; --link: #ccc;
      --nav-pill: #6b6b6b; --nav-pill-text: #e0e0e0;
    }
    body { font-family: 'Sora', sans-serif; background: var(--bg); min-height: 100vh; display: flex; flex-direction: column; }
    nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 32px; height: 72px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .nav-logo { background: var(--sidebar); color: #fff; font-weight: 700; font-size: 15px; padding: 10px 28px; border-radius: 50px; letter-spacing: .5px; }
    .nav-actions { display: flex; gap: 12px; }
    .nav-pill { background: var(--nav-pill); color: var(--nav-pill-text); font-size: 13px; font-weight: 600; padding: 8px 22px; border-radius: 50px; cursor: pointer; border: none; transition: background .2s; text-decoration: none; display: inline-block; }
    .nav-pill:hover { background: #555; }
    main { flex: 1; display: grid; grid-template-columns: 1fr 1fr; align-items: center; padding: 60px 80px; gap: 60px; }
    .left-content { display: flex; flex-direction: column; gap: 24px; }
    .hero-image { width: 100%; height: 130px; background: #c8c8c8; border-radius: 10px; }
    .text-lines { display: flex; flex-direction: column; gap: 12px; }
    .line { height: 18px; background: #d0d0d0; border-radius: 6px; }
    .line:nth-child(4) { width: 60%; }
    .login-card { background: var(--card); border-radius: 20px; padding: 36px 44px 40px; display: flex; flex-direction: column; gap: 20px; box-shadow: 0 8px 40px rgba(0,0,0,.18); }
    .logo-box { background: var(--logo-box); border-radius: 14px; padding: 24px; text-align: center; }
    .logo-box p { color: #555; font-size: 15px; margin-bottom: 4px; }
    .logo-box h2 { color: #111; font-size: 26px; font-weight: 700; }
    .field-group { display: flex; flex-direction: column; gap: 6px; }
    .field-label { color: #e0e0e0; font-size: 13px; font-weight: 600; }
    .input-wrap { background: var(--input-bg); border-radius: 50px; display: flex; align-items: center; padding: 4px 16px 4px 4px; gap: 10px; }
    .input-icon { width: 40px; height: 40px; background: var(--input-icon); border-radius: 50%; flex-shrink: 0; }
    .input-wrap input { background: transparent; border: none; outline: none; width: 100%; color: #fff; font-family: 'Sora', sans-serif; font-size: 14px; }
    .input-wrap input::placeholder { color: #bbb; }
    .btn-login { background: var(--btn-bg); color: var(--btn-text); border: none; border-radius: 50px; padding: 14px; font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; width: 100%; transition: background .2s; }
    .btn-login:hover { background: #d4d4d4; }
    .card-link { text-align: center; }
    .card-link a { color: var(--link); font-size: 12px; text-decoration: underline; cursor: pointer; }
    @media (max-width: 900px) { main { grid-template-columns: 1fr; padding: 40px 24px; } .left-content { display: none; } }
  </style>
</head>
<body>
  <nav>
    <div class="nav-logo">ListaHub</div>
    <div class="nav-actions">
      <a class="nav-pill" href="index.php">Log In</a>
      <a class="nav-pill" href="signup.php">Sign Up</a>
    </div>
  </nav>

  <main>
    <div class="left-content">
      <div class="hero-image"></div>
      <div class="text-lines">
        <div class="line"></div>
        <div class="line"></div>
        <div class="line"></div>
        <div class="line"></div>
      </div>
    </div>

    <div class="login-card">
      <div class="logo-box">
        <p>Welcome To</p>
        <h2>ListaHub</h2>
      </div>

      <form method="POST" action="login.php" style="display:flex; flex-direction:column; gap:20px;">

        <div class="field-group">
          <label class="field-label">Email/Username</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="text" name="login" placeholder="Enter email or username" required/>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Password</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="password" name="password" placeholder="Enter password" required/>
          </div>
        </div>

        <button type="submit" class="btn-login">Log In</button>

        <div class="card-link">
          <a href="signup.php">Don't have an account? Sign up</a>
        </div>

      </form>
    </div>
  </main>
</body>
</html>