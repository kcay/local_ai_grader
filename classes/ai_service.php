<?php
// ==============================================================================
// FILE: classes/ai_service.php
// ==============================================================================
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_connectors/base_connector.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_connectors/openai_connector.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_connectors/gemini_connector.php');
require_once($CFG->dirroot . '/local/ai_autograder/classes/ai_connectors/claude_connector.php');

class ai_service {
    
    /**
     * Grade a submission using AI
     * 
     * @param object $submission Submission record
     * @param object $assignment Assignment record
     * @return array Result with success status, grade, feedback
     */

    /**
 * Grade a submission using AI with improved error handling and debugging
 * 
 * @param object $submission Submission record
 * @param object $assignment Assignment record
 * @return array Result with success status, grade, feedback
 */


public static function grade_submission($submission, $assignment) {
    global $CFG, $DB, $USER;


    // Get configured AI grader user or fallback to current user
    $ai_grader_userid = get_config('local_ai_autograder', 'ai_grader_userid');
    $grader_user = null;
    if ($ai_grader_userid) {
        $grader_user = $DB->get_record('user', ['id' => $ai_grader_userid]);
        if (!$grader_user) {
            debugging("Configured AI grader user ID {$ai_grader_userid} not found, using current user", DEBUG_DEVELOPER);
            $grader_user = $USER;
        }
    } else {
        $grader_user = $USER;
    }
    
    $start_time = microtime(true);
    
    try {
        debugging("Starting AI grading for submission ID: {$submission->id}, assignment ID: {$assignment->id}", DEBUG_DEVELOPER);
        
        // ... [previous validation and setup code remains unchanged] ...
        
        // Get AI provider
        $provider = local_ai_autograder_get_provider($assignment->id);
        $connector = self::get_connector($provider);
        
        if (!$connector->validate_api_key()) {
            throw new \moodle_exception('error_no_api_key', 'local_ai_autograder', '', $provider);
        }
        
        debugging("AI provider: $provider, API key validated", DEBUG_DEVELOPER);
        
        // Get submission content with improved error handling
        $submission_content = self::get_submission_content($submission);
        
        if (empty(trim($submission_content)) || $submission_content === '[No submission content found. Please check file upload or ensure online text was submitted.]') {
            debugging("Warning: No submission content found", DEBUG_DEVELOPER);
            // Continue anyway - let AI handle this case
        } else {
            debugging("Submission content retrieved successfully (" . strlen($submission_content) . " characters)", DEBUG_DEVELOPER);
        }

        // Get course transcript
        $course_transcript = self::get_course_transcript($assignment->id);
        if (!empty($course_transcript)) {
            debugging("Course transcript loaded (" . strlen($course_transcript) . " characters)", DEBUG_DEVELOPER);
        }
        
        // Determine grading method
        $grading_method = self::get_grading_method($assignment);
        debugging("Grading method: $grading_method", DEBUG_DEVELOPER);
        
        // Load configuration
        $config = \local_ai_autograder_get_config($assignment->id);
        
        // Build prompt based on grading method
        require_once($CFG->dirroot . '/local/ai_autograder/classes/grading_handler.php');
        $handler = new grading_handler();
        
        $prompt_data = [
            'submission_content' => $submission_content,
            'course_transcript' => $course_transcript,
            'grading_method' => $grading_method,
            'assignment' => $assignment,
            'config' => $config
        ];
        
        $prompt = $handler->build_prompt($prompt_data);
        debugging("AI prompt built (" . strlen($prompt) . " characters)", DEBUG_DEVELOPER);
        
        // Send to AI
        $ai_response = $connector->send_request($prompt);
        
        if (!$ai_response['success']) {
            throw new \Exception($ai_response['error'] ?? 'AI request failed');
        }
        
        debugging("AI response received successfully", DEBUG_DEVELOPER);
        
        // Parse and process response - this gives us the RAW baseline scores
        $grading_result = $handler->process_ai_response(
            $ai_response['data'],
            $grading_method,
            $assignment,
            $submission
        );

        debugging("AI response processed - RAW baseline grade: {$grading_result['grade']}", DEBUG_DEVELOPER);

        // FIXED: Apply leniency BEFORE saving - this is the critical fix
        require_once($CFG->dirroot . '/local/ai_autograder/classes/leniency_manager.php');
        $leniency_level = \local_ai_autograder_get_leniency($assignment->id);
        $leniency_mgr = new leniency_manager();

        debugging("Applying leniency level: $leniency_level", DEBUG_DEVELOPER);

        // FIXED: For ranged rubrics, apply leniency to individual criteria BEFORE saving
        $final_criteria_scores = $grading_result['criteria_scores'] ?? [];
        $adjusted_grade = $grading_result['grade']; // Initialize with baseline

        if ($grading_method === 'ranged_rubric' && !empty($final_criteria_scores)) {
            debugging("Applying leniency to ranged rubric criteria before saving", DEBUG_DEVELOPER);
            
            // Get rubric data to determine max scores for each criterion
            require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
            $parser = new rubric_parser();
            $rubric_data = $parser->get_ranged_rubric_data($assignment);
            
            // Create mapping of criterion ID to max score
            $criterion_max_scores = [];
            if ($rubric_data && !empty($rubric_data['criteria'])) {
                foreach ($rubric_data['criteria'] as $criterion) {
                    $max_score = 0;
                    if (!empty($criterion['ranges'])) {
                        foreach ($criterion['ranges'] as $range) {
                            $max_score = max($max_score, $range['max_score']);
                        }
                    }
                    $criterion_max_scores[$criterion['id']] = $max_score;
                }
            }
            
            // FIXED: Apply leniency to each criterion individually
            $adjusted_total = 0;
            foreach ($final_criteria_scores as &$criterion) {
                if (isset($criterion['score']) && isset($criterion['criterion_id'])) {
                    $criterion_id = $criterion['criterion_id'];
                    $original_score = (float)$criterion['score'];
                    $max_score = $criterion_max_scores[$criterion_id] ?? 20; // fallback max
                    
                    // Apply leniency to this individual criterion
                    $adjusted_individual = $leniency_mgr->apply_leniency($original_score, $leniency_level, $max_score);
                    $criterion['score'] = (int)round($adjusted_individual);
                    
                    $adjusted_total += $criterion['score'];
                    
                    debugging("Criterion {$criterion_id}: {$original_score} -> {$criterion['score']} (max: {$max_score})", DEBUG_DEVELOPER);
                }
            }
            
            // Use the recalculated total from adjusted criteria
            $adjusted_grade = $adjusted_total;
            
            debugging("Ranged rubric: baseline total {$grading_result['grade']} -> adjusted total {$adjusted_grade}", DEBUG_DEVELOPER);
            
        } else {
            // FIXED: For simple and standard rubric grading, apply leniency to total score
            $baseline_grade = $grading_result['grade'];
            $adjusted_grade = $leniency_mgr->apply_leniency(
                $baseline_grade,
                $leniency_level,
                $assignment->grade
            );
            
            debugging("Simple/standard grading: baseline {$baseline_grade} -> adjusted {$adjusted_grade}", DEBUG_DEVELOPER);
            
            // FIXED: For standard rubrics, also adjust individual criterion scores proportionally
            if ($grading_method === 'rubric' && !empty($final_criteria_scores)) {
                debugging("Applying proportional leniency to standard rubric criteria", DEBUG_DEVELOPER);
                
                if ($baseline_grade > 0) {
                    $adjustment_ratio = $adjusted_grade / $baseline_grade;
                    
                    foreach ($final_criteria_scores as &$criterion) {
                        if (isset($criterion['score'])) {
                            $original_score = (float)$criterion['score'];
                            $adjusted_score = $original_score * $adjustment_ratio;
                            $criterion['score'] = (int)round($adjusted_score);
                            
                            debugging("Standard rubric criterion {$criterion['criterion_id']}: {$original_score} -> {$criterion['score']}", DEBUG_DEVELOPER);
                        }
                    }
                }
            }
        }

        // FIXED: Ensure grade doesn't exceed assignment maximum after leniency
        $adjusted_grade = min($adjusted_grade, $assignment->grade);
        
        debugging("FINAL: Leniency applied ($leniency_level) - final adjusted grade: $adjusted_grade", DEBUG_DEVELOPER);
        
        // FIXED: Update feedback text to show adjusted scores instead of baseline scores
        $final_feedback = $grading_result['feedback'];
        if ($grading_method === 'ranged_rubric' && !empty($final_criteria_scores)) {
            // Replace the criterion scores section in feedback with adjusted scores
            $final_feedback = preg_replace('/<br><br><strong>Criterion Scores:<\/strong><br>.*$/s', '', $final_feedback);
            
            $final_feedback .= "<br><br><strong>Criterion Scores:</strong><br>";
            foreach ($final_criteria_scores as $score) {
                $final_feedback .= sprintf(
                    "- %s (Score: %d)<br>",
                    htmlspecialchars($score['feedback']),
                    $score['score']  // This is now the adjusted score
                );
            }
        }
        
        // FIXED: Save grade and feedback with ADJUSTED scores and UPDATED feedback
        $save_grade = $handler->save_grade(
            $assignment,
            $submission,
            $adjusted_grade,                // Adjusted total grade
            $final_feedback,                // Updated feedback with adjusted scores
            $final_criteria_scores         // Adjusted criteria scores
        );

        if ($save_grade) {
            try {
                // Send notification to student about AI grading
                self::send_grading_notification($assignment, $submission, $adjusted_grade, $grading_result['feedback']);
                debugging("Notification sent to student {$submission->userid}", DEBUG_DEVELOPER);
            } catch (\Exception $e) {
                debugging("Failed to send notification: " . $e->getMessage(), DEBUG_DEVELOPER);
                // Don't fail the grading if notification fails
            }
        }
        
        $processing_time = microtime(true) - $start_time;
        
        debugging("Grade saved successfully in {$processing_time}s", DEBUG_DEVELOPER);
        
        // FIXED: Log with both baseline and adjusted scores for transparency
        \local_ai_autograder_log([
            'assignmentid' => $assignment->id,
            'submissionid' => $submission->id,
            'userid' => $submission->userid,
            'ai_provider' => $provider,
            'ai_model' => $connector->get_model_name(),
            'grading_method' => $grading_method,
            'raw_score' => $grading_result['grade'],      // Baseline AI score
            'adjusted_score' => $adjusted_grade,          // Final adjusted score
            'leniency_level' => $leniency_level,
            'feedback' => $grading_result['feedback'],
            'ai_response' => json_encode($ai_response['data']),
            'status' => 'success',
            'processing_time' => $processing_time
        ]);
        
        return [
            'success' => true,
            'grade' => $adjusted_grade,                   // Return adjusted grade
            'feedback' => $final_feedback                 // Return updated feedback with adjusted scores
        ];
        
    } catch (\Exception $e) {
        $processing_time = microtime(true) - $start_time;
        
        debugging("AI grading failed: " . $e->getMessage(), DEBUG_DEVELOPER);
        
        // Log failure
        \local_ai_autograder_log([
            'assignmentid' => $assignment->id ?? 0,
            'submissionid' => $submission->id ?? 0,
            'userid' => $submission->userid ?? 0,
            'ai_provider' => $provider ?? 'unknown',
            'ai_model' => isset($connector) ? $connector->get_model_name() : 'unknown',
            'grading_method' => $grading_method ?? 'unknown',
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'processing_time' => $processing_time
        ]);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}


/**
 * Send notification to student about AI grading completion
 */
private static function send_grading_notification($assignment, $submission, $grade, $feedback) {
    global $DB, $USER;

    // Get configured AI grader user or fallback to current user
    $ai_grader_userid = get_config('local_ai_autograder', 'ai_grader_userid');
    $grader_user = null;
    if ($ai_grader_userid) {
        $grader_user = $DB->get_record('user', ['id' => $ai_grader_userid]);
        if (!$grader_user) {
            debugging("Configured AI grader user ID {$ai_grader_userid} not found, using current user", DEBUG_DEVELOPER);
            $grader_user = $USER;
        }
    } else {
        $grader_user = $USER;
    }
    
    // Get the student user
    $student = $DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST);
    
    // Create message
    $message = new \core\message\message();
    $message->component = 'local_ai_autograder';
    $message->name = 'grading_completed';
    $message->userfrom = $grader_user; // The grader (teacher/system)
    $message->userto = $student;
    $message->subject = get_string('notification_subject', 'local_ai_autograder');
    
    $message->fullmessage = "Your assignment '{$assignment->name}' has been graded by AI.\n\n";
    $message->fullmessage .= "Grade: {$grade}/{$assignment->grade}\n\n";
    $message->fullmessage .= "Feedback:\n" . strip_tags($feedback);
    
    $message->fullmessageformat = FORMAT_PLAIN;
    $message->fullmessagehtml = $message->fullmessage;
    $message->smallmessage = "Assignment '{$assignment->name}' graded: {$grade}/{$assignment->grade}";
    $message->notification = 1;
    
    return message_send($message);
}

