<?php
defined('MOODLE_INTERNAL') || die();

$messageproviders = array(
    'grading_completed' => array(
        'capability' => 'local/ai_autograder:receivenotifications',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
    ),
    'grading_failed' => array(
        'capability' => 'local/ai_autograder:viewlogs',
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
    ),
);