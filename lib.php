<?php
// ==============================================================================
// FILE: lib.php
// ==============================================================================
defined('MOODLE_INTERNAL') || die();

/**
 * Get AI autograder configuration for an assignment
 * 
 * @param int $assignmentid
 * @return object|false Configuration object or false if not found
 */
function local_ai_autograder_get_config($assignmentid) {
    global $DB;
    return $DB->get_record('local_ai_autograder_config', ['assignmentid' => $assignmentid]);
}

/**
 * Save AI autograder configuration for an assignment
 * 
 * @param object $config Configuration data
 * @return int|bool Record ID or false on failure
 */
function local_ai_autograder_save_config($config) {
    global $DB;
    
    $config->timemodified = time();
    
    if (isset($config->id) && $config->id > 0) {
        return $DB->update_record('local_ai_autograder_config', $config);
    } else {
        $config->timecreated = time();
        return $DB->insert_record('local_ai_autograder_config', $config);
    }
}


/**
 * Get course transcript for an assignment
 * 
 * @param int $assignmentid
 * @return string Transcript content
 */
function local_ai_autograder_get_transcript($assignmentid) {
    global $DB;
    
    $config = local_ai_autograder_get_config($assignmentid);
    
    if (!$config) {
        return '';
    }
    
    // Return text transcript if available
    if (!empty($config->course_transcript)) {
        return $config->course_transcript;
    }
    
    // Otherwise try to get file transcript
    if (!empty($config->transcript_file)) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($config->transcript_file);
        
        if ($file) {
            return $file->get_content();
        }
    }
    
    return '';
}


/**
 * Check if an assignment has AI auto-grading enabled
 * 
 * @param int $assignmentid
 * @return bool
 */
function local_ai_autograder_is_enabled($assignmentid) {
    $config = local_ai_autograder_get_config($assignmentid);
    
    if (!$config || !$config->enabled) {
        // Check sitewide setting
        return get_config('local_ai_autograder', 'sitewide_auto_grade');
    }
    
    return (bool)$config->enabled;
}

/**
 * Get AI provider for an assignment
 * 
 * @param int $assignmentid
 * @return string Provider name (openai, gemini, claude)
 */
function local_ai_autograder_get_provider($assignmentid) {
    $config = local_ai_autograder_get_config($assignmentid);
    
    if ($config && !empty($config->ai_provider)) {
        return $config->ai_provider;
    }
    
    return get_config('local_ai_autograder', 'default_provider') ?: 'openai';
}

/**
 * Get leniency level for an assignment
 * 
 * @param int $assignmentid
 * @return string Leniency level
 */
function local_ai_autograder_get_leniency($assignmentid) {
    $config = local_ai_autograder_get_config($assignmentid);
    
    if ($config && !empty($config->leniency_level)) {
        return $config->leniency_level;
    }
    
    return get_config('local_ai_autograder', 'default_leniency') ?: 'moderate';
}

/**
 * Log AI grading operation
 * 
 * @param array $data Log data
 * @return int Record ID
 */
function local_ai_autograder_log($data) {
    global $DB;
    
    $log = new stdClass();
    $log->assignmentid = $data['assignmentid'];
    $log->submissionid = $data['submissionid'];
    $log->userid = $data['userid'];
    $log->ai_provider = $data['ai_provider'];
    $log->ai_model = $data['ai_model'];
    $log->grading_method = $data['grading_method'];
    $log->raw_score = $data['raw_score'] ?? null;
    $log->adjusted_score = $data['adjusted_score'] ?? null;
    $log->leniency_level = $data['leniency_level'] ?? null;
    $log->feedback = $data['feedback'] ?? null;
    $log->ai_response = $data['ai_response'] ?? null;
    $log->status = $data['status'];
    $log->error_message = $data['error_message'] ?? null;
    $log->processing_time = $data['processing_time'] ?? null;
    $log->timecreated = time();
    
    return $DB->insert_record('local_ai_autograder_logs', $log);
}

/**
 * Clean up old logs based on retention policy
 */
function local_ai_autograder_cleanup_logs() {
    global $DB;
    
    $retention_days = get_config('local_ai_autograder', 'log_retention_days') ?: 90;
    $cutoff_time = time() - ($retention_days * 24 * 60 * 60);
    
    $DB->delete_records_select('local_ai_autograder_logs', 'timecreated < ?', [$cutoff_time]);
}

/**
 * Get supported file types for submissions
 * 
 * @return array
 */
function local_ai_autograder_get_supported_filetypes() {
    return ['.pdf', '.docx', '.doc', '.txt', '.rtf', '.odt'];
}

/**
 * Check if assignment uses supported grading method
 * 
 * @param object $assignment Assignment record
 * @return bool
 */
function local_ai_autograder_is_grading_method_supported($assignment) {
    global $DB;
    
    $enabled_methods = get_config('local_ai_autograder', 'enabled_methods');
    if (empty($enabled_methods)) {
        return false;
    }
    
    // Check if assignment uses advanced grading
    $grading_area = $DB->get_record('grading_areas', [
        'contextid' => $assignment->contextid ?? 0,
        'component' => 'mod_assign',
        'areaname' => 'submissions'
    ]);
    
    if (!$grading_area) {
        // Simple direct grading
        return isset($enabled_methods['simple']);
    }
    
    $grading_def = $DB->get_record('grading_definitions', [
        'areaid' => $grading_area->id
    ]);
    
    if (!$grading_def) {
        return isset($enabled_methods['simple']);
    }
    
    // Check grading method
    if ($grading_def->method == 'rubric') {
        return isset($enabled_methods['rubric']) || isset($enabled_methods['ranged_rubric']);
    }
    
    return false;
}