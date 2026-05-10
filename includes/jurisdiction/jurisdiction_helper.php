<?php
/**
 * Jurisdiction Helper — SerenitySpaces
 *
 * Provides accessor functions for jurisdiction settings and builds the
 * context array passed to block functions in blocks_privacy.php and
 * blocks_ropa.php.
 *
 * All functions require db/connection.php to be loaded (getSetting()).
 */

if (!defined('JURISDICTION_HELPER_INCLUDED')) {
    define('JURISDICTION_HELPER_INCLUDED', true);

    /**
     * Return the primary jurisdiction code.
     * Values: eu | uk | us | canada | australia | custom
     */
    function jur_primary(): string {
        return getSetting('jurisdiction_primary', 'eu');
    }

    /**
     * Return array of additional active jurisdiction codes.
     */
    function jur_additional(): array {
        return json_decode(getSetting('jurisdictions_additional', '[]'), true) ?? [];
    }

    /**
     * Return true if the given jurisdiction code is active (primary OR additional).
     */
    function jur_is_active(string $code): bool {
        return jur_primary() === $code || in_array($code, jur_additional(), true);
    }

    /**
     * Return all active jurisdiction codes (primary first, then additional).
     */
    function jur_active_list(): array {
        $primary = jur_primary();
        $additional = array_values(array_filter(jur_additional(), fn($c) => $c !== $primary));
        return array_merge([$primary], $additional);
    }

    /** Data controller details */
    function jur_controller_name(): string    { return getSetting('controller_name',    ''); }
    function jur_controller_address(): string { return getSetting('controller_address', ''); }
    function jur_controller_email(): string   { return getSetting('controller_email',   ''); }

    /** DPO details */
    function jur_dpo_name(): string  { return getSetting('dpo_name',  ''); }
    function jur_dpo_email(): string { return getSetting('dpo_email', ''); }

    /** Custom HTML blocks (stored as raw HTML — admin-entered only) */
    function jur_custom_privacy_html(): string { return getSetting('jurisdiction_custom_privacy_html', ''); }
    function jur_custom_ropa_html(): string    { return getSetting('jurisdiction_custom_ropa_html',    ''); }

    /**
     * Build the context array passed into every block function.
     * Handles missing controller info gracefully with safe fallbacks.
     *
     * @param  string $appName   Platform display name
     * @param  string $hostUrl   Full host URL (e.g. https://serenityspaces.app)
     */
    function jur_build_ctx(string $appName, string $hostUrl): array {
        $controllerName  = jur_controller_name();
        $controllerEmail = jur_controller_email();
        $controllerAddr  = jur_controller_address();

        // Safe display: fall back to app name when controller name not configured
        $controllerDisplay = $controllerName ?: $appName . ' (operator)';

        // Contact line for use in notice text
        $contactLine = $controllerEmail
            ? '<a href="mailto:' . htmlspecialchars($controllerEmail) . '">'
              . htmlspecialchars($controllerEmail) . '</a>'
            : 'the contact method available on the About page';

        return [
            'app_name'           => $appName,
            'host_url'           => $hostUrl,
            'controller_name'    => $controllerName,
            'controller_display' => $controllerDisplay,
            'controller_address' => $controllerAddr,
            'controller_email'   => $controllerEmail,
            'contact_line'       => $contactLine,
            'dpo_name'           => jur_dpo_name(),
            'dpo_email'          => jur_dpo_email(),
        ];
    }

    /**
     * Return the human-readable label for a jurisdiction code.
     */
    function jur_label(string $code): string {
        return match($code) {
            'eu'        => 'EU / EEA — GDPR',
            'uk'        => 'United Kingdom — UK GDPR',
            'us'        => 'United States — HIPAA / CCPA',
            'canada'    => 'Canada — PIPEDA / Québec Law 25',
            'australia' => 'Australia — Privacy Act 1988',
            'custom'    => 'Custom Jurisdiction',
            default     => ucfirst($code),
        };
    }

    /**
     * Map an ISO-3166-1 alpha-2 country code to a jurisdiction code.
     * Returns '' for countries not covered by a built-in jurisdiction.
     */
    function jur_country_to_code(string $iso2): string {
        $iso2 = strtoupper(trim($iso2));

        // EU member states + EEA (Norway, Iceland, Liechtenstein)
        $eu = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR',
               'HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI',
               'SK','NO','IS','LI'];

        if ($iso2 === 'GB') return 'uk';
        if ($iso2 === 'US') return 'us';
        if ($iso2 === 'CA') return 'canada';
        if ($iso2 === 'AU') return 'australia';
        if (in_array($iso2, $eu, true)) return 'eu';
        return '';
    }

    /**
     * Derive and persist jurisdiction settings from known infrastructure:
     *  — primary install country  → jurisdiction_primary   (settings table)
     *  — active linked DC countries → jurisdictions_additional (settings table)
     *
     * Called automatically after setup completes, and whenever a linked
     * location is added, removed, or toggled.
     *
     * Does NOT overwrite controller_name / controller_email / DPO fields —
     * those remain manually entered.
     *
     * Returns an array ['primary' => string, 'additional' => string[]]
     * describing what was written, for display in the admin UI.
     */
    function jur_sync_from_infrastructure(): array {
        $installCountry = getSetting('install_country', '');
        $primaryCode    = $installCountry ? jur_country_to_code($installCountry) : '';

        // Fall back to the DC_COUNTRY constant written by the alternative install wizard
        if ($primaryCode === '' && defined('DC_COUNTRY')) {
            $primaryCode = jur_country_to_code(DC_COUNTRY);
        }

        // If country is unknown/unmapped, keep whatever was previously set rather
        // than overwriting with a blank; otherwise default to 'eu'.
        if ($primaryCode === '') {
            $primaryCode = getSetting('jurisdiction_primary', 'eu');
        }

        // Gather additional jurisdictions from active linked DC locations
        $additional = [];
        try {
            $pdo  = getDB();
            $rows = $pdo->query(
                "SELECT DISTINCT country FROM locations WHERE is_active = 1 AND country != ''"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $iso2) {
                $jc = jur_country_to_code($iso2);
                if ($jc !== '' && $jc !== $primaryCode && !in_array($jc, $additional, true)) {
                    $additional[] = $jc;
                }
            }
        } catch (Throwable $e) {
            // DB not yet available during early setup — skip
        }

        setSetting('jurisdiction_primary',      $primaryCode);
        setSetting('jurisdictions_additional',  json_encode(array_values($additional)));

        return ['primary' => $primaryCode, 'additional' => $additional];
    }
}
