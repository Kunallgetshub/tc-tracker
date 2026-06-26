<?php
/*
Plugin Name: Terms & Conditions Tracker
Description: Tracks changes in Terms & Conditions pages.
Version: 1.0
Author: Kunal Sharma
*/

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'tct_create_tables');

function tct_create_tables() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sites_table = $wpdb->prefix . 'tct_sites';

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE $sites_table (
        id BIGINT NOT NULL AUTO_INCREMENT,
        site_name VARCHAR(255) NOT NULL,
        url TEXT NOT NULL,
        scan_count BIGINT DEFAULT 0,
        last_scan DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql);
    //new table
    $versions_table = $wpdb->prefix . 'tct_versions';
    
    $sql2 = "CREATE TABLE $versions_table (
        id BIGINT NOT NULL AUTO_INCREMENT,
        site_id BIGINT NOT NULL,
        content LONGTEXT,
        page_hash VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql2);
    $blockchain_table = $wpdb->prefix . 'tct_blockchain';

$sql3 = "CREATE TABLE $blockchain_table (
    id BIGINT NOT NULL AUTO_INCREMENT,
    version_id BIGINT NOT NULL,
    previous_hash VARCHAR(255),
    current_hash VARCHAR(255),
    block_hash VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) $charset_collate;";

dbDelta($sql3);

$scan_logs_table = $wpdb->prefix . 'tct_scan_logs';

$sql4 = "CREATE TABLE $scan_logs_table (
    id BIGINT NOT NULL AUTO_INCREMENT,
    site_id BIGINT NOT NULL,
    result VARCHAR(50),
    http_status INT DEFAULT 0,
    message TEXT,
    duration_ms BIGINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) $charset_collate;";

dbDelta($sql4);
}

add_action('admin_init', 'tct_create_tables');

function tct_admin_menu() {
    add_menu_page(
    'TC Tracker',
    'TC Tracker',
    'manage_options',
    'tc-tracker',
    'tct_dashboard',
    'dashicons-search',
    25
);

add_submenu_page(
    'tc-tracker',
    'Version History',
    'Version History',
    'manage_options',
    'tct-history',
    'tct_history_page'
);
add_submenu_page(
    'tc-tracker',
    'Settings',
    'Settings',
    'manage_options',
    'tct-settings',
    'tct_settings_page'
);
add_submenu_page(
    'tc-tracker',
    'Blockchain',
    'Blockchain',
    'manage_options',
    'tct-blockchain',
    'tct_blockchain_page'
);
}
add_action('admin_menu', 'tct_admin_menu');
function tct_prepare_query($query, $params){

    global $wpdb;

    if(empty($params)){
        return $query;
    }

    return call_user_func_array(
        [$wpdb, 'prepare'],
        array_merge([$query], $params)
    );
}

function tct_cleanup_old_versions($keep_versions){

    global $wpdb;

    $keep_versions = max(1, intval($keep_versions));
    $sites_table = $wpdb->prefix . 'tct_sites';
    $versions_table = $wpdb->prefix . 'tct_versions';
    $deleted = 0;

    $sites = $wpdb->get_results("SELECT id FROM $sites_table");

    foreach($sites as $site){

        $old_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                 FROM $versions_table
                 WHERE site_id=%d
                 ORDER BY id DESC
                 LIMIT 999999 OFFSET %d",
                $site->id,
                $keep_versions
            )
        );

        if(!empty($old_ids)){

            $old_ids = array_map('intval', $old_ids);
            $id_list = implode(',', $old_ids);

            $deleted += $wpdb->query(
                "DELETE FROM $versions_table WHERE id IN ($id_list)"
            );
        }
    }

    return intval($deleted);
}

function tct_format_content_as_text($content){

    $text_content = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $content);
    $text_content = preg_replace('/<\/(p|div|section|article|header|footer|main|aside|nav|h[1-6]|li|tr|table|thead|tbody|tfoot|ul|ol)>/i', "\n", $text_content);
    $text_content = preg_replace('/<li[^>]*>/i', "- ", $text_content);
    $text_content = wp_strip_all_tags($text_content);
    $text_content = html_entity_decode($text_content, ENT_QUOTES, get_bloginfo('charset'));
    $text_content = preg_replace('/[ \t]+/', ' ', $text_content);
    $text_content = preg_replace('/ *\n */', "\n", $text_content);
    $text_content = preg_replace('/\n\s*\n+/', "\n\n", $text_content);

    return trim($text_content);
}

function tct_is_compare_noise($line){

    $line = trim($line);

    if($line === ''){
        return true;
    }

    $lower = strtolower($line);
    $noise_lines = [
        'close',
        'skip to main content',
        'opens in a new tab(opens a footnote)'
    ];

    if(in_array($lower, $noise_lines, true)){
        return true;
    }

    if(preg_match('/^(this is the )?trace id\s*:/i', $line)){
        return true;
    }

    if(preg_match('/^(trace|request|session|correlation|client)\s*id\s*:/i', $line)){
        return true;
    }

    if(preg_match('/^[a-f0-9]{24,}$/i', $line)){
        return true;
    }

    if(strlen($line) < 35 && !preg_match('/[.!?;:]$/', $line)){
        return true;
    }

    return false;
}

function tct_get_meaningful_compare_lines($text){

    $lines = explode("\n", $text);
    $clean_lines = [];

    foreach($lines as $line){

        $line = trim($line);

        if(!tct_is_compare_noise($line)){
            $clean_lines[] = $line;
        }
    }

    return array_values(array_unique($clean_lines));
}

