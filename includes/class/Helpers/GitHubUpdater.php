<?php
namespace EVN\Helpers;

class GitHubUpdater {
    private $plugin_file;   // полный путь к основному плагину (например, /var/www/wp-content/plugins/everneu-control/everneu-control.php)
    private $plugin_slug;   // 'everneu-control/everneu-control.php'
    private $github_user;   // 'MariaDadonova'
    private $github_repo;   // 'evernue'
    private $branch;        // 'Sitemap_and_svg_settings'
    private $subfolder;     // 'wp-content/plugins/everneu-control'

    public function __construct($plugin_file, $github_user, $github_repo, $branch = 'main', $subfolder = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file); // 'everneu-control/everneu-control.php'
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->branch = $branch;
        $this->subfolder = trim($subfolder, '/');

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[GitHubUpdater] ' . $message);
        }
    }

    private function get_auth_headers() {
        return [
            'User-Agent' => 'WordPress Plugin Updater',
            'Authorization' => 'token ' . EVN_GITHUB_TOKEN,
            'Accept' => 'application/vnd.github.v3+json',
        ];
    }

    /**
     * Получить API URL для файла плагина в репо GitHub
     */
    private function get_api_file_url() {
        $file_path = $this->subfolder ? $this->subfolder . '/' . basename($this->plugin_file) : basename($this->plugin_file);
        return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/contents/{$file_path}?ref={$this->branch}";
    }

    /**
     * Получить URL ZIP-архива ветки для обновления
     */
    private function get_zip_url() {
        return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/zipball/{$this->branch}";
    }

    /**
     * Проверка обновления плагина
     */
    public function check_for_update($transient) {
        error_log('check_for_update called');

        if (empty($transient->checked)) {
            $this->log('No plugins checked, skipping update check.');
            return $transient;
        }

        $this->log('Checking update for plugin: ' . $this->plugin_slug);

        $response = wp_remote_get($this->get_api_file_url(), [
            'headers' => $this->get_auth_headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $this->log('Error fetching plugin file from GitHub: ' . $response->get_error_message());
            return $transient;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("Unexpected HTTP code fetching plugin file: {$code}");
            return $transient;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['content'])) {
            $this->log('No content found in GitHub API response.');
            return $transient;
        }

        $file_content = base64_decode($data['content']);
        $plugin_data = $this->get_plugin_data_from_text($file_content);
        $remote_version = $plugin_data['Version'] ?? '';
        $current_version = get_plugin_data($this->plugin_file)['Version'];

        $this->log("Remote version: {$remote_version}, Current version: {$current_version}");

        if ($remote_version && version_compare($remote_version, $current_version, '>')) {
            $this->log("Update found. Adding to transient.");
            $transient->response[$this->plugin_slug] = (object)[
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package' => $this->get_zip_url(),
            ];
        } else {
            $this->log("No update found.");
        }

        return $transient;
    }

    /**
     * Фильтр plugins_api для показа инфо плагина
     */
    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $this->log('Fetching plugin info for API.');

        $response = wp_remote_get($this->get_api_file_url(), [
            'headers' => $this->get_auth_headers(),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $this->log('Error fetching plugin info: ' . $response->get_error_message());
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("Unexpected HTTP code fetching plugin info: {$code}");
            return $result;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['content'])) {
            $this->log('No content found in plugin info response.');
            return $result;
        }

        $file_content = base64_decode($data['content']);
        $plugin_data = $this->get_plugin_data_from_text($file_content);

        return (object)[
            'name' => $plugin_data['Name'] ?? 'Plugin',
            'slug' => dirname($this->plugin_slug),
            'version' => $plugin_data['Version'] ?? '',
            'author' => $plugin_data['Author'] ?? '',
            'homepage' => $plugin_data['PluginURI'] ?? '',
            'sections' => ['description' => $plugin_data['Description'] ?? ''],
        ];
    }

    /**
     * После установки обновления - перемещаем папку с поддиректорией
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->plugin_slug);
        $temp_dir = $result['destination'];

        // Стандартное имя распакованной папки в zipball GitHub
        // Формат: {repo}-{branch}/{subfolder}
        $unpacked_subfolder = trailingslashit($temp_dir) . $this->github_repo . '-' . $this->branch;

        if ($this->subfolder) {
            $unpacked_subfolder = trailingslashit($unpacked_subfolder) . $this->subfolder;
        }

        $this->log("After install: deleting old plugin dir {$plugin_dir}");
        $wp_filesystem->delete($plugin_dir, true);

        if ($wp_filesystem->is_dir($unpacked_subfolder)) {
            $this->log("Moving unpacked subfolder {$unpacked_subfolder} to plugin dir {$plugin_dir}");
            $wp_filesystem->move($unpacked_subfolder, $plugin_dir);
        } else {
            $this->log("Moving temp dir {$temp_dir} to plugin dir {$plugin_dir}");
            $wp_filesystem->move($temp_dir, $plugin_dir);
        }

        $result['destination'] = $plugin_dir;
        return $result;
    }

    /**
     * Парсим данные плагина из текста файла
     * Используем WP функцию get_file_data, но она работает с файлами,
     * поэтому реализуем из текста (упрощённо)
     */
    private function get_plugin_data_from_text($text) {
        $headers = [
            'Name' => 'Plugin Name',
            'PluginURI' => 'Plugin URI',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
        ];

        $data = [];

        foreach ($headers as $field => $header_name) {
            if (preg_match('/' . preg_quote($header_name, '/') . ':\s*(.*)$/mi', $text, $matches)) {
                $data[$field] = trim($matches[1]);
            } else {
                $data[$field] = '';
            }
        }

        return $data;
    }
}