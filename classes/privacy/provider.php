<?php

// ==============================================================================
// FILE: classes/privacy/provider.php
// ==============================================================================
namespace local_ai_autograder\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for AI Auto-Grader plugin
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    
    /**
     * Get metadata about data stored by this plugin
     * 
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        // Local database tables
        $collection->add_database_table(
            'local_ai_autograder_logs',
            [
                'userid' => 'privacy:metadata:local_ai_autograder_logs:userid',
                'feedback' => 'privacy:metadata:local_ai_autograder_logs:feedback',
                'ai_response' => 'privacy:metadata:local_ai_autograder_logs:ai_response',
                'timecreated' => 'privacy:metadata:local_ai_autograder_logs:timecreated',
            ],
            'privacy:metadata:local_ai_autograder_logs'
        );
        
        // External AI services
        $collection->add_external_location_link(
            'ai_provider',
            [
                'submission_content' => 'privacy:metadata:ai_provider:submission_content',
                'rubric_criteria' => 'privacy:metadata:ai_provider:rubric_criteria',
            ],
            'privacy:metadata:ai_provider'
        );
        
        return $collection;
    }
    
    /**
     * Get contexts with user data
     * 
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        
        $sql = "SELECT DISTINCT ctx.id
                FROM {context} ctx
                JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                JOIN {assign} a ON a.id = cm.instance
                JOIN {local_ai_autograder_logs} log ON log.assignmentid = a.id
                WHERE log.userid = :userid";
        
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid
        ];
        
        $contextlist->add_from_sql($sql, $params);
        
        return $contextlist;
    }
    
    /**
     * Export user data
     * 
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        
        if (empty($contextlist->count())) {
            return;
        }
        
        $user = $contextlist->get_user();
        
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            
            $cm = get_coursemodule_from_id('assign', $context->instanceid);
            if (!$cm) {
                continue;
            }
            
            $logs = $DB->get_records('local_ai_autograder_logs', [
                'assignmentid' => $cm->instance,
                'userid' => $user->id
            ]);
            
            if (!empty($logs)) {
                $data = [];
                
                foreach ($logs as $log) {
                    $data[] = [
                        'assignment' => $cm->name,
                        'ai_provider' => $log->ai_provider,
                        'ai_model' => $log->ai_model,
                        'grading_method' => $log->grading_method,
                        'score' => $log->adjusted_score,
                        'feedback' => $log->feedback,
                        'date' => \core_privacy\local\request\transform::datetime($log->timecreated)
                    ];
                }
                
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_autograder')],
                    (object)['logs' => $data]
                );
            }
        }
    }
    
    /**
     * Delete user data for approved contexts
     * 
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        
        $cm = get_coursemodule_from_id('assign', $context->instanceid);
        if (!$cm) {
            return;
        }
        
        $DB->delete_records('local_ai_autograder_logs', ['assignmentid' => $cm->instance]);
    }
    
    /**
     * Delete user data
     * 
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        
        if (empty($contextlist->count())) {
            return;
        }
        
        $user = $contextlist->get_user();
        
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            
            $cm = get_coursemodule_from_id('assign', $context->instanceid);
            if (!$cm) {
                continue;
            }
            
            $DB->delete_records('local_ai_autograder_logs', [
                'assignmentid' => $cm->instance,
                'userid' => $user->id
            ]);
        }
    }
}