   /* public static function grade_submission($submission, $assignment) {
        global $CFG, $DB;
        
        $start_time = microtime(true);
        
        try {
            // Ensure assignment has all required properties
            if (!isset($assignment->cmid)) {
                $cm = get_coursemodule_from_instance('assign', $assignment->id);
                if ($cm) {
                    $assignment->cmid = $cm->id;
                }
            }
            
            if (!isset($assignment->contextid)) {
                if (isset($assignment->cmid)) {
                    $context = \context_module::instance($assignment->cmid);
                    $assignment->contextid = $context->id;
                }
            }
            
            // Get AI provider
            $provider = local_ai_autograder_get_provider($assignment->id);
            $connector = self::get_connector($provider);
            
            if (!$connector->validate_api_key()) {
                throw new \moodle_exception('error_no_api_key', 'local_ai_autograder', '', $provider);
            }
            
            // Get submission content
            $submission_content = self::get_submission_content($submission);

            // Get course transcript (ADD THIS)
            $course_transcript = self::get_course_transcript($assignment->id);
            
            // Determine grading method
            $grading_method = self::get_grading_method($assignment);
            
            // Load configuration
            $config = \local_ai_autograder_get_config($assignment->id);
            
            // Build prompt based on grading method
            require_once($CFG->dirroot . '/local/ai_autograder/classes/grading_handler.php');
            $handler = new grading_handler();
            
             $prompt_data = [
                'submission_content' => $submission_content,
                'course_transcript' => $course_transcript, // ADD THIS
                'grading_method' => $grading_method,
                'assignment' => $assignment,
                'config' => $config
            ];
            
            $prompt = $handler->build_prompt($prompt_data);
            
            // Send to AI
            $ai_response = $connector->send_request($prompt);
            
            if (!$ai_response['success']) {
                throw new \Exception($ai_response['error'] ?? 'AI request failed');
            }
            
            // Parse and process response
            $grading_result = $handler->process_ai_response(
                $ai_response['data'],
                $grading_method,
                $assignment,
                $submission
            );
            
            // Apply leniency
            require_once($CFG->dirroot . '/local/ai_autograder/classes/leniency_manager.php');
            $leniency_level = \local_ai_autograder_get_leniency($assignment->id);
            $leniency_mgr = new leniency_manager();
            $adjusted_grade = $leniency_mgr->apply_leniency(
                $grading_result['grade'],
                $leniency_level,
                $assignment->grade
            );
            
            // Save grade
            $handler->save_grade(
                $assignment,
                $submission,
                $adjusted_grade,
                $grading_result['feedback'],
                $grading_result['criteria_scores'] ?? []
            );
            
            $processing_time = microtime(true) - $start_time;
            
            // Log success
            \local_ai_autograder_log([
                'assignmentid' => $assignment->id,
                'submissionid' => $submission->id,
                'userid' => $submission->userid,
                'ai_provider' => $provider,
                'ai_model' => $connector->get_model_name(),
                'grading_method' => $grading_method,
                'raw_score' => $grading_result['grade'],
                'adjusted_score' => $adjusted_grade,
                'leniency_level' => $leniency_level,
                'feedback' => $grading_result['feedback'],
                'ai_response' => json_encode($ai_response['data']),
                'status' => 'success',
                'processing_time' => $processing_time
            ]);
            
            return [
                'success' => true,
                'grade' => $adjusted_grade,
                'feedback' => $grading_result['feedback']
            ];
            
        } catch (\Exception $e) {
            $processing_time = microtime(true) - $start_time;
            
            // Log failure
            \local_ai_autograder_log([
                'assignmentid' => $assignment->id,
                'submissionid' => $submission->id,
                'userid' => $submission->userid,
                'ai_provider' => $provider ?? 'unknown',
                'ai_model' => isset($connector) ? $connector->get_model_name() : 'unknown',
                'grading_method' => $grading_method ?? 'unknown',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processing_time' => $processing_time
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } */
    