function tct_compare_texts($old_content, $new_content){

    $old_text = tct_format_content_as_text($old_content);
    $new_text = tct_format_content_as_text($new_content);
    $old_lines = tct_get_meaningful_compare_lines($old_text);
    $new_lines = tct_get_meaningful_compare_lines($new_text);
    $removed = array_values(array_diff($old_lines, $new_lines));
    $added = array_values(array_diff($new_lines, $old_lines));
    $text_changed = hash('sha256', $old_text) != hash('sha256', $new_text);
    $meaningful_count = count($removed) + count($added);

    if(!$text_changed){
        $severity = 'No Change';
        $summary = 'Both versions have the same readable Terms text.';
    } elseif($meaningful_count == 0){
        $severity = 'No Meaningful Change';
        $summary = 'Only non-content HTML changed, such as layout, scripts, trace IDs, navigation, or language lists.';
    } elseif($meaningful_count <= 2){
        $severity = 'Minor';
        $summary = count($added).' readable section(s) added and '.count($removed).' section(s) removed.';
    } elseif($meaningful_count <= 8){
        $severity = 'Moderate';
        $summary = count($added).' readable section(s) added and '.count($removed).' section(s) removed.';
    } else {
        $severity = 'Major';
        $summary = count($added).' readable section(s) added and '.count($removed).' section(s) removed.';
    }

    return [
        'old_text' => $old_text,
        'new_text' => $new_text,
        'old_lines' => $old_lines,
        'new_lines' => $new_lines,
        'removed' => $removed,
        'added' => $added,
        'text_changed' => $text_changed,
        'meaningful_changes_found' => $meaningful_count > 0,
        'severity' => $severity,
        'summary' => $summary
    ];
}

function tct_severity_badge($severity){

    $colors = [
        'No Change' => ['#dcfce7', '#166534'],
        'No Meaningful Change' => ['#e0f2fe', '#075985'],
        'Minor' => ['#fef9c3', '#854d0e'],
        'Moderate' => ['#ffedd5', '#9a3412'],
        'Major' => ['#fee2e2', '#991b1b']
    ];

    $color = isset($colors[$severity]) ? $colors[$severity] : ['#e5e7eb', '#374151'];

    return '<span style="
        display:inline-block;
        background:'.$color[0].';
        color:'.$color[1].';
        padding:4px 10px;
        border-radius:999px;
        font-weight:700;
        font-size:12px;
    ">'.esc_html($severity).'</span>';
}

function tct_log_scan($site_id, $result, $http_status = 0, $message = '', $duration_ms = 0){

    global $wpdb;

    $wpdb->insert(
        $wpdb->prefix . 'tct_scan_logs',
        [
            'site_id' => intval($site_id),
            'result' => sanitize_text_field($result),
            'http_status' => intval($http_status),
            'message' => sanitize_text_field($message),
            'duration_ms' => intval($duration_ms)
        ]
    );
}

function tct_scan_website($site_id){

    global $wpdb;

    $started_at = microtime(true);
    $sites_table = $wpdb->prefix . 'tct_sites';
    $versions_table = $wpdb->prefix . 'tct_versions';

    $site = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $sites_table WHERE id=%d",
            $site_id
        )
    );

    if(!$site){
        tct_log_scan($site_id, 'failed', 0, 'Website not found', 0);
        return false;
    }

    $response = wp_remote_get($site->url);

    if(is_wp_error($response)){
        tct_log_scan(
            $site_id,
            'failed',
            0,
            $response->get_error_message(),
            round((microtime(true) - $started_at) * 1000)
        );
        return false;
    }

    $http_status = wp_remote_retrieve_response_code($response);
    $content = wp_remote_retrieve_body($response);

    $text = wp_strip_all_tags($content);
    $text = preg_replace('/\s+/', ' ', $text);

    $hash = hash('sha256', $text);

    $last_version = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $versions_table
             WHERE site_id=%d
             ORDER BY id DESC
             LIMIT 1",
            $site_id
        )
    );
    $wpdb->query( 
    $wpdb->prepare(
        "UPDATE $sites_table
         SET scan_count = scan_count + 1,
             last_scan = %s
         WHERE id=%d",
        current_time('mysql'),
        $site_id
    )
);
    if($last_version && $last_version->page_hash == $hash){
        tct_log_scan(
            $site_id,
            'no_change',
            $http_status,
            'No changes detected',
            round((microtime(true) - $started_at) * 1000)
        );
        return 'no_change';
    }

    $wpdb->insert(
        $versions_table,
        [
            'site_id' => $site_id,
            'content' => $content,
            'page_hash' => $hash
        ]
    );
    
    $version_id = $wpdb->insert_id;

$blockchain_table = $wpdb->prefix . 'tct_blockchain';

$last_block = $wpdb->get_row(
    "SELECT * FROM $blockchain_table
     ORDER BY id DESC
     LIMIT 1"
);

$previous_hash = '';

if($last_block){
    $previous_hash = $last_block->block_hash;
}

$block_hash = hash(
    'sha256',
    $version_id .
    $hash .
    $previous_hash
);

$wpdb->insert(
    $blockchain_table,
    [
        'version_id' => $version_id,
        'previous_hash' => $previous_hash,
        'current_hash' => $hash,
        'block_hash' => $block_hash
    ]
);
   $subject = '🚨 TC Tracker: Terms & Conditions Updated - '.$site->site_name;

$message =
"Hello,

TC Tracker has detected a change in the Terms & Conditions of one of your monitored websites.

──────────────────────────────

🌐 Website
".$site->site_name."

🔗 URL
".$site->url."

🕒 Scan Time
".current_time('mysql')."

🆕 Version ID
".$version_id;

if($last_version){

    $comparison = tct_compare_texts($last_version->content, $content);

    $compare_url = admin_url(
        'admin.php?page=tct-history&compare_auto=1&old='.$last_version->id.'&new='.$version_id
    );

    $message .=

"\n\n📊 Change Analysis

Severity : ".$comparison['severity']."

Summary
".$comparison['summary']."

Compare Versions
".$compare_url;
}

$message .=

"\n\n──────────────────────────────

This notification was generated automatically by TC Tracker.

To view the complete version history and blockchain verification, log in to your WordPress dashboard.

Thank you,
TC Tracker";

if(get_option('tct_enable_email', 1)){

    $mail_sent = wp_mail(
        $admin_email,
        $subject,
        $message
    );

    if($mail_sent){

        error_log('TC Tracker Email Sent');

    } else {

        error_log('TC Tracker Email Failed');

    }

}
    tct_log_scan(
        $site_id,
        'changed',
        $http_status,
        'New version saved: '.$version_id,
        round((microtime(true) - $started_at) * 1000)
    );
    return 'changed';
}

