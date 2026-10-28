# Altijara Content Tools — User Manual

Client / editor guide for the **Altijara Content Tools** plugin.  
Technical details for developers live in the plugin root [`README.md`](../README.md).

**Version covered:** 1.1.0

---

## 1. What this plugin does

Altijara Content Tools works with the **Altijara theme** and **TranslatePress** so each article can appear in:

- **Both** languages (default)
- **Arabic only**
- **English only**

On the public site, listings (home, categories, search, related blocks, etc.) hide posts that do not match the current language. Opening a post by its direct URL still works.

The plugin also adds a **Content Tools** admin hub for:

- Language analytics (how many posts are Both / AR / EN)
- Finding posts **without a featured image**
- **Bulk assign** language by pasting post IDs
- **Recommendations** (empty categories and unused tags)

---

## 2. Requirements

| Requirement | Notes |
|-------------|--------|
| WordPress | 6.0+ |
| **Altijara theme** | Must be active |
| **TranslatePress** | Recommended (AR + EN). Without it, language still follows the site locale |
| Capability | Editors use post meta; Content Tools hub needs `manage_options` (Administrators) |

---

## 3. Install and activate

1. Copy the folder `altijara-content-tools` into `wp-content/plugins/`.
2. In **Plugins**, activate **Altijara Content Tools**.
3. Confirm the **Altijara** theme is active.
4. In the left admin menu, open **Content Tools**.

![Content Tools menu in WP Admin](screenshots/00-content-tools-menu.png)

> **Capture:** WordPress admin sidebar with **Content Tools** highlighted.

---

## 4. Flow A — Set language on one post

Use this when editing a single article.

1. Go to **Posts → All Posts** and open a post (or create a new one).
2. In the sidebar, find **Language visibility**.
3. Choose **Both**, **Arabic only**, or **English only**.
4. Click **Update** / **Publish**.

![Language visibility meta box](screenshots/01-post-language-metabox.png)

> **Capture:** Post edit screen, side panel **Language visibility** with the three radio options visible.

**Notes**

- New and existing posts without a choice behave as **Both**.
- This applies to regular **posts** only (not pages, not magazine **Issues**).

---

## 5. Flow B — Posts list (column and filters)

1. Go to **Posts → All Posts**.
2. Find the **Language** column (Both / AR / EN badges).
3. Use the **language dropdown** above the list to filter by Both / Arabic only / English only.
4. Optionally check **No featured image** to list posts missing a featured image (Categories column stays available).

![Posts list — Language column](screenshots/02-posts-list-language-column.png)

> **Capture:** Posts list table showing titles and the **Language** badge column.

![Posts list — filters](screenshots/03-posts-list-filters.png)

> **Capture:** Top of Posts list with the language dropdown and **No featured image** checkbox.

**Useful URLs**

- Language filter: `wp-admin/edit.php?post_type=post&altijara_lang=ar` (or `en` / `both`)
- No featured image: `wp-admin/edit.php?post_type=post&altijara_no_thumb=1`

You can also set language via **Quick Edit** or **Bulk Edit** on this screen.

---

## 6. Flow C — Content Tools hub

Open **Content Tools** from the admin menu. Use the left tabs to move between panels.

### 6.1 Overview

- Cards show counts: **Published posts**, **Both**, **Arabic only**, **English only**.
- Click a card to open the matching filtered Posts list.
- Quick chips jump to Bulk assign, Missing images, and Empty categories.

![Content Tools — Overview](screenshots/04-hub-overview.png)

> **Capture:** Content Tools hub, **Overview** tab, with stat cards and quick chips.

### 6.2 Bulk assign

Use this to set language on many posts at once.

1. Open the **Bulk assign** tab.
2. Paste post IDs separated by commas or new lines (example: `1204, 1588, 2011`).
3. Choose **Both** / **Arabic only** / **English only**.
4. Click **Assign language** and confirm.

**Safeguards (to avoid overload)**

