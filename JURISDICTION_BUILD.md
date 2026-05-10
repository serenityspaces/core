# Jurisdiction Block Builder — Build Progress

## Status

| Batch | Description | Status |
|-------|-------------|--------|
| 1 | Settings + Admin UI | ✅ COMPLETE |
| 2 | Block functions (`includes/jurisdiction/`) | ✅ COMPLETE |
| 3 | Rewrite `privacy.php` | ✅ COMPLETE |
| 4 | Update `ropa.php` | ✅ COMPLETE |

---

## Batch 1 — Settings + Admin UI
**Files modified:** `admin.php`
**Settings added** (all via `setSetting`/`getSetting`):

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `jurisdiction_primary` | string | `eu` | Primary jurisdiction code: `eu`, `uk`, `us`, `canada`, `australia`, `custom` |
| `jurisdictions_additional` | JSON array | `[]` | Additional jurisdiction codes that also apply |
| `controller_name` | string | `` | Data controller / platform operator name |
| `controller_address` | string | `` | Controller street address |
| `controller_email` | string | `` | Controller contact email |
| `dpo_name` | string | `` | DPO name (required for EU/UK if applicable) |
| `dpo_email` | string | `` | DPO email |
| `jurisdiction_custom_privacy_html` | text | `` | Custom HTML block appended to Privacy Notice (custom jurisdiction) |
| `jurisdiction_custom_ropa_html` | text | `` | Custom HTML block for ROPA page (custom jurisdiction) |

**Admin nav:** "Compliance & Jurisdiction" added under Insights section.
**Admin section id:** `section-compliance`

---

## Batch 2 — Block Functions
**Files to create:**
- `includes/jurisdiction/jurisdiction_helper.php` — `getActiveJurisdictions()`, `isJurisdictionActive($code)`, controller/DPO getters
- `includes/jurisdiction/blocks_privacy.php` — per-jurisdiction privacy notice block functions
- `includes/jurisdiction/blocks_ropa.php` — per-jurisdiction ROPA block functions

**Jurisdiction codes:** `eu`, `uk`, `us_hipaa`, `us_ccpa`, `canada`, `australia`, `custom`

---

## Batch 3 — Rewrite privacy.php
Assemble privacy.php from block functions.
Sections: Header, Intro, EU block (if active), UK block, US-HIPAA block, US-CCPA block, Canada block, Australia block, Custom block, Footer.

---

## Batch 4 — Update ropa.php
Gate ROPA display: show full ROPA for EU/UK, show "not applicable" notice with local law equivalent for US/Canada/Australia, show custom block for custom jurisdiction.

---

## Notes
- All block functions return HTML strings; privacy.php concatenates and echoes.
- Controller/DPO fields are HTML-escaped at render time, not storage time.
- `jurisdictions_additional` allows multi-select (e.g. EU primary + US secondary for a platform serving both markets).
- Custom HTML blocks are rendered via `htmlspecialchars_decode` (stored escaped, rendered raw) — admin-only input, not user input.
