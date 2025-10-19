<?php
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'grading_failed' => [
        'capability' => 'local/ai_autograder:viewlogs',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN + MESSAGE_DEFAULT_LOGGEDOFF,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN + MESSAGE_DEFAULT_LOGGEDOFF,
        ],
    ],
    'grading_completed' => [
        'capability' => 'local/ai_autograder:viewlogs',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN,
        ],
    ],
];
?>