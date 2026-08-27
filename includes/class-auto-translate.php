<?php
/**
 * AI Auto-Translate Integration
 *
 * Automatic translation using AI services:
 * - OpenAI GPT-4o-mini (~$0.0003 per translation, excellent quality)
 * - DeepL API (free tier: 500k chars/month, high quality)
 *
 * Settings stored in WordPress options:
 * - stm_auto_translate_provider: 'openai' | 'deepl'
 * - stm_openai_api_key: OpenAI API key
 * - stm_deepl_api_key: DeepL API key
 *
 * @package SimpleTranslationManager
 */

namespace STM;

class AutoTranslate {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        $namespace = 'stm/v1';

        register_rest_route($namespace, '/translate/auto', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_auto_translate'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/translate/auto/batch', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_batch_translate'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/translate/auto/settings', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_get_settings'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/translate/auto/settings', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_save_settings'],
            'permission_callback' => [API::class, 'check_permissions'],
        ]);
    }

    // =========================================================================
    // Core Translation Methods
    // =========================================================================

    /**
     * Translate text using configured provider
     *
     * @param string $text Text to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param string $context Optional context hint
     * @param int    $post_id Optional ID of the post being translated. MUST be
     *                        forwarded whenever the text being translated
     *                        belongs to a specific post — see the $post_id
     *                        docblock on TranslationMemory::suggest() for why
     *                        (869enmrpz).
     * @return array ['success' => bool, 'translation' => string, 'provider' => string, 'error' => string]
     */
    public static function translate($text, $source_lang, $target_lang, $context = '', $post_id = 0) {
        if (empty($text)) {
            return ['success' => false, 'translation' => '', 'provider' => '', 'error' => 'Empty text'];
        }

        if ($source_lang === $target_lang) {
            return ['success' => true, 'translation' => $text, 'provider' => 'passthrough', 'error' => ''];
        }

        // Check translation memory first. $context carries the field being
        // translated (post_title/post_excerpt/post_content/post_name — see
        // translateField() in admin-post-editor.js) and MUST be forwarded so
        // the memory lookup can scope matches to that same field. Without it,
        // an excerpt translation saved for this post can "exact match" a
        // full-content request, because the memory layer otherwise has no way
        // to tell which field a stored translation belongs to (869enmhwe).
        // $post_id MUST also be forwarded so a stored translation belonging to
        // a DIFFERENT post — however similar the two posts' text — can never
        // come back for this one (869enmrpz).
        $memory_suggestions = TranslationMemory::suggest($text, $target_lang, $context, $post_id);
        if (!empty($memory_suggestions) && $memory_suggestions[0]['similarity'] >= 0.95) {
            return [
                'success' => true,
                'translation' => $memory_suggestions[0]['text'],
                'provider' => 'translation_memory',
                'error' => '',
            ];
        }

        $provider = get_option('stm_auto_translate_provider', 'openai');

        switch ($provider) {
            case 'deepl':
                return self::translate_deepl($text, $source_lang, $target_lang);
            case 'openai':
            default:
                return self::translate_openai($text, $source_lang, $target_lang, $context);
        }
    }

    /**
     * Batch translate multiple texts
     *
     * @param array $texts Array of texts to translate
     * @param string $source_lang Source language
     * @param string $target_lang Target language
     * @return array Array of results matching input order
     */
    public static function batch_translate($texts, $source_lang, $target_lang) {
        $results = [];

        foreach ($texts as $key => $text) {
            $result = self::translate($text, $source_lang, $target_lang);
            $results[$key] = $result;

            // Small delay to respect rate limits
            if (count($texts) > 5) {
                usleep(100000); // 100ms
            }
        }

        return $results;
    }

    // =========================================================================
    // OpenAI Provider
    // =========================================================================

    /**
     * Translate using OpenAI API
     */
    private static function translate_openai($text, $source_lang, $target_lang, $context = '') {
        $api_key = get_option('stm_openai_api_key', '');

        if (empty($api_key)) {
            return ['success' => false, 'translation' => '', 'provider' => 'openai', 'error' => 'OpenAI API key not configured. Go to Translations > Settings.'];
        }

        $lang_names = self::get_language_names();
        $source_name = $lang_names[$source_lang] ?? $source_lang;
        $target_name = $lang_names[$target_lang] ?? $target_lang;

        $system_prompt = "You are a professional translator. Translate the following text from {$source_name} to {$target_name}. "
            . "Maintain the original tone, style, and formatting. "
            . "Only return the translated text, nothing else. No explanations or notes.";

        if ($context) {
            $system_prompt .= " Context: this text is a {$context} field in a WordPress website.";
        }

        $allowed_models = ['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'];
        $model          = get_option('stm_openai_model', 'gpt-4o-mini');
        if (!in_array($model, $allowed_models, true)) {
            $model = 'gpt-4o-mini';
        }

        $temperature = (float) get_option('stm_openai_temperature', 0.3);
        $temperature = max(0.0, min(1.0, $temperature));

        $custom_prompt = trim(get_option('stm_openai_prompt_template', ''));
        if ($custom_prompt) {
            $system_prompt = str_replace(['{source}', '{target}'], [$source_name, $target_name], $custom_prompt);
        }

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => $temperature,
            'max_tokens' => max(strlen($text) * 3, 500),
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            Security::log('OpenAI API error: ' . $response->get_error_message(), 'error');
            return ['success' => false, 'translation' => '', 'provider' => 'openai', 'error' => $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error = $body['error']['message'] ?? "HTTP $status_code";
            Security::log('OpenAI API error: ' . $error, 'error');
            return ['success' => false, 'translation' => '', 'provider' => 'openai', 'error' => $error];
        }

        $translation = trim($body['choices'][0]['message']['content'] ?? '');

        return [
            'success' => !empty($translation),
            'translation' => $translation,
            'provider' => 'openai',
            'error' => empty($translation) ? 'Empty response from OpenAI' : '',
        ];
    }

    // =========================================================================
    // DeepL Provider
    // =========================================================================

    /**
     * Translate using DeepL API
     */
    private static function translate_deepl($text, $source_lang, $target_lang) {
        $api_key = get_option('stm_deepl_api_key', '');

        if (empty($api_key)) {
            return ['success' => false, 'translation' => '', 'provider' => 'deepl', 'error' => 'DeepL API key not configured. Go to Translations > Settings.'];
        }

        // DeepL uses uppercase language codes and some variations
        $deepl_lang_map = [
            'en' => 'EN', 'nl' => 'NL', 'de' => 'DE', 'fr' => 'FR',
            'es' => 'ES', 'it' => 'IT', 'pt' => 'PT-BR', 'ja' => 'JA',
            'zh' => 'ZH', 'ru' => 'RU', 'pl' => 'PL', 'ko' => 'KO',
        ];

        $source = $deepl_lang_map[strtolower($source_lang)] ?? strtoupper($source_lang);
        $target = $deepl_lang_map[strtolower($target_lang)] ?? strtoupper($target_lang);

        // Detect free vs pro API key
        $base_url = strpos($api_key, ':fx') !== false
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $response = wp_remote_post($base_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'text' => [$text],
                'source_lang' => $source,
                'target_lang' => $target,
            ]),
        ]);

        if (is_wp_error($response)) {
            Security::log('DeepL API error: ' . $response->get_error_message(), 'error');
            return ['success' => false, 'translation' => '', 'provider' => 'deepl', 'error' => $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error = $body['message'] ?? "HTTP $status_code";
            Security::log('DeepL API error: ' . $error, 'error');
            return ['success' => false, 'translation' => '', 'provider' => 'deepl', 'error' => $error];
        }

        $translation = trim($body['translations'][0]['text'] ?? '');

        return [
            'success' => !empty($translation),
            'translation' => $translation,
            'provider' => 'deepl',
            'error' => empty($translation) ? 'Empty response from DeepL' : '',
        ];
    }

    // =========================================================================
    // Settings
    // =========================================================================

    /**
     * Get configured settings
     */
    public static function get_settings() {
        return [
            'provider'         => get_option('stm_auto_translate_provider', 'openai'),
            'openai_key_set'   => !empty(get_option('stm_openai_api_key', '')),
            'deepl_key_set'    => !empty(get_option('stm_deepl_api_key', '')),
            'openai_model'     => get_option('stm_openai_model', 'gpt-4o-mini'),
            'openai_temperature' => (float) get_option('stm_openai_temperature', 0.3),
            'openai_prompt_template' => get_option('stm_openai_prompt_template', ''),
        ];
    }

    /**
     * Save settings
     */
    public static function save_settings($provider, $openai_key = null, $deepl_key = null, $extra = []) {
        if (in_array($provider, ['openai', 'deepl'])) {
            update_option('stm_auto_translate_provider', $provider);
        }
        if ($openai_key !== null) {
            update_option('stm_openai_api_key', sanitize_text_field($openai_key));
        }
        if ($deepl_key !== null) {
            update_option('stm_deepl_api_key', sanitize_text_field($deepl_key));
        }

        $allowed_models = ['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'];
        if (!empty($extra['openai_model']) && in_array($extra['openai_model'], $allowed_models, true)) {
            update_option('stm_openai_model', $extra['openai_model']);
        }
        if (isset($extra['openai_temperature'])) {
            $temp = max(0.0, min(1.0, (float) $extra['openai_temperature']));
            update_option('stm_openai_temperature', $temp);
        }
        if (isset($extra['openai_prompt_template'])) {
            update_option('stm_openai_prompt_template', sanitize_textarea_field($extra['openai_prompt_template']));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Map language codes to human-readable names
     */
    private static function get_language_names() {
        return [
            'en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French',
            'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'ja' => 'Japanese',
            'zh' => 'Chinese', 'ru' => 'Russian', 'ko' => 'Korean', 'ar' => 'Arabic',
            'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish', 'fi' => 'Finnish',
            'no' => 'Norwegian', 'tr' => 'Turkish', 'cs' => 'Czech', 'ro' => 'Romanian',
        ];
    }

    // =========================================================================
    // REST Handlers
    // =========================================================================

    /**
     * Resolve source/target language codes from REST params.
     *
     * Accepts both the endpoint's documented source_lang/target_lang keys
     * and the more conventional source_language/target_language spelling.
     * A caller that sends the latter against a handler that only reads the
     * former does NOT get an error — the "?? 'en'" / "?? 'nl'" defaults
     * silently take over, translation runs in the wrong (or a no-op)
     * direction, and OpenAI just echoes text that already matches the
     * (wrongly-defaulted) target language back unchanged. That looked
     * exactly like a translation-memory regression during a live re-test
     * of 869enmhwe (provider correctly read 'openai', but "translation"
     * was identical to the Dutch input) and cost a full review cycle to
     * diagnose — it was a param-name mismatch in the test call, not a
     * product bug, because every real in-repo caller (admin-post-editor.js,
     * admin.js) already uses source_lang/target_lang correctly. Accepting
     * both spellings here removes the trap for any future caller.
     *
     * @return array{0:string,1:string} [$source_lang, $target_lang]
     */
    public static function resolve_lang_params($params) {
        $source = $params['source_lang'] ?? $params['source_language'] ?? 'en';
        $target = $params['target_lang'] ?? $params['target_language'] ?? 'nl';
        return [sanitize_text_field($source), sanitize_text_field($target)];
    }

    /**
     * POST /translate/auto - Translate a single text
     *
     * Body: { "text": "Hello", "source_lang": "en", "target_lang": "nl", "context": "title" }
     */
    public static function rest_auto_translate($request) {
        $params = $request->get_json_params();
        $text = $params['text'] ?? '';
        [$source_lang, $target_lang] = self::resolve_lang_params($params);
        $context = sanitize_text_field($params['context'] ?? '');

        // Optional: save result directly to a post. Also forwarded into
        // translate() so the memory lookup is scoped to this post (869enmrpz).
        $post_id = intval($params['post_id'] ?? 0);
        $field = sanitize_text_field($params['field'] ?? '');

        $result = self::translate($text, $source_lang, $target_lang, $context, $post_id);

        // If post_id and field are provided, save the translation
        if ($result['success'] && $post_id > 0 && $field) {
            API::save_post_translations($post_id, [$field => $result['translation']], $target_lang);
        }

        return rest_ensure_response($result);
    }

    /**
     * POST /translate/auto/batch - Translate multiple texts
     *
     * Body: {
     *   "source_lang": "en",
     *   "target_lang": "nl",
     *   "items": [
     *     { "text": "Hello", "post_id": 123, "field": "title" },
     *     { "text": "World", "post_id": 123, "field": "excerpt" }
     *   ]
     * }
     */
    public static function rest_batch_translate($request) {
        $params = $request->get_json_params();
        [$source_lang, $target_lang] = self::resolve_lang_params($params);
        $items = $params['items'] ?? [];

        if (empty($items)) {
            return new \WP_Error('empty_items', 'No items to translate', ['status' => 400]);
        }

        $results = [];
        $saved = 0;

        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            $context = sanitize_text_field($item['context'] ?? '');
            $post_id = intval($item['post_id'] ?? 0);
            $field = sanitize_text_field($item['field'] ?? '');

            // $post_id scopes the memory lookup to this post so a different
            // post's stored translation can never come back here (869enmrpz).
            $result = self::translate($text, $source_lang, $target_lang, $context, $post_id);

            // Auto-save if post_id + field provided
            if ($result['success'] && $post_id > 0 && $field) {
                API::save_post_translations($post_id, [$field => $result['translation']], $target_lang);
                $result['saved'] = true;
                $saved++;
            }

            $results[] = $result;

            // Rate limiting
            usleep(100000); // 100ms between requests
        }

        // Clear the untranslated content warning cache
        delete_transient('stm_untranslated_warning');

        return rest_ensure_response([
            'results' => $results,
            'total' => count($items),
            'successful' => count(array_filter($results, function($r) { return $r['success']; })),
            'saved' => $saved,
        ]);
    }

    /**
     * GET /translate/auto/settings
     */
    public static function rest_get_settings($request) {
        return rest_ensure_response(self::get_settings());
    }

    /**
     * POST /translate/auto/settings
     */
    public static function rest_save_settings($request) {
        $params = $request->get_json_params();

        self::save_settings(
            $params['provider'] ?? null,
            $params['openai_key'] ?? null,
            $params['deepl_key'] ?? null,
            [
                'openai_model'           => $params['openai_model'] ?? null,
                'openai_temperature'     => $params['openai_temperature'] ?? null,
                'openai_prompt_template' => $params['openai_prompt_template'] ?? null,
            ]
        );

        return rest_ensure_response(['success' => true, 'settings' => self::get_settings()]);
    }
}
