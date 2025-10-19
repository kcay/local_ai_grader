<?php
namespace local_ai_autograder\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class search_users extends \external_api {
    
    public static function execute_parameters() {
        return new \external_function_parameters([
            'query' => new \external_value(PARAM_TEXT, 'Search query'),
            'limit' => new \external_value(PARAM_INT, 'Result limit', VALUE_DEFAULT, 10)
        ]);
    }
    
   public static function execute($query, $limit = 10) {
        global $DB;
        
        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'limit' => $limit
        ]);
        
        // Require capability to manage site config
        require_capability('moodle/site:config', \context_system::instance());
        
        // Use Moodle's proper database methods
        $searchparam = '%' . $DB->sql_like_escape($params['query']) . '%';
        
        // Build conditions array
        $conditions = [
            'deleted' => 0
        ];
        
        // Build WHERE clause for name/email search
        $like_sql = $DB->sql_like('firstname', ':query1', false) . ' OR ' .
                    $DB->sql_like('lastname', ':query2', false) . ' OR ' .
                    $DB->sql_like('email', ':query3', false);
        
        $sql = "SELECT id, firstname, lastname, email 
                FROM {user} 
                WHERE deleted = 0 
                AND ($like_sql)
                ORDER BY lastname, firstname";
        
        $search_params = [
            'query1' => $searchparam,
            'query2' => $searchparam, 
            'query3' => $searchparam
        ];
        
        // Use get_records_sql with limit parameter
        $users = $DB->get_records_sql($sql, $search_params, 0, $params['limit']);
        
        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => $user->id,
                'fullname' => fullname($user),
                'email' => $user->email
            ];
        }
        
        return $result;
    }
    
    public static function execute_returns() {
        return new \external_multiple_structure(
            new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'User ID'),
                'fullname' => new \external_value(PARAM_TEXT, 'Full name'),
                'email' => new \external_value(PARAM_TEXT, 'Email')
            ])
        );
    }
}