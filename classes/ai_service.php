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
    public static function grade_submission($submission, $assignment) {
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
    }
    
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
     * Get submission content
     * 
     * @param object $submission
     * @return string Submission text
     */
    private static function get_submission_content($submission) {
        global $DB;
        
        $fs = get_file_storage();
        $context = \context_module::instance($submission->assignment);
        
        // Get files from submission
        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'timemodified',
            false
        );
        
        $content = '';
        
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array('.' . $extension, \local_ai_autograder_get_supported_filetypes())) {
                $file_content = self::extract_text_from_file($file, $extension);
                $content .= "\n\n--- File: $filename ---\n" . $file_content;
            }
        }
        
        // Also check for online text submission
        $online_text = $DB->get_record('assignsubmission_onlinetext', 
            ['assignment' => $submission->assignment, 'submission' => $submission->id]);
        
        if ($online_text && !empty($online_text->onlinetext)) {
            $content .= "\n\n--- Online Text ---\n" . strip_tags($online_text->onlinetext);
        }
        
        // Anonymize if configured
        if (get_config('local_ai_autograder', 'anonymize_submissions')) {
            $content = self::anonymize_content($content);
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
    private static function extract_text_from_file($file, $extension) {
        switch ($extension) {
            case 'txt':
            case 'rtf':
                return $file->get_content();
                
            case 'pdf':
                // Use PDF parser (requires additional library)
                return self::extract_text_from_pdf($file);
                
            case 'docx':
            case 'doc':
                return self::extract_text_from_docx($file);
                
            default:
                return '[Unsupported file type]';
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
     * Extract text from DOCX
     * 
     * @param stored_file $file
     * @return string
     */
    private static function extract_text_from_docx($file) {
        $temp_file = $file->copy_content_to_temp();
        
        if (!$temp_file) {
            return '[Could not extract DOCX content]';
        }
        
        try {
            $zip = new \ZipArchive();
            if ($zip->open($temp_file) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                
                if ($xml) {
                    $xml = simplexml_load_string($xml);
                    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    $paragraphs = $xml->xpath('//w:p');
                    
                    $text = '';
                    foreach ($paragraphs as $para) {
                        $text .= trim((string)$para) . "\n";
                    }
                    
                    @unlink($temp_file);
                    return $text;
                }
            }
        } catch (\Exception $e) {
            // Fall through
        }
        
        @unlink($temp_file);
        return '[DOCX text extraction failed]';
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
     * Determine grading method for assignment
     * 
     * @param object $assignment
     * @return string Grading method name
     */
    public static function get_grading_method($assignment) {
        global $DB;
        
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $assignment->contextid ?? 0,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
            return 'simple';
        }
        
        $grading_def = $DB->get_record('grading_definitions', [
            'areaid' => $grading_area->id
        ]);
        
        if (!$grading_def) {
            return 'simple';
        }
        
        // Check if ranged rubric tables exist
        if ($grading_def->method == 'rubric') {
            if ($DB->get_manager()->table_exists('gradingform_rubric_ranges_c')) {
                $ranged = $DB->record_exists('gradingform_rubric_ranges_c', 
                    ['definitionid' => $grading_def->id]);
                if ($ranged) {
                    return 'ranged_rubric';
                }
            }
            return 'rubric';
        }
        
        return 'simple';
    }
}