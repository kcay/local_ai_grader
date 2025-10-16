<?php

// ==============================================================================
// FILE: classes/observers.php
// ==============================================================================
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class observers {
    
    /**
     * Observer for submission created event
     * 
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function submission_created(\mod_assign\event\assessable_submitted $event) {
        global $DB;
        
        $submission = $event->get_record_snapshot('assign_submission', $event->objectid);
        $assignment = $DB->get_record('assign', ['id' => $submission->assignment]);
        
        if (!$assignment) {
            return;
        }
        
        // Check if AI auto-grading is enabled
        if (!\local_ai_autograder_is_enabled($assignment->id)) {
            return;
        }
        
        // Check if grading method is supported
        if (!\local_ai_autograder_is_grading_method_supported($assignment)) {
            return;
        }
        
        // Check if already graded
        $existing_grade = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber
        ]);
        
        if ($existing_grade && $existing_grade->grade >= 0) {
            // Already graded, skip
            return;
        }
        
        // If sitewide auto-grading is enabled, queue for immediate grading
        if (get_config('local_ai_autograder', 'sitewide_auto_grade')) {
            // Create adhoc task for grading
            $task = new \local_ai_autograder\task\grade_submission_task();
            $task->set_custom_data([
                'submissionid' => $submission->id,
                'assignmentid' => $assignment->id
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }
}