#!/usr/bin/php
<?php
/**
 * BATCH-PROCESSING QUIC.cloud IP Sync for Cloudflare
 *
 * This script synchronizes QUIC.cloud IPs with Cloudflare's "IP Access Rules".
 * To solve browser timeouts, it uses a self-redirecting batch processing system.
 * Cron job execution runs everything in a single process.
 *
 * @version 2.0.0
 * @author Anshuman
 * @link https://facebook.com/realmranshuman
 *
 * --- HOW IT WORKS ---
 * - Browser: An initial request calculates all needed changes. It then redirects to
 *   `?batch=1`. Each batch processes a small number of IPs (e.g., 10) and then
 *   redirects to the next batch (`?batch=2`, etc.) until all work is done.
 * - Cron: Detects a non-browser environment and processes all changes in one go.
 *
 * --- REQUIREMENTS ---
 * - Must be in the WordPress root directory.
 * - LiteSpeed Cache plugin must be configured with Cloudflare credentials.
 *
 */

// --- Environment & Configuration ---

define( 'IS_CLI', 'cli' === php_sapi_name() );

/**
 * The number of API calls (additions or deletions) to perform in each browser batch.
 * A smaller number is safer for shared hosting with strict execution limits.
 * @var int
 */
define( 'QCS_BATCH_SIZE', 10 );

/**
 * The delay in seconds before the browser redirects to the next batch.
 * @var int
 */
define( 'QCS_REDIRECT_DELAY', 2 );

// --- WordPress Bootstrap ---

if ( ! IS_CLI ) {
	header( 'Content-Type: text/html; charset=utf-8' ); // Use HTML for meta-refresh
	echo '<!DOCTYPE html><html><head><title>Cloudflare Sync</title></head><body style="font-family: monospace; background: #111; color: #eee; line-height: 1.6;">';
	echo '<h1>QUIC.cloud to Cloudflare Sync v2.0</h1>';
}

chdir( __DIR__ );
if ( file_exists( 'wp-load.php' ) ) {
	require_once 'wp-load.php';
} else {
	echo '<p style="color: red;"><strong>FATAL ERROR:</strong> wp-load.php not found. Script must be in the WordPress root.</p>';
	if ( ! IS_CLI ) echo '</body></html>';
	exit( 1 );
}

// --- Security Check ---

if ( ! IS_CLI && ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Access Denied: You must be a logged-in administrator to run this script.' );
}

// --- Credentials ---

$cf_email   = get_option( 'litespeed.conf.cdn-cloudflare_email' );
$cf_api_key = get_option( 'litespeed.conf.cdn-cloudflare_key' );
$cf_zone_id = get_option( 'litespeed.conf.cdn-cloudflare_zone' );

define( 'QCS_IPS_URL', 'https://quic.cloud/ips?json' );
define( 'QCS_RULE_NOTE_IDENTIFIER', 'Managed by QUIC.cloud Sync Script' );

// --- Helper Functions ---

function qcs_cloudflare_api_request( $url, $method = 'GET', $data = [] ) {
	global $cf_email, $cf_api_key;
	$args = [ 'method' => $method, 'headers' => [ 'X-Auth-Email' => $cf_email, 'X-Auth-Key' => $cf_api_key, 'Content-Type' => 'application/json' ], 'timeout' => 45 ];
	if ( ! empty( $data ) ) $args['body'] = wp_json_encode( $data );
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) return [ 'body' => null, 'status' => 500 ];
	return [ 'body' => json_decode( wp_remote_retrieve_body( $response ), true ), 'status' => wp_remote_retrieve_response_code( $response ) ];
}

function qcs_fetch_quic_cloud_ips() {
	$response = wp_remote_get( QCS_IPS_URL );
	$ips = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $ips ) ? $ips : [];
}

/**
 * Gets ALL "allow" rules from Cloudflare, including manually added ones.
 * Also specifically identifies rules managed by this script.
 *
 * @return array [ 'all_ips' => [], 'managed_rules' => [] ]
 */