function tct_dashboard() {

    global $wpdb;
    $sites_table = $wpdb->prefix . 'tct_sites';
    $versions_table = $wpdb->prefix . 'tct_versions';
    $total_sites = $wpdb->get_var(
    "SELECT COUNT(*) FROM $sites_table"
);
$recent_versions = $wpdb->get_results("
    SELECT v.*, s.site_name
    FROM $versions_table v
    JOIN $sites_table s
    ON v.site_id = s.id
    ORDER BY v.id DESC
    LIMIT 5
");

$total_versions = $wpdb->get_var(
    "SELECT COUNT(*) FROM $versions_table"
);
$blockchain_table = $wpdb->prefix . 'tct_blockchain';

$total_blocks = $wpdb->get_var(
    "SELECT COUNT(*) FROM $blockchain_table"
);
$total_scans = $wpdb->get_var(
    "SELECT SUM(scan_count)
     FROM $sites_table"
);

if(!$total_scans){
    $total_scans = 0;
}
$chain_status = 'VALID';

if($total_blocks == 0){
    $chain_status = 'EMPTY';
}

if(isset($_GET['scan'])){

    $site_id = intval($_GET['scan']);

    $result = tct_scan_website($site_id);

    if($result == 'no_change'){

        echo '<div class="notice notice-info">
                <p>No changes detected.</p>
              </div>';

    } elseif($result == 'changed'){

        echo '<div class="notice notice-success">
                <p>Changes detected and new version saved.</p>
              </div>';

    } else {

        echo '<div class="notice notice-error">
                <p>Scan failed.</p>
              </div>';
    }
}
    $table = $wpdb->prefix . 'tct_sites';

    if(isset($_POST['submit_site'])){

        $site_name = sanitize_text_field($_POST['site_name']);
        $url = esc_url_raw($_POST['url']);

        $wpdb->insert(
            $table,
            [
                'site_name' => $site_name,
                'url' => $url
            ]
        );

        echo '<div class="notice notice-success"><p>Website Added Successfully!</p></div>';

echo '<script>
setTimeout(function(){
    location.reload();
}, 1500);
</script>';
    }

    $scan_logs_table = $wpdb->prefix . 'tct_scan_logs';
    $sites = $wpdb->get_results("SELECT * FROM $table ORDER BY LOWER(site_name) ASC");
    $versions_by_site = $wpdb->get_results("
        SELECT s.site_name, COUNT(v.id) AS version_count
        FROM $sites_table s
        LEFT JOIN $versions_table v ON s.id = v.site_id
        GROUP BY s.id
        ORDER BY version_count DESC, LOWER(s.site_name) ASC
        LIMIT 8
    ");
    $changes_by_day = $wpdb->get_results("
        SELECT DATE(created_at) AS change_day, COUNT(*) AS version_count
        FROM $versions_table
        GROUP BY DATE(created_at)
        ORDER BY change_day DESC
        LIMIT 7
    ");
    $recent_logs = $wpdb->get_results("
        SELECT l.*, s.site_name
        FROM $scan_logs_table l
        LEFT JOIN $sites_table s ON l.site_id = s.id
        ORDER BY l.id DESC
        LIMIT 8
    ");

    if(isset($_GET['site_detail'])){

        $detail_id = intval($_GET['site_detail']);
        $detail_site = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $sites_table WHERE id=%d", $detail_id)
        );

        if($detail_site){

            $detail_versions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM $versions_table
                     WHERE site_id=%d
                     ORDER BY id DESC
                     LIMIT 20",
                    $detail_id
                )
            );
            $detail_logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM $scan_logs_table
                     WHERE site_id=%d
                     ORDER BY id DESC
                     LIMIT 10",
                    $detail_id
                )
            );

            echo '<div class="wrap">';
            echo '<h1>'.esc_html($detail_site->site_name).'</h1>';
            echo '<p><a class="button" href="?page=tc-tracker">Back to Dashboard</a> ';
            echo '<a class="button button-primary" href="?page=tc-tracker&scan='.intval($detail_site->id).'">Scan Now</a></p>';
            echo '<div style="background:#fff;padding:25px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);border-top:4px solid #4361ee;">';
            echo '<p><strong>URL:</strong> <a href="'.esc_url($detail_site->url).'" target="_blank">'.esc_html($detail_site->url).'</a></p>';
            echo '<p><strong>Total Scans:</strong> '.intval($detail_site->scan_count).'</p>';
            echo '<p><strong>Last Scan:</strong> '.esc_html($detail_site->last_scan ? $detail_site->last_scan : 'Never').'</p>';
            echo '<p><strong>Total Versions:</strong> '.count($detail_versions).'</p>';

            if(!empty($detail_versions)){
                echo '<p><strong>Latest Hash:</strong> '.esc_html($detail_versions[0]->page_hash).'</p>';
            }

            echo '<h2>Recent Versions</h2>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Hash</th><th>Date</th><th>Action</th></tr></thead><tbody>';

            foreach($detail_versions as $version){
                echo '<tr>';
                echo '<td>'.intval($version->id).'</td>';
                echo '<td>'.esc_html(substr($version->page_hash, 0, 32)).'...</td>';
                echo '<td>'.esc_html($version->created_at).'</td>';
                echo '<td><a class="button" href="?page=tct-history&view='.intval($version->id).'">View</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<h2>Recent Scan Logs</h2>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Result</th><th>HTTP</th><th>Message</th><th>Duration</th><th>Date</th></tr></thead><tbody>';

            foreach($detail_logs as $log){
                echo '<tr>';
                echo '<td>'.esc_html($log->result).'</td>';
                echo '<td>'.intval($log->http_status).'</td>';
                echo '<td>'.esc_html($log->message).'</td>';
                echo '<td>'.intval($log->duration_ms).' ms</td>';
                echo '<td>'.esc_html($log->created_at).'</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div></div>';
            return;
        }
    }

    ?>

    <div class="wrap">
        <h1 style="
font-size:34px;
font-weight:700;
margin-bottom:5px;
color:#1e293b;
">
TC Tracker Dashboard
</h1>

<p style="
font-size:15px;
color:#64748b;
margin-top:0;
margin-bottom:25px;
">
Monitor Terms & Conditions changes with Blockchain Verification
</p>

<div style="
display:flex;
gap:30px;
flex-wrap:wrap;
margin-bottom:20px;
">

<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
min-width:220px;
text-align:center;
">
<h2 style="font-size:36px;margin:0;">
    <?php echo $total_sites; ?>
</h2>
<p style="
font-size:15px;
font-weight:500;
color:#555;
margin-top:8px;
">
🌐 Total Websites
</p>
</div>

<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
min-width:220px;
text-align:center;
">
<h2 style="font-size:36px;margin:0;">
    <?php echo $total_versions; ?>
</h2>
<p style="
font-size:15px;
font-weight:500;
color:#555;
margin-top:8px;
">
📄 Total Versions
</p>
</div>
<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
min-width:220px;
text-align:center;
">
<h2 style="font-size:36px;margin:0;">
    <?php echo $total_blocks; ?>
</h2>
<p style="
font-size:15px;
font-weight:500;
color:#555;
margin-top:8px;
">
🔗 Blockchain Blocks
</p>
</div>
<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
min-width:220px;
text-align:center;
">
<h2 style="font-size:36px;margin:0;">
    <?php echo $chain_status; ?>
</h2>
<p style="
font-size:15px;
font-weight:500;
color:#555;
margin-top:8px;
">
✅ Blockchain Status
</p>
</div> 
<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
min-width:220px;
text-align:center;
">
<h2 style="font-size:36px;margin:0;">
    <?php echo $total_scans; ?>
</h2>
<p style="
font-size:15px;
font-weight:500;
color:#555;
margin-top:8px;
">
📊 Total Scans
</p>
</div>
</div>
<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-left:4px solid #4361ee;
margin-bottom:25px;
">

<h2 style="
font-size:22px;
font-weight:600;
margin-bottom:20px;
color:#1e293b;
">
Recent Activity
</h2>

<table
class="wp-list-table widefat fixed striped"
style="
border-radius:12px;
overflow:hidden;
border-collapse:separate;
border-spacing:0;
">

    <thead>
    <tr style="background:#f8f9fa;">
        <th style="padding:15px;">Version ID</th>
        <th style="padding:15px;">Website Name</th>
        <th style="padding:15px;">Date</th>
    </tr>
</thead>

    <tbody>

    <?php foreach($recent_versions as $version): ?>

        <tr>
           <td style="padding:15px;"><?php echo $version->id; ?></td>
           <td style="padding:15px;"><?php echo esc_html($version->site_name); ?></td>
           <td style="padding:15px;"><?php echo $version->created_at; ?></td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>
</div>
<br>
        <h1 style="
font-size:28px;
font-weight:600;
margin-bottom:20px;
color:#1e293b;
">
Add Website
</h1>
        <div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
margin-bottom:25px;
">

        <form method="post">

            <table class="form-table">

                <tr>
                    <th>Site Name</th>
                    <td>
                        <input
    type="text"
    name="site_name"
    required
    style="
    width:100%;
    max-width:700px;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
">
                    </td>
                </tr>

                <tr>
                    <th>Website URL</th>
                    <td>
                        <input
    type="url"
    name="url"
    required
    style="
    width:100%;
    max-width:700px;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
">
                    </td>
                </tr>

            </table>

            <input
                type="submit"
                name="submit_site"
                class="button button-primary"

style="
padding:8px 20px;
border-radius:8px;
"
                value="Add Website">

        </form>
</div>
        <hr>

        <div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
margin-top:25px;
">

<h2 style="
font-size:22px;
font-weight:600;
margin-bottom:20px;
color:#1e293b;
">
Saved Websites
</h2>

        <table
class="wp-list-table widefat fixed striped"
style="
border-radius:12px;
overflow:hidden;
border-collapse:separate;
border-spacing:0;
">

           <thead>
    <tr style="
        background:#f8f9fa;
    ">
        <th style="padding:15px;">ID</th>
        <th style="padding:15px;">Site Name</th>
        <th style="padding:15px;">URL</th>
        <th style="padding:15px;">Created At</th>
        <th style="padding:15px;">Last Scan</th>
        <th style="padding:15px;">Action</th>
    </tr>
</thead>

            <tbody>

            <?php foreach($sites as $site): ?>

                <tr>
                    <td style="padding:15px;">
    <?php echo $site->id; ?>
</td>
                    <td style="padding:15px;">
                        <?php echo esc_html($site->site_name); ?></td>
                    <td style="padding:15px;">
    <a href="<?php echo esc_url($site->url); ?>"
       target="_blank"
       style="text-decoration:none;">
       <?php echo esc_html($site->url); ?>
    </a>
</td>
                    <td style="padding:15px;">
                        <?php echo $site->created_at; ?></td>

<td style="padding:15px;">
    <?php echo $site->last_scan ? $site->last_scan : 'Never'; ?>
</td>

<td>
    <a href="?page=tc-tracker&scan=<?php echo $site->id; ?>"
       class="button button-primary"
       style="
       border-radius:8px;
       font-weight:600;
       ">
        Scan
    </a>
</td>
                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php
}
function tct_history_page(){

    global $wpdb;

    $sites_table = $wpdb->prefix . 'tct_sites';
    $versions_table = $wpdb->prefix . 'tct_versions';

    $all_sites = $wpdb->get_results(
        "SELECT id, site_name FROM $sites_table ORDER BY LOWER(site_name) ASC"
    );

    $auto_compare = isset($_GET['compare_auto'], $_GET['old'], $_GET['new']);

    if(isset($_POST['compare_versions']) || $auto_compare){

    if($auto_compare || (isset($_POST['compare']) &&
       count($_POST['compare']) == 2)){

        $id1 = $auto_compare ? intval($_GET['old']) : intval($_POST['compare'][0]);
        $id2 = $auto_compare ? intval($_GET['new']) : intval($_POST['compare'][1]);

        $version1 = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT v.*, s.site_name
                 FROM $versions_table v
                 JOIN $sites_table s
                 ON v.site_id = s.id
                 WHERE v.id=%d",
                $id1
            )
        );

        $version2 = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT v.*, s.site_name
                 FROM $versions_table v
                 JOIN $sites_table s
                 ON v.site_id = s.id
                 WHERE v.id=%d",
                $id2
            )
        );

        if(!$version1 || !$version2){

            echo '<div class="notice notice-error" style="
padding:10px;
border-radius:8px;
">
<p>One of the selected versions could not be found.</p>
</div>';
            return;
        }

        if($version1->site_id != $version2->site_id){

            echo '<div class="notice notice-error" style="
padding:10px;
border-radius:8px;
">
<p>Please compare versions from the same website only.</p>
</div>';
            return;

        } else {

        echo '<div class="wrap">';
        echo '<h1>Compare Versions</h1>';
        echo '<p><a class="button" href="?page=tct-history">Back to Version History</a></p>';
        echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
margin-top:20px;
">';



        $comparison = tct_compare_texts($version1->content, $version2->content);
        $text1 = $comparison['old_text'];
        $text2 = $comparison['new_text'];
        $texts_are_different = $comparison['text_changed'];
        $lines1 = $comparison['old_lines'];
        $lines2 = $comparison['new_lines'];
        $removed = $comparison['removed'];
        $added = $comparison['added'];
        $meaningful_changes_found = $comparison['meaningful_changes_found'];

        if(!$texts_are_different){

           echo '<div class="notice notice-success">
            <p>No text differences detected.</p>
          </div>';

        } elseif(!$meaningful_changes_found) {

          echo '<div class="notice notice-info">
            <p>No meaningful Terms text changes detected.</p>
          </div>';

        } else {

          echo '<div class="notice notice-warning">
            <p>Meaningful Terms text changes detected.</p>
          </div>';
}
echo '<h3>Statistics</h3>';

echo '<p><strong>Severity:</strong> '.tct_severity_badge($comparison['severity']).'</p>';
echo '<p><strong>Summary:</strong> '.esc_html($comparison['summary']).'</p>';

echo '<p>Version '.$id1.' Length: '
     .strlen($text1).
     ' characters</p>';

echo '<p>Version '.$id2.' Length: '
     .strlen($text2).
     ' characters</p>';

echo '<p>Website: '.esc_html($version1->site_name).'</p>';

if($texts_are_different){

    echo '<h3>Meaningful Changes</h3>';

    if(empty($removed) && empty($added)){

        echo '<div style="
            background:#f0fdf4;
            border-left:4px solid #16a34a;
            padding:14px;
            margin-bottom:16px;
            line-height:1.6;
        ">';
        echo '<strong>No meaningful Terms text change found.</strong><br>';
        echo 'The saved versions are different, but the difference appears to be in page layout, navigation, language lists, scripts, or other non-content HTML. The readable Terms text looks the same after cleanup.';
        echo '</div>';

    } else {

        echo '<div style="
            background:#eff6ff;
            border-left:4px solid #2563eb;
            padding:14px;
            margin-bottom:16px;
            line-height:1.6;
        ">';
        echo '<strong>Readable Terms text changed.</strong><br>';
        echo 'The sections below show text that disappeared from the old version and text that appeared in the new version. Navigation labels, language lists, and very short menu text are hidden.';
        echo '</div>';
    }

    echo '<h4 style="
        margin-top:18px;
        margin-bottom:10px;
        padding:10px 12px;
        background:#fee2e2;
        border-left:4px solid #dc2626;
        color:#7f1d1d;
        font-size:15px;
        font-weight:700;
    ">Removed From Old Version</h4>';

    if(empty($removed)){

        echo '<p style="color:#475569;">No readable Terms text was removed.</p>';

    } else {

    foreach(array_slice($removed, 0, 8) as $line){

    echo '<div style="
        background:#ffecec;
        border-left:4px solid red;
        padding:12px;
        margin-bottom:8px;
        line-height:1.6;
        white-space:pre-wrap;
    ">';

    echo esc_html($line);

    echo '</div>';
    }
    }

    echo '<h4 style="
        margin-top:18px;
        margin-bottom:10px;
        padding:10px 12px;
        background:#dcfce7;
        border-left:4px solid #16a34a;
        color:#14532d;
        font-size:15px;
        font-weight:700;
    ">Added In New Version</h4>';

    if(empty($added)){

        echo '<p style="color:#475569;">No readable Terms text was added.</p>';

    } else {

    foreach(array_slice($added, 0, 8) as $line){

    echo '<div style="
        background:#eaffea;
        border-left:4px solid green;
        padding:12px;
        margin-bottom:8px;
        line-height:1.6;
        white-space:pre-wrap;
    ">';

    echo esc_html($line);

    echo '</div>';
}
    }

    if(!empty($removed) || !empty($added)){

    $old_preview = !empty($removed) ? $removed[0] : '';
    $new_preview = !empty($added) ? $added[0] : '';

    if($old_preview === '' || $new_preview === ''){

        $max_lines = min(count($lines1), count($lines2));

        for($i=0; $i<$max_lines; $i++){

            if($lines1[$i] != $lines2[$i]){

                if($old_preview === ''){
                    $old_preview = $lines1[$i];
                }

                if($new_preview === ''){
                    $new_preview = $lines2[$i];
                }

                break;
            }
        }
    }

    if($old_preview === ''){
        $old_preview = 'No clear old-version text block found.';
    }

    if($new_preview === ''){
        $new_preview = 'No clear new-version text block found.';
    }

    echo '<h3>First Clear Difference</h3>';

    echo '<div style="
        background:#ffecec;
        border-left:4px solid red;
        padding:12px;
        margin-bottom:10px;
        line-height:1.6;
        white-space:pre-wrap;
    ">';

    echo '<strong>- Old Version</strong><br>';
    echo esc_html($old_preview);

    echo '</div>';

    echo '<div style="
        background:#eaffea;
        border-left:4px solid green;
        padding:12px;
        line-height:1.6;
        white-space:pre-wrap;
    ">';

    echo '<strong>+ New Version</strong><br>';
    echo esc_html($new_preview);

    echo '</div>';
    }
} else {

    echo '<h3>Similarity Result</h3>';
    echo '<div style="
        background:#f0fdf4;
        border-left:4px solid #16a34a;
        padding:14px;
        margin-bottom:16px;
        line-height:1.6;
    ">';
    echo '<strong>Both versions are similar.</strong><br>';
    echo 'No readable Terms text change was found between these two versions for '.esc_html($version1->site_name).'. The page may have been saved again because of HTML, spacing, scripts, tracking code, or layout changes, but the cleaned text content is the same.';
    echo '</div>';
}
echo '<h3 style="
background:#f8f9fa;
padding:10px;
border-radius:8px;
">
Version '.$id1.'
</h3>';
echo '<textarea
rows="12"
readonly
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
font-family:monospace;
">'
     .esc_textarea(substr($text1,0,3000))
     .'</textarea>';

echo '<h3 style="
background:#f8f9fa;
padding:10px;
border-radius:8px;
">
Version '.$id2.'
</h3>';
echo '<textarea
rows="12"
readonly
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
font-family:monospace;
">'
     .esc_textarea(substr($text2,0,3000))
     .'</textarea>';

        echo '</div>';
        echo '</div>';
        return;
        }
    }

    echo '<div class="notice notice-error" style="
