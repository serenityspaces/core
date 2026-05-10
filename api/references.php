<?php
/**
 * Practitioner reference library — quotes, passages, books, films, articles.
 * Backs both the in-session References sidebar tab and the out-of-session
 * management UI in profile.php.
 *
 * Actions:
 *   GET  list                — list practitioner's saved references (filterable)
 *   POST save                — create or update a reference
 *   POST delete              — delete a reference
 *   GET  library_search      — search the built-in quote library + show what's
 *                              already starred
 *   POST star_library_quote  — star a built-in library quote (saves a copy
 *                              to practitioner_references with external_kind='library')
 *   POST star_external       — star a media-search result (book/film/etc.)
 *                              from the existing media_search system
 *   POST unstar              — remove an externally-sourced reference by
 *                              external_kind+external_id (idempotent)
 *
 * Auth: practitioner session only. CSRF on every POST.
 */

session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/quote_library.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Practitioners only']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// Body parser — supports JSON + form-encoded
$input = $_POST;
if ($method === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

// Action may arrive in JSON body rather than query string
if ($action === '') {
    $action = $input['action'] ?? '';
}

if ($method === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_GET['csrf'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
}

$allowedTypes      = ['quote','passage','book','film','tv','article','other'];
$allowedCategories = quote_library_categories();   // includes "Practitioner"

// ═══════════════════════════════════════════════════════════════════════
// LIST — returns this practitioner's references with optional filters
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'list' && $method === 'GET') {
    $type     = trim($_GET['type']     ?? '');
    $tag      = trim($_GET['tag']      ?? '');
    $category = trim($_GET['category'] ?? '');
    $q        = trim($_GET['q']        ?? '');

    $sql  = 'SELECT * FROM practitioner_references WHERE practitioner_id = ?';
    $args = [$practId];

    if ($type !== '' && in_array($type, $allowedTypes, true)) {
        $sql   .= ' AND ref_type = ?';
        $args[] = $type;
    }
    if ($category !== '' && in_array($category, $allowedCategories, true)) {
        $sql   .= ' AND category = ?';
        $args[] = $category;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode tags JSON
    foreach ($rows as &$r) {
        $r['tags'] = json_decode($r['tags'] ?? '[]', true) ?: [];
    }
    unset($r);

    // Optional in-PHP tag/text filtering — keeps the SQL simple while
    // allowing JSON tag filters that don't index cleanly.
    if ($tag !== '') {
        $rows = array_values(array_filter($rows, fn($r) => in_array($tag, $r['tags'], true)));
    }
    if ($q !== '') {
        $qLower = mb_strtolower($q);
        $rows = array_values(array_filter($rows, function($r) use ($qLower) {
            $hay = mb_strtolower(($r['title'] ?? '') . ' ' . ($r['body'] ?? '') . ' ' . ($r['author'] ?? '') . ' ' . ($r['source'] ?? ''));
            return mb_strpos($hay, $qLower) !== false;
        }));
    }

    // Surface the categories the practitioner has used (with counts) so the
    // UI can render filter chips that reflect actual library shape.
    $catCounts = [];
    foreach ($rows as $r) {
        $c = $r['category'] ?? 'Practitioner';
        $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
    }

    echo json_encode([
        'ok' => true,
        'references' => $rows,
        'category_counts' => $catCounts,
        'available_categories' => $allowedCategories,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// LIBRARY_SEARCH — search built-in quote library, mark starred ones
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'library_search' && $method === 'GET') {
    $q        = trim($_GET['q']        ?? '');
    $tag      = trim($_GET['tag']      ?? '');
    $category = trim($_GET['category'] ?? '');
    $hits     = quote_library_search($q);

    if ($tag !== '') {
        $hits = array_values(array_filter($hits, fn($e) => in_array($tag, $e['tags'] ?? [], true)));
    }
    if ($category !== '' && in_array($category, $allowedCategories, true)) {
        $hits = array_values(array_filter($hits, fn($e) => ($e['category'] ?? '') === $category));
    }

    // Find which library ids this practitioner has already starred
    $starred = [];
    if (!empty($hits)) {
        $stmt = $pdo->prepare(
            "SELECT external_id FROM practitioner_references
             WHERE practitioner_id = ? AND external_kind = 'library'"
        );
        $stmt->execute([$practId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $starred[$id] = true;
    }
    foreach ($hits as &$h) {
        $h['starred'] = !empty($starred[$h['id']]);
    }
    unset($h);

    // Surface available tags (with counts) for the filter chips
    $tagCounts = [];
    foreach (quote_library_all() as $e) {
        foreach (($e['tags'] ?? []) as $t) $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
    }
    arsort($tagCounts);

    // Category counts across the WHOLE library (not just current hits) so the
    // chip bar stays stable while the practitioner filters.
    $catCounts = [];
    foreach (quote_library_all() as $e) {
        $c = $e['category'] ?? 'Interfaith';
        $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
    }

    echo json_encode([
        'ok'        => true,
        'results'   => array_slice($hits, 0, 200),
        'tags'      => $tagCounts,
        'categories'=> $catCounts,
        'total'     => count($hits),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// SAVE — create or update a custom reference
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'save' && $method === 'POST') {
    $id       = (int)($input['id'] ?? 0);
    $refType  = $input['ref_type'] ?? 'quote';
    if (!in_array($refType, $allowedTypes, true)) $refType = 'quote';

    $title    = trim((string)($input['title']      ?? ''));
    $body     = trim((string)($input['body']       ?? ''));
    $author   = trim((string)($input['author']     ?? ''));
    $year     = trim((string)($input['year']       ?? ''));
    $source   = trim((string)($input['source']     ?? ''));
    $sourceUrl= trim((string)($input['source_url'] ?? ''));
    $coverUrl = trim((string)($input['cover_url']  ?? ''));
    $notes    = trim((string)($input['notes']      ?? ''));

    $tags = $input['tags'] ?? [];
    if (is_string($tags)) $tags = array_filter(array_map('trim', explode(',', $tags)));
    $tags = array_values(array_filter(array_map(fn($t) => trim((string)$t), (array)$tags), fn($t) => $t !== '' && mb_strlen($t) <= 64));

    // Category — practitioner-created references default to "Practitioner".
    // Practitioners can re-categorise to any of the named taxonomy values.
    $category = trim((string)($input['category'] ?? 'Practitioner'));
    if (!in_array($category, $allowedCategories, true)) $category = 'Practitioner';

    if ($body === '' && $title === '') {
        echo json_encode(['ok' => false, 'error' => 'Either body or title is required']);
        exit;
    }
    if ($sourceUrl !== '' && !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid source URL']);
        exit;
    }

    if ($id > 0) {
        // Update — verify ownership first
        $own = $pdo->prepare('SELECT id FROM practitioner_references WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $own->execute([$id, $practId]);
        if (!$own->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        $stmt = $pdo->prepare(
            'UPDATE practitioner_references
             SET ref_type = ?, category = ?, title = ?, body = ?, author = ?, year = ?,
                 source = ?, source_url = ?, cover_url = ?, tags = ?, notes = ?
             WHERE id = ? AND practitioner_id = ?'
        );
        $stmt->execute([
            $refType, $category, $title, $body, $author, $year,
            $source, $sourceUrl, $coverUrl, json_encode($tags), $notes,
            $id, $practId,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    // Insert — custom item (no external linkage)
    $stmt = $pdo->prepare(
        'INSERT INTO practitioner_references
            (practitioner_id, ref_type, category, title, body, author, year,
             source, source_url, cover_url, tags, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $practId, $refType, $category, $title, $body, $author, $year,
        $source, $sourceUrl, $coverUrl, json_encode($tags), $notes,
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// STAR_LIBRARY_QUOTE — save a copy of a built-in library quote
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'star_library_quote' && $method === 'POST') {
    $libraryId = trim((string)($input['library_id'] ?? ''));
    if ($libraryId === '') {
        echo json_encode(['ok' => false, 'error' => 'library_id required']);
        exit;
    }
    $entry = quote_library_get($libraryId);
    if (!$entry) {
        echo json_encode(['ok' => false, 'error' => 'Library entry not found']);
        exit;
    }

    $libCategory = quote_library_category_for($entry['id']);

    // Idempotent on (practitioner_id, external_kind, external_id) — UNIQUE KEY
    $stmt = $pdo->prepare(
        "INSERT INTO practitioner_references
            (practitioner_id, ref_type, category, title, body, author, year, source,
             external_kind, external_id, tags)
         VALUES (?, 'quote', ?, ?, ?, ?, ?, ?, 'library', ?, ?)
         ON DUPLICATE KEY UPDATE
            body=VALUES(body), author=VALUES(author), year=VALUES(year),
            source=VALUES(source), category=VALUES(category), tags=VALUES(tags)"
    );
    $stmt->execute([
        $practId, $libCategory,
        mb_substr($entry['body'], 0, 120),       // title = preview of body
        $entry['body'],
        $entry['author'] ?? '',
        $entry['year']   ?? '',
        $entry['source'] ?? '',
        $entry['id'],
        json_encode($entry['tags'] ?? []),
    ]);
    echo json_encode(['ok' => true, 'starred' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// STAR_EXTERNAL — favorite a media-search result (book/film/tv)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'star_external' && $method === 'POST') {
    $kind = trim((string)($input['external_kind'] ?? ''));
    $extId= trim((string)($input['external_id']   ?? ''));
    if (!in_array($kind, ['tmdb','openlibrary','google_books','archive_org'], true) || $extId === '') {
        echo json_encode(['ok' => false, 'error' => 'Invalid kind or id']);
        exit;
    }
    $refType  = $input['ref_type'] ?? 'book';
    if (!in_array($refType, ['book','film','tv','article'], true)) $refType = 'book';

    $title    = trim((string)($input['title']      ?? ''));
    $author   = trim((string)($input['author']     ?? ''));   // book author / director
    $year     = trim((string)($input['year']       ?? ''));
    $source   = trim((string)($input['source']     ?? ''));   // publisher / studio
    $sourceUrl= trim((string)($input['source_url'] ?? ''));
    $coverUrl = trim((string)($input['cover_url']  ?? ''));
    $body     = trim((string)($input['body']       ?? ''));   // synopsis / overview

    if ($title === '') {
        echo json_encode(['ok' => false, 'error' => 'title required']);
        exit;
    }

    // Practitioner can pass a category, otherwise default to "Practitioner"
    // (their own curation bucket — they can recategorise later).
    $category = trim((string)($input['category'] ?? 'Practitioner'));
    if (!in_array($category, $allowedCategories, true)) $category = 'Practitioner';

    $stmt = $pdo->prepare(
        "INSERT INTO practitioner_references
            (practitioner_id, ref_type, category, title, body, author, year, source,
             source_url, cover_url, external_kind, external_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            ref_type=VALUES(ref_type), category=VALUES(category),
            title=VALUES(title), body=VALUES(body),
            author=VALUES(author), year=VALUES(year),
            source=VALUES(source), source_url=VALUES(source_url),
            cover_url=VALUES(cover_url)"
    );
    $stmt->execute([
        $practId, $refType, $category, $title, $body, $author, $year,
        $source, $sourceUrl, $coverUrl, $kind, $extId,
    ]);
    echo json_encode(['ok' => true, 'starred' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// UNSTAR — remove a reference by external (kind+id) or by row id
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'unstar' && $method === 'POST') {
    $kind = trim((string)($input['external_kind'] ?? ''));
    $extId= trim((string)($input['external_id']   ?? ''));
    if ($kind !== '' && $extId !== '') {
        $stmt = $pdo->prepare(
            'DELETE FROM practitioner_references
             WHERE practitioner_id = ? AND external_kind = ? AND external_id = ?'
        );
        $stmt->execute([$practId, $kind, $extId]);
        echo json_encode(['ok' => true, 'removed' => $stmt->rowCount()]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'external_kind and external_id required']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// DELETE — remove by row id
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'delete' && $method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'id required']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM practitioner_references WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$id, $practId]);
    echo json_encode(['ok' => true, 'removed' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
