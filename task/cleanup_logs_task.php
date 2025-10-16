<?php

// ==============================================================================
// FILE: classes/task/cleanup_logs_task.php
// ==============================================================================
namespace local_ai_autograder\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to clean up old logs
 */
class cleanup_logs_task extends \core\task\scheduled_task {
    
    /**
     * Get task name
     */
    public function get_name() {
        return get_string('task_cleanup_logs', 'local_ai_autograder');
    }
    
    /**
     * Execute the task
     */
    public function execute() {
        mtrace('Starting AI auto-grader log cleanup...');
        
        \local_ai_autograder_cleanup_logs();
        
        mtrace('Log cleanup completed.');
    }
}
