<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');

$assignmentid = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

require_sesskey();

// Get assignment and submission
$assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);

// Get course module
$cm = get_coursemodule_from_instance('assign', $assignment->id, 0, false, MUST_EXIST);

// Security checks
require_login($cm->course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/ai_autograder:manualgrade', $context);

$PAGE->set_url('/local/ai_autograder/regrade.php', ['id' => $assignmentid, 'submissionid' => $submissionid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('regrade_with_ai', 'local_ai_autograder'));
$PAGE->set_heading($assignment->name);

if ($confirm && confirm_sesskey()) {
    // Perform AI grading
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('grading_in_progress', 'local_ai_autograder'));
    
    echo html_writer::start_div('ai-grading-progress');
    echo html_writer::tag('p', get_string('grading_in_progress', 'local_ai_autograder'));
    
    try {
        $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
        
        if ($result['success']) {
            echo $OUTPUT->notification(
                get_string('grading_complete', 'local_ai_autograder') . 
                ": {$result['grade']}/{$assignment->grade}",
                'success'
            );
            
            echo html_writer::tag('div', $result['feedback'], ['class' => 'alert alert-info mt-3']);
        } else {
            echo $OUTPUT->notification(
                get_string('grading_failed', 'local_ai_autograder') . ': ' . $result['error'],
                'error'
            );
        }
        
    } catch (Exception $e) {
        echo $OUTPUT->notification(
            get_string('grading_failed', 'local_ai_autograder') . ': ' . $e->getMessage(),
            'error'
        );
    }
    
    echo html_writer::end_div();
    
    // Back button
    $return_url = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
    echo $OUTPUT->continue_button($return_url);
    
    echo $OUTPUT->footer();
    
} else {
    // Show confirmation page
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('regrade_with_ai', 'local_ai_autograder'));
    
    $user = $DB->get_record('user', ['id' => $submission->userid]);
    
    echo html_writer::tag('p', 
        "Are you sure you want to re-grade this submission using AI?<br>" .
        "Student: " . fullname($user) . "<br>" .
        "Current grade: " . ($submission->grade ?? 'Not graded')
    );
    
    $confirm_url = new moodle_url('/local/ai_autograder/regrade.php', [
        'id' => $assignmentid,
        'submissionid' => $submissionid,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]);
    
    $cancel_url = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
    
    echo $OUTPUT->confirm(
        get_string('warning_manual_review', 'local_ai_autograder'),
        $confirm_url,
        $cancel_url
    );
    
    echo $OUTPUT->footer();
}