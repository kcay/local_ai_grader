<?php

namespace local_ai_autograder\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when AI grading fails
 */
class grading_failed extends \core\event\base {
    
    /**
     * Init method
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'assign_submission';
    }
    
    /**
     * Get name
     */
    public static function get_name() {
        return get_string('event_grading_failed', 'local_ai_autograder');
    }
    
    /**
     * Get description
     */
    public function get_description() {
        $error = isset($this->other['error']) ? ': ' . $this->other['error'] : '';
        return "AI grading failed for submission {$this->other['submissionid']} " .
               "in assignment {$this->other['assignmentid']}" . $error;
    }
    
    /**
     * Get URL
     */
    public function get_url() {
        return new \moodle_url('/mod/assign/view.php', [
            'id' => $this->contextinstanceid,
            'action' => 'grading'
        ]);
    }
    
    /**
     * Custom validation
     */
    protected function validate_data() {
        parent::validate_data();
        
        if (!isset($this->other['submissionid'])) {
            throw new \coding_exception('The \'submissionid\' value must be set in other.');
        }
        
        if (!isset($this->other['assignmentid'])) {
            throw new \coding_exception('The \'assignmentid\' value must be set in other.');
        }
    }
    
    /**
     * Get mapping
     */
    public static function get_other_mapping() {
        return [
            'submissionid' => ['db' => 'assign_submission', 'restore' => 'submission'],
            'assignmentid' => ['db' => 'assign', 'restore' => 'assign']
        ];
    }
}