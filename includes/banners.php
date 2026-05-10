<?php
/**
 * Banner display helper for Serenity Spaces.
 * Call renderBanners('public'|'dashboard'|'profile'|'client'|'admin') to output HTML.
 */

function renderBanners(string $context): void
{
    static $allBanners = null;
    if ($allBanners === null) {
        try {
            $allBanners = getDB()->query(
                "SELECT * FROM banners WHERE is_active = 1 ORDER BY id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $allBanners = [];
        }
    }

    $col = match($context) {
        'public'    => 'show_public',
        'dashboard' => 'show_dashboard',
        'profile'   => 'show_profile',
        'client'    => 'show_client',
        'admin'     => 'show_admin',
        default     => null,
    };
    if (!$col) return;

    $shown = array_filter($allBanners, fn($b) => !empty($b[$col]));
    if (!$shown) return;

    echo '<div class="site-banners">';
    foreach ($shown as $b) {
        $bg   = preg_match('/^#[0-9a-fA-F]{3,8}$/', $b['bg_color'])   ? $b['bg_color']   : '#2a2060';
        $tc   = preg_match('/^#[0-9a-fA-F]{3,8}$/', $b['text_color']) ? $b['text_color'] : '#ffffff';
        echo '<div class="site-banner" style="background:' . htmlspecialchars($bg, ENT_QUOTES) . ';color:' . htmlspecialchars($tc, ENT_QUOTES) . ';">'
           . htmlspecialchars($b['content'])
           . '</div>';
    }
    echo '</div>';
}
