<?php

namespace local_ai_autograder\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when assignment AI config is updated
 */
class config_updated extends \core\event\base {
    
    /**
     * Init method
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ai_autograder_config';
    }
    
    /**
     * Get name
     */
    public static function get_name() {
        return get_string('event_config_updated', 'local_ai_autograder');
    }
    
    /**
     * Get description
     */
    public function get_description() {
        return "User {$this->userid} updated AI auto-grading configuration for " .
               "assignment {$this->other['assignmentid']}";
    }
    
    /**
     * Get URL
     */
    public function get_url() {
        return new \moodle_url('/mod/assign/view.php', [
            'id' => $this->contextinstanceid
        ]);
    }
    
    /**
     * Custom validation
     */
    protected function validate_data() {
        parent::validate_data();
        
        if (!isset($this->other['assignmentid'])) {
            throw new \coding_exception('The \'assignmentid\' value must be set in other.');
        }
    }
}
