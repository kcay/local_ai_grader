<?php

namespace local_ai_autograder\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * External API for triggering AI grading
 */
class grade_submission extends external_api {
    
    /**
     * Returns description of method parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'submissionid' => new external_value(PARAM_INT, 'Submission ID'),
            'assignmentid' => new external_value(PARAM_INT, 'Assignment ID'),
        ]);
    }
    
    /**
     * Execute AI grading
     */
    public static function execute($submissionid, $assignmentid) {
        global $DB, $CFG;
        
        require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');
        
        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'submissionid' => $submissionid,
            'assignmentid' => $assignmentid
        ]);
        
        // Get records
        $submission = $DB->get_record('assign_submission', ['id' => $params['submissionid']], '*', MUST_EXIST);
        $assignment = $DB->get_record('assign', ['id' => $params['assignmentid']], '*', MUST_EXIST);
        
        // Security check
        $cm = get_coursemodule_from_instance('assign', $assignment->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/ai_autograder:manualgrade', $context);
        
        // Execute grading
        $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
        
        return [
            'success' => $result['success'],
            'grade' => $result['grade'] ?? 0,
            'feedback' => $result['feedback'] ?? '',
            'error' => $result['error'] ?? ''
        ];
    }
    
    /**
     * Returns description of method result value
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'grade' => new external_value(PARAM_FLOAT, 'Grade assigned'),
            'feedback' => new external_value(PARAM_RAW, 'Feedback text'),
            'error' => new external_value(PARAM_TEXT, 'Error message if failed', VALUE_OPTIONAL)
        ]);
    }
}