<?php
/**
 * Plugin Name: WP Media Cleaner
 * Description: Find and delete unused media library files.
 * Version: 1.2.0
 * Author: Rob Kalajian (https://robkalajian.me)
 */

defined( 'ABSPATH' ) || exit;

define( 'TYMC_VERSION', '1.2.0' );
define( 'TYMC_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function () {
	$hook = add_media_page(
		'Media Cleaner',
		'Media Cleaner',
		'delete_posts',
		'tymc-cleaner',
		'tymc_render_page'
	);

	// load-{$hook} fires right before the page renders — the most reliable
	// way to enqueue assets for a specific admin page.
	add_action( "load-{$hook}", function () {
		wp_enqueue_style(
			'tymc-admin',
			plugin_dir_url( TYMC_FILE ) . 'admin.css',
			[],
			TYMC_VERSION
		);
	} );
} );

// ---------------------------------------------------------------------------
// AJAX: scan
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_tymc_scan', function () {
	check_ajax_referer( 'tymc_nonce', 'nonce' );
	if ( ! current_user_can( 'delete_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$offset = max( 0, intval( $_POST['offset'] ?? 0 ) );
	$batch  = 100;

	$attachment_ids = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'posts_per_page' => $batch,
		'offset'         => $offset,
		'fields'         => 'ids',
	] );

	$counts = wp_count_posts( 'attachment' );
	$total  = (int) ( $counts->inherit ?? 0 )
			+ (int) ( $counts->{'0'} ?? 0 );

	// Batch-detect unused IDs — O(~6) queries for the whole batch.
	$unused_ids = tymc_filter_unused( $attachment_ids );

	$unused = [];
	foreach ( $unused_ids as $id ) {
		$file      = get_attached_file( $id );
		$meta      = wp_get_attachment_metadata( $id );
		$size_raw  = $meta['filesize'] ?? ( $file ? @filesize( $file ) : 0 );
		$mime      = get_post_mime_type( $id );
		$ext       = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';

		$unused[] = [
			'id'       => $id,
			'filename' => basename( $file ?? '' ) ?: "(attachment {$id})",
			'url'      => wp_get_attachment_url( $id ),
			'thumb'    => str_starts_with( $mime, 'image/' )
					? ( wp_get_attachment_image_url( $id, 'thumbnail' )
					  ?: wp_get_attachment_image_url( $id, 'medium' )
					  ?: wp_get_attachment_url( $id ) )
					: null,
			'type'     => $mime,
			'ext'      => $ext,
			'size'     => $size_raw ? size_format( $size_raw ) : '—',
			'size_raw' => (int) $size_raw,
		];
	}

	wp_send_json_success( [
		'items'   => $unused,
		'scanned' => $offset + count( $attachment_ids ),
		'total'   => $total,
		'done'    => count( $attachment_ids ) < $batch,
	] );
} );

// ---------------------------------------------------------------------------
// AJAX: delete
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_tymc_delete', function () {
	check_ajax_referer( 'tymc_nonce', 'nonce' );
	if ( ! current_user_can( 'delete_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$ids     = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
	$deleted = 0;
	$failed  = [];

	foreach ( $ids as $id ) {
		if ( ! $id || get_post_type( $id ) !== 'attachment' ) {
			continue;
		}

		// wp_delete_attachment( $id, true ) permanently:
		//   • deletes the post row and all postmeta from the database
		//   • deletes the original file from disk
		//   • deletes every resized variant (thumbnail, medium, large, custom sizes)
		//   • deletes any -scaled or -rotated originals WordPress stores separately
		// Passing `true` skips the trash and bypasses any filter that would move
		// it to the media trash instead of deleting immediately.
		if ( wp_delete_attachment( $id, true ) ) {
			$deleted++;
		} else {
			$failed[] = $id;
		}
	}

	wp_send_json_success( [ 'deleted' => $deleted, 'failed' => $failed ] );
} );

