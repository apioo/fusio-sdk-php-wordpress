<?php
/**
 * Plugin Name:       Fusio
 * Plugin URI:        https://www.fusio-project.org/
 * Description:       Integrates Fusio an open source API management system. This helps if you want to use Wordpress as your Developer-Portal and Fusio as an API management system. If a user registers at your Wordpress-Site this plugin automatically creates also a user at the configured Fusio instance. The user can then use his credentials to access endpoints of your API.
 * Version:           0.1.0
 * Requires at least: 5.2
 * Requires PHP:      8.0
 * Author:            Christoph Kappestein
 * Author URI:        https://chrisk.app/
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://www.fusio-project.org/
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

Copyright 2005-2022 Automattic, Inc.
*/

define('FUSIO_VERSION', '0.1.0');
define('FUSIO_USER_AGENT', 'Wordpress/Fusio-Plugin-' . FUSIO_VERSION);

add_action('user_register', 'fusio_register', 10, 2);
add_action('wp_login', 'fusio_login', 10, 2);
add_action('wp_logout', 'fusio_logout');
add_action('admin_init', 'fusio_register_settings');
add_action('admin_menu', 'fusio_options_page');

function fusio_register(int $userId, array $userData): void
{
    $baseUrl = rtrim(get_option('fusio_base_url'), '/');
    $appKey = get_option('fusio_app_key');
    $appSecret = get_option('fusio_app_secret');
    $roleId = (int)get_option('fusio_role_id');

    if (empty($baseUrl) || empty($appKey) || empty($appSecret)) {
        return;
    }

    $accessToken = fusio_get_access_token($baseUrl, $appKey, $appSecret);
    if (!$accessToken) {
        error_log(FUSIO_USER_AGENT . ': Could not obtain access token for Fusio instance ' . $baseUrl . ', do you have configured the correct base url and app key/secret?', E_USER_NOTICE);
        return;
    }

    $body = [
        'roleId' => $roleId,
        'status' => 1,
        'name' => $userData['user_login'],
        'email' => $userData['user_email'],
        'password' => $userData['user_pass'],
    ];

    $response = wp_remote_post($baseUrl . '/backend/user', [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'User-Agent' => FUSIO_USER_AGENT
        ],
        'body' => json_encode($body),
    ]);

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    if (!$data instanceof stdClass) {
        error_log(FUSIO_USER_AGENT . ': An error occurred while registering a new user, the Fusio instance returned an invalid response', E_USER_NOTICE);
        return;
    }

    if (!isset($data->success) || $data->success === false) {
        $message = $data->message ?? 'An unknown error occurred';
        error_log(FUSIO_USER_AGENT . ': Could not create user at Fusio instance ' . $baseUrl . ' got: ' . $message, E_USER_NOTICE);
    }
}

function fusio_login(string $userLogin, WP_User $user): void
{
    $baseUrl = rtrim(get_option('fusio_base_url'), '/');
    if (empty($baseUrl)) {
        return;
    }

    $accessToken = fusio_get_access_token($baseUrl, $user->user_login, $user->user_pass);
    if (!$accessToken) {
        return;
    }

    $response = wp_remote_get($baseUrl . '/consumer/account', [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'User-Agent' => FUSIO_USER_AGENT
        ],
    ]);

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    if (!$data instanceof stdClass || !$data->name) {
        return;
    }

    // for all further API calls we can use the obtained access token
    update_user_meta($user->ID, 'fusio_access_token', $accessToken);
    update_user_meta($user->ID, 'fusio_account', json_encode($data));
}

function fusio_logout(int $userId): void
{
    update_user_meta($userId, 'fusio_access_token', null);
    update_user_meta($userId, 'fusio_account', null);
}

function fusio_get_access_token(string $baseUrl, string $appKey, string $appSecret): ?string
{
    $response = wp_remote_post($baseUrl . '/authorization/token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($appKey . ':' . $appSecret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => FUSIO_USER_AGENT
        ],
        'body' => [
            'grant_type' => 'client_credentials'
        ],
    ]);

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    if (!$data instanceof stdClass) {
        return null;
    }

    $accessToken = $data->access_token ?? null;
    if (empty($accessToken) || !is_string($accessToken)) {
        return null;
    }

    return $accessToken;
}

function fusio_register_settings(): void
{
    register_setting('fusio', 'fusio_base_url');
    register_setting('fusio', 'fusio_app_key');
    register_setting('fusio', 'fusio_app_secret');
    register_setting('fusio', 'fusio_role_id', [
        'default' => '3'
    ]);

    add_settings_section('fusio_section', 'Settings', 'fusio_section_callback', 'fusio');

    add_settings_field('fusio_base_url', 'Base-URL', 'fusio_field_text', 'fusio', 'fusio_section', [
        'key' => 'fusio_base_url',
        'type' => 'url',
        'description' => 'Contains the absolute url to the remote Fusio instance.',
    ]);
    add_settings_field('fusio_app_key', 'App-Key', 'fusio_field_text', 'fusio', 'fusio_section', [
        'key' => 'fusio_app_key',
        'type' => 'text',
        'description' => 'The App-Key or Username of your Fusio instance, these credentials are used to automatically create a new user on registration.',
    ]);
    add_settings_field('fusio_app_secret', 'App-Secret', 'fusio_field_text', 'fusio', 'fusio_section', [
        'key' => 'fusio_app_secret',
        'type' => 'password',
        'description' => 'The App-Secret or Password of your Fusio instance.',
    ]);
    add_settings_field('fusio_role_id', 'Role-ID', 'fusio_field_text', 'fusio', 'fusio_section', [
        'key' => 'fusio_role_id',
        'type' => 'number',
        'description' => 'The default Role-ID which every user gets assigned on registration, by default this is 3 which represents the consumer role.',
    ]);
}

function fusio_section_callback($args): void
{
    ?>
  <p>Configure the settings of the remote Fusio instance. </p>
    <?php
}

function fusio_field_text($args): void
{
    $value = get_option($args['key']);
    echo '<input type="' . esc_attr($args['type']) . '" id="' . esc_attr($args['key']) . '" name="' . esc_attr($args['key']) . '" value="' . esc_attr($value) . '">';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
}

function fusio_options_page(): void
{
    add_menu_page(
        'Fusio',
        'Fusio',
        'manage_options',
        'fusio',
        'fusio_options_page_html'
    );
}

function fusio_options_page_html(): void
{
    // check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['settings-updated'])) {
        // check whether we can obtain an access token
        $baseUrl = rtrim(get_option('fusio_base_url'), '/');
        $appKey = get_option('fusio_app_key');
        $appSecret = get_option('fusio_app_secret');

        $accessToken = fusio_get_access_token($baseUrl, $appKey, $appSecret);
        if (empty($accessToken)) {
            add_settings_error('fusio_messages', 'fusio_messages', __('Could not obtain an access token at the Fusio instance <a href="' . $baseUrl . '">' . $baseUrl . '</a> for the provided app credentials.', 'fusio'), 'error');
        } else {
            add_settings_error('fusio_messages', 'fusio_messages', __('Settings Saved', 'fusio'), 'updated');
        }
    }

    settings_errors('fusio_messages');
    ?>
  <div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <form action="options.php" method="post">
        <?php
        settings_fields('fusio');
        do_settings_sections('fusio');
        submit_button('Save Settings');
        ?>
    </form>
  </div>
    <?php
}
