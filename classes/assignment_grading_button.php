<?php

namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class assignment_grading_button {
    
    /**
     * Render the AI re-grading button
     * 
     * @param object $assignment
     * @param object $submission
     * @return string HTML for button
     */
    public static function render_button($assignment, $submission) {
        global $OUTPUT;
        
        // Check capability
        $context = \context_module::instance($assignment->cmid);
        if (!has_capability('local/ai_autograder:manualgrade', $context)) {
            return '';
        }
        
        // Check if AI grading is configured
        if (!local_ai_autograder_get_config($assignment->id)) {
            return '';
        }
        
        $url = new \moodle_url('/local/ai_autograder/regrade.php', [
            'id' => $assignment->id,
            'submissionid' => $submission->id,
            'sesskey' => sesskey()
        ]);
        
        $button = \html_writer::link(
            $url,
            get_string('regrade_with_ai', 'local_ai_autograder'),
            [
                'class' => 'btn btn-secondary ai-regrade-btn',
                'data-action' => 'ai-regrade'
            ]
        );
        
        return \html_writer::div($button, 'ai-autograder-actions mt-2');
    }
}
