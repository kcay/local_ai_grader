<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');

$assignmentid = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$debug = optional_param('debug', 0, PARAM_INT);

require_sesskey();

// Enhanced debugging for development
if ($debug && debugging()) {
    echo "<pre>DEBUG MODE:\n";
    echo "Assignment ID: $assignmentid\n";
    echo "Submission ID: $submissionid\n";
}

// Get assignment and submission with detailed error checking
$assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);

// Verify the submission belongs to the assignment
if ($submission->assignment != $assignment->id) {
    print_error('Submission does not belong to this assignment');
}

if ($debug && debugging()) {
    echo "Assignment verification passed\n";
    echo "Assignment: {$assignment->name} (Course: {$assignment->course})\n";
    echo "Submission: User {$submission->userid}, Status: {$submission->status}\n";
    
    // Check for existing grade
    $existing_grade = $DB->get_record('assign_grades', [
        'assignment' => $assignment->id,
        'userid' => $submission->userid,
        'attemptnumber' => $submission->attemptnumber ?? 0
    ]);
    
    if ($existing_grade) {
        echo "Existing grade: {$existing_grade->grade}\n";
    } else {
        echo "No existing grade found\n";
    }
    
    // Check assignsubmission_file table first
    $file_submission = $DB->get_record('assignsubmission_file', [
        'assignment' => $assignment->id,
        'submission' => $submission->id
    ]);
    
    if ($file_submission) {
        echo "File submission record found:\n";
        echo "  - ID: {$file_submission->id}\n";
        echo "  - Assignment: {$file_submission->assignment}\n";
        echo "  - Submission: {$file_submission->submission}\n";
        echo "  - Number of files: {$file_submission->numfiles}\n";
    } else {
        echo "No file submission record found in assignsubmission_file table\n";
    }
    
    // Check for actual files in file storage
    $cm = get_coursemodule_from_instance('assign', $assignment->id);
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    
    $files = $fs->get_area_files(
        $context->id,
        'assignsubmission_file',
        'submission_files',
        $submission->id,
        'timemodified',
        false
    );
    
    echo "Files found in storage: " . count($files) . "\n";
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            echo "  - {$file->get_filename()} ({$file->get_filesize()} bytes) [ItemID: {$file->get_itemid()}, UserID: {$file->get_userid()}]\n";
        }
    }
    
    // Check for online text
    $online_text = $DB->get_record('assignsubmission_onlinetext', [
        'assignment' => $assignment->id,
        'submission' => $submission->id
    ]);
    
    if ($online_text) {
        echo "Online text submission found: " . strlen(strip_tags($online_text->onlinetext)) . " characters\n";
    } else {
        echo "No online text submission found\n";
    }
    
    echo "</pre>";
}

// Get course module
$cm = get_coursemodule_from_instance('assign', $assignment->id, 0, false, MUST_EXIST);

