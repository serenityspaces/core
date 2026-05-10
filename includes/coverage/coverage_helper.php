<?php
/**
 * Coverage helper — load practitioner-type coverage content from md files
 * and filter by the practice types currently offered on this instance.
 *
 * Each md file lives in includes/coverage/practitioner_types/ and carries
 * YAML frontmatter (type_key, display_label, tier, license_gate,
 * default_role_icons) plus markdown body sections.
 */

if (!function_exists('coverage_types_dir')) {
    function coverage_types_dir(): string
    {
        return __DIR__ . '/practitioner_types';
    }
}

/**
 * Return the set of practice_type keys offered by at least one active
 * practitioner who is also visible in the public directory (mirrors the
 * filter used in index.php).
 *
 * @return array<string,bool>  set of practice_type keys
 */
function coverage_active_practice_types(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT practice_types
         FROM practitioners
         WHERE display_name IS NOT NULL
           AND (account_status IS NULL OR account_status = 'active')
           AND EXISTS (
             SELECT 1 FROM practitioner_availability a WHERE a.practitioner_id = practitioners.id LIMIT 1
           )"
    );
    $present = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $types = json_decode($row['practice_types'] ?? '[]', true) ?: [];
        foreach ($types as $t) {
            if (is_string($t) && $t !== '') $present[$t] = true;
        }
    }
    return $present;
}

/**
 * Parse a single coverage md file. Returns null on read error.
 *
 * Fields returned:
 *   type_key, display_label, tier, license_gate, default_role_icons,
 *   summary, body_html
 */
function coverage_parse_md(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;

    // ── Frontmatter ──
    $front = [];
    $body  = $raw;
    if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
        $body = $m[2];
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*)$/', $line, $kv)) {
                $front[$kv[1]] = trim($kv[2]);
            }
        }
    }

    // ── Pull "Coverage Summary" and "Coverage Page Copy" sections ──
    $summary = '';
    $copy    = '';
    if (preg_match('/##\s*Coverage Summary\s*\n+(.*?)(?=\n##\s|\z)/s', $body, $m)) {
        $summary = trim($m[1]);
    }
    if (preg_match('/##\s*Coverage Page Copy\s*\n+(.*?)(?=\n##\s|\z)/s', $body, $m)) {
        $copy = trim($m[1]);
    }

    // ── Tiny markdown → HTML for the body copy ──
    // Splits on blank lines into paragraphs, escapes HTML, restores **bold**
    // and `code`. No need for a full markdown library — md content is
    // operator-controlled, never user-controlled.
    $bodyHtml = '';
    if ($copy !== '') {
        $paragraphs = preg_split('/\n\s*\n/', $copy);
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $p = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            // Smart quotes left in MD as curly-already; pass through.
            $p = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $p);
            $p = preg_replace('/`([^`]+)`/', '<code>$1</code>', $p);
            $bodyHtml .= '<p>' . $p . '</p>' . "\n";
        }
    }

    return [
        'type_key'           => $front['type_key']           ?? '',
        'display_label'      => $front['display_label']      ?? '',
        'tier'               => $front['tier']               ?? '',
        'license_gate'       => $front['license_gate']       ?? 'None',
        'default_role_icons' => $front['default_role_icons'] ?? '',
        'summary'            => $summary,
        'body_html'          => $bodyHtml,
    ];
}

/**
 * Load all coverage entries that have at least one active practitioner
 * offering that type. Order follows the canonical taxonomy order from the
 * practitioner taxonomy (non-clinical first, pastoral middle, clinical last).
 *
 * @return array<int,array>  list of coverage entries
 */
function coverage_load_active(PDO $pdo): array
{
    $present = coverage_active_practice_types($pdo);

    // Canonical ordering — keep alignment with the practitioner-types report.
    $order = [
        'life_coaching',
        'peer_support',
        'philosophical_counseling',
        'mindfulness',
        'grief_support',
        'addiction_recovery',
        'trauma_informed',
        'pastoral_care',
        'spiritual_direction',
        'licensed_therapy',
        'cbt',
        'dbt',
    ];

    $entries = [];
    foreach ($order as $key) {
        if (!isset($present[$key])) continue;
        $entry = coverage_parse_md(coverage_types_dir() . '/' . $key . '.md');
        if ($entry && $entry['type_key'] !== '') {
            $entries[] = $entry;
        }
    }
    return $entries;
}
