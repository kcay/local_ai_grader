<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_service.php');

$assignmentid = required_param('id', PARAM_INT);
$action = optional_param('action', 'view', PARAM_ALPHA);
$submissionid = optional_param('submissionid', 0, PARAM_INT);

// Get assignment
$assignment = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $assignment->id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $assignment->course], '*', MUST_EXIST);

// Security
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/ai_autograder:manualgrade', $context);

$PAGE->set_url('/local/ai_autograder/grade_assignment.php', ['id' => $assignmentid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('manual_grading', 'local_ai_autograder'));
$PAGE->set_heading($course->fullname);

// Handle actions
if ($action === 'grade_all' && confirm_sesskey()) {
    // Queue all ungraded submissions
    $sql = "SELECT s.* 
            FROM {assign_submission} s
            LEFT JOIN {assign_grades} g ON g.assignment = s.assignment 
                AND g.userid = s.userid 
                AND g.attemptnumber = s.attemptnumber
            WHERE s.assignment = :assignmentid
            AND s.status = 'submitted'
            AND (g.id IS NULL OR g.grade < 0)";
    
    $submissions = $DB->get_records_sql($sql, ['assignmentid' => $assignmentid]);
    
    $count = 0;
    foreach ($submissions as $submission) {
        $task = new \local_ai_autograder\task\grade_submission_task();
        $task->set_custom_data([
            'submissionid' => $submission->id,
            'assignmentid' => $assignmentid
        ]);
        \core\task\manager::queue_adhoc_task($task);
        $count++;
    }
    
    redirect($PAGE->url, "Queued $count submissions for grading. Run cron or adhoc tasks to process.", 
        null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'grade_one' && $submissionid && confirm_sesskey()) {
    // Grade single submission immediately
    $submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);
    
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('grading_submission', 'local_ai_autograder'));
    
    echo html_writer::start_div('alert alert-info');
    echo 'Grading in progress, please wait...';
    echo html_writer::end_div();
    
    // Flush output
    flush();
    
    try {
        $result = \local_ai_autograder\ai_service::grade_submission($submission, $assignment);
        
        if ($result['success']) {
            echo $OUTPUT->notification(
                "Successfully graded! Score: {$result['grade']}/{$assignment->grade}",
                'success'
            );
            
            echo html_writer::start_div('card mt-3');
            echo html_writer::start_div('card-body');
            echo html_writer::tag('h5', 'AI Feedback', ['class' => 'card-title']);
            echo html_writer::tag('div', format_text($result['feedback']), ['class' => 'card-text']);
            echo html_writer::end_div();
            echo html_writer::end_div();
        } else {
            echo $OUTPUT->notification(
                'Grading failed: ' . ($result['error'] ?? 'Unknown error'),
                'error'
            );
            
            // Show debug info if available
            if (debugging('', DEBUG_DEVELOPER)) {
                echo html_writer::start_div('alert alert-warning mt-3');
                echo html_writer::tag('strong', 'Debug Info:');
                echo html_writer::tag('pre', print_r($result, true));
                echo html_writer::end_div();
            }
        }
    } catch (Exception $e) {
        echo $OUTPUT->notification('Exception: ' . $e->getMessage(), 'error');
    }
    
    echo $OUTPUT->continue_button($PAGE->url);
    echo $OUTPUT->footer();
    exit;
}

// Display main page
echo $OUTPUT->header();
echo $OUTPUT->heading("AI Grading: {$assignment->name}");

