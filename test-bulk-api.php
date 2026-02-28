<?php
/**
 * Test script for STM Bulk Translation API
 *
 * Upload to WordPress root and access via browser:
 * http://localhost/test-bulk-api.php
 *
 * This script will:
 * 1. Test save_post_translations() method
 * 2. Verify data is saved correctly
 * 3. Test retrieval via stm_get_post_translation()
 * 4. Clean up after itself
 */

require_once(__DIR__ . '/wp-load.php');

if (!function_exists('stm_get_post_translation')) {
    die("ERROR: STM plugin not active!");
}

echo "<h1>STM Bulk Translation API Test</h1>\n";
echo "<pre>\n";

// Test data
$test_post_id = 2903; // Adjust to existing post ID
$test_lang = 'nl';
$test_translations = [
    'category' => 'TEST Categorie',
    'title' => 'TEST Titel',
    'description' => 'TEST Beschrijving voor bulk API test',
    'test_field' => 'TEST Custom veld'
];

echo "=== Test 1: Bulk Save ===\n\n";

echo "Post ID: $test_post_id\n";
echo "Language: $test_lang\n";
echo "Fields: " . count($test_translations) . "\n\n";

// Test bulk save
$result = STM\API::save_post_translations($test_post_id, $test_translations, $test_lang);

echo "Result:\n";
echo "  Success: {$result['success']}/{$result['total']}\n";

if (count($result['errors']) > 0) {
    echo "  Errors:\n";
    foreach ($result['errors'] as $error) {
        echo "    - $error\n";
    }
} else {
    echo "  ✓ No errors!\n";
}

echo "\n=== Test 2: Retrieval ===\n\n";

// Test retrieval
foreach ($test_translations as $field => $expected_value) {
    $retrieved = stm_get_post_translation($test_post_id, $field, $test_lang);

    $match = ($retrieved === $expected_value) ? '✓' : '✗';

    echo "$match Field '$field':\n";
    echo "    Expected: $expected_value\n";
    echo "    Retrieved: $retrieved\n";

    if ($retrieved !== $expected_value) {
        echo "    ERROR: Mismatch!\n";
    }

    echo "\n";
}

echo "=== Test 3: Database Verification ===\n\n";

global $wpdb;
$table = $wpdb->prefix . 'stm_post_translations';

$count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND language_code = %s",
    $test_post_id,
    $test_lang
));

echo "Database records for post $test_post_id ($test_lang): $count\n";
echo "Expected: " . count($test_translations) . "\n";

if ($count == count($test_translations)) {
    echo "✓ Count matches!\n";
} else {
    echo "✗ Count mismatch!\n";
}

echo "\n=== Test 4: Update Existing ===\n\n";

// Test updating existing translation
$updated_translations = [
    'title' => 'TEST Titel UPDATED',
    'new_field' => 'TEST Nieuw veld'
];

echo "Updating 1 existing field + adding 1 new field\n\n";

$result2 = STM\API::save_post_translations($test_post_id, $updated_translations, $test_lang);

echo "Result:\n";
echo "  Success: {$result2['success']}/{$result2['total']}\n\n";

// Verify update
$retrieved_title = stm_get_post_translation($test_post_id, 'title', $test_lang);
$retrieved_new = stm_get_post_translation($test_post_id, 'new_field', $test_lang);

$match1 = ($retrieved_title === 'TEST Titel UPDATED') ? '✓' : '✗';
$match2 = ($retrieved_new === 'TEST Nieuw veld') ? '✓' : '✗';

echo "$match1 Updated field: $retrieved_title\n";
echo "$match2 New field: $retrieved_new\n";

echo "\n=== Test 5: Error Handling ===\n\n";

// Test with invalid language code (should log error)
echo "Testing with invalid language code...\n";
$result3 = STM\API::save_post_translations($test_post_id, ['title' => 'TEST'], 'invalid_lang_code');

echo "  Success: {$result3['success']}/{$result3['total']}\n";
echo "  Errors: " . count($result3['errors']) . "\n";

if (count($result3['errors']) > 0) {
    echo "  ✓ Error handling works!\n";
    foreach ($result3['errors'] as $error) {
        echo "    - $error\n";
    }
} else {
    echo "  ✗ Should have errors for invalid language code\n";
}

echo "\n=== Cleanup ===\n\n";

// Clean up test data
$deleted = $wpdb->delete($table, [
    'post_id' => $test_post_id,
    'language_code' => $test_lang
]);

echo "Deleted $deleted test records\n";

// Clear cache
STM\Cache::invalidate_post($test_post_id);
echo "Cache cleared\n";

echo "\n=== Test Complete ===\n\n";

echo "All tests finished. Check results above.\n";
echo "Expected: All ✓ marks (except error handling test which should have errors)\n";

echo "</pre>\n";

// Self-delete
@unlink(__FILE__);
echo "<p><em>This test script has been deleted automatically.</em></p>";
?>
