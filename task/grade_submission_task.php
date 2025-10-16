<?php

// ==============================================================================
// FILE: classes/task/grade_submission_task.php
// ==============================================================================
namespace local_ai_autograder\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task to grade a single submission
 */
class grade_submission_task extends \core\task\adhoc_task {
    
    /**
     * Execute the task
     */
    public function execute() {
        global $DB, $CFG;
        
        require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');
        
        $data = $this->get_custom_data();
        
        if (empty($data->submissionid) || empty($data->assignmentid)) {
            mtrace('Invalid task data. Skipping...');
            return;
        }
        
        mtrace("Processing adhoc grading task for submission {$data->submissionid}");
        
        // Get submission
        $submission = $DB->get_record('assign_submission', ['id' => $data->submissionid]);
        
        if (!$submission) {
            mtrace('Submission not found. Skipping...');
            return;
        }
        
        // Get assignment
        $assignment = $DB->get_record('assign', ['id' => $data->assignmentid]);
        
        if (!$assignment) {
            mtrace('Assignment not found. Skipping...');
            return;
        }
        
        // Check if already graded
        $existing_grade = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber
        ]);
        
        if ($existing_grade && $existing_grade->grade >= 0) {
            mtrace('Submission already graded. Skipping...');
            return;
        }
        
        // Grade the submission
        try {
            $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
            
            if ($result['success']) {
                mtrace("✓ Successfully graded: {$result['grade']}/{$assignment->grade}");
            } else {
                mtrace("✗ Grading failed: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            mtrace("✗ Exception: " . $e->getMessage());
        }
    }
}