padding:10px;
border-radius:8px;
">
<p>Please select exactly 2 versions.</p>
</div>';
}
    if(isset($_GET['view'])){

    $version_id = intval($_GET['view']);

    $version = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT v.*, s.url, s.site_name
             FROM $versions_table v
             JOIN $sites_table s
             ON v.site_id = s.id
             WHERE v.id=%d",
            $version_id
        )
    );

    if($version){

        $site_version_number = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $versions_table
                 WHERE site_id=%d
                 AND id <= %d",
                $version->site_id,
                $version->id
            )
        );

        $preview_mode = isset($_GET['preview']) ? sanitize_text_field($_GET['preview']) : 'rendered';

        if(!in_array($preview_mode, ['rendered', 'raw', 'text'], true)){
            $preview_mode = 'rendered';
        }
        echo '<div class="wrap">';
        echo '<h1>'.esc_html($version->site_name).' - Version '.intval($site_version_number).'</h1>';
        echo '<p><a class="button" href="?page=tct-history">Back to Version History</a></p>';
        echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
margin-top:20px;
">';

        echo '<p><strong>Database ID:</strong> '.intval($version_id).'</p>';
        echo '<p><strong>Saved On:</strong> '.esc_html($version->created_at).'</p>';

        echo '<h3 style="margin-top:0;">Version Hash</h3>';
       echo '<textarea
