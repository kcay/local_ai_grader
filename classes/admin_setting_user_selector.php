<?php
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class admin_setting_user_selector extends \admin_setting_configtext {
    
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
    }

    public function validate($data) {
        global $DB;
        
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }
        
        if (!empty($data)) {
            if (!$DB->record_exists('user', ['id' => (int)$data, 'deleted' => 0])) {
                return 'Selected user not found';
            }
        }
        
        return true;
    }

    public function output_html($data, $query = '') {
        global $PAGE, $DB, $OUTPUT;

        // Get current user if data exists
        $selected_user = null;
        if (!empty($data)) {
            $selected_user = $DB->get_record('user', ['id' => $data]);
        }

        // Use Moodle's built-in user autocomplete
        $PAGE->requires->js_call_amd('core_user/repository', 'init');
        
        $context = [
            'name' => $this->get_full_name(),
            'value' => $data,
            'selecteduser' => $selected_user ? fullname($selected_user) . ' (' . $selected_user->email . ')' : '',
            'placeholder' => 'Search for users...'
        ];

        $html = $OUTPUT->render_from_template('local_ai_autograder/user_selector', $context);

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }
}