// Security checks
require_login($cm->course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/ai_autograder:manualgrade', $context);

$PAGE->set_url('/local/ai_autograder/manual_grader.php', [
    'id' => $assignmentid, 
    'submissionid' => $submissionid,
    'debug' => $debug
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('regrade_with_ai', 'local_ai_autograder'));
$PAGE->set_heading($assignment->name);

if ($confirm && confirm_sesskey()) {
    // Perform AI grading
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('grading_in_progress', 'local_ai_autograder'));
    
    echo html_writer::start_div('ai-grading-progress');
    echo html_writer::tag('p', get_string('grading_in_progress', 'local_ai_autograder'));
    
    // Flush output so user sees progress
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    try {
        // Enable debugging temporarily if in debug mode
        $original_debug = $CFG->debug;
        if ($debug && debugging()) {
            $CFG->debug = DEBUG_DEVELOPER;
        }
        
        $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
        
        // Restore original debug level
        if ($debug && debugging()) {
            $CFG->debug = $original_debug;
        }
        
        if ($result['success']) {
            echo $OUTPUT->notification(
                get_string('grading_complete', 'local_ai_autograder') . 
                ": {$result['grade']}/{$assignment->grade}",
                'success'
            );
            
            // Show the feedback
            echo html_writer::start_div('alert alert-info mt-3');
            echo html_writer::tag('h4', 'AI Feedback:');
            echo html_writer::tag('div', nl2br(htmlspecialchars($result['feedback'])));
            echo html_writer::end_div();
            
            // Show debug info if requested
            if ($debug && debugging()) {
                echo html_writer::start_div('alert alert-secondary mt-3');
                echo html_writer::tag('h5', 'Debug Information:');
                echo html_writer::tag('p', "Assignment ID: {$assignment->id}");
                echo html_writer::tag('p', "Submission ID: {$submission->id}");
                echo html_writer::tag('p', "User ID: {$submission->userid}");
                echo html_writer::tag('p', "Course Module ID: {$cm->id}");
                echo html_writer::tag('p', "Context ID: {$context->id}");
                
                // Check if feedback was saved
                $saved_grade = $DB->get_record('assign_grades', [
                    'assignment' => $assignment->id,
                    'userid' => $submission->userid,
                    'attemptnumber' => $submission->attemptnumber ?? 0
                ]);
                
                if ($saved_grade) {
                    echo html_writer::tag('p', "Grade saved: ID {$saved_grade->id}, Grade: {$saved_grade->grade}");
                    
                    $saved_feedback = $DB->get_record('assignfeedback_comments', [
                        'assignment' => $assignment->id,
                        'grade' => $saved_grade->id
                    ]);
                    
                    if ($saved_feedback) {
                        echo html_writer::tag('p', "Feedback saved: ID {$saved_feedback->id}");
                    } else {
                        echo html_writer::tag('p', "Warning: Feedback not found in database", 'alert alert-warning');
                    }
                } else {
                    echo html_writer::tag('p', "Warning: Grade not found in database", 'alert alert-warning');
                }
                
                echo html_writer::end_div();
            }
            
        } else {
            echo $OUTPUT->notification(
                get_string('grading_failed', 'local_ai_autograder') . ': ' . $result['error'],
                'error'
            );
            
            // Show debug info for failures
            if ($debug && debugging()) {
                echo html_writer::start_div('alert alert-danger mt-3');
                echo html_writer::tag('h5', 'Error Debug Information:');
                echo html_writer::tag('pre', print_r($result, true));
                echo html_writer::end_div();
            }
        }
        
    } catch (Exception $e) {
        echo $OUTPUT->notification(
            get_string('grading_failed', 'local_ai_autograder') . ': ' . $e->getMessage(),
            'error'
        );
        
        if ($debug && debugging()) {
            echo html_writer::start_div('alert alert-danger mt-3');
            echo html_writer::tag('h5', 'Exception Debug Information:');
            echo html_writer::tag('pre', $e->getTraceAsString());
            echo html_writer::end_div();
        }
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
    
    // Get current grade if exists
    $current_grade = $DB->get_record('assign_grades', [
        'assignment' => $assignment->id,
        'userid' => $submission->userid,
        'attemptnumber' => $submission->attemptnumber ?? 0
    ]);
    
    $grade_text = $current_grade ? $current_grade->grade : 'Not graded';
    
    echo html_writer::tag('p', 
        "Are you sure you want to grade this submission using AI?<br>" .
        "Student: " . fullname($user) . "<br>" .
        "Current grade: " . $grade_text . " / {$assignment->grade}<br>" .
        "Submission status: {$submission->status}"
    );
    
    $confirm_url = new moodle_url('/local/ai_autograder/manual_grader.php', [
        'id' => $assignmentid,
        'submissionid' => $submissionid,
        'confirm' => 1,
        'debug' => $debug,
        'sesskey' => sesskey()
    ]);
    
    $cancel_url = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
    
    echo $OUTPUT->confirm(
        get_string('warning_manual_review', 'local_ai_autograder'),
        $confirm_url,
        $cancel_url
    );
    
    // Add debug link if user has appropriate permissions
    if (debugging() && has_capability('moodle/site:config', context_system::instance())) {
        $debug_url = new moodle_url('/local/ai_autograder/manual_grader.php', [
            'id' => $assignmentid,
            'submissionid' => $submissionid,
            'debug' => 1,
            'sesskey' => sesskey()
        ]);
        
        echo html_writer::div(
            html_writer::link($debug_url, 'Enable Debug Mode', ['class' => 'btn btn-sm btn-secondary mt-2']),
            'mt-3'
        );
    }
    
    echo $OUTPUT->footer();
}