<?php
// lang/en/local_ai_autograder.php

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Auto-Grader';
$string['ai_autograder'] = 'AI Auto-Grader';

// General
$string['enabled'] = 'Enabled';
$string['disabled'] = 'Disabled';
$string['configure'] = 'Configure';
$string['save'] = 'Save changes';

// Grading Methods
$string['grading_methods'] = 'Grading Methods';
$string['grading_methods_desc'] = 'Select which grading methods are eligible for AI auto-grading';
$string['enabled_methods'] = 'Enabled Grading Methods';
$string['enabled_methods_desc'] = 'Check the grading methods you want to enable for AI auto-grading';
$string['simple_direct'] = 'Simple Direct Grading';
$string['rubric'] = 'Rubric';
$string['ranged_rubric'] = 'Ranged Rubric';

// AI Provider Settings
$string['ai_provider_settings'] = 'AI Provider Configuration';
$string['ai_provider_settings_desc'] = 'Configure AI service providers and their API settings';
$string['default_provider'] = 'Default AI Provider';
$string['default_provider_desc'] = 'Select the default AI provider for grading';
$string['api_key'] = 'API Key';
$string['api_key_desc'] = 'Enter the API key for this AI provider';
$string['endpoint_url'] = 'API Endpoint URL';
$string['endpoint_url_desc'] = 'Custom API endpoint (leave default if unsure)';
$string['model_name'] = 'Model Name';
$string['model_name_desc'] = 'Specify the AI model to use (e.g., gpt-4o, gemini-pro, claude-3-opus)';
$string['max_tokens'] = 'Max Tokens';
$string['max_tokens_desc'] = 'Maximum tokens for AI response';
$string['temperature'] = 'Temperature';
$string['temperature_desc'] = 'AI creativity/randomness (0.0-1.0, lower = more consistent)';

// Leniency Settings
$string['leniency_settings'] = 'Leniency Settings';
$string['leniency_settings_desc'] = 'Configure grading leniency levels';
$string['default_leniency'] = 'Default Leniency Level';
$string['default_leniency_desc'] = 'Select the default leniency level for grading';
$string['very_lenient'] = 'Very Lenient';
$string['lenient'] = 'Lenient';
$string['moderate'] = 'Moderate';
$string['strict'] = 'Strict';
$string['very_strict'] = 'Very Strict';

// Automatic Grading
$string['auto_grading'] = 'Automatic Grading';
$string['auto_grading_desc'] = 'Configure automatic grading behavior';
$string['sitewide_auto_grade'] = 'Enable Sitewide Automatic Grading';
$string['sitewide_auto_grade_desc'] = 'Automatically grade all eligible submissions';
$string['batch_size'] = 'Batch Size';
$string['batch_size_desc'] = 'Maximum number of submissions to grade in one batch';
$string['max_retries'] = 'Maximum Retries';
$string['max_retries_desc'] = 'Number of times to retry failed API requests';

// Privacy Settings
$string['privacy_settings'] = 'Privacy & Security';
$string['privacy_settings_desc'] = 'GDPR and data protection settings';
$string['anonymize_submissions'] = 'Anonymize Submissions';
$string['anonymize_submissions_desc'] = 'Remove student names/IDs before sending to AI';
$string['log_retention_days'] = 'Log Retention (Days)';
$string['log_retention_days_desc'] = 'Number of days to retain grading logs';

// Assignment Settings
$string['assignment_settings'] = 'AI Auto-Grading Settings';
$string['enable_for_assignment'] = 'Enable AI Auto-Grading';
$string['enable_for_assignment_desc'] = 'Enable automatic AI grading for this assignment';
$string['override_provider'] = 'Override AI Provider';
$string['override_provider_desc'] = 'Use a different AI provider for this assignment';
$string['override_leniency'] = 'Override Leniency Level';
$string['override_leniency_desc'] = 'Use a different leniency level for this assignment';
$string['custom_prompt'] = 'Custom AI Prompt';
$string['custom_prompt_desc'] = 'Optional: Provide custom instructions for the AI grader';
$string['reference_document'] = 'Reference Document';
$string['reference_document_desc'] = 'Upload a reference document (answer key, rubric, etc.)';
$string['validation_error'] = 'Either custom prompt or reference document must be provided';

