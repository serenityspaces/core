<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
$pdo    = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$me = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
$me->execute([$practId]);
$me = $me->fetchColumn();
if (!$me) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    $rows = $pdo->query('SELECT * FROM banners ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'banners'=>$rows]);
    exit;
}

if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrf)) {
        echo json_encode(['ok'=>false,'error'=>'CSRF mismatch']); exit;
    }

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $content  = trim($_POST['content'] ?? '');
        $bgColor  = trim($_POST['bg_color']   ?? '#2a2060');
        $txtColor = trim($_POST['text_color'] ?? '#ffffff');
        $showPublic    = isset($_POST['show_public'])    ? 1 : 0;
        $showDash      = isset($_POST['show_dashboard']) ? 1 : 0;
        $showProfile   = isset($_POST['show_profile'])   ? 1 : 0;
        $showClient    = isset($_POST['show_client'])    ? 1 : 0;
        $showAdmin     = isset($_POST['show_admin'])     ? 1 : 0;
        $isActive      = isset($_POST['is_active'])      ? 1 : 0;

        if (!$content) { echo json_encode(['ok'=>false,'error'=>'Content is required']); exit; }
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $bgColor))  $bgColor  = '#2a2060';
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $txtColor)) $txtColor = '#ffffff';

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE banners SET content=?,bg_color=?,text_color=?,show_public=?,show_dashboard=?,show_profile=?,show_client=?,show_admin=?,is_active=? WHERE id=?'
            )->execute([$content,$bgColor,$txtColor,$showPublic,$showDash,$showProfile,$showClient,$showAdmin,$isActive,$id]);
        } else {
            $pdo->prepare(
                'INSERT INTO banners (content,bg_color,text_color,show_public,show_dashboard,show_profile,show_client,show_admin,is_active) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$content,$bgColor,$txtColor,$showPublic,$showDash,$showProfile,$showClient,$showAdmin,$isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        $row = $pdo->prepare('SELECT * FROM banners WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        echo json_encode(['ok'=>true,'banner'=>$row->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'Invalid ID']); exit; }
        $pdo->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'toggle') {
        $id    = (int)($_POST['id']    ?? 0);
        $field = $_POST['field'] ?? '';
        $allowed = ['show_public','show_dashboard','show_profile','show_client','show_admin','is_active'];
        if (!$id || !in_array($field, $allowed, true)) {
            echo json_encode(['ok'=>false,'error'=>'Invalid']); exit;
        }
        $pdo->prepare("UPDATE banners SET `{$field}` = 1 - `{$field}` WHERE id = ?")->execute([$id]);
        $val = $pdo->prepare("SELECT `{$field}` FROM banners WHERE id = ? LIMIT 1");
        $val->execute([$id]);
        echo json_encode(['ok'=>true,'value'=>(int)$val->fetchColumn()]);
        exit;
    }
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
