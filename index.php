<?php
require_once __DIR__ . '/functions.php';

$page = $_GET['page'] ?? (logged_in() ? 'dashboard' : 'home');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'register') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $company = trim($_POST['company'] ?? '');

            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !$company) {
                throw new Exception('Enter your name, valid email, company and a password of at least 8 characters.');
            }

            $check = db()->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            if ($check->fetch()) throw new Exception('That email address is already registered.');

            db()->beginTransaction();

            $stmt = db()->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$company]);
            $companyId = (int)db()->lastInsertId();

            $count = (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $role = $count === 0 ? 'admin' : 'customer';

            $stmt = db()->prepare("INSERT INTO users (company_id,name,email,password,role) VALUES (?,?,?,?,?)");
            $stmt->execute([$companyId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $id = (int)db()->lastInsertId();

            db()->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id;
            flash($role === 'admin' ? 'Your administrator account is ready.' : 'Your account has been created.');
            redirect('index.php?page=dashboard');
        }

        if ($action === 'login') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';

            $stmt = db()->prepare("SELECT * FROM users WHERE email=?");
            $stmt->execute([$email]);
            $account = $stmt->fetch();

            if (!$account || !password_verify($password, $account['password'])) {
                throw new Exception('Incorrect email or password.');
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $account['id'];
            flash('Welcome back, ' . $account['name'] . '!');
            redirect('index.php?page=dashboard');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect('index.php?page=home');
        }

        require_login();
        $user = current_user();

        if ($action === 'create_ticket') {
            if ($user['role'] !== 'customer' && $user['role'] !== 'admin') {
                throw new Exception('You cannot create customer tickets from this account.');
            }

            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $priority = $_POST['priority'] ?? 'Normal';

            if (!$subject || !$message) throw new Exception('Enter a subject and describe the issue.');
            if (!in_array($priority, ['Low','Normal','High','Urgent'], true)) $priority = 'Normal';

            $stmt = db()->prepare("INSERT INTO tickets (company_id,user_id,subject,message,priority,status) VALUES (?,?,?,?,?,'Open')");
            $stmt->execute([$user['company_id'], $user['id'], $subject, $message, $priority]);
            $ticketId = (int)db()->lastInsertId();

            db()->prepare("INSERT INTO ticket_messages (ticket_id,user_id,message) VALUES (?,?,?)")
                ->execute([$ticketId, $user['id'], $message]);

            flash("Ticket #$ticketId has been created.");
            redirect("index.php?page=ticket&id=$ticketId");
        }

        if ($action === 'reply') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $ticket = ticket_access($ticketId);
            $message = trim($_POST['message'] ?? '');
            if (!$message) throw new Exception('Type a message before sending.');

            db()->prepare("INSERT INTO ticket_messages (ticket_id,user_id,message) VALUES (?,?,?)")
                ->execute([$ticketId, $user['id'], $message]);

            if ($user['role'] === 'agent' && $ticket['status'] === 'Open') {
                db()->prepare("UPDATE tickets SET status='In Progress' WHERE id=?")->execute([$ticketId]);
            }

            flash('Your reply has been sent.');
            redirect("index.php?page=ticket&id=$ticketId");
        }

        if ($action === 'update_ticket') {
            require_role(['admin','agent']);
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            ticket_access($ticketId);

            $status = $_POST['status'] ?? 'Open';
            $priority = $_POST['priority'] ?? 'Normal';
            $agentId = $_POST['assigned_to'] ?? '';

            if (!in_array($status, ['Open','In Progress','Closed'], true)) $status = 'Open';
            if (!in_array($priority, ['Low','Normal','High','Urgent'], true)) $priority = 'Normal';

            if ($agentId !== '') {
                $check = db()->prepare("SELECT id FROM users WHERE id=? AND company_id=? AND role IN ('agent','admin')");
                $check->execute([(int)$agentId, $user['company_id']]);
                if (!$check->fetch()) throw new Exception('Choose a valid agent.');
            } else {
                $agentId = null;
            }

            db()->prepare("UPDATE tickets SET status=?,priority=?,assigned_to=? WHERE id=?")
                ->execute([$status,$priority,$agentId,$ticketId]);

            flash('Ticket details updated.');
            redirect("index.php?page=ticket&id=$ticketId");
        }

        if ($action === 'create_agent') {
            require_role(['admin']);
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';

            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                throw new Exception('Enter a name, valid email and password of at least 8 characters.');
            }

            $stmt = db()->prepare("INSERT INTO users (company_id,name,email,password,role) VALUES (?,?,?,?, 'agent')");
            $stmt->execute([$user['company_id'],$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            flash('Support agent created successfully.');
            redirect('index.php?page=team');
        }

    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex instanceof PDOException ? 'This action could not be completed. Check that the email is not already registered and the database is set up.' : $ex->getMessage(), 'error');
        redirect('index.php?page=' . ($page ?: 'dashboard'));
    }
}