rows="3"
readonly
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
background:#f8fafc;
color:#334155;
">'
 .esc_textarea($version->page_hash).
'</textarea>';

        echo '<h3>Content Preview</h3>';
        echo '<p>';
        echo '<a class="button '.($preview_mode == 'rendered' ? 'button-primary' : '').'" href="?page=tct-history&view='.intval($version_id).'&preview=rendered">Rendered Preview</a> ';
        echo '<a class="button '.($preview_mode == 'raw' ? 'button-primary' : '').'" href="?page=tct-history&view='.intval($version_id).'&preview=raw">Raw HTML</a> ';
        echo '<a class="button '.($preview_mode == 'text' ? 'button-primary' : '').'" href="?page=tct-history&view='.intval($version_id).'&preview=text">Text Only</a>';
        echo '</p>';

        if($preview_mode == 'raw'){

            echo '<textarea
rows="24"
readonly
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
background:#f8fafc;
font-family:monospace;
">'
            .esc_textarea($version->content).
            '</textarea>';

        } elseif($preview_mode == 'text'){

            $text_content = tct_format_content_as_text($version->content);

            echo '<textarea
rows="24"
readonly
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
background:#f8fafc;
line-height:1.6;
">'
            .esc_textarea($text_content).
            '</textarea>';

        } else {

            $preview_content = $version->content;
            $base_tag = '<base href="'.esc_url($version->url).'">';

            if(stripos($preview_content, '<head') !== false){
                $preview_content = preg_replace('/<head([^>]*)>/i', '<head$1>'.$base_tag, $preview_content, 1);
            } else {
                $preview_content = $base_tag.$preview_content;
            }

       echo '<iframe
sandbox="allow-scripts allow-forms allow-popups"
srcdoc="'.esc_attr($preview_content).'"
style="
width:100%;
min-height:500px;
border-radius:8px;
border:1px solid #ddd;
background:#fff;
"></iframe>';
        }

        echo '</div>';
        echo '</div>';
        return;
    }
}

    $selected_site = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;
    $search = isset($_GET['history_search']) ? sanitize_text_field($_GET['history_search']) : '';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;
    $where = ['1=1'];
    $params = [];

    if($selected_site > 0){
        $where[] = 'v.site_id=%d';
        $params[] = $selected_site;
    }

    if($search !== ''){
        $where[] = '(s.site_name LIKE %s OR v.page_hash LIKE %s OR v.id = %d)';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = intval($search);
    }

    if($date_from !== ''){
        $where[] = 'DATE(v.created_at) >= %s';
        $params[] = $date_from;
    }

    if($date_to !== ''){
        $where[] = 'DATE(v.created_at) <= %s';
        $params[] = $date_to;
    }

    $where_sql = implode(' AND ', $where);

    $total_versions = $wpdb->get_var(
        tct_prepare_query(
            "SELECT COUNT(*)
             FROM $versions_table v
             JOIN $sites_table s
             ON v.site_id = s.id
             WHERE $where_sql",
            $params
        )
    );

    $query_params = $params;
    $query_params[] = $per_page;
    $query_params[] = $offset;

    $versions = $wpdb->get_results(
        tct_prepare_query(
            "SELECT v.*, s.site_name,
                (
                    SELECT COUNT(*)
                    FROM $versions_table v2
                    WHERE v2.site_id = v.site_id
                    AND v2.id <= v.id
                ) AS site_version_number
             FROM $versions_table v
             JOIN $sites_table s
             ON v.site_id = s.id
             WHERE $where_sql
             ORDER BY LOWER(s.site_name) ASC, v.id DESC
             LIMIT %d OFFSET %d",
            $query_params
        )
    );

    $total_pages = max(1, ceil($total_versions / $per_page));

    echo '<div class="wrap">';
    echo '<h1 style="
