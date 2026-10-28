# Altijara Content Tools

Technical documentation for the companion WordPress plugin that adds **language visibility**, **featured-image health**, and **content recommendations** for the Altijara magazine theme with TranslatePress.

| | |
|---|---|
| **Plugin slug** | `altijara-content-tools` |
| **Version** | 1.1.0 |
| **Requires** | WordPress 6.0+, PHP 7.4+ |
| **Hard dependency** | Active Altijara theme (`altijara-theme` / `altijara`) |
| **Soft dependency** | TranslatePress (`trp_get_current_language`) |
| **Text domain** | `altijara-content-tools` |
| **Author** | O2Nations |

---

## Purpose

TranslatePress keeps one post and translates strings. Editors still need posts that should appear only in Arabic, only in English, or in both. This plugin stores that choice as post meta and filters public listings accordingly.

It also gives editors an admin hub (Theme Options–style UI) for:

- Counts of AR-only / EN-only / Both posts
- A one-click list of published posts with no featured image
- Empty category / unused tag cleanup

**Not in scope (v1):** duplicate posts per language, auto-translation, filtering the `issue` CPT, changing the theme’s public “require featured image” SQL filter.

---

## Install & activate

1. Deploy folder to `wp-content/plugins/altijara-content-tools/`
2. Deploy Altijara theme including the `altijara_cache_key_parts` filter in `inc/core/caching.php` (required so EN/AR section caches do not collide)
3. Activate **Altijara theme**, then **Altijara Content Tools**
4. Recommended: activate TranslatePress (AR default + EN)

Without the theme, the plugin shows an admin error and loads no modules. Without TranslatePress, language detection falls back to the site locale (same idea as `altijara_is_english_view()`).

---

## Directory layout

```
altijara-content-tools/
├── altijara-content-tools.php   # Bootstrap, constants, helpers
├── includes/
│   ├── class-lang-meta.php      # Meta box, list column, quick/bulk edit
│   ├── class-lang-query.php     # Front + admin query filtering, cache key
│   ├── class-missing-thumbs.php # No-thumb admin filter + counts
│   ├── class-bulk-assign.php    # Bulk language assign by ID list
│   └── class-admin-hub.php      # Content Tools menu + panels
├── assets/
│   ├── css/admin.css            # Badges, stat cards, reco tables
│   └── js/admin.js              # Hub tab switching
├── README.md                    # This file
└── readme.txt                   # Short WP-style readme
```

Bootstrap runs on `after_setup_theme` priority `20` so the theme is already available.

---

## Language visibility

### Meta

| Key | Constant | Values | Default |
|-----|----------|--------|---------|
| `_altijara_lang_visibility` | `ALTIJARA_CT_LANG_META` | `both` \| `ar` \| `en` | `both` |

- Missing / empty / invalid meta is treated as **`both`** (safe for existing content).
- Registered with `register_post_meta( 'post', …, show_in_rest => true )`.
- Applies to **`post` only** — not `page`, not `issue`.

### Editor UX

- Side meta box **Language visibility** on post edit
- Posts list: **Language** column (Both / AR / EN badges)
- Dropdown filter: `edit.php?post_type=post&altijara_lang={both|ar|en}`
- Quick Edit + Bulk Edit fields

### Front-end filtering rules

| Current view | Excluded from listings |
|--------------|------------------------|
| English (`en`) | Posts with meta `ar` |
| Arabic (`ar`) | Posts with meta `en` |
| Other | No language exclusion |

Always included: `both` and posts with no meta.

**Listings only.** Singular requests (direct post URLs) are not filtered so share/editor links still open.

### How filtering is applied

1. `Altijara_CT_Lang_Query::filter_front_queries` (`pre_get_posts`) sets `altijara_ct_exclude_lang` when the query should be filtered.
2. `posts_clauses` (priority 25) adds SQL that excludes posts of type `post` whose meta equals the excluded language. Other post types in mixed queries are left alone.
3. `rest_post_query` sets the same query flag for REST post collections (category load-more via `wp-json`, etc.).
4. Admin + `admin-ajax` are **not** language-filtered (Section Manager pickers stay complete).

Opt out of filtering on a custom `WP_Query` by setting:

```php
'altijara_ct_skip_lang' => true,
```

### Language detection

`altijara_ct_current_lang()` returns `en` | `ar` | `other`:

1. Prefer theme `altijara_is_english_view()` when defined
2. Else `trp_get_current_language()` if TranslatePress is present
3. Else `determine_locale()` / `get_locale()`

---

## Theme coupling: cache keys

Theme helper (`altijara-theme/inc/core/caching.php`):

```php
$parts = apply_filters( 'altijara_cache_key_parts', array(), $group, $args );
```

The plugin appends `lang` => current language so `altijara_query_posts()` / section transients are language-aware. Without this theme patch, AR and EN could share one cached ID list after filtering.

Content saves still purge Altijara transients via the theme’s existing `save_post` invalidation.

---

## Missing featured images

The theme already hides posts without `_thumbnail_id` on the public front (`altijara_posts_clauses_require_thumb`). This plugin does **not** change that.