- Max **100** unique IDs per run  
- Max **4000** characters in the box  
- Short cooldown between runs  
- Only regular **posts**; skips trash, missing IDs, and posts you cannot edit  
- You get a success notice and a skip report for rejected IDs  

![Content Tools — Bulk assign](screenshots/05-hub-bulk-assign.png)

> **Capture:** Bulk assign tab with the ID textarea, language radios, and safeguards tip cards.

**Tip:** Open a filtered Posts list first, note the IDs you need, then paste them here.

### 6.3 Featured images

1. Open **Featured images**.
2. Read the count of published posts without a featured image.
3. Click **View posts without featured image** to open the filtered Posts list (with Categories).

The theme already hides these posts from public listings until a featured image is set.

![Content Tools — Featured images](screenshots/06-hub-featured-images.png)

> **Capture:** Featured images tab with the large count and primary button.

### 6.4 Recommendations

1. Open **Recommendations**.
2. Review **Empty categories** (0 posts) — Edit, View posts, or **Delete** (with confirmation). The default category is never listed for delete.
3. Review **Unused tags** the same way.
4. If missing featured images exist, a callout links back to that list.

![Content Tools — Recommendations](screenshots/07-hub-recommendations.png)

> **Capture:** Recommendations tab with empty categories/tags table and actions.

---

## 7. Flow D — Front-end behavior

TranslatePress switches the public language (Arabic vs English). The plugin then filters **listings** only:

| Current public language | What readers see in listings |
|-------------------------|------------------------------|
| Arabic | Posts set to **Both** or **Arabic only** (hides **English only**) |
| English | Posts set to **Both** or **English only** (hides **Arabic only**) |

- Home sections, category archives, search, tags, related blocks, and REST-powered loads follow this rule.
- A direct link to a single post still opens even if that post is “wrong” language for the current view.

![Arabic front listing](screenshots/08-front-ar-listing.png)

> **Capture (optional):** Arabic homepage or category grid — confirm English-only posts are absent.

![English front listing](screenshots/09-front-en-listing.png)

> **Capture (optional):** English homepage or category grid — confirm Arabic-only posts are absent.

---

## 8. Quick reference

### Language values

| Editor label | Stored value | Default |
|--------------|--------------|---------|
| Both | `both` | Yes (also if meta is missing) |
| Arabic only | `ar` | |
| English only | `en` | |

Meta key: `_altijara_lang_visibility` (posts only).

### Admin entry points

| Task | Where |
|------|--------|
| One post | Post edit → **Language visibility** |
| List / filter | **Posts → All Posts** |
| Hub | **Content Tools** menu |
| Bulk by IDs | Content Tools → **Bulk assign** |
| No featured image list | Content Tools → **Featured images** → button, or `?altijara_no_thumb=1` |

### Bulk assign limits

| Limit | Default |
|-------|---------|
| Unique IDs per run | 100 |
| Textarea length | 4000 characters |
| Cooldown | ~3 seconds between runs |

---

## 9. Screenshot checklist

Drop PNG files into [`screenshots/`](screenshots/) using these names:

| File | Required | What to capture |
|------|----------|-----------------|
| `00-content-tools-menu.png` | Recommended | Admin sidebar — Content Tools |
| `01-post-language-metabox.png` | Yes | Post edit — Language visibility box |
| `02-posts-list-language-column.png` | Yes | Posts list — Language column |
| `03-posts-list-filters.png` | Yes | Language dropdown + No featured image |
| `04-hub-overview.png` | Yes | Hub Overview tab |
| `05-hub-bulk-assign.png` | Yes | Hub Bulk assign tab |
| `06-hub-featured-images.png` | Yes | Hub Featured images tab |
| `07-hub-recommendations.png` | Yes | Hub Recommendations tab |
| `08-front-ar-listing.png` | Optional | Arabic front listing |
| `09-front-en-listing.png` | Optional | English front listing |

After files are added, the images above will show automatically in this manual (Markdown preview or GitHub/GitLab).
