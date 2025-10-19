<?php
// ==============================================================================
// FILE: settings.php
// ==============================================================================
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ai_autograder', 
        get_string('pluginname', 'local_ai_autograder'));

    // === Section 1: Enabled Grading Methods ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/grading_methods_heading',
        get_string('grading_methods', 'local_ai_autograder'),
        get_string('grading_methods_desc', 'local_ai_autograder')
    ));

    $grading_options = [
        'simple' => get_string('simple_direct', 'local_ai_autograder'),
        'rubric' => get_string('rubric', 'local_ai_autograder'),
        'ranged_rubric' => get_string('ranged_rubric', 'local_ai_autograder')
    ];

    $settings->add(new admin_setting_configmulticheckbox(
        'local_ai_autograder/enabled_methods',
        get_string('enabled_methods', 'local_ai_autograder'),
        get_string('enabled_methods_desc', 'local_ai_autograder'),
        ['simple' => 1, 'rubric' => 1],
        $grading_options
    ));

    // === Section 2: AI Provider Configuration ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/ai_provider_heading',
        get_string('ai_provider_settings', 'local_ai_autograder'),
        get_string('ai_provider_settings_desc', 'local_ai_autograder')
    ));

    $provider_options = [
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'claude' => 'Anthropic Claude'
    ];

    $settings->add(new admin_setting_configselect(
        'local_ai_autograder/default_provider',
        get_string('default_provider', 'local_ai_autograder'),
        get_string('default_provider_desc', 'local_ai_autograder'),
        'openai',
        $provider_options
    ));

    // OpenAI Settings
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/openai_heading',
        'OpenAI Configuration',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/openai_api_key',
        get_string('api_key', 'local_ai_autograder') . ' (OpenAI)',
        get_string('api_key_desc', 'local_ai_autograder'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/openai_model',
        get_string('model_name', 'local_ai_autograder') . ' (OpenAI)',
        get_string('model_name_desc', 'local_ai_autograder'),
        'gpt-4o',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/openai_endpoint',
        get_string('endpoint_url', 'local_ai_autograder') . ' (OpenAI)',
        get_string('endpoint_url_desc', 'local_ai_autograder'),
        'https://api.openai.com/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/openai_max_tokens',
        get_string('max_tokens', 'local_ai_autograder') . ' (OpenAI)',
        get_string('max_tokens_desc', 'local_ai_autograder'),
        '2000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/openai_temperature',
        get_string('temperature', 'local_ai_autograder') . ' (OpenAI)',
        get_string('temperature_desc', 'local_ai_autograder'),
        '0.3',
        PARAM_FLOAT
    ));

    // Gemini Settings
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/gemini_heading',
        'Google Gemini Configuration',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/gemini_api_key',
        get_string('api_key', 'local_ai_autograder') . ' (Gemini)',
        get_string('api_key_desc', 'local_ai_autograder'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/gemini_model',
        get_string('model_name', 'local_ai_autograder') . ' (Gemini)',
        get_string('model_name_desc', 'local_ai_autograder'),
        'gemini-2.0-flash-exp',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/gemini_endpoint',
        get_string('endpoint_url', 'local_ai_autograder') . ' (Gemini)',
        get_string('endpoint_url_desc', 'local_ai_autograder'),
        'https://generativelanguage.googleapis.com/v1beta/models/',
        PARAM_URL
    ));

    // Claude Settings
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/claude_heading',
        'Anthropic Claude Configuration',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/claude_api_key',
        get_string('api_key', 'local_ai_autograder') . ' (Claude)',
        get_string('api_key_desc', 'local_ai_autograder'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/claude_model',
        get_string('model_name', 'local_ai_autograder') . ' (Claude)',
        get_string('model_name_desc', 'local_ai_autograder'),
        'claude-sonnet-4-5-20250929',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/claude_endpoint',
        get_string('endpoint_url', 'local_ai_autograder') . ' (Claude)',
        get_string('endpoint_url_desc', 'local_ai_autograder'),
        'https://api.anthropic.com/v1/messages',
        PARAM_URL
    ));

    // === Section 3: Leniency Level ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/leniency_heading',
        get_string('leniency_settings', 'local_ai_autograder'),
        get_string('leniency_settings_desc', 'local_ai_autograder')
    ));

    $leniency_options = [
        'very_lenient' => get_string('very_lenient', 'local_ai_autograder') . ' (Dove, +10%)',
        'lenient' => get_string('lenient', 'local_ai_autograder') . ' (+5%)',
        'moderate' => get_string('moderate', 'local_ai_autograder') . ' (0%)',
        'strict' => get_string('strict', 'local_ai_autograder') . ' (-5%)',
        'very_strict' => get_string('very_strict', 'local_ai_autograder') . ' (Hawk, -10%)'
    ];

    $settings->add(new admin_setting_configselect(
        'local_ai_autograder/default_leniency',
        get_string('default_leniency', 'local_ai_autograder'),
        get_string('default_leniency_desc', 'local_ai_autograder'),
        'moderate',
        $leniency_options
    ));

    // === Section 4: Automatic Grading ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/auto_grading_heading',
        get_string('auto_grading', 'local_ai_autograder'),
        get_string('auto_grading_desc', 'local_ai_autograder')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_autograder/sitewide_auto_grade',
        get_string('sitewide_auto_grade', 'local_ai_autograder'),
        get_string('sitewide_auto_grade_desc', 'local_ai_autograder'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/batch_size',
        get_string('batch_size', 'local_ai_autograder'),
        get_string('batch_size_desc', 'local_ai_autograder'),
        '50',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/max_retries',
        get_string('max_retries', 'local_ai_autograder'),
        get_string('max_retries_desc', 'local_ai_autograder'),
        '3',
        PARAM_INT
    ));

    // === Section 5: Privacy & Security ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/privacy_heading',
        get_string('privacy_settings', 'local_ai_autograder'),
        get_string('privacy_settings_desc', 'local_ai_autograder')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_autograder/anonymize_submissions',
        get_string('anonymize_submissions', 'local_ai_autograder'),
        get_string('anonymize_submissions_desc', 'local_ai_autograder'),
        '1'
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_autograder/log_retention_days',
        get_string('log_retention_days', 'local_ai_autograder'),
        get_string('log_retention_days_desc', 'local_ai_autograder'),
        '90',
        PARAM_INT
    ));

    // === Section 6: AI Grader Identity ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/grader_identity_heading',
        get_string('grader_identity', 'local_ai_autograder'),
        get_string('grader_identity_desc', 'local_ai_autograder')
    ));

    $settings->add(new \local_ai_autograder\admin_setting_configuser_autocomplete(
        'local_ai_autograder/ai_grader_userid',
        get_string('ai_grader_userid', 'local_ai_autograder'),
        get_string('ai_grader_userid_desc', 'local_ai_autograder'),
        '0',
        PARAM_INT
    ));

    // === Section: Course Context ===
    $settings->add(new admin_setting_heading(
        'local_ai_autograder/course_context_heading',
        get_string('course_context', 'local_ai_autograder'),
        get_string('course_context_desc', 'local_ai_autograder')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_autograder/use_course_transcript',
        get_string('use_course_transcript', 'local_ai_autograder'),
        get_string('use_course_transcript_desc', 'local_ai_autograder'),
        '1'
    ));

    $ADMIN->add('localplugins', $settings);
}