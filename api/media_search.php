<?php
/**
 * Serenity Spaces — Media Search Proxy
 *
 * Proxies search requests to external media APIs so keys are never
 * exposed to the browser.
 *
 * POST action=search_books  — Open Library + Google Books
 * POST action=search_movies — TMDB (movies and/or TV)
 * POST action=detail        — full detail for a single item
 *
 * Auth: practitioner session only
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

if (!validate_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

$query = trim($input['query'] ?? '');
if ($action !== 'detail' && !$query) {
    echo json_encode(['ok' => false, 'error' => 'Query required']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$ctx     = "media_config:{$practId}";

// Load config
try {
    $stmt = $pdo->prepare('SELECT * FROM practitioner_media_config WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$practId]);
    $cfg = $stmt->fetch();
} catch (PDOException $e) {
    $cfg = null;
}

/**
 * Simple cURL GET; returns decoded JSON array or null on failure.
 */
function media_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'SerenitySpaces/1.0 (media-search)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

// ── Search Books ─────────────────────────────────────────────────
if ($action === 'search_books') {
    $results = [];

    // ── Open Library ────────────────────────────────────────────
    if ($cfg && (int)$cfg['open_library']) {
        $url  = 'https://openlibrary.org/search.json?q=' . urlencode($query)
              . '&limit=10&fields=key,title,author_name,first_publish_year,subject,cover_i';
        $data = media_get($url);
        if ($data && !empty($data['docs'])) {
            foreach ($data['docs'] as $doc) {
                $coverId    = $doc['cover_i'] ?? null;
                $results[]  = [
                    'type'        => 'book',
                    'source'      => 'openlibrary',
                    'id'          => $doc['key'] ?? '',
                    'title'       => $doc['title'] ?? 'Unknown',
                    'author'      => implode(', ', array_slice($doc['author_name'] ?? [], 0, 2)),
                    'year'        => (string)($doc['first_publish_year'] ?? ''),
                    'cover_url'   => $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg" : null,
                    'cover_large' => $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg" : null,
                    'genre'       => implode(', ', array_slice($doc['subject'] ?? [], 0, 3)),
                    'rating'      => null,
                    'description' => null,
                ];
            }
        }
    }

    // ── Google Books ─────────────────────────────────────────────
    if ($cfg && (int)$cfg['google_books_enabled'] && !empty($cfg['google_books_key_enc'])) {
        $apiKey = phi_decrypt($cfg['google_books_key_enc'], $ctx);
        if ($apiKey) {
            $url  = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($query)
                  . '&maxResults=10&key=' . urlencode($apiKey);
            $data = media_get($url);
            if ($data && !empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $vol      = $item['volumeInfo'] ?? [];
                    $img      = $vol['imageLinks'] ?? [];
                    $thumb    = $img['thumbnail'] ?? ($img['smallThumbnail'] ?? null);
                    $large    = $img['medium'] ?? ($img['large'] ?? ($img['thumbnail'] ?? null));
                    $results[] = [
                        'type'        => 'book',
                        'source'      => 'google_books',
                        'id'          => $item['id'] ?? '',
                        'title'       => $vol['title'] ?? 'Unknown',
                        'author'      => implode(', ', array_slice($vol['authors'] ?? [], 0, 2)),
                        'year'        => substr($vol['publishedDate'] ?? '', 0, 4),
                        'cover_url'   => $thumb ? str_replace('http://', 'https://', $thumb) : null,
                        'cover_large' => $large  ? str_replace('http://', 'https://', $large)  : null,
                        'genre'       => implode(', ', array_slice($vol['categories'] ?? [], 0, 3)),
                        'rating'      => isset($vol['averageRating'])
                            ? number_format((float)$vol['averageRating'], 1) . '/5' : null,
                        'description' => isset($vol['description'])
                            ? substr($vol['description'], 0, 600) : null,
                        'publisher'   => $vol['publisher'] ?? null,
                    ];
                }
            }
        }
    }

    // Deduplicate by normalised title+author
    $seen    = [];
    $deduped = [];
    foreach ($results as $r) {
        $key = strtolower(trim($r['title'] . '|' . $r['author']));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $deduped[]  = $r;
        }
    }

    echo json_encode(['ok' => true, 'results' => $deduped]);
    exit;
}

