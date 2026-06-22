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
}

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

function tct_scan_website($site_id){

    global $wpdb;

    $sites_table = $wpdb->prefix . 'tct_sites';
    $versions_table = $wpdb->prefix . 'tct_versions';

    $site = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $sites_table WHERE id=%d",
            $site_id
        )
    );

    if(!$site){
        return false;
    }

    $response = wp_remote_get($site->url);

    if(is_wp_error($response)){
        return false;
    }

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
    $admin_email = get_option(
    'tct_alert_email',
    get_option('admin_email')
);

$subject = 'TC Tracker Alert';

$message =
    "A change was detected.\n\n".
    "Website: ".$site->site_name."\n".
    "URL: ".$site->url."\n".
    "Time: ".current_time('mysql');

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
    }

    $sites = $wpdb->get_results("SELECT * FROM $table");

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
    if(isset($_POST['compare_versions'])){

    if(isset($_POST['compare']) &&
       count($_POST['compare']) == 2){

        $id1 = intval($_POST['compare'][0]);
        $id2 = intval($_POST['compare'][1]);

        $version1 = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $versions_table WHERE id=%d",
                $id1
            )
        );

        $version2 = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $versions_table WHERE id=%d",
                $id2
            )
        );

        echo '<div class="wrap">';
        echo '<h1>Compare Versions</h1>';
        echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
margin-top:20px;
">';



        $text1 = wp_strip_all_tags($version1->content);
        $text2 = wp_strip_all_tags($version2->content);
        
        if(hash('sha256',$text1) ==
           hash('sha256',$text2)){

           echo '<div class="notice notice-success">
            <p>No text differences detected.</p>
          </div>';

        } else {

          echo '<div class="notice notice-warning">
            <p>Text differences detected.</p>
          </div>';
}
echo '<h3>Statistics</h3>';

echo '<p>Version '.$id1.' Length: '
     .strlen($text1).
     ' characters</p>';

echo '<p>Version '.$id2.' Length: '
     .strlen($text2).
     ' characters</p>';

$max = min(strlen($text1), strlen($text2));

$position = -1;

for($i=0; $i<$max; $i++){

    if($text1[$i] != $text2[$i]){

        $position = $i;
        break;
    }
}

if($position != -1){

    $old_snippet = substr($text1,$position,300);
    $new_snippet = substr($text2,$position,300);
    
    $lines1 = explode("\n", wordwrap($text1, 100, "\n"));
    $lines2 = explode("\n", wordwrap($text2, 100, "\n"));

    $removed = array_diff($lines1, $lines2);
    $added   = array_diff($lines2, $lines1);

    echo '<h3>Removed Lines</h3>';

    foreach($removed as $line){

    echo '<div style="
        background:#ffecec;
        border-left:4px solid red;
        padding:8px;
        margin-bottom:5px;
    ">';

    echo '- '.esc_html($line);

    echo '</div>';
    }

    echo '<h3>Added Lines</h3>';

    foreach($added as $line){

    echo '<div style="
        background:#eaffea;
        border-left:4px solid green;
        padding:8px;
        margin-bottom:5px;
    ">';

    echo '+ '.esc_html($line);

    echo '</div>';
}
    echo '<h3>Difference Preview</h3>';

    echo '<div style="
        background:#ffecec;
        border-left:4px solid red;
        padding:10px;
        margin-bottom:10px;
        white-space:pre-wrap;
    ">';

    echo '<strong>- Old Version</strong><br>';
    echo esc_html($old_snippet);

    echo '</div>';

    echo '<div style="
        background:#eaffea;
        border-left:4px solid green;
        padding:10px;
        white-space:pre-wrap;
    ">';

    echo '<strong>+ New Version</strong><br>';
    echo esc_html($new_snippet);

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
            "SELECT * FROM $versions_table WHERE id=%d",
            $version_id
        )
    );

    if($version){

        echo '<div class="wrap">';
        echo '<h1>Version '.$version_id.'</h1>';
        echo '<div style="
background:#fff;
padding:25px;
border-radius:12px;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
margin-top:20px;
">';

        echo '<h3 style="margin-top:0;">Version Hash</h3>';
       echo '<textarea
rows="3"
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
">'
.$version->page_hash.
'</textarea>';

        echo '<h3>Content Preview</h3>';

       echo '<textarea
rows="20"
style="
width:100%;
padding:12px;
border-radius:8px;
border:1px solid #ddd;
font-family:monospace;
">'
             .esc_textarea(substr($version->content,0,5000)).
             '</textarea>';

        echo '</div>';
        echo '</div>';
        return;
    }
}

    $versions = $wpdb->get_results("
        SELECT v.*, s.site_name
        FROM $versions_table v
        JOIN $sites_table s
        ON v.site_id = s.id
        ORDER BY v.id DESC
    ");

    echo '<div class="wrap">';
    echo '<h1 style="
font-size:34px;
font-weight:700;
margin-bottom:20px;
color:#1e293b;
">
Version History
</h1>';
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
            <th style="padding:15px;">ID</th>
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

        echo '<td style="padding:15px;">'.$version->id.'</td>';

echo '<td style="padding:15px;">'
     .esc_html($version->site_name).
     '</td>';

echo '<td style="padding:15px;">'
     .substr($version->page_hash,0,20).
     '...</td>';

echo '<td style="padding:15px;">'
     .$version->created_at.
     '</td>';
        echo '<td>
        <a class="button"
   style="
   border-radius:8px;
   padding:6px 14px;
   "
   href="?page=tct-history&view='.$version->id.'">
   View
</a>
      </td>';
      
      echo '<td>
        <input type="checkbox"
               name="compare[]"
               value="'.$version->id.'">
        '.$version->id.'
      </td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

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

        echo '<div class="notice notice-success">
                <p>Settings Saved.</p>
              </div>';
    }

    $email = get_option(
        'tct_alert_email',
        get_option('admin_email')
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