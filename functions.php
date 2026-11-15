<?php
require_once __DIR__ . '/config.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function db(): PDO {
    global $pdo;
    return $pdo;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare("SELECT id,name,email,role,company_id FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function logged_in(): bool {
    return current_user() !== null;
}

function require_login(): void {
    if (!logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Please go back and try again.');
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function ticket_access(int $ticketId): array {
    $user = current_user();
    $stmt = db()->prepare("SELECT t.*, u.name AS requester, a.name AS agent
        FROM tickets t
        JOIN users u ON u.id=t.user_id
        LEFT JOIN users a ON a.id=t.assigned_to
        WHERE t.id=? AND t.company_id=?");
    $stmt->execute([$ticketId, $user['company_id']]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        http_response_code(404);
        exit('Ticket not found.');
    }

    if ($user['role'] === 'customer' && (int)$ticket['user_id'] !== (int)$user['id']) {
        http_response_code(403);
        exit('You cannot access this ticket.');
    }

    if ($user['role'] === 'agent' && $ticket['assigned_to'] && (int)$ticket['assigned_to'] !== (int)$user['id']) {
        http_response_code(403);
        exit('This ticket is assigned to another agent.');
    }

    return $ticket;
}
