<?php
/*
Plugin Name: Featured Job Milestone Notifier
Description: Sends milestone-based email updates for selected WPJM featured job ads.
Version: 1.6
Author: Deepak Chhabra
*/

if (!defined('ABSPATH')) exit;

class Featured_Job_Milestone_Notifier {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('wp_loaded', [$this, 'check_milestones']);
        add_action('wp_ajax_fjn_preview_email', [$this, 'ajax_preview_email']);
    }

    public function register_admin_page() {
        add_menu_page(
            'Job Milestone Notifier',
            'Job Notifier',
            'manage_options',
            'job-milestone-notifier',
            [$this, 'admin_page_html'],
            'dashicons-megaphone',
            26
        );
    }

public function admin_page_html() {
    if (isset($_POST['fjn_delete']) && check_admin_referer('fjn_save_settings', 'fjn_nonce')) {
        $job_id = intval($_POST['fjn_job_id']);
        
        // Delete job-specific settings
        delete_option('fjn_tracking_job_' . $job_id);

        // Remove job_id from array
        $job_ids = get_option('fjn_tracking_job_ids', []);
        $job_ids = array_diff($job_ids, [$job_id]);
        update_option('fjn_tracking_job_ids', array_values($job_ids)); // reindex

        
        for ($i = 1; $i <= 3; $i++) {
            delete_post_meta($job_id, "_fjn_milestone_{$i}_sent");
        }
        delete_post_meta($job_id, '_fjn_expired_sent');
        echo '<div class="updated"><p>Settings deleted for job ID ' . $job_id . '.</p></div>';
    } elseif (isset($_POST['fjn_save']) && check_admin_referer('fjn_save_settings', 'fjn_nonce')) {
        // Remove slashes deeply before sanitizing
        $_POST = stripslashes_deep($_POST);

        $job_id = intval($_POST['fjn_job_id']);
        $settings = [
            'email' => sanitize_email($_POST['fjn_email']),
            'milestone_subject' => sanitize_text_field($_POST['fjn_milestone_subject']),
            'milestone_body' => wp_kses_post($_POST['fjn_milestone_body']),
            'expiry_subject' => sanitize_text_field($_POST['fjn_expiry_subject']),
            'expiry_body' => wp_kses_post($_POST['fjn_expiry_body']),
        ];
        for ($i = 1; $i <= 3; $i++) {
            $settings["milestone_{$i}_clicks"] = intval($_POST["fjn_milestone_{$i}_clicks"]);
            $settings["milestone_{$i}_impressions"] = intval($_POST["fjn_milestone_{$i}_impressions"]);
            $settings["milestone_{$i}_mode"] = sanitize_text_field($_POST["fjn_milestone_{$i}_mode"]);
        }
        
        // Save individual job settings
        update_option('fjn_tracking_job_' . $job_id, $settings);

        // Store job ID in array (fjn_tracking_job_ids)
        $job_ids = get_option('fjn_tracking_job_ids', []);
        if (!in_array($job_id, $job_ids)) {
            $job_ids[] = $job_id;
            update_option('fjn_tracking_job_ids', $job_ids);
        }
        
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $args = [
        'post_type' => 'job_listing',
        'meta_query' => [
            ['key' => '_featured', 'value' => '1', 'compare' => '=']
        ],
        'posts_per_page' => 100,
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    $jobs = get_posts($args);
    
    $job_ids = get_option('fjn_tracking_job_ids', []);
    $job_id = isset($_POST['fjn_job_id']) ? intval($_POST['fjn_job_id']) : ($job_ids[0] ?? 0);
    
    
    $saved_settings = get_option('fjn_tracking_job_' . $job_id, []);
    // Set default template content if not already saved
    $signature = "\nPeter\nwww.nytlægejob.dk\n\nNytlægejob.dk er Danmarks største jobsite kun for lægejobs. Vi viser jobs både i den private og offentlige sektor.";

    $defaults = [
        'milestone_subject' => 'Milestone Reached: {job_title}',
        'milestone_body' => "Congratulations!\n\nYour featured job \"{job_title}\" has reached a milestone.\n{click_line}\n{impression_line}" . $signature,
        'expiry_subject' => 'Your Job Listing Has Expired: {job_title}',
        'expiry_body' => "Hello,\n\nYour featured job listing \"{job_title}\" has expired.\n\nThank you for using our platform." . $signature
    ];

    foreach ($defaults as $key => $value) {
        if (empty($saved_settings[$key])) {
            $saved_settings[$key] = $value;
        }
    }
    
    // Check which milestones have been sent
    $milestone_sent = [];
    for ($i = 1; $i <= 3; $i++) {
        $milestone_sent[$i] = get_post_meta($job_id, "_fjn_milestone_{$i}_sent", true);
    }

    echo '<div class="wrap"><h1>Featured Job Milestone Notifier</h1>';
    echo '<form method="post">';
    wp_nonce_field('fjn_save_settings', 'fjn_nonce');

    echo '<div><label for="fjn_job_id"><strong>Select Job:</strong></label><br>';
    echo '<select name="fjn_job_id" id="fjn_job_id" onchange="this.form.submit()">';
    foreach ($jobs as $job) {
        $selected = ($job->ID == $job_id) ? 'selected' : '';
        echo "<option value='{$job->ID}' $selected>" . esc_html($job->post_title) . "</option>";
    }
    echo '</select></div><br>';

    echo '<div><label for="fjn_email"><strong>Email Address:</strong></label><br>';
    echo "<input type='email' name='fjn_email' id='fjn_email' value='" . esc_attr($saved_settings['email'] ?? '') . "'></div><br>";

    for ($i = 1; $i <= 3; $i++) {
        $clicks = $saved_settings["milestone_{$i}_clicks"] ?? '';
        $impressions = $saved_settings["milestone_{$i}_impressions"] ?? '';
        $mode = $saved_settings["milestone_{$i}_mode"] ?? 'both';
        $disabled = $milestone_sent[$i] ? 'disabled' : '';

        echo "<fieldset style='padding:1em; border:1px solid #ccc; margin-bottom:1em;'><legend><strong>Milestone {$i}" . 
             ($milestone_sent[$i] ? ' (Already Sent)' : '') . "</strong></legend>";

        echo '<div><label for="fjn_milestone_' . $i . '_clicks">Clicks Threshold:</label><br>';
        echo "<input type='number' name='fjn_milestone_{$i}_clicks' id='fjn_milestone_{$i}_clicks' value='" . 
             esc_attr($clicks) . "' $disabled></div><br>";

        echo '<div><label for="fjn_milestone_' . $i . '_impressions">Impressions Threshold:</label><br>';
        echo "<input type='number' name='fjn_milestone_{$i}_impressions' id='fjn_milestone_{$i}_impressions' value='" . 
             esc_attr($impressions) . "' $disabled></div><br>";

        echo '<div><label for="fjn_milestone_' . $i . '_mode">Send Email Based On:</label><br>';
        echo "<select name='fjn_milestone_{$i}_mode' id='fjn_milestone_{$i}_mode' $disabled>";
        foreach (['clicks' => 'Clicks Only', 'impressions' => 'Impressions Only', 'both' => 'Both'] as $val => $label) {
            $selected = $val === $mode ? 'selected' : '';
            echo "<option value='{$val}' {$selected}>{$label}</option>";
        }
        echo "</select></div>";
        
        // Add preview button for this milestone
        echo '<div style="margin-top:10px;">';
        echo '<button type="button" class="button" onclick="fjnPreviewMilestoneEmail(' . $i . ')" ' . 
             ($milestone_sent[$i] ? 'disabled' : '') . '>Preview Milestone ' . $i . ' Email</button>';
        echo '</div>';

        echo "</fieldset>";
    }

    echo '<hr><h2>Email Templates</h2>';

    echo '<div><label for="fjn_milestone_subject">Milestone Email Subject:</label><br>';
    echo '<input type="text" name="fjn_milestone_subject" id="fjn_milestone_subject" style="width:100%;" value="' . 
         esc_attr($saved_settings['milestone_subject'] ?? '') . '"></div><br>';

    echo '<div><label for="fjn_milestone_body">Milestone Email Body:</label><br>';
    echo '<textarea name="fjn_milestone_body" id="fjn_milestone_body" rows="6" style="width:100%;">' . 
         esc_textarea($saved_settings['milestone_body'] ?? '') . '</textarea><br>';
    echo '<button type="button" class="button" onclick="fjnPreviewEmail(\'milestone\')">Preview Milestone Email</button></div><br>';

    echo '<div><label for="fjn_expiry_subject">Expiry Email Subject:</label><br>';
    echo '<input type="text" name="fjn_expiry_subject" id="fjn_expiry_subject" style="width:100%;" value="' . 
         esc_attr($saved_settings['expiry_subject'] ?? '') . '"></div><br>';

    echo '<div><label for="fjn_expiry_body">Expiry Email Body:</label><br>';
    echo '<textarea name="fjn_expiry_body" id="fjn_expiry_body" rows="6" style="width:100%;">' . 
         esc_textarea($saved_settings['expiry_body'] ?? '') . '</textarea><br>';
    echo '<button type="button" class="button" onclick="fjnPreviewEmail(\'expiry\')">Preview Expiry Email</button></div><br>';

    echo '<input type="submit" name="fjn_save" class="button button-primary" value="Save Settings"> ';
    echo '<input type="submit" name="fjn_delete" class="button button-secondary" value="Delete Settings" onclick="return confirm(\'Are you sure you want to delete all settings for this job?\');">';
    echo '</form></div>';

    // Modal container for preview popup
    ?>
    <div id="fjn-preview-modal" style="display:none; position:fixed; top:10%; left:50%; transform:translateX(-50%); background:#fff; border:1px solid #ccc; padding:20px; max-width:600px; max-height:80vh; overflow:auto; z-index:99999; box-shadow:0 0 15px rgba(0,0,0,0.5);">
        <h2>Email Preview</h2>
        <div id="fjn-preview-content" style="white-space:pre-wrap; font-family: monospace; background:#f7f7f7; padding:15px; border:1px solid #ddd;"></div>
        <button onclick="document.getElementById('fjn-preview-modal').style.display='none';" class="button" style="margin-top:15px;">Close</button>
    </div>
    <script>
    function fjnPreviewEmail(type) {
        const jobId = document.getElementById('fjn_job_id').value;
        const subject = document.getElementById('fjn_' + type + '_subject').value;
        const body = document.getElementById('fjn_' + type + '_body').value;

        if (!jobId) {
            alert('Please select a job first.');
            return;
        }

        const data = new FormData();
        data.append('action', 'fjn_preview_email');
        data.append('job_id', jobId);
        data.append('email_type', type);
        data.append('subject', subject);
        data.append('body', body);
        data.append('_ajax_nonce', '<?php echo wp_create_nonce('fjn_preview_email_nonce'); ?>');

        fetch(ajaxurl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(json => {
            if (json.success) {
                const modal = document.getElementById('fjn-preview-modal');
                const content = document.getElementById('fjn-preview-content');
                content.innerHTML = json.data;
                modal.style.display = 'block';
            } else {
                alert('Error: ' + (json.data || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
    }

    function fjnPreviewMilestoneEmail(milestoneNumber) {
        const jobId = document.getElementById('fjn_job_id').value;
        const subject = document.getElementById('fjn_milestone_subject').value;
        const body = document.getElementById('fjn_milestone_body').value;
        const mode = document.getElementById('fjn_milestone_' + milestoneNumber + '_mode').value;

        if (!jobId) {
            alert('Please select a job first.');
            return;
        }

        const data = new FormData();
        data.append('action', 'fjn_preview_email');
        data.append('job_id', jobId);
        data.append('email_type', 'milestone');
        data.append('milestone_number', milestoneNumber);
        data.append('subject', subject);
        data.append('body', body);
        data.append('mode', mode);
        data.append('_ajax_nonce', '<?php echo wp_create_nonce('fjn_preview_email_nonce'); ?>');

        fetch(ajaxurl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(json => {
            if (json.success) {
                const modal = document.getElementById('fjn-preview-modal');
                const content = document.getElementById('fjn-preview-content');
                content.innerHTML = json.data;
                modal.style.display = 'block';
            } else {
                alert('Error: ' + (json.data || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
    }
    </script>
    <?php
}



public function check_milestones() {
    $job_ids = get_option('fjn_tracking_job_ids', []);

    if (!is_array($job_ids) || empty($job_ids)) return;
	
    // Add filters to modify the from name and email
    add_filter('wp_mail_from', function($from_email) {
        return 'kontakt@nytlaegejob.dk'; 
    });
    
    add_filter('wp_mail_from_name', function($from_name) {
        return 'Nytlægejob.dk'; 
    });
	
    foreach ($job_ids as $job_id) {
        $job_id = intval($job_id);
        if (!$job_id) continue;

        $config = get_option('fjn_tracking_job_' . $job_id, []);
        if (empty($config['email'])) continue;

        $email = $config['email'];
        $stats = $this->get_job_stats($job_id);

        // Milestone loop
        for ($i = 1; $i <= 3; $i++) {
            $click_threshold = isset($config["milestone_{$i}_clicks"]) ? (int)$config["milestone_{$i}_clicks"] : 0;
            $impression_threshold = isset($config["milestone_{$i}_impressions"]) ? (int)$config["milestone_{$i}_impressions"] : 0;
            $mode = $config["milestone_{$i}_mode"] ?? 'both';
            $sent_key = "_fjn_milestone_{$i}_sent";

            // Skip if already sent or if thresholds are not defined
            if (get_post_meta($job_id, $sent_key, true) ||
                ($click_threshold <= 0 && $impression_threshold <= 0)) {
                continue;
            }

            $should_send = false;

            // Decide based on mode
            if ($mode === 'clicks' && $stats['clicks'] >= $click_threshold) {
                $should_send = true;
            } elseif ($mode === 'impressions' && $stats['impressions'] >= $impression_threshold) {
                $should_send = true;
            } elseif ($mode === 'both') {
                $should_send = ($click_threshold > 0 && $stats['clicks'] >= $click_threshold)
                            || ($impression_threshold > 0 && $stats['impressions'] >= $impression_threshold);
            }

            if ($should_send) {
                $subject = $this->replace_tags($config['milestone_subject'], $job_id, $stats, $mode);
                $body = $this->replace_tags($config['milestone_body'], $job_id, $stats, $mode);
                //$footer = "\nPeter\nwww.nytlægejob.dk\n\nNytlægejob.dk er Danmarks største jobsite kun for lægejobs. Vi viser jobs både i den private og offentlige sektor.";
                //$body .= $footer;

                if (wp_mail($email, $subject, $body)) {
                    update_post_meta($job_id, $sent_key, true);
                } else {
                    error_log("[Featured Job Notifier] Failed to send milestone {$i} email for Job ID: {$job_id}");
                }
            }
        }

        // Expired job handling
        if (get_post_status($job_id) === 'expired' && !get_post_meta($job_id, '_fjn_expired_sent', true)) {
            $subject = $this->replace_tags($config['expiry_subject'], $job_id, $stats);
            $body = $this->replace_tags($config['expiry_body'], $job_id, $stats);
            //$footer = "\nPeter\nwww.nytlægejob.dk\n\nNytlægejob.dk er Danmarks største jobsite kun for lægejobs. Vi viser jobs både i den private og offentlige sektor.";
            //$body .= $footer;

            if (wp_mail($email, $subject, $body)) {
                update_post_meta($job_id, '_fjn_expired_sent', true);
				// Remove job ID from tracking list
				$job_ids = get_option('fjn_tracking_job_ids', []);
				if (($key = array_search($job_id, $job_ids)) !== false) {
					unset($job_ids[$key]);
					update_option('fjn_tracking_job_ids', array_values($job_ids)); // reindex array
				}
				
				// Also delete the job's specific settings
				delete_option('fjn_tracking_job_' . $job_id);				
            } else {
                error_log("[Featured Job Notifier] Failed to send expiry email for Job ID: {$job_id}");
            }
        }
    }
    // Remove the filters immediately after sending
    remove_filter('wp_mail_from', function($from_email) {
        return 'kontakt@nytlaegejob.dk';
    });
    
    remove_filter('wp_mail_from_name', function($from_name) {
        return 'Nytlægejob.dk';
    });	
}

public function ajax_preview_email() {
    check_ajax_referer('fjn_preview_email_nonce');

    $job_id = intval($_POST['job_id'] ?? 0);
    $email_type = sanitize_text_field($_POST['email_type'] ?? '');
    $subject = isset($_POST['subject']) ? stripslashes_deep($_POST['subject']) : '';
    $body = isset($_POST['body']) ? stripslashes_deep($_POST['body']) : '';
    $milestone_number = intval($_POST['milestone_number'] ?? 0);
    $mode = sanitize_text_field($_POST['mode'] ?? 'both');

    if (!$job_id || !in_array($email_type, ['milestone', 'expiry'], true)) {
        wp_send_json_error('Invalid input');
    }

    $subject = wp_kses_post($subject);
    $body = wp_kses_post($body);

    $stats = $this->get_job_stats($job_id);
    $config = get_option('fjn_tracking_job_' . $job_id, []);

    // Generate click and impression lines based on mode
    $click_line = ($mode === 'clicks' || $mode === 'both') ? "Clicks: {$stats['clicks']}\n" : '';
    $impression_line = ($mode === 'impressions' || $mode === 'both') ? "Impressions: {$stats['impressions']}\n" : '';
    
    // Add these to the stats array for replacement
    $stats['click_line'] = $click_line;
    $stats['impression_line'] = $impression_line;

    //$footer = nl2br("Peter\nwww.nytlægejob.dk\n\nNytlægejob.dk er Danmarks største jobsite kun for lægejobs. Vi viser jobs både i den private og offentlige sektor.");

    $full_body = nl2br($this->replace_tags($body, $job_id, $stats, $mode)) . '<br><br>' . $footer;
    $full_subject = esc_html($this->replace_tags($subject, $job_id, $stats, $mode));

    $html = '<div style="font-family: Arial, sans-serif; line-height: 1.5;">';
    $html .= '<h2 style="margin-bottom: 10px;">Email Preview</h2>';
    if ($email_type === 'milestone' && $milestone_number) {
        $html .= '<strong>Milestone:</strong> ' . esc_html($milestone_number) . '<br>';
    }
    $html .= '<strong>Mode:</strong> ' . esc_html(ucfirst($mode)) . '<br>';
    $html .= '<strong>Subject:</strong> ' . $full_subject;
    $html .= '<hr>';
    $html .= '<strong>Body:</strong><br>' . $full_body;
    $html .= '</div>';

    wp_send_json_success($html);
}

	private function get_job_stats( $job_id ) {
		global $wpdb;

		$results = $wpdb->get_row(
			$wpdb->prepare(
				"
				SELECT 
					SUM(CASE WHEN name = 'job_view' THEN count ELSE 0 END) AS views,
					SUM(CASE WHEN name = 'job_search_impression' THEN count ELSE 0 END) AS impressions
				FROM {$wpdb->prefix}wpjm_stats
				WHERE post_id = %d
				", 
				$job_id
			),
			ARRAY_A
		);

		return [
			'clicks'      => isset( $results['views'] ) ? (int) $results['views'] : 0,
			'impressions' => isset( $results['impressions'] ) ? (int) $results['impressions'] : 0,
		];
	}


    private function replace_tags($text, $job_id, $stats, $mode = 'both') {
        $click_line = ($mode === 'clicks' || $mode === 'both') ? "Clicks: {$stats['clicks']}" : '';
        $impression_line = ($mode === 'impressions' || $mode === 'both') ? "Impressions: {$stats['impressions']}" : '';
        $job = get_post($job_id);
        if (!$job) return $text;

        $replacements = [
            '{job_title}' => $job->post_title,
            '{job_id}' => $job_id,
            '{clicks}' => $stats['clicks'],
            '{impressions}' => $stats['impressions'],
            '{click_line}' => $click_line,
            '{impression_line}' => $impression_line,
            '{job_url}' => get_permalink($job_id),
        ];
        return strtr($text, $replacements);
    }
}

new Featured_Job_Milestone_Notifier();