font-size:34px;
font-weight:700;
margin-bottom:20px;
color:#1e293b;
">
Version History
</h1>';

    echo '<form method="get" style="
background:#fff;
padding:18px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
margin-bottom:20px;
">';
    echo '<input type="hidden" name="page" value="tct-history">';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">';
    echo '<label>Website<br><select name="site_id" style="min-width:180px;">';
    echo '<option value="0">All websites</option>';

    foreach($all_sites as $site){
        echo '<option value="'.intval($site->id).'" '.selected($selected_site, $site->id, false).'>'.esc_html($site->site_name).'</option>';
    }

    echo '</select></label>';
    echo '<label>Search<br><input type="search" name="history_search" value="'.esc_attr($search).'" placeholder="Name, hash, or ID"></label>';
    echo '<label>From<br><input type="date" name="date_from" value="'.esc_attr($date_from).'"></label>';
    echo '<label>To<br><input type="date" name="date_to" value="'.esc_attr($date_to).'"></label>';
    echo '<button class="button button-primary" type="submit">Filter</button>';
    echo '<a class="button" href="?page=tct-history">Clear</a>';
    echo '</div>';
    echo '</form>';

    echo '<form method="post">';
    echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
margin-top:20px;
">';

echo '<table
class="wp-list-table widefat fixed striped"
style="
border-radius:12px;
overflow:hidden;
border-collapse:separate;
border-spacing:0;
">';

    echo '<thead>
        <tr style="background:#f8f9fa;">
            <th style="padding:15px;">Version</th>
            <th style="padding:15px;">Website</th>
            <th style="padding:15px;">Hash</th>
            <th style="padding:15px;">Date</th>
            <th style="padding:15px;">Action</th>
            <th style="padding:15px;">Compare</th>
        </tr>
      </thead>';

    echo '<tbody>';

    foreach($versions as $version){

        echo '<tr>';

        echo '<td style="padding:15px;">Version '.intval($version->site_version_number).'<br><small>ID '.intval($version->id).'</small></td>';

echo '<td style="padding:15px;">'
     .esc_html($version->site_name).
     '</td>';

echo '<td style="padding:15px;">'
     .esc_html(substr($version->page_hash,0,20)).
     '...</td>';

echo '<td style="padding:15px;">'
     .esc_html($version->created_at).
     '</td>';
        echo '<td>
        <a class="button"
   style="
   border-radius:8px;
   padding:6px 14px;
   "
   href="?page=tct-history&view='.intval($version->id).'">
   View
</a>
      </td>';
      
      echo '<td>
        <input type="checkbox"
               name="compare[]"
               value="'.intval($version->id).'">
        Version '.intval($version->site_version_number).'
      </td>';

        echo '</tr>';
    }

    if(empty($versions)){
        echo '<tr><td colspan="6" style="padding:15px;">No versions found.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<p style="margin-top:15px;">Showing page '.intval($paged).' of '.intval($total_pages).' - '.intval($total_versions).' versions found.</p>';

    if($total_pages > 1){

        $base_args = [
            'page' => 'tct-history',
            'site_id' => $selected_site,
            'history_search' => $search,
            'date_from' => $date_from,
            'date_to' => $date_to
        ];

        echo '<p>';

        if($paged > 1){
            $base_args['paged'] = $paged - 1;
            echo '<a class="button" href="'.esc_url(add_query_arg($base_args, admin_url('admin.php'))).'">Previous</a> ';
        }

        if($paged < $total_pages){
            $base_args['paged'] = $paged + 1;
            echo '<a class="button" href="'.esc_url(add_query_arg($base_args, admin_url('admin.php'))).'">Next</a>';
        }

        echo '</p>';
    }

echo '<hr style="margin:20px 0;">';

echo '<input type="submit"
             name="compare_versions"
             class="button button-primary"
             style="
             border-radius:8px;
             padding:8px 18px;
             "
             value="Compare Selected Versions">';

echo '</form>';
echo '</div>';
echo '</div>';
}
function tct_settings_page(){

    if(isset($_POST['save_email'])){

        update_option(
            'tct_alert_email',
            sanitize_email($_POST['alert_email'])
        );
        update_option(
    'tct_enable_email',
    isset($_POST['enable_email']) ? 1 : 0
);

        update_option(
            'tct_keep_versions_per_site',
            max(1, intval($_POST['keep_versions_per_site']))
        );

        echo '<div class="notice notice-success is-dismissible">
        <p><strong>✓ Settings saved successfully.</strong></p>
      </div>';
    }

    if(isset($_POST['cleanup_versions'])){

        $keep_versions = max(1, intval($_POST['keep_versions_per_site']));

        update_option(
            'tct_keep_versions_per_site',
            $keep_versions
        );

        $deleted_versions = tct_cleanup_old_versions($keep_versions);

        echo '<div class="notice notice-success is-dismissible">
        <p><strong>✓ Cleanup completed.</strong><br>
        '.$deleted_versions.' old version(s) were removed successfully.</p>
      </div>';
    }
if(isset($_POST['send_test_email'])){

    $email = get_option('tct_alert_email');

    $subject = 'TC Tracker Test Email';

    $message = "Congratulations!\n\n".
               "Your TC Tracker email notification system is working successfully.\n\n".
               "This is a test email sent from your plugin.";

    if(wp_mail($email,$subject,$message)){

        echo '<div class="notice notice-success is-dismissible">
                <p><strong>✓ Test email sent successfully.</strong></p>
              </div>';

    }else{

        echo '<div class="notice notice-error is-dismissible">
                <p><strong>✗ Failed to send test email.</strong></p>
              </div>';

    }

}
    $email = get_option(
        'tct_alert_email',
        get_option('admin_email')
    );

    $keep_versions_per_site = get_option(
        'tct_keep_versions_per_site',
        20
    );

    ?>

    <div class="wrap">

    <h1 style="
font-size:34px;
font-weight:700;
margin-bottom:20px;
color:#1e293b;
">
TC Tracker Settings
</h1>
<p style="
font-size:15px;
color:#64748b;
margin-top:0;
margin-bottom:25px;
">
Configure email notifications and automatic version management for monitored websites.
</p>
    <div style="
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.1);
    border-top:4px solid #4361ee;
    max-width:700px;
    ">

    <form method="post">

            <table class="form-table">

                <tr>
                    <th>Alert Email</th>

                    <td>
                        <input
    type="email"
    name="alert_email"
    value="<?php echo esc_attr($email); ?>"
    style="
    width:100%;
    max-width:500px;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
    ">
                    </td>

                </tr>
                <tr>
    <th>Email Notifications</th>

    <td>
        <label>
            <input
                type="checkbox"
                name="enable_email"
                value="1"
                <?php checked(get_option('tct_enable_email', 1), 1); ?>
            >
            Receive an email alert whenever a monitored website's Terms & Conditions change and a new version is created.
        </label>
    </td>

