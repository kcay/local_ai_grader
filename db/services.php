<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_autograder_grade_submission' => [
        'classname'   => 'local_ai_autograder\external\grade_submission',
        'methodname'  => 'execute',
        'description' => 'Trigger AI grading for a submission',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> 'local/ai_autograder:manualgrade',
    ],
];

$services = [
    'AI Auto-Grader Service' => [
        'functions' => ['local_ai_autograder_grade_submission'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];