    /**
     * Get AI connector instance
     * 
     * @param string $provider Provider name
     * @return object Connector instance
     */
    private static function get_connector($provider) {
        switch ($provider) {
            case 'openai':
                return new \local_ai_autograder\ai_connectors\openai_connector();
            case 'gemini':
                return new \local_ai_autograder\ai_connectors\gemini_connector();
            case 'claude':
                return new \local_ai_autograder\ai_connectors\claude_connector();
            default:
                throw new \moodle_exception('Invalid AI provider: ' . $provider);
        }
    }
    
    /**
 * Get submission content using the assignsubmission_file table for accurate file retrieval
 * 
 * @param object $submission
 * @return string Submission text
 */
private static function get_submission_content($submission) {
    global $DB;
    
    // First, get the assignment and course module
    $assignment = $DB->get_record('assign', ['id' => $submission->assignment], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('assign', $assignment->id, 0, false, MUST_EXIST);
    $context = \context_module::instance($cm->id);
    
    debugging("Getting submission content for submission ID: {$submission->id}, assignment ID: {$assignment->id}", DEBUG_DEVELOPER);
    
    $fs = get_file_storage();
    $content = '';
    
    // METHOD 1: Check assignsubmission_file table first to see if files exist
    $file_submission = $DB->get_record('assignsubmission_file', [
        'assignment' => $submission->assignment,
        'submission' => $submission->id
    ]);
    
    if ($file_submission) {
        debugging("Found file submission record: ID {$file_submission->id}, numfiles: {$file_submission->numfiles}", DEBUG_DEVELOPER);
        
        if ($file_submission->numfiles > 0) {
            // Files exist, now get them from file storage
            $files = $fs->get_area_files(
                $context->id,
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                'timemodified',
                false
            );
            
            debugging("Retrieved " . count($files) . " files from file storage", DEBUG_DEVELOPER);
            
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                
                $filename = $file->get_filename();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                debugging("Processing file: $filename (extension: $extension, size: {$file->get_filesize()} bytes)", DEBUG_DEVELOPER);
                
                if (in_array('.' . $extension, \local_ai_autograder_get_supported_filetypes())) {
                    $file_content = self::extract_text_from_file($file, $extension);
                    if (!empty(trim($file_content))) {
                        $content .= "\n\n--- File: $filename ---\n" . $file_content;
                        debugging("Successfully extracted content from $filename (" . strlen($file_content) . " characters)", DEBUG_DEVELOPER);
                    } else {
                        debugging("Warning: No content extracted from $filename", DEBUG_DEVELOPER);
                        $content .= "\n\n--- File: $filename ---\n[Could not extract content from this file]";
                    }
                } else {
                    debugging("Skipping unsupported file type: $extension", DEBUG_DEVELOPER);
                    $content .= "\n\n--- File: $filename ---\n[Unsupported file type: .$extension]";
                }
            }
        } else {
            debugging("File submission record exists but numfiles is 0", DEBUG_DEVELOPER);
        }
    } else {
        debugging("No file submission record found in assignsubmission_file table", DEBUG_DEVELOPER);
    }
    
    // METHOD 2: Check for online text submission
    $online_text_submission = $DB->get_record('assignsubmission_onlinetext', [
        'assignment' => $submission->assignment,
        'submission' => $submission->id
    ]);
    
    if ($online_text_submission && !empty($online_text_submission->onlinetext)) {
        debugging("Found online text submission (" . strlen($online_text_submission->onlinetext) . " characters)", DEBUG_DEVELOPER);
        $cleaned_text = strip_tags($online_text_submission->onlinetext);
        $content .= "\n\n--- Online Text Submission ---\n" . $cleaned_text;
    } else {
        debugging("No online text submission found", DEBUG_DEVELOPER);
    }
    
    // METHOD 3: Fallback - try alternative file retrieval methods if still no content
    if (empty(trim($content))) {
        debugging("No content found via standard methods, trying alternative approaches", DEBUG_DEVELOPER);
        
        // Try different file area combinations
        $alternative_areas = [
            ['assignsubmission_file', 'submission_files', $submission->id],
            ['assignsubmission_file', 'submission_files', 0],
            ['mod_assign', 'submission_files', $submission->id],
            ['assignsubmission_file', 'files', $submission->id]
        ];
        
        foreach ($alternative_areas as $area_config) {
            $files = $fs->get_area_files(
                $context->id,
                $area_config[0],  // component
                $area_config[1],  // filearea
                $area_config[2],  // itemid
                'timemodified',
                false
            );
            
            debugging("Trying alternative: component={$area_config[0]}, area={$area_config[1]}, itemid={$area_config[2]} - found " . count($files) . " files", DEBUG_DEVELOPER);
            
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                
                $filename = $file->get_filename();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array('.' . $extension, \local_ai_autograder_get_supported_filetypes())) {
                    $file_content = self::extract_text_from_file($file, $extension);
                    if (!empty(trim($file_content))) {
                        $content .= "\n\n--- File: $filename (Alternative Method) ---\n" . $file_content;
                        debugging("Successfully found content via alternative method", DEBUG_DEVELOPER);
                        break 2; // Break out of both loops
                    }
                }
            }
        }
    }
    
    // METHOD 4: Debug - List all files in context if still no content
    if (empty(trim($content))) {
        debugging("Still no content found. Listing ALL files in context for debugging:", DEBUG_DEVELOPER);
        
        $all_files = $fs->get_area_files($context->id);
        $file_count = 0;
        
        foreach ($all_files as $f) {
            if (!$f->is_directory()) {
                $file_count++;
                debugging("  File #$file_count: Component={$f->get_component()}, Area={$f->get_filearea()}, ItemID={$f->get_itemid()}, Filename={$f->get_filename()}, UserID={$f->get_userid()}", DEBUG_DEVELOPER);
                
                // Check if this file might belong to our submission
                if ($f->get_component() === 'assignsubmission_file' && 
                    ($f->get_itemid() == $submission->id || $f->get_userid() == $submission->userid)) {
                    
                    debugging("  ^ This file might belong to our submission! Attempting to extract...", DEBUG_DEVELOPER);
                    
                    $filename = $f->get_filename();
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array('.' . $extension, \local_ai_autograder_get_supported_filetypes())) {
                        $file_content = self::extract_text_from_file($f, $extension);
                        if (!empty(trim($file_content))) {
                            $content .= "\n\n--- File: $filename (Found via Debug Search) ---\n" . $file_content;
                            debugging("Successfully extracted content via debug search!", DEBUG_DEVELOPER);
                        }
                    }
                }
            }
        }
        
        debugging("Total files in context: $file_count", DEBUG_DEVELOPER);
    }
    
    // Final validation and content preparation
    if (empty(trim($content))) {
        debugging("ERROR: No submission content found after all methods attempted", DEBUG_DEVELOPER);
        debugging("Submission details: ID={$submission->id}, Assignment={$submission->assignment}, User={$submission->userid}, Status={$submission->status}", DEBUG_DEVELOPER);
        
        // Check if this is a valid submission status
        if ($submission->status !== 'submitted') {
            $content = "[Warning: Submission status is '{$submission->status}', not 'submitted'. No content may be available.]";
        } else {
            $content = "[No submission content found. The student may not have uploaded any files or entered online text.]";
        }
    } else {
        debugging("SUCCESS: Found submission content (" . strlen($content) . " total characters)", DEBUG_DEVELOPER);
    }
    
    // Anonymize if configured
    if (get_config('local_ai_autograder', 'anonymize_submissions')) {
        $content = self::anonymize_content($content);
        debugging("Content anonymized", DEBUG_DEVELOPER);
    }
    
    return $content;
}
    
    /**
     * Extract text from file
     * 
     * @param stored_file $file
     * @param string $extension
     * @return string
     */

    private static function extract_text_from_docx($file) {
        debugging("=== Starting DOCX extraction for: {$file->get_filename()} ===", DEBUG_DEVELOPER);
        debugging("File size: {$file->get_filesize()} bytes", DEBUG_DEVELOPER);
        
        $temp_file = $file->copy_content_to_temp();
        
        if (!$temp_file) {
            debugging("ERROR: Failed to copy DOCX to temp file", DEBUG_DEVELOPER);
            return '[Could not create temporary file for DOCX extraction]';
        }
        
        debugging("DOCX copied to temp file: $temp_file", DEBUG_DEVELOPER);
        debugging("Temp file exists: " . (file_exists($temp_file) ? 'YES' : 'NO'), DEBUG_DEVELOPER);
        debugging("Temp file size: " . (file_exists($temp_file) ? filesize($temp_file) : 'N/A') . " bytes", DEBUG_DEVELOPER);
        
        $extracted_text = '';
        
        try {
            // Method 1: Try ZipArchive approach
            debugging("--- Method 1: ZipArchive approach ---", DEBUG_DEVELOPER);
            
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                $result = $zip->open($temp_file);
                
                debugging("ZipArchive open result: $result", DEBUG_DEVELOPER);
                
                if ($result === true) {
                    debugging("DOCX opened successfully as ZIP", DEBUG_DEVELOPER);
                    
                    // List all files in the ZIP for debugging
                    $file_count = $zip->numFiles;
                    debugging("ZIP contains $file_count files:", DEBUG_DEVELOPER);
                    
                    for ($i = 0; $i < $file_count; $i++) {
                        $file_info = $zip->statIndex($i);
                        debugging("  - {$file_info['name']} ({$file_info['size']} bytes)", DEBUG_DEVELOPER);
                    }
                    
                    // Try to get the main document content
                    $xml_content = $zip->getFromName('word/document.xml');
                    
                    if ($xml_content !== false) {
                        debugging("Successfully extracted word/document.xml (" . strlen($xml_content) . " bytes)", DEBUG_DEVELOPER);
                        
                        // Method 1a: Detailed XML parsing
                        $text1 = self::parse_docx_xml_detailed($xml_content);
                        if (!empty(trim($text1))) {
                            $extracted_text = $text1;
                            debugging("SUCCESS: Method 1a extracted " . strlen($text1) . " characters", DEBUG_DEVELOPER);
                        } else {
                            debugging("Method 1a failed - no text extracted", DEBUG_DEVELOPER);
                            
                            // Method 1b: Simple regex extraction as fallback
                            $text2 = self::parse_docx_xml_simple($xml_content);
                            if (!empty(trim($text2))) {
                                $extracted_text = $text2;
                                debugging("SUCCESS: Method 1b extracted " . strlen($text2) . " characters", DEBUG_DEVELOPER);
                            } else {
                                debugging("Method 1b also failed", DEBUG_DEVELOPER);
                            }
                        }
                    } else {
                        debugging("ERROR: Could not extract word/document.xml from ZIP", DEBUG_DEVELOPER);
                        
                        // Try alternative document locations
                        $alt_locations = ['word/document.xml', 'document.xml', 'content.xml'];
                        foreach ($alt_locations as $location) {
                            $alt_content = $zip->getFromName($location);
                            if ($alt_content !== false) {
                                debugging("Found content at alternative location: $location", DEBUG_DEVELOPER);
                                $text = self::parse_docx_xml_simple($alt_content);
                                if (!empty(trim($text))) {
                                    $extracted_text = $text;
                                    break;
                                }
                            }
                        }
                    }
                    
                    $zip->close();
                } else {
                    debugging("ERROR: Could not open DOCX as ZIP. Error code: $result", DEBUG_DEVELOPER);
                }
            } else {
                debugging("ERROR: ZipArchive class not available", DEBUG_DEVELOPER);
            }
            
            // Method 2: Raw ZIP reading (if ZipArchive failed)
            if (empty($extracted_text)) {
                debugging("--- Method 2: Raw ZIP extraction ---", DEBUG_DEVELOPER);
                $extracted_text = self::extract_docx_raw($temp_file);
            }
            
            // Method 3: Try treating as XML directly (corrupted ZIP)
            if (empty($extracted_text)) {
                debugging("--- Method 3: Direct XML reading ---", DEBUG_DEVELOPER);
                $file_content = file_get_contents($temp_file);
                if ($file_content !== false) {
                    // Look for XML patterns in the raw content
                    if (strpos($file_content, '<?xml') !== false || strpos($file_content, '<w:document') !== false) {
                        debugging("Found XML patterns in file", DEBUG_DEVELOPER);
                        $extracted_text = self::parse_docx_xml_simple($file_content);
                    }
                }
            }
            
            // Method 4: Binary pattern extraction (last resort)
            if (empty($extracted_text)) {
                debugging("--- Method 4: Binary pattern extraction ---", DEBUG_DEVELOPER);
                $extracted_text = self::extract_docx_binary_patterns($temp_file);
            }
            
        } catch (\Exception $e) {
            debugging("EXCEPTION during DOCX extraction: " . $e->getMessage(), DEBUG_DEVELOPER);
            debugging("Exception trace: " . $e->getTraceAsString(), DEBUG_DEVELOPER);
        } finally {
            // Always clean up temp file
            if (file_exists($temp_file)) {
                @unlink($temp_file);
                debugging("Temp file cleaned up", DEBUG_DEVELOPER);
            }
        }
        
        // Final result
        if (!empty(trim($extracted_text))) {
            debugging("=== DOCX EXTRACTION SUCCESS: " . strlen($extracted_text) . " characters ===", DEBUG_DEVELOPER);
            debugging("First 200 characters: " . substr($extracted_text, 0, 200), DEBUG_DEVELOPER);
            return trim($extracted_text);
        } else {
            debugging("=== DOCX EXTRACTION FAILED: No content extracted ===", DEBUG_DEVELOPER);
            return '[DOCX file found but no readable text content could be extracted. The file may be corrupted, empty, or in an unsupported format.]';
        }
    }

    /**
     * Detailed XML parsing for DOCX content
     */
    private static function parse_docx_xml_detailed($xml_content) {
        debugging("Parsing XML with detailed method", DEBUG_DEVELOPER);
        
        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_content);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                debugging("XML parsing errors: " . print_r($errors, true), DEBUG_DEVELOPER);
                return '';
            }
            
            debugging("XML parsed successfully", DEBUG_DEVELOPER);
            
            // Register namespaces
            $namespaces = $xml->getNamespaces(true);
            debugging("Found namespaces: " . print_r($namespaces, true), DEBUG_DEVELOPER);
            
            $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            
            // Try different XPath expressions
            $xpath_expressions = [
                '//w:t',           // Standard text nodes
                '//w:p//w:t',      // Text within paragraphs
                '//text()',        // Any text content
                '//*[local-name()="t"]'  // Text nodes without namespace
            ];
            
            $text_content = '';
            
            foreach ($xpath_expressions as $xpath) {
                debugging("Trying XPath: $xpath", DEBUG_DEVELOPER);
                $text_nodes = $xml->xpath($xpath);
                
                if ($text_nodes && count($text_nodes) > 0) {
                    debugging("Found " . count($text_nodes) . " text nodes with XPath: $xpath", DEBUG_DEVELOPER);
                    
                    foreach ($text_nodes as $text_node) {
                        $text = trim((string)$text_node);
                        if (!empty($text)) {
                            $text_content .= $text . ' ';
                        }
                    }
                    
                    if (!empty(trim($text_content))) {
                        debugging("Successfully extracted text using XPath: $xpath", DEBUG_DEVELOPER);
                        break;
                    }
                } else {
                    debugging("No text nodes found with XPath: $xpath", DEBUG_DEVELOPER);
                }
            }
            
            return trim($text_content);
            
        } catch (\Exception $e) {
            debugging("Exception in detailed XML parsing: " . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Simple regex-based XML parsing for DOCX content
     */
    private static function parse_docx_xml_simple($xml_content) {
        debugging("Parsing XML with simple regex method", DEBUG_DEVELOPER);
        
        try {
            // Remove XML declarations and process
            $content = preg_replace('/<\?xml[^>]*\?>/', '', $xml_content);
            
            // Extract text within <w:t> tags
            $pattern1 = '/<w:t[^>]*>(.*?)<\/w:t>/s';
            preg_match_all($pattern1, $content, $matches1);
            
            $text = '';
            if (!empty($matches1[1])) {
                debugging("Found " . count($matches1[1]) . " <w:t> matches", DEBUG_DEVELOPER);
                foreach ($matches1[1] as $match) {
                    $text .= html_entity_decode(strip_tags($match)) . ' ';
                }
            }
            
            // Try alternative pattern without namespace
            if (empty(trim($text))) {
                $pattern2 = '/<t[^>]*>(.*?)<\/t>/s';
                preg_match_all($pattern2, $content, $matches2);
                
                if (!empty($matches2[1])) {
                    debugging("Found " . count($matches2[1]) . " <t> matches", DEBUG_DEVELOPER);
                    foreach ($matches2[1] as $match) {
                        $text .= html_entity_decode(strip_tags($match)) . ' ';
                    }
                }
            }
            
            return trim($text);
            
        } catch (\Exception $e) {
            debugging("Exception in simple XML parsing: " . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Raw ZIP extraction without ZipArchive
     */
    private static function extract_docx_raw($temp_file) {
        debugging("Attempting raw ZIP extraction", DEBUG_DEVELOPER);
        
        try {
            $file_content = file_get_contents($temp_file);
            if ($file_content === false) {
                return '';
            }
            
            // Look for the ZIP central directory signature
            $zip_signature = "\x50\x4b\x03\x04"; // ZIP file signature
            if (strpos($file_content, $zip_signature) === false) {
                debugging("No ZIP signature found", DEBUG_DEVELOPER);
                return '';
            }
            
            // Look for document.xml content
            $xml_start = strpos($file_content, '<?xml');
            if ($xml_start !== false) {
                $xml_end = strpos($file_content, '</w:document>', $xml_start);
                if ($xml_end !== false) {
                    $xml_content = substr($file_content, $xml_start, $xml_end - $xml_start + 13);
                    debugging("Extracted XML from raw ZIP (" . strlen($xml_content) . " bytes)", DEBUG_DEVELOPER);
                    return self::parse_docx_xml_simple($xml_content);
                }
            }
            
            return '';
            
        } catch (\Exception $e) {
            debugging("Exception in raw ZIP extraction: " . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Binary pattern extraction (last resort)
     */
    private static function extract_docx_binary_patterns($temp_file) {
        debugging("Attempting binary pattern extraction", DEBUG_DEVELOPER);
        
        try {
            $content = file_get_contents($temp_file);
            if ($content === false) {
                return '';
            }
            
            // Look for readable text patterns in the binary content
            // This is a very crude method but sometimes works
            $text = '';
            
            // Extract sequences of printable characters
            preg_match_all('/[a-zA-Z0-9\s\.,;:!?\-]{10,}/', $content, $matches);
            
            if (!empty($matches[0])) {
                debugging("Found " . count($matches[0]) . " text patterns", DEBUG_DEVELOPER);
                foreach ($matches[0] as $match) {
                    $clean_text = trim($match);
                    if (strlen($clean_text) > 10) { // Only include substantial text
                        $text .= $clean_text . ' ';
                    }
                }
            }
            
            return trim($text);
            
        } catch (\Exception $e) {
            debugging("Exception in binary pattern extraction: " . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
}
    /**
     * Extract text from PDF
     * 
     * @param stored_file $file
     * @return string
     */
    private static function extract_text_from_pdf($file) {
        // Simple implementation - in production, use a proper PDF library
        // like TCPDF or Smalot\PdfParser
        $content = $file->get_content();
        
        // Very basic text extraction
        if (preg_match_all('/\(([^)]+)\)/', $content, $matches)) {
            return implode(' ', $matches[1]);
        }
        
        return '[PDF text extraction not available - please install PDF parser]';
    }
    
    
    
    /**
     * Anonymize content by removing names and IDs
     * 
     * @param string $content
     * @return string
     */
    private static function anonymize_content($content) {
        // Remove common patterns for student names/IDs
        $patterns = [
            '/Student (Name|ID):?\s*[^\n]+/i',
            '/Name:?\s*[^\n]+/i',
            '/ID Number:?\s*\d+/i',
            '/Matric(ulation)? (Number|No\.?):?\s*\d+/i'
        ];
        
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '[REDACTED]', $content);
        }
        
        return $content;
    }


    /**
     * Get course transcript content
     * 
     * @param int $assignmentid
     * @return string Transcript content or empty string
     */
    private static function get_course_transcript($assignmentid) {
        global $DB;
        
        // Check if feature is enabled
        $use_transcript = get_config('local_ai_autograder', 'use_course_transcript');
        if (!$use_transcript) {
            return '';
        }
        
        $config = \local_ai_autograder_get_config($assignmentid);
        
        if (!$config) {
            return '';
        }
        
        // Priority 1: Text transcript
        if (!empty($config->course_transcript)) {
            return $config->course_transcript;
        }
        
        // Priority 2: File transcript
        if (!empty($config->transcript_file)) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($config->transcript_file);
            
            if ($file) {
                $filename = $file->get_filename();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                return self::extract_text_from_file($file, $extension);
            }
        }
        
        return '';
    }


    /**
     * Enhanced extract_text_from_file method with better error handling
     * 
     * @param stored_file $file
     * @param string $extension
     * @return string
     */
    private static function extract_text_from_file($file, $extension) {
        debugging("Extracting text from file: {$file->get_filename()} (type: $extension)", DEBUG_DEVELOPER);
        
        switch ($extension) {
            case 'txt':
            case 'rtf':
                $content = $file->get_content();
                debugging("Text file content length: " . strlen($content), DEBUG_DEVELOPER);
                return $content;
                
            case 'pdf':
                return self::extract_text_from_pdf($file);
                
            case 'docx':
                return self::extract_text_from_docx($file);
                
            case 'doc':
                debugging("DOC files require additional library - not fully supported", DEBUG_DEVELOPER);
                return '[DOC files are not fully supported - please convert to DOCX or TXT]';
                
            case 'odt':
                debugging("ODT files require additional library - not fully supported", DEBUG_DEVELOPER);
                return '[ODT files are not fully supported - please convert to DOCX or TXT]';
                
            default:
                debugging("Unsupported file type: $extension", DEBUG_DEVELOPER);
                return '[Unsupported file type: .' . $extension . ']';
        }
    }

    
    /**
     * Determine grading method for assignment
     * 
     * @param object $assignment
     * @return string Grading method name
     */
    public static function get_grading_method($assignment) {
        global $DB;
        
        debugging("Determining grading method for assignment {$assignment->id}", DEBUG_DEVELOPER);
        
        // Get course module and context if not already set
        if (!isset($assignment->contextid)) {
            $cm = get_coursemodule_from_instance('assign', $assignment->id);
            if ($cm) {
                $context = \context_module::instance($cm->id);
                $assignment->contextid = $context->id;
            }
        }
        
        if (!$assignment->contextid) {
            debugging("No context ID found for assignment {$assignment->id}", DEBUG_DEVELOPER);
            return 'simple';
        }
        
        debugging("Using context ID: {$assignment->contextid}", DEBUG_DEVELOPER);
        
        // Step 1: Get grading area
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $assignment->contextid,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
            debugging("No grading area found for context {$assignment->contextid}", DEBUG_DEVELOPER);
            return 'simple';
        }
        
        debugging("Found grading area: ID={$grading_area->id}, activemethod={$grading_area->activemethod}", DEBUG_DEVELOPER);
        
        // Step 2: Check if there's an active method set
        if (empty($grading_area->activemethod)) {
            debugging("No active method set in grading area", DEBUG_DEVELOPER);
            return 'simple';
        }
        
        // Step 3: Get grading definition for this area
        $grading_def = $DB->get_record('grading_definitions', [
            'areaid' => $grading_area->id
        ]);
        
        if (!$grading_def) {
            debugging("No grading definition found for area {$grading_area->id}", DEBUG_DEVELOPER);
            return 'simple';
        }
        
        debugging("Found grading definition: ID={$grading_def->id}, method={$grading_def->method}", DEBUG_DEVELOPER);
        
        $method = $grading_def->method;
        
        // Step 4: Determine specific rubric type
        if ($method == 'rubric') {
            // Check if it's a ranged rubric by looking for the ranges tables
            $dbman = $DB->get_manager();
            
            if ($dbman->table_exists('gradingform_rubric_ranges_c')) {
                // Check if this specific definition uses ranged rubrics
                $ranged_criteria = $DB->record_exists('gradingform_rubric_ranges_c', 
                    ['definitionid' => $grading_def->id]);
                
                if ($ranged_criteria) {
                    debugging("Detected ranged rubric method", DEBUG_DEVELOPER);
                    return 'ranged_rubric';
                }
            }
            
            debugging("Detected standard rubric method", DEBUG_DEVELOPER);
            return 'rubric';
        }
        
        // Step 5: Handle other methods
        switch ($method) {
            case 'guide':
                debugging("Detected marking guide method (not supported)", DEBUG_DEVELOPER);
                return 'simple'; // Fallback to simple for unsupported methods
                
            case 'rubric_ranges':
                debugging("Detected rubric_ranges method", DEBUG_DEVELOPER);
                return 'ranged_rubric';
                
            default:
                debugging("Unknown grading method: $method, defaulting to simple", DEBUG_DEVELOPER);
                return 'simple';
        }
    }
}