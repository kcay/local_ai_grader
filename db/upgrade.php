<?php
// ==============================================================================
// FILE: db/upgrade.php
// ==============================================================================
defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_ai_autograder upgrade from the given old version
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ai_autograder_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Moodle v4.1.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2025101501) {
        // Define table local_ai_autograder_config to be created.
        $table = new xmldb_table('local_ai_autograder_config');

        // Adding fields to table local_ai_autograder_config.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ai_provider', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('ai_model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('leniency_level', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('custom_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reference_file', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_ai_autograder_config.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('assignmentid_unique', XMLDB_KEY_UNIQUE, ['assignmentid']);

        // Adding indexes to table local_ai_autograder_config.
        $table->add_index('ai_provider', XMLDB_INDEX_NOTUNIQUE, ['ai_provider']);
        $table->add_index('enabled', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        // Conditionally launch create table for local_ai_autograder_config.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ai_autograder savepoint reached.
        upgrade_plugin_savepoint(true, 2025101501, 'local', 'ai_autograder');
    }

    if ($oldversion < 2025101502) {
        // Define table local_ai_autograder_logs to be created.
        $table = new xmldb_table('local_ai_autograder_logs');

        // Adding fields to table local_ai_autograder_logs.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ai_provider', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ai_model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grading_method', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('raw_score', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('adjusted_score', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('leniency_level', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('ai_response', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('processing_time', XMLDB_TYPE_NUMBER, '10, 3', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_ai_autograder_logs.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table local_ai_autograder_logs.
        $table->add_index('assignmentid', XMLDB_INDEX_NOTUNIQUE, ['assignmentid']);
        $table->add_index('submissionid', XMLDB_INDEX_NOTUNIQUE, ['submissionid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        // Conditionally launch create table for local_ai_autograder_logs.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ai_autograder savepoint reached.
        upgrade_plugin_savepoint(true, 2025101502, 'local', 'ai_autograder');
    }

    // Example future upgrade: Add new field to config table
    if ($oldversion < 2025101503) {
        $table = new xmldb_table('local_ai_autograder_config');
        $field = new xmldb_field('max_attempts', XMLDB_TYPE_INTEGER, '10', null, null, null, '3', 'reference_file');

        // Conditionally launch add field max_attempts.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ai_autograder savepoint reached.
        upgrade_plugin_savepoint(true, 2025101503, 'local', 'ai_autograder');
    }

    // Example future upgrade: Add index for better performance
    if ($oldversion < 2025101504) {
        $table = new xmldb_table('local_ai_autograder_logs');
        $index = new xmldb_index('provider_status', XMLDB_INDEX_NOTUNIQUE, ['ai_provider', 'status']);

        // Conditionally launch add index provider_status.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Ai_autograder savepoint reached.
        upgrade_plugin_savepoint(true, 2025101504, 'local', 'ai_autograder');
    }

    if ($oldversion < 2025101505) {
        $table = new xmldb_table('local_ai_autograder_config');
        
        // Add course_transcript field
        $field = new xmldb_field('course_transcript', XMLDB_TYPE_TEXT, null, null, null, null, null, 'reference_file');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Add transcript_file field
        $field = new xmldb_field('transcript_file', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'course_transcript');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2025101505, 'local', 'ai_autograder');
    }

    return true;
}