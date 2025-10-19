<?php
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class admin_setting_configuser_autocomplete extends \admin_setting_configtext {
    
    // ADD $paramtype with a default value of PARAM_INT
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype = PARAM_INT) {
        
        // Pass all arguments, including $paramtype, to the parent constructor
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
    }

    public function validate($data) {
        global $DB;
        
        // Call parent validation first
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }
        
        // Custom validation
        if (!empty($data)) {
            if (!$DB->record_exists('user', ['id' => (int)$data, 'deleted' => 0])) {
                return get_string('error:invaliduser', 'local_ai_autograder');
            }
        }
        
        return true;
    }

    public function output_html($data, $query = '') {
        global $PAGE, $DB;

        // Get current user if data exists
        $selected_user = null;
        if (!empty($data)) {
            $selected_user = $DB->get_record('user', ['id' => $data], 'id, firstname, lastname, email');
        }

        // Add JavaScript for autocomplete
        $PAGE->requires->js_init_code($this->get_inline_js($this->get_full_name(), $data));

        $html = '';
        $html .= '<div class="form-autocomplete-selection" id="' . $this->get_full_name() . '_wrapper">';
        
        // Hidden input for the actual user ID (this is what gets saved)
        $html .= '<input type="hidden" name="' . $this->get_full_name() . '" id="' . $this->get_full_name() . '" value="' . s($data) . '">';
        
        // Display input for user search
        $display_value = '';
        if ($selected_user) {
            $display_value = $selected_user->firstname . ' ' . $selected_user->lastname . ' (' . $selected_user->email . ')';
        }
        
        $html .= '<input type="text" 
                    class="form-control" 
                    id="' . $this->get_full_name() . '_display" 
                    placeholder="Search for users..."
                    value="' . s($display_value) . '"
                    autocomplete="off">';
        
        $html .= '<div id="' . $this->get_full_name() . '_results" class="user-search-results" style="display:none;"></div>';
        $html .= '</div>';

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }

    private function get_inline_js($fieldname, $currentValue) {
        global $CFG;
        
        return "
        require(['jquery', 'core/ajax'], function($, ajax) {
            var wrapper = $('#{$fieldname}_wrapper');
            var hiddenInput = $('#{$fieldname}');
            var displayInput = $('#{$fieldname}_display');
            var resultsDiv = $('#{$fieldname}_results');
            var searchTimeout;
            
            displayInput.on('input', function() {
                var query = $(this).val();
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    resultsDiv.hide().empty();
                    return;
                }
                
                searchTimeout = setTimeout(function() {
                    searchUsers(query);
                }, 300);
            });
            
            $(document).on('click', function(e) {
                if (!wrapper.is(e.target) && wrapper.has(e.target).length === 0) {
                    resultsDiv.hide();
                }
            });
            
            function searchUsers(query) {
                var promises = ajax.call([{
                    methodname: 'local_ai_autograder_search_users',
                    args: { query: query, limit: 10 }
                }]);
                
                promises[0].done(function(users) {
                    resultsDiv.empty();
                    
                    if (users.length === 0) {
                        resultsDiv.html('<div class=\"user-search-item\">No users found</div>').show();
                        return;
                    }
                    
                    var html = '';
                    users.forEach(function(user) {
                        html += '<div class=\"user-search-item\" data-userid=\"' + user.id + '\">' +
                            '<strong>' + user.fullname + '</strong><br>' +
                            '<small>' + user.email + '</small></div>';
                    });
                    
                    resultsDiv.html(html).show();
                    
                    resultsDiv.find('.user-search-item').on('click', function() {
                        var userId = $(this).data('userid');
                        var userName = $(this).find('strong').text();
                        var userEmail = $(this).find('small').text();
                        
                        hiddenInput.val(userId);
                        displayInput.val(userName + ' (' + userEmail + ')');
                        resultsDiv.hide();
                    });
                });
            }
        });
        ";
    }
}