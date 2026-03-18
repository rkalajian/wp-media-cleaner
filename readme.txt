=== Simple Media Cleaner ===
Contributors: robkalajian
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.4.0
Requires PHP: 8.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress admin plugin that scans the media library for unused attachments and permanently deletes them — files, all WordPress-generated sizes, and database records in one pass.

== Description ==

Simple Media Cleaner scans your media library for unused attachments and permanently deletes them — files, all WordPress-generated sizes, and database records in one pass.

= Features =

* Batched scan with live progress bar — handles large libraries without timeouts
* Seven-point usage check: post parent, featured image, post content URLs, WooCommerce product gallery, ACF image/file fields, site icon/custom logo, and options table
* Card grid UI with thumbnail previews and file type badges
* Multi-select with floating action bar
* Confirmation modal listing files before deletion
* Live stats: total scanned, unused count, estimated disk savings

= How it works =

**Scan** — Fetches attachments in batches of 100 via AJAX. For each batch, a single call to `tymc_filter_unused()` runs ~6–8 DB queries to classify the entire batch, rather than per-attachment queries. Attachments that pass all seven checks are returned as unused and rendered as cards.

**Delete** — Selected IDs are sent to `wp_delete_attachment( $id, true )`, which permanently removes the post row, all postmeta, the original file, and every resized variant WordPress has generated.

= Usage checks =

An attachment is considered in use if any of the following are true:

1. Has a post_parent (attached via editor uploader) — Batch DB query
2. Used as a featured image (_thumbnail_id) — Batch IN() query
3. URL appears in any published post content — Per-item LIKE query
4. Included in a WooCommerce product gallery — Single query, resolved in PHP
5. Stored in any postmeta value (ACF image/file fields) — Batch IN() query
6. Set as site icon or custom logo — get_option / get_theme_mod
7. URL or quoted ID appears in the options table — Per-item LIKE query

== Installation ==

1. Drop the `simple-media-cleaner/` folder into `wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. The tool appears under **Media → Media Cleaner**

== Changelog ==

= 1.4.0 =
* Renamed plugin from WP Media Cleaner to Simple Media Cleaner

= 1.3.0 =
* Fixed delete doing nothing for large selections: IDs are now sent in chunks of 50, staying well under PHP's max_input_vars limit (default 1000)
* Deletion progress is now shown in the floating action bar (fixed at the bottom of the viewport) so it remains visible regardless of scroll position
* Progress bar fills per-chunk with a file count and percentage; holds at 100% briefly before restoring the selection UI

= 1.2.0 =
* Replaced per-attachment tymc_is_unused() with tymc_filter_unused() — batch DB queries reduce scan query count from ~6N to ~6–8 per 100-item batch
* Fixed multi-file delete: URLSearchParams array serialization bug meant only the first selected ID was deleted
* URL computed during content check is cached and reused for the options check
* JS: added itemById Map for O(1) modal lookups; cached scan button SVG reference; post-delete filtering uses a Set

= 1.1.0 =
* Added WooCommerce product gallery check
* Added options table check for ACF options fields
* Added site icon and custom logo exclusions
* Progress bar with shimmer animation

= 1.0.0 =
* Initial release
