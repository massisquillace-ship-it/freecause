<?php
/**
 * Laravel → WordPress SSO endpoint.
 *
 * Login:  ?p=BASE64_PAYLOAD&s=HMAC_SIG&r=REDIRECT
 * Logout: ?action=logout&r=REDIRECT
 *
 * The SSO_SECRET constant is defined in wp-config.php (loaded by wp-load.php).
 */

define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

// Validate and sanitise the redirect target — only relative paths allowed.
$redirect = $_GET['r'] ?? '/';
$signedRedirect = is_string($redirect) ? $redirect : '';
if (!is_string($redirect) || !preg_match('#^/[^/]#', $redirect)) {
    $redirect = '/';
}

// ── Logout ───────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    wp_logout();
    wp_redirect($redirect);
    exit;
}

// ── Login ────────────────────────────────────────────────────────────────────
$payload = $_GET['p'] ?? '';
$sig     = $_GET['s'] ?? '';

if (!$payload || !$sig || !defined('SSO_SECRET') || !SSO_SECRET) {
    wp_redirect($redirect);
    exit;
}

// Verify HMAC signature.
if (!hash_equals(hash_hmac('sha256', $payload . '|' . $signedRedirect, SSO_SECRET), $sig)) {
    wp_redirect($redirect);
    exit;
}

$data = json_decode(base64_decode($payload), true);

if (!is_array($data) || empty($data['e']) || empty($data['x'])) {
    wp_redirect($redirect);
    exit;
}

// Check token expiry.
if ((int) $data['x'] < time()) {
    wp_redirect($redirect);
    exit;
}

$nonce = (string) ($data['j'] ?? '');
if ($nonce === '' || get_transient('fc_sso_' . $nonce)) {
    wp_redirect($redirect);
    exit;
}
set_transient('fc_sso_' . $nonce, 1, 300);

$email       = strtolower(trim((string) $data['e']));
$displayName = $data['n'] ?? '';

$localPart = strtolower(strstr($email, '@', true));
$username  = sanitize_user($localPart . '_' . substr(md5($email), 0, 6), true);

// Find existing WP user by email, or create a subscriber account.
$wpUser = get_user_by('email', $email);

// Only log into accounts this endpoint provisioned: subscriber-only, flagged
// fc_sso_account (or the generated login, for accounts created before the flag).
// Never sign in as an editorial account that happens to share the email.
if ($wpUser && (array_values($wpUser->roles) !== ['subscriber']
    || (!get_user_meta($wpUser->ID, 'fc_sso_account', true) && $wpUser->user_login !== $username))) {
    error_log('FreeCause SSO: refused login into non-SSO WP user ID ' . (int) $wpUser->ID);
    wp_redirect($redirect);
    exit;
}

if (!$wpUser) {

    $userId = wp_insert_user([
        'user_login'   => $username,
        'user_email'   => $email,
        'display_name' => $displayName,
        'user_pass'    => wp_generate_password(),
        'role'         => 'subscriber',
    ]);

    if (is_wp_error($userId)) {
        wp_redirect($redirect);
        exit;
    }

    $wpUser = get_user_by('ID', $userId);
}

if (!$wpUser || is_wp_error($wpUser)) {
    wp_redirect($redirect);
    exit;
}

// Set WordPress auth cookies (persistent = true so it survives browser restarts).
update_user_meta($wpUser->ID, 'fc_sso_account', 1);
wp_set_current_user($wpUser->ID);
wp_set_auth_cookie($wpUser->ID, true);

wp_redirect($redirect);
exit;