// Grading Interface
$string['regrade_with_ai'] = 'Grade with AI';
$string['grading_in_progress'] = 'AI grading in progress...';
$string['grading_complete'] = 'AI grading complete';
$string['grading_failed'] = 'AI grading failed';
$string['view_logs'] = 'View Grading Logs';
$string['ai_generated_feedback'] = 'AI-Generated Feedback';
$string['graded_by_ai'] = 'Graded by AI ({$a->provider} - {$a->model})';
$string['manual_grading'] = 'Manual Grading';

// Feedback Messages
$string['feedback_intro'] = 'This assignment was automatically graded using AI.';
$string['feedback_criteria'] = 'Criterion: {$a}';
$string['feedback_score'] = 'Score: {$a->score}/{$a->max}';

// Errors & Warnings
$string['error_no_api_key'] = 'No API key configured for {$a}';
$string['error_api_request_failed'] = 'API request failed: {$a}';
$string['error_invalid_response'] = 'Invalid response from AI provider';
$string['error_file_not_found'] = 'Submission file not found';
$string['error_unsupported_filetype'] = 'Unsupported file type: {$a}';
$string['error_no_grading_method'] = 'Assignment grading method not supported';
$string['warning_manual_review'] = 'This grade was generated by AI. Please review for accuracy.';

// Logs
$string['log_table_assignment'] = 'Assignment';
$string['log_table_student'] = 'Student';
$string['log_table_provider'] = 'AI Provider';
$string['log_table_method'] = 'Method';
$string['log_table_score'] = 'Score';
$string['log_table_status'] = 'Status';
$string['log_table_time'] = 'Time';
$string['log_status_success'] = 'Success';
$string['log_status_failed'] = 'Failed';
$string['log_status_pending'] = 'Pending';

// Tasks
$string['task_auto_grade'] = 'Automatic AI Grading';
$string['task_cleanup_logs'] = 'Clean Up Old Grading Logs';

// Capabilities
$string['ai_autograder:configure'] = 'Configure AI auto-grading settings';
$string['ai_autograder:viewlogs'] = 'View AI grading logs';
$string['ai_autograder:manualgrade'] = 'Trigger manual AI grading';
$string['ai_autograder:override'] = 'Override AI-generated grades';

// Privacy
$string['privacy:metadata:local_ai_autograder_logs'] = 'Logs of AI grading operations';
$string['privacy:metadata:local_ai_autograder_logs:userid'] = 'The ID of the user whose work was graded';
$string['privacy:metadata:local_ai_autograder_logs:feedback'] = 'AI-generated feedback';
$string['privacy:metadata:local_ai_autograder_logs:timecreated'] = 'When the grading occurred';
$string['privacy:metadata:ai_provider'] = 'Submission content sent to external AI service for grading';
$string['privacy:metadata:ai_provider:submission_content'] = 'Anonymized submission text';
$string['privacy:metadata:ai_provider:rubric_criteria'] = 'Grading rubric (if applicable)';

// Notifications
$string['notification_grading_complete'] = 'AI grading completed for {$a->count} submissions';
$string['notification_grading_failed'] = 'AI grading failed for {$a->count} submissions';
$string['notification_subject'] = 'AI Auto-Grading Report';

// Course Transcript
$string['course_context'] = 'Course Context';
$string['course_context_desc'] = 'Course transcript in AI grading prompts for better context';
$string['course_transcript'] = 'Course Transcript';
$string['course_transcript_desc'] = 'Upload course transcript, syllabus, or lecture notes to provide context for AI grading';
$string['course_transcript_text'] = 'Course Transcript (Text)';
$string['course_transcript_text_desc'] = 'Paste course transcript text directly (alternative to file upload)';
$string['course_transcript_file'] = 'Course Transcript (File)';
$string['course_transcript_file_desc'] = 'Upload PDF/DOCX of course transcript or syllabus';
$string['use_course_transcript'] = 'Use Course Transcript for Context';
$string['use_course_transcript_desc'] = 'Include course transcript in AI grading prompts for better context';

// Notifications
$string['notification_subject'] = 'Assignment Graded by AI';
$string['notification_grading_complete'] = 'Your assignment has been automatically graded';


$string['search_users'] = 'Search for users...';
$string['error:invaliduser'] = 'Invalid user selected';
$string['grader_identity'] = 'AI Grader Identity';
$string['grader_identity_desc'] = 'Configure which user account is used for AI grading operations';
$string['ai_grader_userid'] = 'AI Grader User ID';
$string['ai_grader_userid_desc'] = 'Enter the user ID of the account to use for AI grading (e.g., your "LMS AI Grader" account). Leave blank to use the current user.';