</tr>
<tr>
    <th>SMTP Provider</th>

    <td>

        <span style="
        color:#16a34a;
        font-weight:600;
        ">
        🟢 Gmail SMTP Configured
        </span>

    </td>
</tr>

                <tr>
                    <th>Versions to Keep Per Website</th>

                    <td>
                        <input
    type="number"
    name="keep_versions_per_site"
    min="1"
    value="<?php echo esc_attr($keep_versions_per_site); ?>"
    style="
    width:120px;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
    ">
                        <p class="description">
                            Cleanup keeps this many newest versions for each website.
                        </p>
                    </td>

                </tr>

            </table>

            <input
                type="submit"
                name="save_email"
                class="button button-primary"
style="
padding:8px 18px;
border-radius:8px;
"
                value="Save Settings">

            <input
                type="submit"
                name="cleanup_versions"
                class="button"
style="
padding:8px 18px;
border-radius:8px;
margin-left:8px;
"
                value="Cleanup Old Versions">
                <input
type="submit"
name="send_test_email"
class="button"
style="
padding:8px 18px;
border-radius:8px;
margin-left:8px;
background:#16a34a;
border-color:#16a34a;
color:#fff;
"
value="Send Test Email">

        </form>

    </div>
</div>
    <?php
}
function tct_blockchain_page(){

    global $wpdb;

    $blockchain_table =
        $wpdb->prefix . 'tct_blockchain';

    $blocks = $wpdb->get_results(
        "SELECT *
         FROM $blockchain_table
         ORDER BY id ASC"
    );
    $is_valid = true;

for($i = 1; $i < count($blocks); $i++){

    $current = $blocks[$i];
    $previous = $blocks[$i - 1];

    if(
        $current->previous_hash !=
        $previous->block_hash
    ){

        $is_valid = false;
        break;
    }
}

    echo '<div class="wrap">';
    echo '<h1 style="
font-size:34px;
font-weight:700;
margin-bottom:20px;
color:#1e293b;
">
Blockchain Verification
</h1>';

    echo '<h2>Total Blocks: '
         .count($blocks).
         '</h2>';
         echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
border-top:4px solid #4361ee;
margin-top:20px;
">';
         if($is_valid){

    echo '<div class="notice notice-success">
            <p>Blockchain Status: VALID</p>
          </div>';

} else {

    echo '<div class="notice notice-error">
            <p>Blockchain Status: TAMPERED</p>
          </div>';
}
echo '<h2>Blockchain Records</h2>';

echo '<table
class="wp-list-table widefat fixed striped"
style="
border-radius:12px;
overflow:hidden;
border-collapse:separate;
border-spacing:0;
">';

echo '<thead>
<tr style="background:#f8f9fa;">
<th style="padding:15px;">ID</th>
<th style="padding:15px;">Version ID</th>
<th style="padding:15px;">Previous Hash</th>
<th style="padding:15px;">Current Hash</th>
<th style="padding:15px;">Block Hash</th>
</tr>
</thead>';

echo '<tbody>';

foreach($blocks as $block){

    echo '<tr>';

    echo '<td style="padding:15px;">'.$block->id.'</td>';

    echo '<td style="padding:15px;">'.$block->version_id.'</td>';

    echo '<td style="padding:15px;">'
         .substr($block->previous_hash,0,20)
         .'...</td>';

    echo '<td style="padding:15px;">'
         .substr($block->current_hash,0,20)
         .'...</td>';

    echo '<td style="padding:15px;">'
         .substr($block->block_hash,0,20)
         .'...</td>';

    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
    echo '</div>';
    echo '</div>';
}
if(!wp_next_scheduled('tct_daily_scan')){

    wp_schedule_event(
        time(),
        'daily',
        'tct_daily_scan'
    );
}
add_action(
    'tct_daily_scan',
    'tct_run_daily_scan'
);

function tct_run_daily_scan(){

    global $wpdb;

    $sites_table = $wpdb->prefix . 'tct_sites';

    $sites = $wpdb->get_results(
        "SELECT * FROM $sites_table"
    );

    foreach($sites as $site){

    tct_scan_website($site->id);

}
}
function tct_stats_shortcode() {
    global $wpdb;

    $sites = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tct_sites");

    $versions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tct_versions");

    $blockchain = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tct_blockchain");

    $scans = $wpdb->get_var("SELECT SUM(scan_count) FROM {$wpdb->prefix}tct_sites");

    ob_start();
    ?>
    <style>
.tct-stats-wrapper{
    display:flex;
    gap:20px;
    flex-wrap:wrap;
    justify-content:center;
    margin-top:20px;
    max-width:1000px;
margin:20px auto;
}

.tct-stat-card{
    width:220px;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:16px;
    padding:25px;
    text-align:center;
    box-shadow:0 4px 15px rgba(0,0,0,0.08);
    transition:all .3s ease;
}

.tct-stat-card h2{
    font-size:56px;
    font-weight:700;
    color:#4361ee;
    margin:0 0 10px 0;
}

.tct-stat-card p{
    margin:0;
    color:#64748b;
    font-size:16px;
}
.tct-stat-card{
    transition:all .3s ease;
}

.tct-stat-card:hover{
    transform:translateY(-5px);
}
</style>
    <div class="tct-stats-wrapper">

        <div class="tct-stat-card">
            <h2><?php echo $sites; ?></h2>
            <p>Websites Monitored</p>
        </div>

        <div class="tct-stat-card">
            <h2><?php echo $versions; ?></h2>
            <p>Versions Recorded</p>
        </div>

        <div class="tct-stat-card">
            <h2><?php echo $blockchain; ?></h2>
            <p>Blockchain Records</p>
        </div>

        <div class="tct-stat-card">
            <h2><?php echo $scans ? $scans : 0; ?></h2>
            <p>Total Scans</p>
        </div>

    </div>

    <?php
    return ob_get_clean();
}

add_shortcode('tc_tracker_stats', 'tct_stats_shortcode');
