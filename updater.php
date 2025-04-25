#!/usr/bin/php
<?php
// quic-cloudflare-integration.php

/**
 * Dummy/hardcoded Cloudflare credentials.
 * Modify these as necessary.
 */
$CF_EMAIL = "yourcloudflareloginemail@gmail.com";
$CF_API_KEY = "GlobalAPIKey";
$CF_ZONE_ID = "YourZoneID";

// URL for QUIC.cloud IPs
$QUIC_CLOUD_IPS_URL = "https://quic.cloud/ips?json";

// Function to perform cURL GET requests.
function curl_get($url, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$response, $httpStatus];
}

// Function to perform cURL POST/DELETE requests.
function curl_request($url, $customRequest, $data = null, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$response, $httpStatus];
}

// Function to display progress bar in CLI.
function show_progress($progress, $totalIPs)
{
    $progress_percent = intval($progress * 100 / $totalIPs);
    $bar_length = 50;
    $filled_bar = intval($progress * $bar_length / $totalIPs);
    $empty_bar = $bar_length - $filled_bar;

    // Compose the progress bar string.
    $bar = "[" . str_repeat("#", $filled_bar) . str_repeat(" ", $empty_bar) . "]";
    printf("\rProgress: %s %d%% (%d/%d)", $bar, $progress_percent, $progress, $totalIPs);
    // Force immediate output.
    flush();
}

// Function to print message in a "box".
function print_box($message)
{
    $lines = explode("\n", $message);
    $max_length = 0;
    foreach ($lines as $line) {
        $max_length = max($max_length, strlen($line));
    }
    $border = "+" . str_repeat("-", $max_length + 2) . "+\n";

    echo "\n" . $border;
    foreach ($lines as $line) {
        printf("| %-" . $max_length . "s |\n", $line);
    }
    echo $border . "\n";
}

// Function to fetch QUIC.cloud IPs.
function fetch_quic_cloud_ips($url)
{
    list($response, $httpStatus) = curl_get($url);
    if ($httpStatus != 200) {
        echo "Error: Unable to access QUIC.cloud IPs (HTTP status: $httpStatus).\n";
        exit(1);
    }
    $ips = json_decode($response, true);
    return $ips;
}

// Function to get all allowlisted IPs from Cloudflare (with pagination).
function get_allowlisted_ips($CF_EMAIL, $CF_API_KEY, $CF_ZONE_ID)
{
    $page = 1;
    $all_ips = [];
    while (true) {
        $url = "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/firewall/access_rules/rules?page=$page&per_page=50&mode=whitelist";
        $headers = [
            "X-Auth-Email: $CF_EMAIL",
            "X-Auth-Key: $CF_API_KEY",
            "Content-Type: application/json"
        ];
        list($response, $httpStatus) = curl_get($url, $headers);
        if ($httpStatus != 200) {
            echo "Error retrieving allowlisted IPs (HTTP status: $httpStatus).\n";
            exit(1);
        }
        $data = json_decode($response, true);
        if (!isset($data["result"]) || !is_array($data["result"])) {
            break;
        }
        foreach ($data["result"] as $rule) {
            if (isset($rule["configuration"]["value"])) {
                $all_ips[] = $rule["configuration"]["value"];
            }
        }
        $total_pages = isset($data["result_info"]["total_pages"]) ? (int) $data["result_info"]["total_pages"] : 1;
        if ($page >= $total_pages) {
            break;
        }
        $page++;
    }
    return $all_ips;
}

// Current date for notes.
$CURRENT_DATE = date("Y-m-d");

// Check if deletion flag is specified.
$deleteAction = (isset($argv[1]) && strtolower($argv[1]) == "delete");

// Fetch QUIC.cloud IPs.
$quicCloudIPs = fetch_quic_cloud_ips($QUIC_CLOUD_IPS_URL);

// Depending on the command-line argument, either delete or allowlist IPs.
if ($deleteAction) {
    echo "Deleting QUIC.cloud IPs, please wait...\n";
    // Get existing allowlisted IPs with pagination.
    $existingIPs = get_allowlisted_ips($CF_EMAIL, $CF_API_KEY, $CF_ZONE_ID);

    // Filter QUIC.cloud IPs that are allowlisted.
    $IPsToDelete = [];
    foreach ($existingIPs as $ip) {
        if (in_array($ip, $quicCloudIPs)) {
            $IPsToDelete[] = $ip;
        }
    }

    $totalIPs = count($IPsToDelete);
    $progress = 0;
    $totalIPsDeleted = 0;

    foreach ($IPsToDelete as $ip) {
        // Get the rule ID for the IP.
        $url = "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/firewall/access_rules/rules?configuration.value=$ip";
        $headers = [
            "X-Auth-Email: $CF_EMAIL",
            "X-Auth-Key: $CF_API_KEY",
            "Content-Type: application/json"
        ];
        list($response, $httpStatus) = curl_get($url, $headers);
        $data = json_decode($response, true);
        $RULE_ID = isset($data["result"][0]["id"]) ? $data["result"][0]["id"] : null;
        if (!empty($RULE_ID)) {
            // Delete the rule.
            $deleteUrl = "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/firewall/access_rules/rules/$RULE_ID";
            list($deleteResponse, $deleteStatus) = curl_request($deleteUrl, "DELETE", null, $headers);
            $deleteData = json_decode($deleteResponse, true);
            if (isset($deleteData["success"]) && $deleteData["success"] === true) {
                $totalIPsDeleted++;
            }
        }
        $progress++;
        show_progress($progress, $totalIPs);
    }
    echo "\n";
    $boxMessage = "Successfully deleted $totalIPsDeleted relevant IP addresses from the allowlist at CF WAF.";
    print_box($boxMessage);
} else {
    // Allowlisting action.
    echo "Whitelisting QUIC.cloud IPs, please wait...\n";
    $existingIPs = get_allowlisted_ips($CF_EMAIL, $CF_API_KEY, $CF_ZONE_ID);

    // Filter out IPs not yet allowlisted.
    $IPsToWhitelist = [];
    $totalIPsSkipped = 0;
    $totalIPsFailed = 0;

    foreach ($quicCloudIPs as $ip) {
        if (in_array($ip, $existingIPs)) {
            $totalIPsSkipped++;
        } else {
            $IPsToWhitelist[] = $ip;
        }
    }

    $totalIPs = count($IPsToWhitelist);
    $progress = 0;
    $totalIPsAdded = 0;

    foreach ($IPsToWhitelist as $ip) {
        $url = "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/firewall/access_rules/rules";
        $headers = [
            "X-Auth-Email: $CF_EMAIL",
            "X-Auth-Key: $CF_API_KEY",
            "Content-Type: application/json"
        ];
        $data = json_encode([
            "mode" => "whitelist",
            "configuration" => [
                "target" => "ip",
                "value" => $ip
            ],
            "notes" => "QUIC.cloud IP, IP allowed on $CURRENT_DATE"
        ]);
        list($response, $httpStatus) = curl_request($url, "POST", $data, $headers);
        $resData = json_decode($response, true);
        if (isset($resData["success"]) && $resData["success"] === true) {
            $totalIPsAdded++;
        } else {
            $totalIPsFailed++;
        }
        $progress++;
        show_progress($progress, $totalIPs);
    }

    echo "\n";
    $boxMessage = "Successfully added $totalIPsAdded new IP addresses to the allowlist at CF WAF.\n" .
        "$totalIPsSkipped IP addresses were already allowlisted.\n" .
        "$totalIPsFailed IP addresses could not be added due to errors.";
    print_box($boxMessage);
}

exit(0);