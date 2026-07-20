<?php

function lp_extract_comment_text($comment)
{
    $text_parts = [];

    if (is_array($comment)) {
        $text_parts[] = isset($comment['comment_author']) ? $comment['comment_author'] : '';
        $text_parts[] = isset($comment['comment_author_email']) ? $comment['comment_author_email'] : '';
        $text_parts[] = isset($comment['comment_content']) ? $comment['comment_content'] : '';
        $text_parts[] = isset($comment['comment_author_url']) ? $comment['comment_author_url'] : '';
    } elseif (is_object($comment)) {
        $text_parts[] = isset($comment->comment_author) ? $comment->comment_author : '';
        $text_parts[] = isset($comment->comment_author_email) ? $comment->comment_author_email : '';
        $text_parts[] = isset($comment->comment_content) ? $comment->comment_content : '';
        $text_parts[] = isset($comment->comment_author_url) ? $comment->comment_author_url : '';
    }

    return implode(' ', $text_parts);
}

function lp_is_likely_spam_comment($comment)
{
    $text = lp_extract_comment_text($comment);
    $normalized_text = mb_strtolower($text, 'UTF-8');

    if (preg_match('/[\x{0400}-\x{04FF}\x{0500}-\x{052F}]/u', $normalized_text) === 1) {
        return true;
    }

    if (preg_match('/\bporn\b/u', $normalized_text) === 1) {
        return true;
    }

    return false;
}

function lp_group_likely_spam_comments($comments)
{
    $groups = [];

    foreach ($comments as $comment) {
        if (!lp_is_likely_spam_comment($comment)) {
            continue;
        }

        $author = '';
        $email = '';
        $comment_id = 0;

        if (is_array($comment)) {
            $author = isset($comment['comment_author']) ? $comment['comment_author'] : '';
            $email = isset($comment['comment_author_email']) ? $comment['comment_author_email'] : '';
            $comment_id = isset($comment['comment_ID']) ? (int) $comment['comment_ID'] : 0;
        } elseif (is_object($comment)) {
            $author = isset($comment->comment_author) ? $comment->comment_author : '';
            $email = isset($comment->comment_author_email) ? $comment->comment_author_email : '';
            $comment_id = isset($comment->comment_ID) ? (int) $comment->comment_ID : 0;
        }

        $key = mb_strtolower($author . "\0" . $email, 'UTF-8');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'comment_author' => $author,
                'comment_author_email' => $email,
                'count' => 0,
                'comment_ids' => [],
            ];
        }

        $groups[$key]['count']++;
        if ($comment_id) {
            $groups[$key]['comment_ids'][] = $comment_id;
        }
    }

    usort($groups, function ($left, $right) {
        return $right['count'] <=> $left['count'];
    });

    return array_values($groups);
}

function lp_get_pending_likely_spam_comments()
{
    $comments = get_comments([
        'status' => 'hold',
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC',
        'number' => -1,
    ]);

    return lp_group_likely_spam_comments($comments);
}

function lp_render_likely_spam_groups_html($groups)
{
    ob_start();

    if (empty($groups)) {
        echo '<p>No pending comments matched the suspicious criteria.</p>';
        return ob_get_clean();
    }

    echo '<table class="widefat fixed striped" style="margin-top: 8px;">';
    echo '<thead><tr><th>Username</th><th>Email</th><th>Spam comments</th></tr></thead>';
    echo '<tbody>';

    foreach ($groups as $group) {
        $author = $group['comment_author'] !== '' ? esc_html($group['comment_author']) : '(no username)';
        $email = $group['comment_author_email'] !== '' ? esc_html($group['comment_author_email']) : '(no email)';
        echo '<tr>';
        echo '<td>' . $author . '</td>';
        echo '<td>' . $email . '</td>';
        echo '<td>' . (int) $group['count'] . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    return ob_get_clean();
}

function lp_comments_spam_panel_markup()
{
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'edit-comments' || !current_user_can('moderate_comments')) {
        return;
    }

    echo '<div id="lp-comments-spam-toolbar" class="notice notice-info inline" style="margin: 20px 0 10px;">';
    echo '<p><strong>Likely spam review</strong></p>';
    echo '<button type="button" class="button" id="lp-find-likely-spam-comments">Find likely spam comments</button>';
    echo ' <button type="button" class="button button-primary" id="lp-mark-likely-spam-comments" disabled>Mark these as spam</button>';
    echo '<div id="lp-comments-spam-results" style="margin-top: 12px;"></div>';
    echo '</div>';
}

function lp_comments_spam_enqueue($hook_suffix)
{
    if ($hook_suffix !== 'edit-comments.php') {
        return;
    }

    if (!current_user_can('moderate_comments')) {
        return;
    }

    $script_url = plugins_url('js/lp-comments-spam.js', dirname(__DIR__) . '/local-petition.php');
    wp_enqueue_script('lp-comments-spam', $script_url, ['jquery'], LOCAL_PETITION_VERSION, true);
    wp_localize_script('lp-comments-spam', 'lpCommentsSpam', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lp_likely_spam_comments'),
    ]);
}

function lp_ajax_find_likely_spam_comments()
{
    if (!current_user_can('moderate_comments')) {
        wp_send_json_error(['message' => 'Forbidden']);
    }

    check_ajax_referer('lp_likely_spam_comments', 'nonce');

    $groups = lp_get_pending_likely_spam_comments();
    $html = lp_render_likely_spam_groups_html($groups);

    wp_send_json_success([
        'html' => $html,
        'count' => count($groups),
    ]);
}

function lp_ajax_mark_likely_spam_comments()
{
    if (!current_user_can('moderate_comments')) {
        wp_send_json_error(['message' => 'Forbidden']);
    }

    check_ajax_referer('lp_likely_spam_comments', 'nonce');

    $groups = lp_get_pending_likely_spam_comments();
    $comment_ids = [];
    foreach ($groups as $group) {
        foreach ($group['comment_ids'] as $comment_id) {
            $comment_ids[] = (int) $comment_id;
        }
    }

    $comment_ids = array_values(array_unique(array_filter($comment_ids)));
    foreach ($comment_ids as $comment_id) {
        wp_set_comment_status($comment_id, 'spam');
    }

    wp_send_json_success([
        'count' => count($comment_ids),
    ]);
}

if (function_exists('add_action')) {
    add_action('admin_notices', 'lp_comments_spam_panel_markup');
    add_action('admin_enqueue_scripts', 'lp_comments_spam_enqueue');
    add_action('wp_ajax_lp_find_likely_spam_comments', 'lp_ajax_find_likely_spam_comments');
    add_action('wp_ajax_lp_mark_likely_spam_comments', 'lp_ajax_mark_likely_spam_comments');
}