// Check configuration
$config = local_ai_autograder_get_config($assignmentid);
if (!$config || !$config->enabled) {
    echo $OUTPUT->notification('AI grading is not enabled for this assignment.', 'warning');
    echo $OUTPUT->continue_button(new moodle_url('/mod/assign/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

// Show configuration summary
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', 'AI Grading Configuration', ['class' => 'card-title']);
echo html_writer::start_tag('ul');
echo html_writer::tag('li', 'Provider: ' . ($config->ai_provider ?: 'Default'));
echo html_writer::tag('li', 'Model: ' . ($config->ai_model ?: 'Default'));
echo html_writer::tag('li', 'Leniency: ' . ($config->leniency_level ?: 'Moderate'));
echo html_writer::tag('li', 'Course Transcript: ' . (!empty($config->course_transcript) ? 'Yes' : 'No'));
echo html_writer::tag('li', 'Custom Prompt: ' . (!empty($config->custom_prompt) ? 'Yes' : 'No'));
echo html_writer::end_tag('ul');
echo html_writer::end_div();
echo html_writer::end_div();

// Get ungraded submissions
$sql = "SELECT s.*, u.firstname, u.lastname, u.email
        FROM {assign_submission} s
        JOIN {user} u ON u.id = s.userid
        LEFT JOIN {assign_grades} g ON g.assignment = s.assignment 
            AND g.userid = s.userid 
            AND g.attemptnumber = s.attemptnumber
        WHERE s.assignment = :assignmentid
        AND s.status = 'submitted'
        AND (g.id IS NULL OR g.grade < 0)
        ORDER BY s.timemodified DESC";

$ungraded = $DB->get_records_sql($sql, ['assignmentid' => $assignmentid]);

// Get graded submissions
$sql_graded = "SELECT s.*, u.firstname, u.lastname, u.email, g.grade
        FROM {assign_submission} s
        JOIN {user} u ON u.id = s.userid
        JOIN {assign_grades} g ON g.assignment = s.assignment 
            AND g.userid = s.userid 
            AND g.attemptnumber = s.attemptnumber
        WHERE s.assignment = :assignmentid
        AND s.status = 'submitted'
        AND g.grade >= 0
        ORDER BY g.timemodified DESC";

$graded = $DB->get_records_sql($sql_graded, ['assignmentid' => $assignmentid]);

// Grade all button
if (!empty($ungraded)) {
    echo html_writer::start_div('mb-3');
    $gradeallurl = new moodle_url($PAGE->url, ['action' => 'grade_all', 'sesskey' => sesskey()]);
    echo html_writer::link(
        $gradeallurl,
        'Grade All Ungraded Submissions (' . count($ungraded) . ')',
        ['class' => 'btn btn-primary btn-lg']
    );
    echo html_writer::end_div();
}

// Ungraded submissions table
if (!empty($ungraded)) {
    echo html_writer::tag('h4', 'Ungraded Submissions (' . count($ungraded) . ')');
    
    $table = new html_table();
    $table->head = ['Student', 'Submitted', 'Action'];
    $table->attributes['class'] = 'generaltable';
    
    foreach ($ungraded as $submission) {
        $gradeurl = new moodle_url($PAGE->url, [
            'action' => 'grade_one',
            'submissionid' => $submission->id,
            'sesskey' => sesskey()
        ]);

        $gradenowurl = new moodle_url('/local/ai_autograder/manual_grader.php', [
            'id' => $assignmentid,
            'submissionid' => $submission->id,
            'sesskey' => sesskey()
        ]);
        
        $row = [
            fullname($submission),
            userdate($submission->timemodified),
             html_writer::link($gradeurl, 'Add to Task', ['class' => 'btn btn-sm btn-secondary']). " ". html_writer::link($gradenowurl, 'Grade Now', ['class' => 'btn btn-sm btn-primary'])
        ];
        
        $table->data[] = $row;
    }
    
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification('No ungraded submissions.', 'info');
}

// Recently graded submissions
if (!empty($graded)) {
    echo html_writer::tag('h4', 'Recently Graded Submissions (Last 10)', ['class' => 'mt-4']);
    
    $table = new html_table();
    $table->head = ['Student', 'Grade', 'Graded On'];
    $table->attributes['class'] = 'generaltable';
    
    $count = 0;
    foreach ($graded as $submission) {
        if ($count >= 10) break;
        
        $row = [
            fullname($submission),
            sprintf('%.2f / %.2f', $submission->grade, $assignment->grade),
            userdate($submission->timemodified)
        ];
        
        $table->data[] = $row;
        $count++;
    }
    
    echo html_writer::table($table);
}

// Instructions for running tasks
if (!empty($ungraded)) {
    echo html_writer::start_div('alert alert-info mt-4');
    echo html_writer::tag('strong', 'Note:');
    echo ' After clicking "Grade All", you need to run the scheduled tasks. You can either:';
    echo html_writer::start_tag('ul');
    echo html_writer::tag('li', 'Wait for cron to run (next scheduled time)');
    echo html_writer::tag('li', 'Run manually: <code>php admin/cli/adhoc_task.php --execute</code>');
    echo html_writer::end_tag('ul');
    echo html_writer::end_div();
}

echo $OUTPUT->footer();