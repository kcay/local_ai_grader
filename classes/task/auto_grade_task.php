<?php
// ==============================================================================
// FILE: classes/task/auto_grade_task.php
// ==============================================================================
namespace local_ai_autograder\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to automatically grade submissions
 */
class auto_grade_task extends \core\task\scheduled_task {
    
    /**
     * Get task name
     */
    public function get_name() {
        return get_string('task_auto_grade', 'local_ai_autograder');
    }
    
    /**
     * Execute the task
     */
    public function execute() {
        global $DB, $CFG;
        
        require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');
        
        // Check if sitewide auto-grading is enabled
        if (!get_config('local_ai_autograder', 'sitewide_auto_grade')) {
            mtrace('Sitewide auto-grading is disabled. Skipping...');
            return;
        }
        
        mtrace('Starting AI auto-grading task...');
        
        $batch_size = get_config('local_ai_autograder', 'batch_size') ?: 50;
        $enabled_methods = get_config('local_ai_autograder', 'enabled_methods');
        
        if (empty($enabled_methods)) {
            mtrace('No grading methods enabled. Skipping...');
            return;
        }
        
        // Find ungraded submissions
        $sql = "SELECT s.*, a.id as assignid, a.grade as maxgrade, a.intro, 
                       cm.id as cmid, c.id as courseid
                FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment
                JOIN {course_modules} cm ON cm.instance = a.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                JOIN {course} c ON c.id = a.course
                LEFT JOIN {assign_grades} g ON g.assignment = s.assignment 
                    AND g.userid = s.userid 
                    AND g.attemptnumber = s.attemptnumber
                WHERE s.status = 'submitted'
                AND (g.id IS NULL OR g.grade < 0)
                AND s.timemodified > :cutofftime
                ORDER BY s.timemodified ASC";
        
        // Only process submissions from the last 24 hours
        $cutoff_time = time() - (24 * 60 * 60);
        
        $submissions = $DB->get_records_sql($sql, ['cutofftime' => $cutoff_time], 0, $batch_size);
        
        if (empty($submissions)) {
            mtrace('No ungraded submissions found.');
            return;
        }
        
        mtrace('Found ' . count($submissions) . ' ungraded submissions to process.');
        
        $success_count = 0;
        $failure_count = 0;
        
        foreach ($submissions as $submission) {
            try {
                // Get assignment object
                $assignment = $DB->get_record('assign', ['id' => $submission->assignment]);
                
                if (!$assignment) {
                    mtrace("Assignment {$submission->assignment} not found. Skipping...");
                    continue;
                }
                
                // Check if assignment-level auto-grading is explicitly disabled
                $config = \local_ai_autograder_get_config($assignment->id);
                if ($config && isset($config->enabled) && !$config->enabled) {
                    mtrace("Auto-grading disabled for assignment {$assignment->id}. Skipping...");
                    continue;
                }
                
                // Check if grading method is supported
                if (!\local_ai_autograder_is_grading_method_supported($assignment)) {
                    mtrace("Grading method not supported for assignment {$assignment->id}. Skipping...");
                    continue;
                }
                
                mtrace("Grading submission {$submission->id} for user {$submission->userid}...");
                
                // Grade the submission
                $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
                
                if ($result['success']) {
                    mtrace("  ✓ Successfully graded: {$result['grade']}/{$assignment->grade}");
                    $success_count++;
                } else {
                    mtrace("  ✗ Failed: " . ($result['error'] ?? 'Unknown error'));
                    $failure_count++;
                }
                
                // Small delay to avoid rate limiting
                usleep(500000); // 0.5 seconds
                
            } catch (\Exception $e) {
                mtrace("  ✗ Exception: " . $e->getMessage());
                $failure_count++;
            }
        }
        
        mtrace("\n=== Grading Summary ===");
        mtrace("Successful: $success_count");
        mtrace("Failed: $failure_count");
        mtrace("Total: " . ($success_count + $failure_count));
        
        // Send notification if there were failures
        if ($failure_count > 0) {
            $this->send_failure_notification($failure_count);
        }
    }
    
    /**
     * Send notification about failures
     * 
     * @param int $failure_count
     */
    private function send_failure_notification($failure_count) {
        global $CFG;
        
        $admins = get_admins();
        
        $message = new \core\message\message();
        $message->component = 'local_ai_autograder';
        $message->name = 'grading_failed';
        $message->userfrom = \core_user::get_noreply_user();
        $message->subject = get_string('notification_subject', 'local_ai_autograder');
        $message->fullmessage = get_string('notification_grading_failed', 'local_ai_autograder', 
            ['count' => $failure_count]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $message->fullmessage;
        $message->smallmessage = "AI grading failed for $failure_count submissions";
        $message->notification = 1;
        
        foreach ($admins as $admin) {
            $message->userto = $admin;
            message_send($message);
        }
    }
}