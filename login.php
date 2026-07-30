<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if (hash_equals(ADMIN_USER, $user) && password_verify($pass, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — ZODML Listmonk Analytics</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-card{background:#fff;border-radius:12px;padding:36px;box-shadow:0 8px 30px rgba(0,0,0,0.3);width:100%;max-width:360px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:24px}
.brand-icon{font-size:26px}
.brand-text{font-size:18px;font-weight:700;color:#1a1a2e}
h2{font-size:16px;color:#333;margin-bottom:20px;font-weight:600}
label{display:block;font-size:12px;font-weight:600;color:#666;margin-bottom:6px}
.field{margin-bottom:16px}
input{width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;transition:border-color 0.2s}
input:focus{border-color:#ff9900}
.login-btn{width:100%;padding:13px;background:#ff9900;color:#000;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;margin-top:6px}
.login-btn:hover{background:#e88a00}
.error{background:#fdecea;color:#c62828;font-size:12px;font-weight:600;padding:10px 12px;border-radius:8px;margin-bottom:16px}
</style>
</head>
<body>
  <div class="login-card">
    <div class="brand"><span class="brand-icon">📊</span><span class="brand-text">ZODML Analytics</span></div>
    <h2>Sign in to continue</h2>
    <?php if ($error): ?>
      <div class="error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="login-btn">Sign In</button>
    </form>
  </div>
</body>
</html>