// ---------------------------------------------------------------------------
// Core: batch unused filter
// Replaces per-item queries with batch queries — ~6 queries per 100-item
// batch instead of up to 600.
// ---------------------------------------------------------------------------

/**
 * Given a list of attachment IDs, return only those not currently in use.
 *
 * @param int[] $ids
 * @return int[]
 */
function tymc_filter_unused( array $ids ): array {
	if ( empty( $ids ) ) return [];

	global $wpdb;

	$in_use = []; // $id => true

	// ── 1. Has a post parent (attached via editor uploader) ───────────────
	// Safe to interpolate: all values cast to int.
	$id_list = implode( ',', array_map( 'intval', $ids ) );
	$rows    = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($id_list) AND post_parent > 0"
	);
	foreach ( $rows as $id ) {
		$in_use[ (int) $id ] = true;
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 2. Used as a featured image ───────────────────────────────────────
	$placeholders = implode( ',', array_fill( 0, count( $remaining ), '%s' ) );
	$rows = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value IN ($placeholders)",
			...$remaining
		)
	);
	foreach ( $rows as $id ) {
		$in_use[ (int) $id ] = true;
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 3. Referenced by URL in post content ──────────────────────────────
	// Must be per-item (each URL differs). Cache URLs here; reused in check 7.
	$url_cache = [];
	foreach ( $remaining as $id ) {
		$url               = wp_get_attachment_url( $id );
		$url_cache[ $id ]  = $url;
		$url_no_scheme     = preg_replace( '#^https?://#', '//', $url );
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('trash','auto-draft')
			 AND (post_content LIKE %s OR post_content LIKE %s)
			 LIMIT 1",
			'%' . $wpdb->esc_like( $url ) . '%',
			'%' . $wpdb->esc_like( $url_no_scheme ) . '%'
		) ) ) {
			$in_use[ $id ] = true;
		}
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 4. WooCommerce product gallery ────────────────────────────────────
	// Fetch all gallery strings once; resolve in PHP.
	$galleries = $wpdb->get_col(
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
	);
	if ( $galleries ) {
		$remaining_flip = array_flip( $remaining );
		foreach ( $galleries as $gallery ) {
			foreach ( explode( ',', $gallery ) as $gid ) {
				$gid = (int) $gid;
				if ( isset( $remaining_flip[ $gid ] ) ) {
					$in_use[ $gid ] = true;
					unset( $remaining_flip[ $gid ] );
				}
			}
		}
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 5. Any postmeta value equal to this ID (ACF image/file fields) ────
	$placeholders = implode( ',', array_fill( 0, count( $remaining ), '%s' ) );
	$rows = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_value IN ($placeholders)
			 AND meta_key NOT IN ('_thumbnail_id','_product_image_gallery')
			 AND meta_key NOT LIKE '\_%wc%'",
			...$remaining
		)
	);
	foreach ( $rows as $id ) {
		if ( in_array( (int) $id, $remaining, true ) ) {
			$in_use[ (int) $id ] = true;
		}
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 6. Site icon / custom logo ────────────────────────────────────────
	$site_icon   = (int) get_option( 'site_icon' );
	$custom_logo = (int) get_theme_mod( 'custom_logo' );
	foreach ( $remaining as $id ) {
		if ( $id === $site_icon || $id === $custom_logo ) {
			$in_use[ $id ] = true;
		}
	}

	$remaining = array_values( array_diff( $ids, array_keys( $in_use ) ) );
	if ( empty( $remaining ) ) return [];

	// ── 7. Options table — URL or quoted ID (ACF options, widgets, theme mods)
	foreach ( $remaining as $id ) {
		$url = $url_cache[ $id ] ?? wp_get_attachment_url( $id );
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->options}
			 WHERE option_name NOT LIKE '\_transient\_%'
			 AND option_name NOT LIKE '\_site\_transient\_%'
			 AND (option_value LIKE %s OR option_value LIKE %s)
			 LIMIT 1",
			'%' . $wpdb->esc_like( $url ) . '%',
			'%"' . $wpdb->esc_like( (string) $id ) . '"%'
		) ) ) {
			$in_use[ $id ] = true;
		}
	}

	return array_values( array_diff( $ids, array_keys( $in_use ) ) );
}