function qcs_get_all_cf_rules() {
	global $cf_zone_id;
	$page          = 1;
	$all_ips       = [];
	$managed_rules = [];

	while ( true ) {
		$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/firewall/access_rules/rules?page=%d&per_page=100&mode=whitelist', $cf_zone_id, $page );
		$response = qcs_cloudflare_api_request( $url );
		if ( 200 !== $response['status'] ) break;
		$data = $response['body'];
		if ( empty( $data['result'] ) ) break;

		foreach ( $data['result'] as $rule ) {
			$ip = $rule['configuration']['value'] ?? null;
			if ( ! $ip ) continue;
			$all_ips[] = $ip;
			if ( false !== strpos( $rule['notes'], QCS_RULE_NOTE_IDENTIFIER ) ) {
				$managed_rules[] = [ 'id' => $rule['id'], 'ip' => $ip ];
			}
		}

		$result_info = $data['result_info'] ?? [];
		if ( ( $result_info['page'] ?? 1 ) >= ( $result_info['total_pages'] ?? 1 ) ) break;
		$page++;
	}
	return [ 'all_ips' => $all_ips, 'managed_rules' => $managed_rules ];
}

// --- Main Logic ---

// 1. Initial Data Fetch (Done in all modes)
if ( empty( $cf_email ) || empty( $cf_api_key ) || empty( $cf_zone_id ) ) {
	echo '<p style="color: red;"><strong>ERROR:</strong> Missing Cloudflare Credentials in LiteSpeed Cache settings.</p>';
	if ( ! IS_CLI ) echo '</body></html>';
	exit( 1 );
}

echo "<p>Fetching IP lists from QUIC.cloud and Cloudflare...</p>";
$quic_cloud_ips  = qcs_fetch_quic_cloud_ips();
$cf_rules_data   = qcs_get_all_cf_rules();
$all_cf_ips      = $cf_rules_data['all_ips'];
$managed_cf_rules = $cf_rules_data['managed_rules'];
$managed_cf_ips  = wp_list_pluck( $managed_cf_rules, 'ip' );

// 2. Calculate the total work to be done
// We add to this list ONLY if the IP doesn't already exist in ANY allow rule.
$ips_to_add    = array_diff( $quic_cloud_ips, $all_cf_ips );
// We remove from this list ONLY if the IP was previously managed by this script.
$ips_to_remove = array_diff( $managed_cf_ips, $quic_cloud_ips );

$jobs = [];
foreach ( $ips_to_remove as $ip ) {
	$jobs[] = [ 'action' => 'remove', 'ip' => $ip ];
}
foreach ( $ips_to_add as $ip ) {
	$jobs[] = [ 'action' => 'add', 'ip' => $ip ];
}
$total_jobs = count($jobs);

// 3. Execute based on mode (CLI vs Browser)

