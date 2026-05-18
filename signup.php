<?php
// ============================================================
//  signup.php  –  Sign Up page (HTML portion)
//  The form POSTs to signup_process.php for processing.
//  If already logged in, redirect to dashboard.
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
  <title>Sign Up – ListaHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #f4f5f7; --card: #5a5a5a; --input-bg: #7a7a7a;
      --input-icon: #a0a0a0; --logo-box: #d0d0d0;
      --btn-bg: #e8e8e8; --btn-text: #111; --link: #ccc; --nav-pill: #6b6b6b;
    }
    body { font-family: 'Sora', sans-serif; background: var(--bg); min-height: 100vh; display: flex; flex-direction: column; }
    nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 32px; height: 72px; display: flex; align-items: center; justify-content: space-between; }
    .nav-logo { background: #4a4a4a; color: #fff; font-weight: 700; font-size: 15px; padding: 10px 28px; border-radius: 50px; }
    .nav-actions { display: flex; gap: 12px; }
    .nav-pill { background: var(--nav-pill); color: #e0e0e0; font-size: 13px; font-weight: 600; padding: 8px 22px; border-radius: 50px; cursor: pointer; border: none; transition: background .2s; text-decoration: none; display: inline-block; }
    .nav-pill:hover { background: #555; }
    main { flex: 1; display: grid; grid-template-columns: 1fr 1fr; align-items: center; padding: 60px 80px; gap: 60px; }
    .left-content { display: flex; flex-direction: column; gap: 24px; }
    .hero-image { width: 100%; height: 130px; background: #c8c8c8; border-radius: 10px; }
    .text-lines { display: flex; flex-direction: column; gap: 12px; }
    .line { height: 18px; background: #d0d0d0; border-radius: 6px; }
    .line:nth-child(4) { width: 60%; }
    .signup-card { background: var(--card); border-radius: 20px; padding: 32px 44px 36px; display: flex; flex-direction: column; gap: 16px; box-shadow: 0 8px 40px rgba(0,0,0,.18); }
    .logo-box { background: var(--logo-box); border-radius: 14px; padding: 20px; text-align: center; }
    .logo-box p { color: #555; font-size: 14px; margin-bottom: 4px; }
    .logo-box h2 { color: #111; font-size: 24px; font-weight: 700; }
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { color: #e0e0e0; font-size: 13px; font-weight: 600; }
    .input-wrap { background: var(--input-bg); border-radius: 50px; display: flex; align-items: center; padding: 4px 16px 4px 4px; gap: 10px; }
    .input-icon { width: 38px; height: 38px; background: var(--input-icon); border-radius: 50%; flex-shrink: 0; }
    .input-wrap input { background: transparent; border: none; outline: none; width: 100%; color: #fff; font-family: 'Sora', sans-serif; font-size: 14px; }
    .input-wrap input::placeholder { color: #bbb; }
    .terms-row { display: flex; align-items: center; gap: 8px; }
    .terms-row input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
    .terms-row label { color: #ccc; font-size: 12px; cursor: pointer; }
    .btn-create { background: var(--btn-bg); color: var(--btn-text); border: none; border-radius: 50px; padding: 13px; font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 4px; transition: background .2s; }
    .btn-create:hover { background: #d4d4d4; }
    .card-link { text-align: center; }
    .card-link a { color: var(--link); font-size: 12px; text-decoration: underline; }
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

    <div class="signup-card">
      <div class="logo-box">
        <p>Welcome to</p>
        <h2>ListaHub</h2>
      </div>

      <form method="POST" action="signup_process.php" style="display:flex; flex-direction:column; gap:16px;">

        <div class="field-group">
          <label class="field-label">Username</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="text" name="username" placeholder="Enter your username" required/>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Email</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="email" name="email" placeholder="Enter your email" required/>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Password</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="password" name="password" placeholder="Create password" required/>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Confirm Password</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="password" name="confirm" placeholder="Confirm password" required/>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">Store Name</label>
          <div class="input-wrap">
            <div class="input-icon"></div>
            <input type="text" name="store_name" placeholder="Enter store name" required/>
          </div>
        </div>

        <div class="terms-row">
          <input type="checkbox" name="terms" id="terms"/>
          <label for="terms">I agree to the terms and conditions</label>
        </div>

        <button type="submit" class="btn-create">Create Account</button>

        <div class="card-link">
          <a href="index.php">Already have an account? Log in</a>
        </div>

      </form>
    </div>
  </main>
</body>
</html>