// ---------------------------------------------------------------------------
// Page renderer
// ---------------------------------------------------------------------------

function tymc_render_page(): void {
	$nonce    = wp_create_nonce( 'tymc_nonce' );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<div class="wrap tymc-wrap">

		<!-- App header -->
		<div class="tymc-app-header">
			<div class="tymc-app-header-inner">
				<div class="tymc-app-header-left">
					<div class="tymc-app-header-logo">
						<?php echo tymc_svg( 'scan', 22 ); ?>
					</div>
					<div class="tymc-app-header-text">
						<h1>Media Cleaner</h1>
						<p>Find and permanently remove unused files from your media library.</p>
					</div>
				</div>
				<div class="tymc-app-header-actions">
					<button id="tymc-scan-btn" class="tymc-btn tymc-btn--primary">
						<?php echo tymc_svg( 'search', 15 ); ?>
						Scan Library
					</button>
				</div>
			</div>

			<!-- Progress bar — lives inside the header card -->
			<div class="tymc-header-progress" id="tymc-progress-wrap" hidden>
				<div class="tymc-progress-meta">
					<span id="tymc-progress-text">Preparing…</span>
					<strong id="tymc-progress-pct">0%</strong>
				</div>
				<div class="tymc-progress-track">
					<div class="tymc-progress-fill" id="tymc-progress-fill"></div>
				</div>
			</div>
		</div>

		<!-- Stat row -->
		<div class="tymc-stat-row" id="tymc-stats" hidden>
			<div class="tymc-stat-cell">
				<div class="tymc-stat-icon tymc-stat-icon--blue"><?php echo tymc_svg( 'layers', 18 ); ?></div>
				<div class="tymc-stat-body">
					<div class="tymc-stat-label">Total Scanned</div>
					<div class="tymc-stat-value" id="stat-scanned">—</div>
				</div>
			</div>
			<div class="tymc-stat-cell">
				<div class="tymc-stat-icon tymc-stat-icon--red"><?php echo tymc_svg( 'alert', 18 ); ?></div>
				<div class="tymc-stat-body">
					<div class="tymc-stat-label">Unused Files</div>
					<div class="tymc-stat-value" id="stat-unused">—</div>
				</div>
			</div>
			<div class="tymc-stat-cell">
				<div class="tymc-stat-icon tymc-stat-icon--green"><?php echo tymc_svg( 'disk', 18 ); ?></div>
				<div class="tymc-stat-body">
					<div class="tymc-stat-label">Est. Savings</div>
					<div class="tymc-stat-value" id="stat-size">—</div>
				</div>
			</div>
		</div>

		<!-- Notices -->
		<div id="tymc-notice" hidden></div>

		<!-- Results -->
		<div id="tymc-results" hidden>
			<div class="tymc-grid-header">
				<div class="tymc-grid-header-title">
					Unused files <span id="tymc-grid-count"></span>
				</div>
				<div class="tymc-grid-controls">
					<button id="tymc-select-all-btn" class="tymc-btn tymc-btn--ghost">Select all</button>
				</div>
			</div>
			<div class="tymc-grid" id="tymc-grid"></div>
		</div>

		<!-- Empty state -->
		<div id="tymc-empty" hidden>
			<div class="tymc-empty">
				<div class="tymc-empty-icon"><?php echo tymc_svg( 'check', 24 ); ?></div>
				<h3>All clear</h3>
				<p id="tymc-empty-msg">No unused media files found.</p>
			</div>
		</div>

		<!-- Floating selection bar -->
		<div class="tymc-float-bar" id="tymc-float-bar" aria-live="polite" hidden>
			<!-- Selection state -->
			<div id="tymc-float-selection">
				<div class="tymc-float-bar-count">
					<strong id="tymc-float-count">0</strong> selected
				</div>
				<div class="tymc-float-bar-divider"></div>
				<div class="tymc-float-bar-actions">
					<button id="tymc-deselect-btn" class="tymc-float-btn tymc-float-btn--deselect">Deselect all</button>
					<button id="tymc-delete-btn" class="tymc-float-btn tymc-float-btn--delete">
						<?php echo tymc_svg( 'trash', 13 ); ?>
						Delete selected
					</button>
				</div>
			</div>
			<!-- Delete progress state -->
			<div id="tymc-float-progress" hidden>
				<span id="tymc-float-progress-text">Deleting…</span>
				<div class="tymc-float-progress-track">
					<div class="tymc-float-progress-fill" id="tymc-float-progress-fill"></div>
				</div>
				<span id="tymc-float-progress-pct">0%</span>
			</div>
		</div>

		<!-- Confirm modal -->
		<div id="tymc-modal" class="tymc-modal-backdrop" hidden role="dialog" aria-modal="true" aria-labelledby="tymc-modal-title">
			<div class="tymc-modal">
				<div class="tymc-modal-head">
					<div class="tymc-modal-head-icon"><?php echo tymc_svg( 'alert-tri', 20 ); ?></div>
					<div>
						<h2 id="tymc-modal-title">Delete <span id="tymc-modal-count">0</span> file(s)?</h2>
						<p>This removes files from the server and database. It cannot be undone.</p>
					</div>
				</div>
				<div class="tymc-modal-body">
					<p>The following files will be permanently deleted, including all WordPress-generated sizes:</p>
					<div class="tymc-modal-filelist" id="tymc-modal-filelist"></div>
				</div>
				<div class="tymc-modal-foot">
					<button id="tymc-modal-cancel" class="tymc-btn tymc-btn--ghost">Cancel</button>
					<button id="tymc-modal-confirm" class="tymc-btn tymc-btn--danger">
						<?php echo tymc_svg( 'trash', 14 ); ?>
						Delete permanently
					</button>
				</div>
			</div>
		</div>

	</div><!-- .tymc-wrap -->

	<script>
	(function () {
		'use strict';

		const NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
		const AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;

		const $ = id => document.getElementById(id);

		const scanBtn      = $('tymc-scan-btn');
		const scanBtnIcon  = scanBtn.querySelector('svg');
		const deleteBtn    = $('tymc-delete-btn');
		const deselectBtn  = $('tymc-deselect-btn');
		const selectAllBtn = $('tymc-select-all-btn');
		const progressWrap = $('tymc-progress-wrap');
		const progressFill = $('tymc-progress-fill');
		const progressText = $('tymc-progress-text');
		const progressPct  = $('tymc-progress-pct');
		const statsEl      = $('tymc-stats');
		const noticeEl     = $('tymc-notice');
		const resultsEl    = $('tymc-results');
		const gridCountEl  = $('tymc-grid-count');
		const emptyEl      = $('tymc-empty');
		const emptyMsg     = $('tymc-empty-msg');
		const gridEl       = $('tymc-grid');
		const floatBar            = $('tymc-float-bar');
		const floatSelection      = $('tymc-float-selection');
		const floatCount          = $('tymc-float-count');
		const floatProgress       = $('tymc-float-progress');
		const floatProgressFill   = $('tymc-float-progress-fill');
		const floatProgressText   = $('tymc-float-progress-text');
		const floatProgressPct    = $('tymc-float-progress-pct');
		const statScanned  = $('stat-scanned');
		const statUnused   = $('stat-unused');
		const statSize     = $('stat-size');
		const modal        = $('tymc-modal');
		const modalCount   = $('tymc-modal-count');
		const modalFilelist= $('tymc-modal-filelist');
		const modalCancel  = $('tymc-modal-cancel');
		const modalConfirm = $('tymc-modal-confirm');

		let allItems  = [];
		let itemById  = new Map(); // id (number) → item — O(1) lookups
		let totalSize = 0;

		// ── Helpers ────────────────────────────────────────────────────────

		function post(action, data = {}) {
			const body = new URLSearchParams({ action, nonce: NONCE });
			for (const [key, val] of Object.entries(data)) {
				if (Array.isArray(val)) {
					// Serialize arrays as key[]=v1&key[]=v2 so PHP sees an array.
					val.forEach(v => body.append(key + '[]', v));
				} else {
					body.append(key, val);
				}
			}
			return fetch(AJAX_URL, { method: 'POST', body }).then(r => r.json());
		}

		function fmtBytes(b) {
			if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
			if (b >= 1048576)    return (b / 1048576).toFixed(1) + ' MB';
			if (b >= 1024)       return (b / 1024).toFixed(0) + ' KB';
			return b + ' B';
		}

		function showBanner(type, html) {
			const icons = {
				success: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>`,
				error:   `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>`,
				info:    `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>`,
			};
			noticeEl.innerHTML = `<div class="tymc-banner tymc-banner--${type}">${icons[type] ?? ''}<span>${html}</span></div>`;
			noticeEl.hidden = false;
		}

		function setProgress(pct, text, done = false) {
			progressFill.style.width = pct + '%';
			progressPct.textContent  = Math.round(pct) + '%';
			progressText.textContent = text;
			progressFill.classList.toggle('is-scanning', !done);
			progressFill.classList.toggle('is-done', done);
		}

		function selectedCards() {
			return Array.from(gridEl.querySelectorAll('.tymc-card.is-selected'));
		}

		function updateFloatBar() {
			const sel = selectedCards();
			const n   = sel.length;
			floatCount.textContent = n;
			floatBar.hidden = n === 0;
			floatBar.classList.toggle('is-visible', n > 0);
			const all = gridEl.querySelectorAll('.tymc-card').length;
			selectAllBtn.textContent = (n === all && all > 0) ? 'Deselect all' : 'Select all';
		}

		// ── Card builder ───────────────────────────────────────────────────

		function buildCard(item) {
			const card = document.createElement('div');
			card.className  = 'tymc-card';
			card.dataset.id = item.id;

			const thumbHtml = item.thumb
				? `<img src="${item.thumb}" alt="" loading="lazy">`
				: `<div class="tymc-card-filetype">
					<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
					<span class="tymc-card-filetype-ext">${item.ext || item.type.split('/')[1] || '?'}</span>
				   </div>`;

			card.innerHTML = `
				<div class="tymc-card-pip">
					<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="none" viewBox="0 0 24 24" stroke-width="3.5" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
					</svg>
				</div>
				<div class="tymc-card-thumb">${thumbHtml}</div>
				<div class="tymc-card-footer">
					<div class="tymc-card-name" title="${item.filename}">${item.filename}</div>
					<div class="tymc-card-size">${item.size}</div>
				</div>
			`;

			card.addEventListener('click', () => {
				card.classList.toggle('is-selected');
				updateFloatBar();
			});

			return card;
		}

		// ── Scan ───────────────────────────────────────────────────────────

		async function runScan() {
			allItems  = [];
			itemById  = new Map();
			totalSize = 0;
			gridEl.innerHTML = '';
			resultsEl.hidden  = true;
			emptyEl.hidden    = true;
			noticeEl.hidden   = true;
			statsEl.hidden    = true;
			floatBar.classList.remove('is-visible');
			progressWrap.hidden = false;
			scanBtn.disabled    = true;
			scanBtnIcon.classList.add('tymc-spin');
			setProgress(0, 'Preparing scan…');

			let offset = 0, done = false, total = 0;

			while (!done) {
				let resp;
				try {
					resp = await post('tymc_scan', { offset });
				} catch {
					showBanner('error', 'Network error — please try again.');
					scanBtn.disabled = false;
					scanBtnIcon.classList.remove('tymc-spin');
					progressWrap.hidden = true;
					return;
				}

				if (!resp.success) {
					showBanner('error', 'Scan error: ' + (resp.data ?? 'unknown'));
					scanBtn.disabled = false;
					scanBtnIcon.classList.remove('tymc-spin');
					progressWrap.hidden = true;
					return;
				}

				total  = resp.data.total;
				done   = resp.data.done;
				offset = resp.data.scanned;

				const pct  = total > 0 ? (offset / total) * 100 : 0;
				setProgress(pct, `Scanning — ${offset.toLocaleString()} of ${total.toLocaleString()} files`);

				resp.data.items.forEach(item => {
					allItems.push(item);
					itemById.set(item.id, item);
					totalSize += item.size_raw || 0;
					gridEl.appendChild(buildCard(item));
				});

				statsEl.hidden = false;
				statScanned.textContent = total.toLocaleString();
				statUnused.textContent  = allItems.length.toLocaleString();
				statUnused.className    = 'tymc-stat-value' + (allItems.length > 0 ? ' is-warn' : ' is-ok');
				statSize.textContent    = totalSize > 0 ? fmtBytes(totalSize) : '—';
			}

			setProgress(100, `Scan complete — ${total.toLocaleString()} files checked`, true);
			scanBtn.disabled = false;
			scanBtnIcon.classList.remove('tymc-spin');

			if (allItems.length > 0) {
				resultsEl.hidden = false;
				gridCountEl.textContent = `(${allItems.length})`;
				updateFloatBar();
				showBanner('info',
					`Found <strong>${allItems.length}</strong> unused file${allItems.length !== 1 ? 's' : ''} — ` +
					`<strong>${fmtBytes(totalSize)}</strong> recoverable. Select files below, then delete.`
				);
			} else {
				emptyEl.hidden = false;
				statUnused.className = 'tymc-stat-value is-ok';
			}
		}

		// ── Select all toggle ──────────────────────────────────────────────

		selectAllBtn.addEventListener('click', () => {
			const cards = gridEl.querySelectorAll('.tymc-card');
			const allSelected = selectedCards().length === cards.length;
			cards.forEach(c => c.classList.toggle('is-selected', !allSelected));
			updateFloatBar();
		});

		deselectBtn.addEventListener('click', () => {
			gridEl.querySelectorAll('.tymc-card').forEach(c => c.classList.remove('is-selected'));
			updateFloatBar();
		});

		// ── Delete flow ────────────────────────────────────────────────────

		function openModal() {
			const sel = selectedCards();
			if (!sel.length) return;

			modalCount.textContent = sel.length;
			modalFilelist.innerHTML = sel.slice(0, 25).map(c => {
				const item = itemById.get(Number(c.dataset.id));
				if (!item) return '';
				return `<div class="tymc-modal-filelist-item">
					<span class="tymc-modal-filelist-name">${item.filename}</span>
					<span class="tymc-modal-filelist-size">${item.size}</span>
				</div>`;
			}).join('') + (sel.length > 25
				? `<div class="tymc-modal-filelist-item"><span class="tymc-modal-filelist-name" style="color:#8c8f94">… and ${sel.length - 25} more</span></div>`
				: '');

			modal.hidden = false;
			modalConfirm.focus();
		}

		deleteBtn.addEventListener('click', openModal);
		modalCancel.addEventListener('click', () => { modal.hidden = true; });
		modal.addEventListener('click', e => { if (e.target === modal) modal.hidden = true; });
		document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) modal.hidden = true; });

		function setDeleteProgress(done, total) {
			const pct = total > 0 ? Math.round((done / total) * 100) : 0;
			floatProgressFill.style.width = pct + '%';
			floatProgressPct.textContent  = pct + '%';
			floatProgressText.textContent = `Deleting — ${done.toLocaleString()} of ${total.toLocaleString()}`;
		}

		modalConfirm.addEventListener('click', async () => {
			modal.hidden = true;

			const sel = selectedCards();
			const ids = sel.map(c => c.dataset.id);
			const CHUNK = 50; // stay well under PHP's max_input_vars (default 1000)

			// Switch float bar to progress mode — visible at all scroll positions.
			floatSelection.hidden = true;
			floatProgress.hidden  = false;
			floatBar.hidden       = false;
			floatBar.classList.add('is-visible');
			setDeleteProgress(0, ids.length);

			noticeEl.hidden = true;

			let totalDeleted = 0;
			const allFailed  = [];

			for (let i = 0; i < ids.length; i += CHUNK) {
				const chunk = ids.slice(i, i + CHUNK);
				setDeleteProgress(i, ids.length);

				let resp;
				try {
					resp = await post('tymc_delete', { ids: chunk });
				} catch {
					floatSelection.hidden = false;
					floatProgress.hidden  = true;
					showBanner('error', 'Network error — some files may not have been removed.');
					updateFloatBar();
					return;
				}

				if (!resp.success) {
					floatSelection.hidden = false;
					floatProgress.hidden  = true;
					showBanner('error', 'Delete error: ' + (resp.data ?? 'unknown'));
					updateFloatBar();
					return;
				}

				totalDeleted += resp.data.deleted;
				allFailed.push(...resp.data.failed);
			}

			setDeleteProgress(ids.length, ids.length);
			// Brief pause so user sees 100% before the bar disappears.
			await new Promise(r => setTimeout(r, 400));

			// Restore float bar to selection mode.
			floatSelection.hidden = false;
			floatProgress.hidden  = true;
			floatProgressFill.style.width = '0%';

			const failedSet = new Set(allFailed.map(String));

			sel.forEach(card => {
				if (!failedSet.has(card.dataset.id)) {
					card.classList.add('is-deleting');
					setTimeout(() => card.remove(), 240);
				}
			});

			const deletedIdSet = new Set(ids.filter(id => !failedSet.has(id)).map(Number));
			allItems  = allItems.filter(i => !deletedIdSet.has(i.id));
			deletedIdSet.forEach(id => itemById.delete(id));
			totalSize = allItems.reduce((a, i) => a + (i.size_raw || 0), 0);

			statUnused.textContent = allItems.length.toLocaleString();
			statUnused.className   = 'tymc-stat-value' + (allItems.length > 0 ? ' is-warn' : ' is-ok');
			statSize.textContent   = totalSize > 0 ? fmtBytes(totalSize) : '—';

			setTimeout(() => {
				updateFloatBar();
				gridCountEl.textContent = allItems.length > 0 ? `(${allItems.length})` : '';

				let msg = `Deleted <strong>${totalDeleted}</strong> file${totalDeleted !== 1 ? 's' : ''} — files, all WordPress-generated sizes, and database records removed.`;
				if (allFailed.length) msg += ` <strong>${allFailed.length}</strong> could not be removed.`;
				showBanner(allFailed.length ? 'error' : 'success', msg);

				if (allItems.length === 0) {
					resultsEl.hidden = true;
					emptyEl.hidden   = false;
					emptyMsg.textContent = 'All selected files have been deleted.';
					statUnused.className = 'tymc-stat-value is-ok';
				}
			}, 260);
		});

		scanBtn.addEventListener('click', runScan);
	}());
	</script>
	<?php
}

// ---------------------------------------------------------------------------
// SVG helper
// ---------------------------------------------------------------------------

function tymc_svg( string $name, int $size = 16 ): string {
	$s = $size;
	$d = [
		'scan'      => 'M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15',
		'search'    => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
		'trash'     => 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
		'alert'     => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
		'alert-tri' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
		'check'     => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		'layers'    => 'M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3',
		'disk'      => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
	];
	if ( ! isset( $d[ $name ] ) ) return '';
	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="%s"/></svg>',
		$s, $s, esc_attr( $d[ $name ] )
	);
}
