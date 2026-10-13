# Install, update and runtime test

- Scan `20261013T181732Z-64d014` status `complete`: Every scenario ran to the end.
- ZIP `simple-translation-manager-1.3.2.zip` sha256 `0856487161318e89a63c6152410861d18abd0f47b2cf4f0897fd68d2b89846f3`, plugin version 1.3.2
- Updated from `simple-translation-manager-1.3.0.zip` (version 1.3.0, sha256 `95d5bd6a3e39b4abb0a706ddb30f60794a9c285a41b33f27a7d188f600ecc06a`)
- Updated from `simple-translation-manager-1.3.1.zip` (version 1.3.1, sha256 `bc4e231c2325ced14e054a2ae031888b9b1ef0a7db31c097b2ff7d39ed0893fd`)
- Environment: WordPress 7.1.3, PHP 8.5.6, C:/Program Files/MariaDB 12.3/bin/mariadbd.exe  Ver 12.3.2-MariaDB for Win64 on AMD64 (MariaDB Server)
- Result: **all 280 checks passed**

## PASS - install old 1.3.0
- ok: install old 1.3.0: wp plugin install exits 0
- ok: install old 1.3.0: no PHP error while installing
- ok: install old 1.3.0: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - seed (update-from-1.3.0)
- ok: seed (update-from-1.3.0): plugin is active before the update
- ok: seed (update-from-1.3.0): synthetic strings and translations were stored
- ok: seed (update-from-1.3.0): synthetic post translation was stored
- ok: seed (update-from-1.3.0): old release version recorded
- ok: seed (update-from-1.3.0): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - update to 1.3.2
- ok: update to 1.3.2: wp plugin install exits 0
- ok: update to 1.3.2: no PHP error while installing
- ok: update to 1.3.2: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - after-update (update-from-1.3.0)
- ok: after-update (update-from-1.3.0): the seed snapshot exists
- ok: after-update (update-from-1.3.0): plugin is still active after the update
- ok: after-update (update-from-1.3.0): every one of the seven tables exists after the update
- ok: after-update (update-from-1.3.0): table languages: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.0): table strings: all 2 rows survived unchanged
- ok: after-update (update-from-1.3.0): table translations: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.0): table post_translations: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.0): table post_associations: all 0 rows survived unchanged
- ok: after-update (update-from-1.3.0): table term_translations: all 1 rows survived unchanged
- ok: after-update (update-from-1.3.0): table field_value_translations: all 1 rows survived unchanged
- ok: after-update (update-from-1.3.0): option stm_default_language kept its value
- ok: after-update (update-from-1.3.0): option stm_enable_url_routing kept its value
- ok: after-update (update-from-1.3.0): option stm_cache_duration kept its value
- ok: after-update (update-from-1.3.0): option stm_openai_model kept its value
- ok: after-update (update-from-1.3.0): option stm_switcher_position kept its value
- ok: after-update (update-from-1.3.0): database version option matches the new plugin version
- ok: after-update (update-from-1.3.0): a translation stored before the update is served by the new code
- ok: after-update (update-from-1.3.0): the helper function __stm() exists for themes
- ok: after-update (update-from-1.3.0): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - prepare-remove (update-from-1.3.0)
- ok: prepare-remove (update-from-1.3.0): keep-data option is off and synthetic key and preference are set
- ok: prepare-remove (update-from-1.3.0): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - uninstall after update scenario
- ok: uninstall after update scenario: deactivate and uninstall exit 0
- ok: uninstall after update scenario: plugin folder is gone
- ok: uninstall after update scenario: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - verify-removed (update-from-1.3.0)
- ok: verify-removed (update-from-1.3.0): plugin files are gone
- ok: verify-removed (update-from-1.3.0): uninstall removed table languages
- ok: verify-removed (update-from-1.3.0): uninstall removed table strings
- ok: verify-removed (update-from-1.3.0): uninstall removed table translations
- ok: verify-removed (update-from-1.3.0): uninstall removed table post_translations
- ok: verify-removed (update-from-1.3.0): uninstall removed table post_associations
- ok: verify-removed (update-from-1.3.0): uninstall removed table term_translations
- ok: verify-removed (update-from-1.3.0): uninstall removed table field_value_translations
- ok: verify-removed (update-from-1.3.0): uninstall removed every plugin option, API keys included
- ok: verify-removed (update-from-1.3.0): uninstall removed the per-user admin preference
- ok: verify-removed (update-from-1.3.0): no option or transient with the stm_ prefix is left in the options table
- ok: verify-removed (update-from-1.3.0): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - install old 1.3.1
- ok: install old 1.3.1: wp plugin install exits 0
- ok: install old 1.3.1: no PHP error while installing
- ok: install old 1.3.1: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - seed (update-from-1.3.1)
- ok: seed (update-from-1.3.1): plugin is active before the update
- ok: seed (update-from-1.3.1): synthetic strings and translations were stored
- ok: seed (update-from-1.3.1): synthetic post translation was stored
- ok: seed (update-from-1.3.1): old release version recorded
- ok: seed (update-from-1.3.1): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - update to 1.3.2
- ok: update to 1.3.2: wp plugin install exits 0
- ok: update to 1.3.2: no PHP error while installing
- ok: update to 1.3.2: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - after-update (update-from-1.3.1)
- ok: after-update (update-from-1.3.1): the seed snapshot exists
- ok: after-update (update-from-1.3.1): plugin is still active after the update
- ok: after-update (update-from-1.3.1): every one of the seven tables exists after the update
- ok: after-update (update-from-1.3.1): table languages: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.1): table strings: all 2 rows survived unchanged
- ok: after-update (update-from-1.3.1): table translations: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.1): table post_translations: all 3 rows survived unchanged
- ok: after-update (update-from-1.3.1): table post_associations: all 0 rows survived unchanged
- ok: after-update (update-from-1.3.1): table term_translations: all 1 rows survived unchanged
- ok: after-update (update-from-1.3.1): table field_value_translations: all 1 rows survived unchanged
- ok: after-update (update-from-1.3.1): option stm_default_language kept its value
- ok: after-update (update-from-1.3.1): option stm_enable_url_routing kept its value
- ok: after-update (update-from-1.3.1): option stm_cache_duration kept its value
- ok: after-update (update-from-1.3.1): option stm_openai_model kept its value
- ok: after-update (update-from-1.3.1): option stm_switcher_position kept its value
- ok: after-update (update-from-1.3.1): database version option matches the new plugin version
- ok: after-update (update-from-1.3.1): a translation stored before the update is served by the new code
- ok: after-update (update-from-1.3.1): the helper function __stm() exists for themes
- ok: after-update (update-from-1.3.1): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - prepare-remove (update-from-1.3.1)
- ok: prepare-remove (update-from-1.3.1): keep-data option is off and synthetic key and preference are set
- ok: prepare-remove (update-from-1.3.1): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - uninstall after update scenario
- ok: uninstall after update scenario: deactivate and uninstall exit 0
- ok: uninstall after update scenario: plugin folder is gone
- ok: uninstall after update scenario: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - verify-removed (update-from-1.3.1)
- ok: verify-removed (update-from-1.3.1): plugin files are gone
- ok: verify-removed (update-from-1.3.1): uninstall removed table languages
- ok: verify-removed (update-from-1.3.1): uninstall removed table strings
- ok: verify-removed (update-from-1.3.1): uninstall removed table translations
- ok: verify-removed (update-from-1.3.1): uninstall removed table post_translations
- ok: verify-removed (update-from-1.3.1): uninstall removed table post_associations
- ok: verify-removed (update-from-1.3.1): uninstall removed table term_translations
- ok: verify-removed (update-from-1.3.1): uninstall removed table field_value_translations
- ok: verify-removed (update-from-1.3.1): uninstall removed every plugin option, API keys included
- ok: verify-removed (update-from-1.3.1): uninstall removed the per-user admin preference
- ok: verify-removed (update-from-1.3.1): no option or transient with the stm_ prefix is left in the options table
- ok: verify-removed (update-from-1.3.1): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - clean install 1.3.2
- ok: clean install 1.3.2: wp plugin install exits 0
- ok: clean install 1.3.2: no PHP error while installing
- ok: clean install 1.3.2: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - fresh-install (clean-install)
- ok: fresh-install (clean-install): plugin is active
- ok: fresh-install (clean-install): all seven tables were created on activation
- ok: fresh-install (clean-install): English and Dutch were seeded as the default languages
- ok: fresh-install (clean-install): exactly one default language
- ok: fresh-install (clean-install): database version option is set
- ok: fresh-install (clean-install): rewrite rules were flushed with language prefixes
- ok: fresh-install (clean-install): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - functional (clean-install)
- ok: functional (clean-install): synthetic subscriber and editor exist
- ok: functional (clean-install): the plugin registers REST routes (at least 20 method/route pairs)
- ok: functional (clean-install): anonymous visitors can call exactly the documented public read routes
- ok: functional (clean-install): a subscriber has no more REST rights than an anonymous visitor
- ok: functional (clean-install): an editor may call the public routes plus only the routes that follow edit_post, nothing administrator-only
- ok: functional (clean-install): the edit_post routes are exactly the post-scoped ones (delete translation, Elementor panel)
- ok: functional (clean-install): an administrator may call every route
- ok: functional (clean-install): subscriber, editor and administrator can log in over HTTP
- ok: functional (clean-install): a REST nonce is issued to each logged-in role
- ok: functional (clean-install): anonymous GET /languages answers 200 with a list of languages
- ok: functional (clean-install): anonymous POST /stm/v1/languages is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/languages is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/languages is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/strings is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/strings is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/strings is refused (403)
- ok: functional (clean-install): anonymous DELETE /stm/v1/strings/1 is refused (401/403)
- ok: functional (clean-install): subscriber DELETE /stm/v1/strings/1 is refused (403)
- ok: functional (clean-install): editor DELETE /stm/v1/strings/1 is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/translations is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/translations is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/translations is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/import is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/import is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/import is refused (403)
- ok: functional (clean-install): anonymous GET /stm/v1/export?lang=nl is refused (401/403)
- ok: functional (clean-install): subscriber GET /stm/v1/export?lang=nl is refused (403)
- ok: functional (clean-install): editor GET /stm/v1/export?lang=nl is refused (403)
- ok: functional (clean-install): anonymous GET /stm/v1/export/po?lang=nl is refused (401/403)
- ok: functional (clean-install): subscriber GET /stm/v1/export/po?lang=nl is refused (403)
- ok: functional (clean-install): editor GET /stm/v1/export/po?lang=nl is refused (403)
- ok: functional (clean-install): anonymous GET /stm/v1/export/xliff?source=en&target=nl is refused (401/403)
- ok: functional (clean-install): subscriber GET /stm/v1/export/xliff?source=en&target=nl is refused (403)
- ok: functional (clean-install): editor GET /stm/v1/export/xliff?source=en&target=nl is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/cache/flush is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/cache/flush is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/cache/flush is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/translations/bulk is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/translations/bulk is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/translations/bulk is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/posts/8/translations is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/posts/8/translations is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/posts/8/translations is refused (403)
- ok: functional (clean-install): anonymous POST /stm/v1/posts/8/slugs is refused (401/403)
- ok: functional (clean-install): subscriber POST /stm/v1/posts/8/slugs is refused (403)
- ok: functional (clean-install): editor POST /stm/v1/posts/8/slugs is refused (403)
- ok: functional (clean-install): the refused anonymous, subscriber and editor writes changed nothing
- ok: functional (clean-install): a valid administrator nonce without the login cookie does not authenticate
- ok: functional (clean-install): anonymous: translations of a published post are readable
- ok: functional (clean-install): anonymous: draft post /translations does not reveal its translations
- ok: functional (clean-install): subscriber: draft post /translations does not reveal its translations
- ok: functional (clean-install): anonymous: draft post /slugs does not reveal its translations
- ok: functional (clean-install): subscriber: draft post /slugs does not reveal its translations
- ok: functional (clean-install): editor: may read the translations of a draft post they can edit
- ok: functional (clean-install): anonymous: private post /translations does not reveal its translations
- ok: functional (clean-install): subscriber: private post /translations does not reveal its translations
- ok: functional (clean-install): anonymous: private post /slugs does not reveal its translations
- ok: functional (clean-install): subscriber: private post /slugs does not reveal its translations
- ok: functional (clean-install): editor: may read the translations of a private post they can edit
- ok: functional (clean-install): anonymous: password post /translations does not reveal its translations
- ok: functional (clean-install): subscriber: password post /translations does not reveal its translations
- ok: functional (clean-install): anonymous: password post /slugs does not reveal its translations
- ok: functional (clean-install): subscriber: password post /slugs does not reveal its translations
- ok: functional (clean-install): editor: may read the translations of a password post they can edit
- ok: functional (clean-install): anonymous: a draft translation is not listed on /strings or /translations
- ok: functional (clean-install): administrator: the draft translation is listed on /strings
- ok: functional (clean-install): hostile input on GET /strings?context=x%27%20OR%20%271%27%3D%271: no server error, no hash or SQL error in the body
- ok: functional (clean-install): hostile input on GET /translations?lang=nl%27%20UNION%20SELECT%20user_pass%20FROM%20wp_users--%20-: no server error, no hash or SQL error in the body
- ok: functional (clean-install): hostile input on GET /strings/1%20OR%201%3D1: no server error, no hash or SQL error in the body
- ok: functional (clean-install): hostile input on GET /posts/1%3BDROP%20TABLE%20x/translations: no server error, no hash or SQL error in the body
- ok: functional (clean-install): hostile input on GET /translations?lang[]=a&lang[]=b: no server error, no hash or SQL error in the body
- ok: functional (clean-install): hostile input on GET /strings?context=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA: no server error, no hash or SQL error in the body
- ok: functional (clean-install): frontend GET /: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): frontend GET /lifecycle-synthetic-post/: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): frontend GET /lifecycle-synthetic-post/?lang=nl: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): frontend GET /nl/lifecycle-synthetic-post/: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): frontend GET /?lang=zz: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): frontend GET /wp-sitemap.xml: 200, expected text present, no PHP error in the page
- ok: functional (clean-install): one stm_lang Set-Cookie header per response (no duplicate cookie)
- ok: functional (clean-install): hreflang alternates are advertised for the translated post
- ok: functional (clean-install): the site REST index lists the stm/v1 namespace
- ok: functional (clean-install): the administrator menu lists all seven STM screens
- ok: functional (clean-install): administrator opens stm-dashboard: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-dashboard
- ok: functional (clean-install): editor cannot open stm-dashboard
- ok: functional (clean-install): administrator opens stm-documentation: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-documentation
- ok: functional (clean-install): editor cannot open stm-documentation
- ok: functional (clean-install): administrator opens stm-field-values: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-field-values
- ok: functional (clean-install): editor cannot open stm-field-values
- ok: functional (clean-install): administrator opens stm-import-export: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-import-export
- ok: functional (clean-install): editor cannot open stm-import-export
- ok: functional (clean-install): administrator opens stm-languages: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-languages
- ok: functional (clean-install): editor cannot open stm-languages
- ok: functional (clean-install): administrator opens stm-settings: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-settings
- ok: functional (clean-install): editor cannot open stm-settings
- ok: functional (clean-install): administrator opens stm-translations: 200, a screen is rendered, no PHP error
- ok: functional (clean-install): subscriber cannot open stm-translations
- ok: functional (clean-install): editor cannot open stm-translations
- ok: functional (clean-install): the post edit screen loads with the plugin active
- ok: functional (clean-install): the import form carries a nonce
- ok: functional (clean-install): anonymous POST to the import form handler imports nothing
- ok: functional (clean-install): administrator POST without a nonce is refused and imports nothing
- ok: functional (clean-install): subscriber POST with the administrator's nonce is refused and imports nothing
- ok: functional (clean-install): administrator POST with the nonce imports the file
- ok: functional (clean-install): anonymous cannot download an export through admin-post.php?action=stm_export_xliff
- ok: functional (clean-install): administrator without nonce cannot download an export through admin-post.php?action=stm_export_xliff
- ok: functional (clean-install): anonymous cannot download an export through admin-post.php?action=stm_export_po
- ok: functional (clean-install): administrator without nonce cannot download an export through admin-post.php?action=stm_export_po
- ok: functional (clean-install): JSON export contains the seeded Dutch translation
- ok: functional (clean-install): JSON import with dry_run writes nothing
- ok: functional (clean-install): JSON import creates a new translation
- ok: functional (clean-install): JSON import of an unrecognised format is a clean 400
- ok: functional (clean-install): PO export contains the seeded translation
- ok: functional (clean-install): PO import updates the translation
- ok: functional (clean-install): XLIFF export is well-formed XML and contains the seeded key
- ok: functional (clean-install): XLIFF import updates the translation
- ok: functional (clean-install): XLIFF import does not resolve external entities (no local file content stored)
- ok: functional (clean-install): an upload with an unsupported extension is refused with 400
- ok: functional (clean-install): a malformed XLIFF file does not crash the site (no 5xx)
- ok: functional (clean-install): anonymous cannot upload an import file
- ok: functional (clean-install): subscriber cannot upload an import file
- ok: functional (clean-install): administrator can add a language
- ok: functional (clean-install): an invalid language code is a clean 400
- ok: functional (clean-install): administrator can delete the added language
- ok: functional (clean-install): administrator can create a string
- ok: functional (clean-install): a string key with markup is either refused or stored without the markup
- ok: functional (clean-install): anonymous DELETE of a post translation is refused
- ok: functional (clean-install): subscriber DELETE of a post translation is refused
- ok: functional (clean-install): the refused deletes removed nothing
- ok: functional (clean-install): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - prepare-keep (keep-data)
- ok: prepare-keep (keep-data): keep-data option is on
- ok: prepare-keep (keep-data): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - uninstall, keep data on
- ok: uninstall, keep data on: deactivate and uninstall exit 0
- ok: uninstall, keep data on: plugin folder is gone
- ok: uninstall, keep data on: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - verify-kept (keep-data)
- ok: verify-kept (keep-data): the snapshot taken before the uninstall is still there
- ok: verify-kept (keep-data): plugin files are gone after the uninstall
- ok: verify-kept (keep-data): keep data: table languages still holds its 3 rows
- ok: verify-kept (keep-data): keep data: table strings still holds its 4 rows
- ok: verify-kept (keep-data): keep data: table translations still holds its 5 rows
- ok: verify-kept (keep-data): keep data: table post_translations still holds its 9 rows
- ok: verify-kept (keep-data): keep data: table post_associations still holds its 0 rows
- ok: verify-kept (keep-data): keep data: table term_translations still holds its 2 rows
- ok: verify-kept (keep-data): keep data: table field_value_translations still holds its 1 rows
- ok: verify-kept (keep-data): keep data: the keep option is still set
- ok: verify-kept (keep-data): keep data: settings were not deleted
- ok: verify-kept (keep-data): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - reinstall over kept data
- ok: reinstall over kept data: wp plugin install exits 0
- ok: reinstall over kept data: no PHP error while installing
- ok: reinstall over kept data: debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - verify-reinstall (keep-data)
- ok: verify-reinstall (keep-data): plugin is active after the reinstall
- ok: verify-reinstall (keep-data): translations survived uninstall and reinstall
- ok: verify-reinstall (keep-data): a kept translation is served again
- ok: verify-reinstall (keep-data): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - prepare-remove (remove-data)
- ok: prepare-remove (remove-data): keep-data option is off and synthetic key and preference are set
- ok: prepare-remove (remove-data): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - uninstall, keep data off, no user (WP-CLI)
- ok: uninstall, keep data off, no user (WP-CLI): deactivate and uninstall exit 0
- ok: uninstall, keep data off, no user (WP-CLI): plugin folder is gone
- ok: uninstall, keep data off, no user (WP-CLI): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin

## PASS - verify-removed (remove-data)
- ok: verify-removed (remove-data): plugin files are gone
- ok: verify-removed (remove-data): uninstall removed table languages
- ok: verify-removed (remove-data): uninstall removed table strings
- ok: verify-removed (remove-data): uninstall removed table translations
- ok: verify-removed (remove-data): uninstall removed table post_translations
- ok: verify-removed (remove-data): uninstall removed table post_associations
- ok: verify-removed (remove-data): uninstall removed table term_translations
- ok: verify-removed (remove-data): uninstall removed table field_value_translations
- ok: verify-removed (remove-data): uninstall removed every plugin option, API keys included
- ok: verify-removed (remove-data): uninstall removed the per-user admin preference
- ok: verify-removed (remove-data): no option or transient with the stm_ prefix is left in the options table
- ok: verify-removed (remove-data): debug.log has no PHP notice, warning, fatal error or [STM-E-] line from the plugin
