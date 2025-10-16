<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\local_ai_autograder\observers::submission_created',
    ],
];