if ( IS_CLI ) {
	// --- CRON JOB EXECUTION ---
	echo "CLI mode detected. Processing all $total_jobs tasks now...\n";
	$processed = 0;
	foreach($jobs as $job) {
		$processed++;
		echo "[$processed/$total_jobs] " . ucfirst($job['action']) . "ing IP: " . $job['ip'] . "... ";
		if ( 'add' === $job['action'] ) {
			$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/firewall/access_rules/rules', $cf_zone_id );
			$note = QCS_RULE_NOTE_IDENTIFIER . ' | Added on ' . date( 'Y-m-d' );
			$payload = [ 'mode' => 'whitelist', 'configuration' => [ 'target' => 'ip', 'value' => $job['ip'] ], 'notes' => $note ];
			$response = qcs_cloudflare_api_request( $url, 'POST', $payload );
		} else { // remove
			$rule_id_to_remove = null;
			foreach ($managed_cf_rules as $rule) { if ($rule['ip'] === $job['ip']) { $rule_id_to_remove = $rule['id']; break; } }
			if ($rule_id_to_remove) {
				$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/firewall/access_rules/rules/%s', $cf_zone_id, $rule_id_to_remove );
				$response = qcs_cloudflare_api_request( $url, 'DELETE' );
			}
		}
		echo ( 200 === $response['status'] ) ? "Success.\n" : "Failed.\n";
	}
	echo "\nCron job finished.\n";

} else {
	// --- BROWSER BATCH EXECUTION ---
	$current_batch = isset( $_GET['batch'] ) ? absint( $_GET['batch'] ) : 0;
	$total_batches = $total_jobs > 0 ? ceil( $total_jobs / QCS_BATCH_SIZE ) : 0;

	if ( $current_batch === 0 ) {
		// Initial browser request: Kick off the process.
		if ( $total_jobs > 0 ) {
			echo "<p>Sync required. Found $total_jobs tasks to process across $total_batches batches.</p>";
			echo "<p style='color: yellow;'>Starting process... Your browser will now automatically redirect through each batch.</p>";
			// Redirect to the first batch
			$redirect_url = esc_url_raw( add_query_arg( 'batch', 1 ) );
			printf( '<meta http-equiv="refresh" content="%d;url=%s">', QCS_REDIRECT_DELAY, $redirect_url );
		} else {
			echo "<p style='color: lightgreen;'><strong>Sync Complete!</strong> No changes were needed.</p>";
		}
	} else {
		// Processing a specific batch
		echo "<h2>Processing Batch $current_batch of $total_batches...</h2>";
		$offset = ( $current_batch - 1 ) * QCS_BATCH_SIZE;
		$jobs_for_this_batch = array_slice( $jobs, $offset, QCS_BATCH_SIZE );

		if ( empty($jobs_for_this_batch) ) {
			echo "<p style='color: lightgreen;'><strong>Sync Complete!</strong> All tasks have been processed.</p>";
		} else {
			echo "<ul>";
			foreach( $jobs_for_this_batch as $job ) {
				echo "<li>" . ucfirst($job['action']) . "ing IP: " . esc_html($job['ip']) . "... ";
				if ( 'add' === $job['action'] ) {
					$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/firewall/access_rules/rules', $cf_zone_id );
					$note = QCS_RULE_NOTE_IDENTIFIER . ' | Added on ' . date( 'Y-m-d' );
					$payload = [ 'mode' => 'whitelist', 'configuration' => [ 'target' => 'ip', 'value' => $job['ip'] ], 'notes' => $note ];
					$response = qcs_cloudflare_api_request( $url, 'POST', $payload );
				} else { // remove
					$rule_id_to_remove = null;
					foreach ($managed_cf_rules as $rule) { if ($rule['ip'] === $job['ip']) { $rule_id_to_remove = $rule['id']; break; } }
					if ($rule_id_to_remove) {
						$url = sprintf( 'https://api.cloudflare.com/client/v4/zones/%s/firewall/access_rules/rules/%s', $cf_zone_id, $rule_id_to_remove );
						$response = qcs_cloudflare_api_request( $url, 'DELETE' );
					}
				}
				echo ( 200 === $response['status'] ) ? "<span style='color: lightgreen;'>Success.</span></li>" : "<span style='color: red;'>Failed.</span></li>";
				flush(); // Send output to the browser immediately
			}
			echo "</ul>";

			// Redirect to the next batch
			$next_batch = $current_batch + 1;
			echo "<p style='color: yellow;'>Batch $current_batch complete. Redirecting to next batch in " . QCS_REDIRECT_DELAY . " seconds...</p>";
			$redirect_url = esc_url_raw( add_query_arg( 'batch', $next_batch ) );
			printf( '<meta http-equiv="refresh" content="%d;url=%s">', QCS_REDIRECT_DELAY, $redirect_url );
		}
	}
	echo '</body></html>';
}

exit(0);