$user = current_user();
if ($page !== 'home' && !in_array($page, ['login','register'], true)) require_login();
if (in_array($page, ['team'], true)) require_role(['admin']);
if ($page === 'new-ticket' && !in_array($user['role'] ?? '', ['customer','admin'], true)) redirect('index.php?page=dashboard');

$flash = get_flash();
$companyId = $user['company_id'] ?? 0;
function page_count(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SupportDesk Pro</title>
<link rel="stylesheet" href="style.css">
<script defer src="script.js"></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php">SupportDesk<span>Pro</span></a>
    <?php if ($user): ?>
    <nav>
        <a href="index.php?page=dashboard">Dashboard</a>
        <?php if (in_array($user['role'], ['customer','admin'], true)): ?>
        <a href="index.php?page=new-ticket">New ticket</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
        <a href="index.php?page=team">Team</a>
        <?php endif; ?>
    </nav>
    <div class="account">
        <span><?= e($user['name']) ?></span>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="link-button">Log out</button></form>
    </div>
    <?php else: ?>
    <nav><a href="index.php?page=login">Log in</a><a class="nav-cta" href="index.php?page=register">Create account</a></nav>
    <?php endif; ?>
</header>

<main class="container">
<?php if ($flash): ?>
<div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($page === 'home' && !$user): ?>
<section class="hero">
    <div class="hero-copy">
        <span class="eyebrow">CUSTOMER SUPPORT, SIMPLIFIED</span>
        <h1>Every customer conversation, <span>in one place.</span></h1>
        <p>Manage support requests, respond to customers and keep your team organised with SupportDesk Pro.</p>
        <div class="hero-actions"><a class="button primary" href="index.php?page=register">Get started</a><a class="button secondary" href="index.php?page=login">Sign in</a></div>
    </div>
    <div class="hero-panel"><div class="panel-top"><span class="dot"></span><span class="dot amber"></span><span class="dot green"></span><span>Support overview</span></div><div class="mock-stat"><strong>24</strong><span>Tickets handled</span></div><div class="mock-line"><i></i></div><div class="mock-ticket"><b>Account access issue</b><span class="pill open">Open</span></div><div class="mock-ticket"><b>Payment question</b><span class="pill progress">In Progress</span></div><div class="mock-ticket"><b>Profile update</b><span class="pill closed">Closed</span></div></div>
</section>
<?php elseif ($page === 'register' && !$user): ?>
<section class="auth-card">
    <span class="eyebrow">CREATE YOUR WORKSPACE</span><h1>Get started</h1><p class="muted">Create your company workspace and administrator account.</p>
    <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="register">
        <label>Full name<input name="name" required placeholder="Your full name"></label>
        <label>Work email<input type="email" name="email" required placeholder="you@company.com"></label>
        <label>Company name<input name="company" required placeholder="Your company"></label>
        <label>Password<input type="password" name="password" minlength="8" required placeholder="At least 8 characters"></label>
        <button class="button primary full">Create account</button>
    </form><p class="auth-foot">Already registered? <a href="index.php?page=login">Log in</a></p>
</section>
<?php elseif ($page === 'login' && !$user): ?>
<section class="auth-card">
    <span class="eyebrow">WELCOME BACK</span><h1>Sign in</h1><p class="muted">Access your support workspace.</p>
    <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login">
        <label>Email address<input type="email" name="email" required placeholder="you@company.com"></label>
        <label>Password<input type="password" name="password" required placeholder="Your password"></label>
        <button class="button primary full">Log in</button>
    </form><p class="auth-foot">New to SupportDesk? <a href="index.php?page=register">Create an account</a></p>
</section>
<?php elseif ($page === 'dashboard'): ?>
<?php
$base = " FROM tickets WHERE company_id=?";
$params = [$companyId];
if ($user['role'] === 'customer') { $base .= " AND user_id=?"; $params[] = $user['id']; }
if ($user['role'] === 'agent') { $base .= " AND (assigned_to=? OR assigned_to IS NULL)"; $params[] = $user['id']; }
$total = page_count("SELECT COUNT(*)".$base,$params);
$open = page_count("SELECT COUNT(*)".$base." AND status='Open'",$params);
$progress = page_count("SELECT COUNT(*)".$base." AND status='In Progress'",$params);
$closed = page_count("SELECT COUNT(*)".$base." AND status='Closed'",$params);
$sql = "SELECT t.*,u.name AS requester FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.company_id=?";
$listParams = [$companyId];
if ($user['role'] === 'customer') { $sql .= " AND t.user_id=?"; $listParams[] = $user['id']; }
if ($user['role'] === 'agent') { $sql .= " AND (t.assigned_to=? OR t.assigned_to IS NULL)"; $listParams[] = $user['id']; }
$sql .= " ORDER BY t.created_at DESC LIMIT 50";
$stmt = db()->prepare($sql); $stmt->execute($listParams); $tickets = $stmt->fetchAll();
?>
<div class="page-heading"><div><span class="eyebrow"><?= e(strtoupper($user['role'])) ?> WORKSPACE</span><h1>Your support dashboard</h1><p class="muted">Welcome, <?= e($user['name']) ?>. Here's your ticket activity.</p></div>
<?php if (in_array($user['role'],['customer','admin'],true)): ?><a class="button primary" href="index.php?page=new-ticket">+ Create ticket</a><?php endif; ?></div>
<div class="stats">
    <div class="stat-card"><span>Total tickets</span><strong><?= $total ?></strong><small>All requests</small></div>
    <div class="stat-card"><span>Open</span><strong><?= $open ?></strong><small>Awaiting attention</small></div>
    <div class="stat-card"><span>In progress</span><strong><?= $progress ?></strong><small>Being handled</small></div>
    <div class="stat-card"><span>Closed</span><strong><?= $closed ?></strong><small>Completed requests</small></div>
</div>
<section class="content-card"><div class="section-heading"><div><h2><?= $user['role']==='customer' ? 'Your recent tickets' : 'Ticket queue' ?></h2><p class="muted">Select a ticket to view its conversation.</p></div></div>
<?php if (!$tickets): ?><div class="empty"><div class="empty-icon">✉</div><h3>No tickets yet</h3><p>Support requests will appear here.</p></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Ticket</th><th>Requester</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($tickets as $t): ?><tr><td><a class="ticket-link" href="index.php?page=ticket&id=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> · <?= e($t['subject']) ?></a></td><td><?= e($t['requester']) ?></td><td><span class="priority <?= strtolower(e($t['priority'])) ?>"><?= e($t['priority']) ?></span></td><td><span class="pill <?= strtolower(str_replace(' ','-',e($t['status']))) ?>"><?= e($t['status']) ?></span></td><td><?= e(date('M j, Y',strtotime($t['created_at']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>

<?php elseif ($page === 'new-ticket'): ?>
<section class="form-card"><span class="eyebrow">CUSTOMER SUPPORT</span><h1>Create a ticket</h1><p class="muted">Describe the issue and our team will get back to you.</p>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_ticket">
<label>Subject<input name="subject" required maxlength="180" placeholder="What do you need help with?"></label>
<label>Priority<select name="priority"><option>Normal</option><option>Low</option><option>High</option><option>Urgent</option></select></label>
<label>Describe your issue<textarea name="message" rows="7" required placeholder="Please provide details so we can help..."></textarea></label>
<div class="form-actions"><a class="button secondary" href="index.php?page=dashboard">Cancel</a><button class="button primary">Submit ticket</button></div></form></section>

<?php elseif ($page === 'ticket'): ?>
<?php
$ticket = ticket_access((int)($_GET['id'] ?? 0));
$stmt = db()->prepare("SELECT m.*,u.name,u.role FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=? ORDER BY m.created_at ASC");
$stmt->execute([$ticket['id']]); $messages = $stmt->fetchAll();
$agentsStmt = db()->prepare("SELECT id,name FROM users WHERE company_id=? AND role IN ('agent','admin') ORDER BY name");
$agentsStmt->execute([$companyId]); $agents = $agentsStmt->fetchAll();
?>
<div class="page-heading"><div><a class="back-link" href="index.php?page=dashboard">← Back to dashboard</a><span class="eyebrow">TICKET #<?= (int)$ticket['id'] ?></span><h1><?= e($ticket['subject']) ?></h1><p class="muted">Submitted by <?= e($ticket['requester']) ?> · <?= e(date('M j, Y',strtotime($ticket['created_at']))) ?></p></div><span class="pill <?= strtolower(str_replace(' ','-',e($ticket['status']))) ?>"><?= e($ticket['status']) ?></span></div>
<div class="ticket-layout"><section class="content-card conversation"><h2>Conversation</h2>
<?php foreach ($messages as $m): ?><article class="message <?= $m['role']==='customer'?'customer-message':'staff-message' ?>"><div class="message-head"><strong><?= e($m['name']) ?></strong><span><?= e(ucfirst($m['role'])) ?> · <?= e(date('M j, g:i a',strtotime($m['created_at']))) ?></span></div><p><?= nl2br(e($m['message'])) ?></p></article><?php endforeach; ?>
<?php if ($ticket['status'] !== 'Closed'): ?><form method="post" class="form reply-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label>Your reply<textarea name="message" rows="4" required placeholder="Write your message..."></textarea></label><button class="button primary">Send reply</button></form><?php else: ?><p class="muted">This ticket is closed.</p><?php endif; ?>
</section>
<aside class="content-card details"><h2>Ticket details</h2><div class="detail-row"><span>Requester</span><strong><?= e($ticket['requester']) ?></strong></div><div class="detail-row"><span>Priority</span><strong><?= e($ticket['priority']) ?></strong></div><div class="detail-row"><span>Assigned agent</span><strong><?= e($ticket['agent'] ?: 'Unassigned') ?></strong></div>
<?php if (in_array($user['role'],['admin','agent'],true)): ?><form method="post" class="form manage-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_ticket"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><label>Status<select name="status"><?php foreach(['Open','In Progress','Closed'] as $s): ?><option <?= $ticket['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><label>Priority<select name="priority"><?php foreach(['Low','Normal','High','Urgent'] as $p): ?><option <?= $ticket['priority']===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?></select></label><label>Assign to<select name="assigned_to"><option value="">Unassigned</option><?php foreach($agents as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$ticket['assigned_to']===(int)$a['id']?'selected':'' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label><button class="button primary full">Update ticket</button></form><?php endif; ?>
</aside></div>

<?php elseif ($page === 'team'): ?>
<div class="page-heading"><div><span class="eyebrow">WORKSPACE ADMINISTRATION</span><h1>Team management</h1><p class="muted">Create support agents for your workspace.</p></div></div>
<div class="two-column"><section class="content-card"><h2>Add a support agent</h2><form method="post" class="form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_agent"><label>Full name<input name="name" required></label><label>Email address<input type="email" name="email" required></label><label>Temporary password<input type="password" name="password" minlength="8" required></label><button class="button primary">Create agent</button></form></section>
<section class="content-card"><h2>Your team</h2><?php $stmt=db()->prepare("SELECT name,email,role,created_at FROM users WHERE company_id=? ORDER BY role,name");$stmt->execute([$companyId]);foreach($stmt->fetchAll() as $member): ?><div class="team-member"><div class="avatar"><?= e(strtoupper(substr($member['name'],0,1))) ?></div><div><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></div><span class="role-tag"><?= e($member['role']) ?></span></div><?php endforeach; ?></section></div>

<?php else: ?>
<section class="content-card"><h1>Page not found</h1><a href="index.php?page=dashboard">Return to dashboard</a></section>
<?php endif; ?>
</main>
<footer class="footer">© <?= date('Y') ?> SupportDesk Pro. Support operations made simple.</footer>
</body>
</html>