// ── Search Movies / TV ───────────────────────────────────────────
if ($action === 'search_movies') {
    if (!$cfg || !(int)$cfg['tmdb_enabled'] || empty($cfg['tmdb_key_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'TMDB not configured for your account.']);
        exit;
    }
    $apiKey    = phi_decrypt($cfg['tmdb_key_enc'], $ctx);
    if (!$apiKey) {
        echo json_encode(['ok' => false, 'error' => 'TMDB key could not be read.']);
        exit;
    }

    $mediaType = $input['media_type'] ?? 'both'; // movie | tv | both
    $results   = [];

    if ($mediaType === 'movie' || $mediaType === 'both') {
        $url  = 'https://api.themoviedb.org/3/search/movie?query=' . urlencode($query)
              . '&api_key=' . urlencode($apiKey) . '&language=en-US&page=1';
        $data = media_get($url);
        if ($data && !empty($data['results'])) {
            foreach (array_slice($data['results'], 0, 8) as $item) {
                $poster    = $item['poster_path'] ?? null;
                $results[] = [
                    'type'        => 'movie',
                    'source'      => 'tmdb',
                    'id'          => (string)($item['id'] ?? ''),
                    'title'       => $item['title'] ?? 'Unknown',
                    'author'      => null,
                    'year'        => substr($item['release_date'] ?? '', 0, 4),
                    'cover_url'   => $poster ? "https://image.tmdb.org/t/p/w185{$poster}"  : null,
                    'cover_large' => $poster ? "https://image.tmdb.org/t/p/w500{$poster}"  : null,
                    'genre'       => null,
                    'rating'      => (isset($item['vote_average']) && $item['vote_average'] > 0)
                        ? number_format((float)$item['vote_average'], 1) . '/10' : null,
                    'description' => $item['overview'] ?? null,
                    'tmdb_id'     => $item['id'],
                    'tmdb_type'   => 'movie',
                ];
            }
        }
    }

    if ($mediaType === 'tv' || $mediaType === 'both') {
        $url  = 'https://api.themoviedb.org/3/search/tv?query=' . urlencode($query)
              . '&api_key=' . urlencode($apiKey) . '&language=en-US&page=1';
        $data = media_get($url);
        if ($data && !empty($data['results'])) {
            foreach (array_slice($data['results'], 0, 8) as $item) {
                $poster    = $item['poster_path'] ?? null;
                $results[] = [
                    'type'        => 'tv',
                    'source'      => 'tmdb',
                    'id'          => 'tv_' . ($item['id'] ?? ''),
                    'title'       => $item['name'] ?? 'Unknown',
                    'author'      => null,
                    'year'        => substr($item['first_air_date'] ?? '', 0, 4),
                    'cover_url'   => $poster ? "https://image.tmdb.org/t/p/w185{$poster}"  : null,
                    'cover_large' => $poster ? "https://image.tmdb.org/t/p/w500{$poster}"  : null,
                    'genre'       => null,
                    'rating'      => (isset($item['vote_average']) && $item['vote_average'] > 0)
                        ? number_format((float)$item['vote_average'], 1) . '/10' : null,
                    'description' => $item['overview'] ?? null,
                    'tmdb_id'     => $item['id'],
                    'tmdb_type'   => 'tv',
                ];
            }
        }
    }

    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

// ── Detail ───────────────────────────────────────────────────────
if ($action === 'detail') {
    $source   = $input['source']    ?? '';
    $itemId   = $input['id']        ?? '';
    $tmdbType = $input['tmdb_type'] ?? 'movie';

    // ── TMDB detail ──────────────────────────────────────────────
    if ($source === 'tmdb') {
        if (!$cfg || !(int)$cfg['tmdb_enabled'] || empty($cfg['tmdb_key_enc'])) {
            echo json_encode(['ok' => false, 'error' => 'TMDB not configured']);
            exit;
        }
        $apiKey = phi_decrypt($cfg['tmdb_key_enc'], $ctx);
        $numId  = preg_replace('/[^0-9]/', '', $itemId);
        $url    = "https://api.themoviedb.org/3/{$tmdbType}/{$numId}?api_key="
                . urlencode($apiKey) . '&language=en-US';
        $data   = media_get($url);
        if (!$data) {
            echo json_encode(['ok' => false, 'error' => 'Detail fetch failed']);
            exit;
        }
        $poster = $data['poster_path'] ?? null;
        $genres = implode(', ', array_column(array_slice($data['genres'] ?? [], 0, 3), 'name'));
        $author = null;
        if ($tmdbType === 'movie' && !empty($data['production_companies'])) {
            $author = implode(', ', array_column(array_slice($data['production_companies'], 0, 2), 'name'));
        } elseif ($tmdbType === 'tv') {
            if (!empty($data['created_by'])) {
                $author = implode(', ', array_column(array_slice($data['created_by'], 0, 2), 'name'));
            } elseif (!empty($data['networks'])) {
                $author = implode(', ', array_column(array_slice($data['networks'], 0, 2), 'name'));
            }
        }
        echo json_encode(['ok' => true, 'item' => [
            'type'        => $tmdbType === 'tv' ? 'tv' : 'movie',
            'source'      => 'tmdb',
            'id'          => $itemId,
            'tmdb_id'     => $numId,
            'tmdb_type'   => $tmdbType,
            'title'       => $tmdbType === 'tv' ? ($data['name'] ?? '') : ($data['title'] ?? ''),
            'author'      => $author,
            'year'        => substr(($tmdbType === 'tv' ? ($data['first_air_date'] ?? '') : ($data['release_date'] ?? '')), 0, 4),
            'cover_url'   => $poster ? "https://image.tmdb.org/t/p/w185{$poster}" : null,
            'cover_large' => $poster ? "https://image.tmdb.org/t/p/w500{$poster}" : null,
            'genre'       => $genres,
            'rating'      => (isset($data['vote_average']) && $data['vote_average'] > 0)
                ? number_format((float)$data['vote_average'], 1) . '/10' : null,
            'description' => $data['overview'] ?? null,
        ]]);
        exit;
    }

    // ── Google Books detail ──────────────────────────────────────
    if ($source === 'google_books') {
        if (!$cfg || !(int)$cfg['google_books_enabled'] || empty($cfg['google_books_key_enc'])) {
            echo json_encode(['ok' => false, 'error' => 'Google Books not configured']);
            exit;
        }
        $apiKey = phi_decrypt($cfg['google_books_key_enc'], $ctx);
        $url    = 'https://www.googleapis.com/books/v1/volumes/'
                . urlencode($itemId) . '?key=' . urlencode($apiKey);
        $data   = media_get($url);
        if (!$data) {
            echo json_encode(['ok' => false, 'error' => 'Detail fetch failed']);
            exit;
        }
        $vol = $data['volumeInfo'] ?? [];
        $img = $vol['imageLinks'] ?? [];
        echo json_encode(['ok' => true, 'item' => [
            'type'        => 'book',
            'source'      => 'google_books',
            'id'          => $data['id'],
            'title'       => $vol['title'] ?? 'Unknown',
            'author'      => implode(', ', array_slice($vol['authors'] ?? [], 0, 2)),
            'year'        => substr($vol['publishedDate'] ?? '', 0, 4),
            'cover_url'   => isset($img['thumbnail'])
                ? str_replace('http://', 'https://', $img['thumbnail']) : null,
            'cover_large' => isset($img['large'])
                ? str_replace('http://', 'https://', $img['large'])
                : (isset($img['medium'])
                    ? str_replace('http://', 'https://', $img['medium'])
                    : (isset($img['thumbnail']) ? str_replace('http://', 'https://', $img['thumbnail']) : null)),
            'genre'       => implode(', ', array_slice($vol['categories'] ?? [], 0, 3)),
            'rating'      => isset($vol['averageRating'])
                ? number_format((float)$vol['averageRating'], 1) . '/5' : null,
            'description' => $vol['description'] ?? null,
            'publisher'   => $vol['publisher'] ?? null,
        ]]);
        exit;
    }

    // ── Open Library detail (description only) ───────────────────
    if ($source === 'openlibrary') {
        $safePath = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $itemId);
        $url      = 'https://openlibrary.org' . $safePath . '.json';
        $data     = media_get($url);
        $desc     = '';
        if ($data) {
            if (!empty($data['description'])) {
                $desc = is_array($data['description'])
                    ? ($data['description']['value'] ?? '')
                    : (string)$data['description'];
            }
        }
        echo json_encode(['ok' => true, 'item' => ['description' => substr($desc, 0, 800)]]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown source']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