Admin tooling:

| Piece | Detail |
|-------|--------|
| Hub tab | **Featured images** — count + CTA |
| List URL | `edit.php?post_type=post&altijara_no_thumb=1` |
| List checkbox | “No featured image” on Posts screen |
| Categories column | Ensured visible so editors see category while fixing |

Deep link from hub matches the editorial workflow of opening a filtered `edit.php` list (same idea as `?category_name=…`).

---

## Admin hub — Content Tools

Top-level menu: **Content Tools** (`admin.php?page=altijara-content-tools`), capability `manage_options`, position 62.

UI reuses Altijara Theme Options shell CSS (`admin-sections.css`) when the theme is active, plus plugin overlay styles.

| Tab | Role |
|-----|------|
| **Overview** | Stat cards: total / both / AR-only / EN-only → filtered `edit.php`; quick chips |
| **Bulk assign** | Paste comma/newline-separated post IDs → set Both / AR / EN with hard caps |
| **Featured images** | Count + link to no-thumb list |
| **Recommendations** | Empty categories (delete / edit / view posts), unused tags (delete / edit), tip if missing thumbs remain |

### Bulk assign safeguards

| Limit | Default | Filter |
|-------|---------|--------|
| Max unique IDs per run | 100 (hard ceiling 200) | `altijara_ct_bulk_max_ids` |
| Max textarea characters | 4000 (ceiling 20000) | `altijara_ct_bulk_max_input_chars` |
| Cooldown | 3 seconds per user | — |

Also: dedupe IDs, `post` type only, skip trash, per-ID `edit_post` check, skip report (first 20), purge theme caches after successful updates. Handler: `admin-post.php?action=altijara_ct_bulk_assign_lang`.

Empty-term delete: `admin-post.php?action=altijara_ct_delete_empty_term` with nonce. Blocks default category and non-empty terms.

---

## Public PHP helpers

| Function | Returns |
|----------|---------|
| `altijara_ct_theme_active()` | `bool` |
| `altijara_ct_trp_available()` | `bool` |
| `altijara_ct_current_lang()` | `en`\|`ar`\|`other` |
| `altijara_ct_lang_values()` | `['both','ar','en']` |
| `altijara_ct_sanitize_lang( $value )` | normalized value |
| `altijara_ct_get_post_lang( $post_id )` | `both`\|`ar`\|`en` |
| `altijara_ct_lang_labels()` | translated labels |

Constants: `ALTIJARA_CT_VERSION`, `ALTIJARA_CT_FILE`, `ALTIJARA_CT_DIR`, `ALTIJARA_CT_URI`, `ALTIJARA_CT_LANG_META`.

---

## Query / request flags (internal)

| Flag / arg | Where | Meaning |
|------------|-------|---------|
| `altijara_ct_exclude_lang` | Front `WP_Query` | Exclude this meta value (`ar` or `en`) |
| `altijara_ct_admin_lang` | Admin posts list | Exact-match filter (`both` includes missing meta) |
| `altijara_ct_skip_lang` | Any query | Disable front language filter |
| `altijara_lang` | `$_GET` on `edit.php` | Admin language dropdown |
| `altijara_no_thumb` | `$_GET` on `edit.php` | Admin missing-thumb filter |
| `altijara_allow_no_thumb` | Set by plugin on no-thumb admin query | Aligns with theme thumb clause opt-out |

---

## Architecture (runtime)

```
Editor saves _altijara_lang_visibility
                │
                ▼
         postmeta (DB)
                │
    ┌───────────┴───────────┐
    │                       │
 Front view (TRP/locale)   Admin hub / edit.php
    │                       │
 exclude opposite lang     filter lists + counts
    │
 altijara_query_posts / main query / REST
    │
 cache key += lang (theme filter)
```

---

## Security notes

- Meta save: nonce `altijara_ct_save_lang`, capability `edit_post`
- Term delete: `manage_options` + `check_admin_referer( 'altijara_ct_delete_term_{id}' )` + confirm dialog
- All hub output escaped; SQL uses `$wpdb->prepare` where values are interpolated
- REST meta gated with `edit_posts` auth callback

---

## Acceptance checklist

- [ ] Plugin inactive or theme switched away → modules do not load (theme notice if plugin active alone)
- [ ] New post defaults to Both; appears on AR and EN home/category
- [ ] Post set to English only → hidden on Arabic listings; visible on English listings; direct URL works on both
- [ ] Post set to Arabic only → inverse of above
- [ ] Overview cards match filtered `edit.php` counts
- [ ] “View posts without featured image” opens list with Categories column
- [ ] Empty category delete removes only zero-count, non-default terms
- [ ] After language change, section caches show correct language-specific posts (theme cache filter present)

---

## Changelog

### 1.1.0

- Bulk assign language by ID list with validation/limits
- Hub UX: quick chips, featured-image health hero, bulk tab

### 1.0.0

- Initial release: language meta + front/REST filtering, Content Tools hub, missing-thumb list, empty term recommendations, theme `altijara_cache_key_parts` integration
