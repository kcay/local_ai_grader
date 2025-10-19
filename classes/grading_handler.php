<?php
// ==============================================================================
// FILE: classes/grading_handler.php
// ==============================================================================
namespace local_ai_autograder;

use grade_item;

defined('MOODLE_INTERNAL') || die();

// Ensure gradingform_instance constants are available
require_once($CFG->dirroot . '/grade/grading/form/lib.php');

class grading_handler {

    // Define constants locally to avoid namespace issues - CORRECTED CONSTANTS
    const INSTANCE_STATUS_INCOMPLETE = 0;
    const INSTANCE_STATUS_ACTIVE = 1;
    const INSTANCE_STATUS_NEEDUPDATE = 2;
    const INSTANCE_STATUS_ARCHIVE = 3;
    
    // ... [previous methods remain unchanged until save_grade] ...
    
    /**
     * Build AI prompt based on grading method
     * 
     * @param array $data Prompt data
     * @return string Complete prompt
     */
    public function build_prompt($data) {
        $method = $data['grading_method'];
        
        switch ($method) {
            case 'simple':
                return $this->build_simple_prompt($data);
            case 'rubric':
                return $this->build_rubric_prompt($data);
            case 'ranged_rubric':
                return $this->build_ranged_rubric_prompt($data);
            default:
                throw new \Exception('Unknown grading method: ' . $method);
        }
    }
    
    /**
     * Build simple direct grading prompt
     */
    private function build_simple_prompt($data) {
        global $DB;
        
        $assignment = $data['assignment'];
        $config = $data['config'];
        $submission_content = $data['submission_content'];
        
        debugging("Building simple prompt for assignment {$assignment->id}", DEBUG_DEVELOPER);
        
        $prompt = "You are grading a student submission for an assignment.\n\n";
        $prompt .= "**Assignment Details:**\n";
        $prompt .= "- Maximum Grade: {$assignment->grade}\n";
        $prompt .= "- Assignment Instructions: " . strip_tags($assignment->intro) . "\n\n";

        if (!empty($data['course_transcript'])) {
            $prompt .= "**Course Context (Transcript/Syllabus):**\n";
            $prompt .= $data['course_transcript'] . "\n\n";
            $prompt .= "Use this course context to understand what has been taught and what standards to apply.\n\n";
        }
        
        if ($config && !empty($config->custom_prompt)) {
            $prompt .= "**Custom Grading Instructions:**\n";
            $prompt .= strip_tags($config->custom_prompt) . "\n\n";
        }
        
        if ($config && $config->reference_file) {
            $reference = $this->get_reference_document($config->reference_file);
            $prompt .= "**Reference/Answer Key:**\n";
            $prompt .= $reference . "\n\n";
        }
        
        // Determine submission type and add appropriate context
        $submission_info = $this->analyze_submission_type($assignment, $submission_content);
        
        $prompt .= "**Submission Information:**\n";
        $prompt .= "- Submission Type: {$submission_info['type']}\n";
        
        if (!empty($submission_info['files'])) {
            $prompt .= "- Files Submitted: " . implode(', ', $submission_info['files']) . "\n";
        }
        
        if ($submission_info['has_content']) {
            $prompt .= "- Content Status: Successfully extracted and readable\n";
        } else {
            $prompt .= "- Content Status: No readable content found or extraction failed\n";
        }
        
        $prompt .= "\n";
        
        // Add submission content with proper framing
        if ($submission_info['has_content']) {
            $prompt .= "**Student Submission Content:**\n";
            $prompt .= "The following content was extracted from the student's submission file(s):\n\n";
            $prompt .= $submission_content . "\n\n";
        } else {
            $prompt .= "**Student Submission Status:**\n";
            if (strpos($submission_content, '[No submission content found') !== false) {
                $prompt .= "The student appears to have not submitted any readable content. ";
                $prompt .= "This could mean:\n";
                $prompt .= "- No files were uploaded\n";
                $prompt .= "- No online text was entered\n";
                $prompt .= "- Files were uploaded but could not be read (unsupported format or corrupted)\n\n";
                $prompt .= "Please grade accordingly, typically with a score of 0 unless partial credit is warranted for attempt or file upload.\n\n";
            } else {
                $prompt .= $submission_content . "\n\n";
            }
        }
        
        $prompt .= "**Grading Requirements:**\n";
        $prompt .= "Please grade this submission based on the assignment instructions above. ";
        
        if (!$submission_info['has_content']) {
            $prompt .= "Since no readable content was found, consider whether:\n";
            $prompt .= "- The student should receive 0 points for no submission\n";
            $prompt .= "- Partial credit should be given for attempting to submit\n";
            $prompt .= "- The file format issue should be noted in feedback\n\n";
        }
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "score": <numeric score out of ' . $assignment->grade . ">,\n";
        $prompt .= '  "feedback": "<detailed constructive feedback>",'. "\n";
        $prompt .= '  "strengths": ["strength 1", "strength 2"],'. "\n";
        $prompt .= '  "improvements": ["area 1", "area 2"]'. "\n";
        $prompt .= "}\n\n";
        $prompt .= "Provide objective, constructive feedback. Be specific about strengths and areas for improvement.";
        
        if (!$submission_info['has_content']) {
            $prompt .= " If no content was readable, explain this in the feedback and suggest the student resubmit in a supported format.";
        }
        
        return $prompt;
    }

    /**
     * Analyze submission type and content to provide better context
     */
    private function analyze_submission_type($assignment, $submission_content) {
        global $DB;
        
        $info = [
            'type' => 'Unknown',
            'files' => [],
            'has_content' => false
        ];
        
        // Get submission record
        // Note: We need to determine which submission this is for. 
        // This method should be called with submission context
        
        // Check for file submission
        $file_submissions = $DB->get_records('assignsubmission_file', [
            'assignment' => $assignment->id
        ]);
        
        // Check for online text submissions  
        $text_submissions = $DB->get_records('assignsubmission_onlinetext', [
            'assignment' => $assignment->id
        ]);
        
        // Analyze the content to determine what we have
        if (strpos($submission_content, '--- File:') !== false) {
            $info['type'] = 'File Upload';
            
            // Extract file names from content
            preg_match_all('/--- File: ([^-\n]+) ---/', $submission_content, $matches);
            if (!empty($matches[1])) {
                $info['files'] = array_map('trim', $matches[1]);
            }
            
            // Check if we have actual readable content
            $content_without_headers = preg_replace('/--- File: [^-\n]+ ---\n/', '', $submission_content);
            $clean_content = trim(str_replace(['[Could not extract content from this file]', '[Unsupported file type:', '[DOCX file found but no readable text'], '', $content_without_headers));
            
            $info['has_content'] = !empty($clean_content) && 
                                strlen($clean_content) > 10 && 
                                !preg_match('/^\[.*\]$/', trim($clean_content));
            
        } elseif (strpos($submission_content, '--- Online Text') !== false) {
            $info['type'] = 'Online Text';
            $info['has_content'] = strlen(trim($submission_content)) > 50; // Minimum meaningful content
            
        } else {
            // Determine based on content patterns
            if (strpos($submission_content, '[No submission content found') !== false) {
                $info['type'] = 'No Submission';
                $info['has_content'] = false;
            } elseif (strlen(trim($submission_content)) < 20) {
                $info['type'] = 'Minimal Content';
                $info['has_content'] = false;
            } else {
                $info['type'] = 'Text Content';
                $info['has_content'] = true;
            }
        }
        
        debugging("Submission analysis: Type={$info['type']}, Has Content=" . ($info['has_content'] ? 'YES' : 'NO') . ", Files=" . implode(',', $info['files']), DEBUG_DEVELOPER);
        
        return $info;
    }
    
    /**
     * Build rubric grading prompt
     */
    private function build_rubric_prompt($data) {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
        
        $assignment = $data['assignment'];
        $submission = $data['submission_content'];
        $config = $data['config'];
        
        $parser = new rubric_parser();
        $rubric_data = $parser->get_rubric_data($assignment);
        
        if (!$rubric_data) {
            throw new \Exception('Could not load rubric data');
        }
        
        $prompt = "You are grading a student submission using a rubric.\n\n";
        $prompt .= "**Assignment Instructions:**\n";
        $prompt .= strip_tags($assignment->intro) . "\n\n";


        if (!empty($data['course_transcript'])) {
            $prompt .= "**Course Context:**\n";
            $prompt .= $data['course_transcript'] . "\n\n";
            $prompt .= "Consider what was taught in the course when evaluating each criterion.\n\n";
        }
        
        if ($config && !empty($config->custom_prompt)) {
            $prompt .= "**Additional Grading Guidelines:**\n";
            $prompt .= strip_tags($config->custom_prompt) . "\n\n";
        }
        
        $prompt .= "**Rubric Criteria:**\n\n";
        
        foreach ($rubric_data['criteria'] as $criterion) {
            $prompt .= "**{$criterion['description']}**\n";
            foreach ($criterion['levels'] as $level) {
                $prompt .= "- {$level['definition']} ({$level['score']} points)\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "**Student Submission:**\n";
        $prompt .= $submission . "\n\n";
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "criteria_scores": [' . "\n";
        $prompt .= '    {"criterion_id": <id>, "level_id": <id>, "score": <points>, "feedback": "<specific feedback>"},' . "\n";
        $prompt .= "    ...\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_feedback": "<summary feedback>"' . "\n";
        $prompt .= "}\n\n";
        $prompt .= "Evaluate each criterion independently and select the most appropriate level.";
        
        return $prompt;
    }
    
    /**
     * Build ranged rubric grading prompt with correct understanding of the structure
     */
  
    private function build_ranged_rubric_prompt($data) {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
        
        $assignment = $data['assignment'];
        $submission = $data['submission_content'];
        $config = $data['config'];
        
        debugging("Building ranged rubric prompt", DEBUG_DEVELOPER);
        
        $parser = new rubric_parser();
        $rubric_data = $parser->get_ranged_rubric_data($assignment);
        
        if (!$rubric_data) {
            throw new \Exception('Could not load ranged rubric data');
        }
        
        $prompt = "You are grading a student submission using a ranged rubric system.\n\n";
        $prompt .= "**IMPORTANT: This is a RANGED RUBRIC** - You can assign any score within the specified ranges for each criterion, not just the exact level scores.\n\n";
        
        $prompt .= "**Assignment Instructions:**\n";
        $prompt .= strip_tags($assignment->intro) . "\n\n";

        if (!empty($data['course_transcript'])) {
            $prompt .= "**Course Learning Context:**\n";
            $prompt .= $data['course_transcript'] . "\n\n";
            $prompt .= "Evaluate the submission based on concepts and standards taught in the course.\n\n";
        }
        
        if ($config && !empty($config->custom_prompt)) {
            $prompt .= "**Additional Guidelines:**\n";
            $prompt .= strip_tags($config->custom_prompt) . "\n\n";
        }
        
        $prompt .= "**Rubric Criteria with Score Ranges:**\n\n";
        
        $total_max_score = 0;
        
        foreach ($rubric_data['criteria'] as $criterion) {
            // CRITICAL: Include the actual database ID in the prompt
            $prompt .= "**Criterion ID {$criterion['id']}: {$criterion['description']}**\n";
            
            if (!empty($criterion['ranges'])) {
                $criterion_max = 0;
                foreach ($criterion['ranges'] as $range) {
                    if ($range['max_score'] > $criterion_max) {
                        $criterion_max = $range['max_score'];
                    }
                }
                $total_max_score += $criterion_max;
                
                $prompt .= "Score Range: 0 to {$criterion_max} points\n";
                $prompt .= "Performance Levels:\n";
                
                foreach ($criterion['ranges'] as $range) {
                    $min = number_format($range['min_score']);
                    $max = number_format($range['max_score']);
                    $prompt .= "- {$range['definition']} (Range: {$min} - {$max} points)\n";
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= "**Student Submission:**\n";
        $prompt .= $submission . "\n\n";
        
        $prompt .= "**Grading Instructions:**\n";
        $prompt .= "1. For each criterion, read the performance level descriptions\n";
        $prompt .= "2. Determine which performance level best describes the student's work\n";
        $prompt .= "3. Assign a specific score within that performance level's range based on the quality\n";
        $prompt .= "4. You can assign ANY integer score within the range (e.g., 13, 16, 18, etc.). No decimals\n";
        $prompt .= "5. Higher scores within a range indicate better performance in that level\n";
        $prompt .= "6. Provide specific feedback explaining your score choice\n\n";
        
        $prompt .= "**CRITICAL: Use the EXACT criterion IDs shown above in your response**\n\n";
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "criteria_scores": [' . "\n";
        
        // Show example with actual IDs
        $first_criterion = reset($rubric_data['criteria']);
        $prompt .= '    {' . "\n";
        $prompt .= '      "criterion_id": ' . $first_criterion['id'] . ',' . "\n";
        $prompt .= '      "score": <integer_score_within_range>,' . "\n";
        $prompt .= '      "feedback": "<specific_feedback_for_this_criterion>"' . "\n";
        $prompt .= '    },' . "\n";
        $prompt .= "    ...\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_feedback": "<comprehensive_summary_feedback>"' . "\n";
        $prompt .= "}\n\n";
        
        $prompt .= "**Example Response Structure:**\n";
        $prompt .= "{\n";
        $prompt .= '  "criteria_scores": [' . "\n";
        foreach ($rubric_data['criteria'] as $criterion) {
            $prompt .= '    {"criterion_id": ' . $criterion['id'] . ', "score": <score>, "feedback": "<feedback>"},' . "\n";
        }
        $prompt = rtrim($prompt, ",\n") . "\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_feedback": "<summary>"' . "\n";
        $prompt .= "}\n\n";
        
        $prompt .= "Remember: Use the EXACT criterion IDs (" . implode(', ', array_column($rubric_data['criteria'], 'id')) . ") in your response.";
        
        return $prompt;
    }
    
    /**
     * Process AI response and extract grading data
     * 
     * @param array $ai_response
     * @param string $method
     * @param object $assignment
     * @param object $submission
     * @return array
     */
    public function process_ai_response($ai_response, $method, $assignment, $submission) {
        switch ($method) {
            case 'simple':
                return $this->process_simple_response($ai_response, $assignment);
            case 'rubric':
                return $this->process_rubric_response($ai_response, $assignment);
            case 'ranged_rubric':
                return $this->process_ranged_rubric_response($ai_response, $assignment);
            default:
                throw new \Exception('Unknown grading method');
        }
    }

    /**
     * Format feedback text with HTML formatting
     */
    private function format_feedback_html($feedback) {
        // Basic HTML formatting
        $formatted = htmlspecialchars($feedback);
        
        // Convert line breaks to <br>
        $formatted = nl2br($formatted);
        
        // Bold important phrases (basic formatting)
        $formatted = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formatted);
        
        return $formatted;
    }
    
    private function process_simple_response($response, $assignment) {
        $score = $response['score'] ?? 0;
        $feedback = $response['feedback'] ?? '';
        
        // FORMAT FEEDBACK WITH HTML
        $formatted_feedback = $this->format_feedback_html($feedback);
        
        if (isset($response['strengths']) && is_array($response['strengths'])) {
            $formatted_feedback .= "<br><br><strong>Strengths:</strong><br>";
            $formatted_feedback .= "<ul>";
            foreach ($response['strengths'] as $strength) {
                $formatted_feedback .= "<li>" . htmlspecialchars($strength) . "</li>";
            }
            $formatted_feedback .= "</ul>";
        }
        
        if (isset($response['improvements']) && is_array($response['improvements'])) {
            $formatted_feedback .= "<br><strong>Areas for Improvement:</strong><br>";
            $formatted_feedback .= "<ul>";
            foreach ($response['improvements'] as $improvement) {
                $formatted_feedback .= "<li>" . htmlspecialchars($improvement) . "</li>";
            }
            $formatted_feedback .= "</ul>";
        }
        
        return [
            'grade' => min($score, $assignment->grade),
            'feedback' => $formatted_feedback
        ];
    }
    
    private function process_rubric_response($response, $assignment) {
        $criteria_scores = $response['criteria_scores'] ?? [];
        $total_score = 0;
        $formatted_scores = [];
        
        foreach ($criteria_scores as $criterion) {
            $score = $criterion['score'] ?? 0;
            $total_score += $score;
            
            // Ensure we have the required fields for standard rubric
            $formatted_scores[] = [
                'criterion_id' => $criterion['criterion_id'] ?? $criterion['criterionid'] ?? 0,
                'level_id' => $criterion['level_id'] ?? $criterion['levelid'] ?? 0,
                'score' => $score,
                'feedback' => $criterion['feedback'] ?? $criterion['justification'] ?? ''
            ];
        }
        
        $feedback = $response['overall_feedback'] ?? '';

        // FORMAT FEEDBACK WITH HTML
        $formatted_feedback = $this->format_feedback_html($feedback);
        
        if (isset($response['strengths']) && is_array($response['strengths'])) {
            $formatted_feedback .= "<br><br><strong>Strengths:</strong><br>";
            $formatted_feedback .= "<ul>";
            foreach ($response['strengths'] as $strength) {
                $formatted_feedback .= "<li>" . htmlspecialchars($strength) . "</li>";
            }
            $formatted_feedback .= "</ul>";
        }
        
        if (isset($response['improvements']) && is_array($response['improvements'])) {
            $formatted_feedback .= "<br><strong>Areas for Improvement:</strong><br>";
            $formatted_feedback .= "<ul>";
            foreach ($response['improvements'] as $improvement) {
                $formatted_feedback .= "<li>" . htmlspecialchars($improvement) . "</li>";
            }
            $formatted_feedback .= "</ul>";
        }
        
        // Add criterion-specific feedback to overall feedback
        if (!empty($formatted_scores)) {
            $feedback .= "\n\n**Criterion Scores:**\n";
            foreach ($formatted_scores as $score) {
                if (!empty($score['feedback'])) {
                    $feedback .= "- " . $score['feedback'] . "\n";
                }
            }
        }
        
        return [
            'grade' => min($total_score, $assignment->grade),
            'feedback' => $formatted_feedback,
            'criteria_scores' => $formatted_scores
        ];
    }

    /**
     * Get the configured AI grader user or current user as fallback
     */
    private function get_ai_grader_user() {
        global $DB, $USER;
        
        $ai_grader_userid = get_config('local_ai_autograder', 'ai_grader_userid');
        if ($ai_grader_userid) {
            $grader_user = $DB->get_record('user', ['id' => $ai_grader_userid]);
            if ($grader_user) {
                return $grader_user;
            }
        }
        return $USER;
    }
    
   /**
     * Process ranged rubric response with ID validation
     */
    private function process_ranged_rubric_response($response, $assignment) {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
        
        $criteria_scores = $response['criteria_scores'] ?? [];
        $total_score = 0;
        $formatted_scores = [];
        
        // Get the actual rubric data to validate criterion IDs
        $parser = new rubric_parser();
        $rubric_data = $parser->get_ranged_rubric_data($assignment);
        $valid_criterion_ids = array_column($rubric_data['criteria'], 'id');
        
        debugging("Valid criterion IDs for this rubric: " . implode(', ', $valid_criterion_ids), DEBUG_DEVELOPER);
        debugging("AI provided " . count($criteria_scores) . " criterion scores", DEBUG_DEVELOPER);
        
        foreach ($criteria_scores as $index => $criterion) {
            $ai_criterion_id = $criterion['criterion_id'] ?? 0;
            $score = (int)round($criterion['score'] ?? 0);
            
            debugging("Processing AI criterion {$ai_criterion_id} with score {$score}", DEBUG_DEVELOPER);
            
            // Validate that the AI provided a valid criterion ID
            if (!in_array($ai_criterion_id, $valid_criterion_ids)) {
                debugging("WARNING: AI provided invalid criterion ID {$ai_criterion_id}. Valid IDs are: " . implode(', ', $valid_criterion_ids), DEBUG_DEVELOPER);
                
                // Try to map by position if AI used sequential IDs
                if ($ai_criterion_id > 0 && $ai_criterion_id <= count($valid_criterion_ids)) {
                    $correct_criterion_id = $valid_criterion_ids[$ai_criterion_id - 1];
                    debugging("Mapping AI criterion {$ai_criterion_id} to database criterion {$correct_criterion_id}", DEBUG_DEVELOPER);
                    $ai_criterion_id = $correct_criterion_id;
                } else {
                    debugging("ERROR: Cannot map criterion ID {$ai_criterion_id}", DEBUG_DEVELOPER);
                    continue;
                }
            }
            
            $total_score += $score;
            
            $formatted_scores[] = [
                'criterion_id' => $ai_criterion_id, // Use the validated/corrected ID
                'score' => $score,
                'feedback' => $criterion['feedback'] ?? ''
            ];
            
            debugging("Formatted score: criterion {$ai_criterion_id}, score {$score}", DEBUG_DEVELOPER);
        }
        
        $feedback = $response['overall_feedback'] ?? '';
        
        // Format feedback with HTML
        $formatted_feedback = $this->format_feedback_html($feedback);
        
        // Add criterion-specific feedback
        if (!empty($formatted_scores)) {
            $formatted_feedback .= "<br><br><strong>Criterion Scores:</strong><br>";
            foreach ($formatted_scores as $score) {
                $formatted_feedback .= sprintf(
                    "- %s (Score: %d)<br>",
                    htmlspecialchars($score['feedback']),
                    $score['score']
                );
            }
        }
        
        debugging("Final total score: {$total_score}, formatted scores count: " . count($formatted_scores), DEBUG_DEVELOPER);
        
        return [
            'grade' => min($total_score, $assignment->grade),
            'feedback' => $formatted_feedback,
            'criteria_scores' => $formatted_scores
        ];
    }

    /**
     * Map AI criterion IDs to database IDs if needed
     */
    private function map_criterion_ids($ai_scores, $assignment) {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
        
        $parser = new rubric_parser();
        $rubric_data = $parser->get_ranged_rubric_data($assignment);
        $db_criteria = $rubric_data['criteria'];
        
        $mapped_scores = [];
        
        foreach ($ai_scores as $index => $score) {
            $ai_criterion_id = $score['criterion_id'] ?? 0;
            
            // If AI used correct database ID, keep it
            $criterion_found = false;
            foreach ($db_criteria as $db_criterion) {
                if ($db_criterion['id'] == $ai_criterion_id) {
                    $mapped_scores[] = $score;
                    $criterion_found = true;
                    break;
                }
            }
            
            // If not found, try to map by position
            if (!$criterion_found && $ai_criterion_id > 0 && $ai_criterion_id <= count($db_criteria)) {
                $db_criterion = $db_criteria[$ai_criterion_id - 1];
                $score['criterion_id'] = $db_criterion['id'];
                $mapped_scores[] = $score;
                debugging("Mapped AI criterion {$ai_criterion_id} to DB criterion {$db_criterion['id']}", DEBUG_DEVELOPER);
            }
        }
        
        return $mapped_scores;
    }

/**
 * FIXED: Save grade with proper leniency and archival handling
 */
public function save_grade($assignment, $submission, $grade, $feedback, $criteria_scores = []) {
    global $DB, $USER, $CFG;
    
    debugging("=== Starting complete grade save with FIXED leniency and archival ===", DEBUG_DEVELOPER);
    debugging("Input grade: $grade, criteria count: " . count($criteria_scores), DEBUG_DEVELOPER);
    
    // Ensure assignment has cmid
    if (!isset($assignment->cmid)) {
        $cm = get_coursemodule_from_instance('assign', $assignment->id);
        if ($cm) {
            $assignment->cmid = $cm->id;
        }
    }
    
    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    require_once($CFG->dirroot . '/grade/grading/lib.php');
    
    $cm = get_coursemodule_from_instance('assign', $assignment->id);
    $context = \context_module::instance($cm->id);
    $assign = new \assign($context, $cm, $assignment->course);
    
    $grading_method = \local_ai_autograder\ai_service::get_grading_method($assignment);
    
    $final_grade = (int)round($grade);
    $grade_id = null;
    
    if ($grading_method === 'ranged_rubric' && !empty($criteria_scores)) {
        // FIXED: For ranged rubric, the criteria_scores should already have leniency applied
        debugging("Using ranged rubric grading workflow with adjusted scores", DEBUG_DEVELOPER);
        
        // Get form data from save_rubric_scores (this should use the already-adjusted scores)
        $form_data = $this->save_rubric_scores($assignment, $submission, $criteria_scores);
        
        if (!$form_data || empty($form_data['criteria'])) {
            throw new \Exception('Failed to prepare rubric form data');
        }
        
        // Get the grading components
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_controller($gradingmanager->get_active_method());
        
        if (!$controller) {
            throw new \Exception('Could not get grading controller');
        }
        
        // Get or create the grade record FIRST
        $grade_record = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber ?? 0
        ]);
        
        if ($grade_record) {
            $grade_id = $grade_record->id;
            debugging("Using existing grade record: {$grade_id}", DEBUG_DEVELOPER);
        } else {
            // Create initial grade record
            $grade_record = new \stdClass();
            $grade_record->assignment = $assignment->id;
            $grade_record->userid = $submission->userid;
            $grade_record->attemptnumber = $submission->attemptnumber ?? 0;
            $grade_record->timecreated = time();
            $grade_record->timemodified = time();
            $grade_record->grader = $this->get_ai_grader_user()->id;
            $grade_record->grade = -1;
            $grade_id = $DB->insert_record('assign_grades', $grade_record);
            debugging("Created new grade record: {$grade_id}", DEBUG_DEVELOPER);
        }
        
        // FIXED: Implement proper grading instance archival instead of reusing
        $instance = $this->create_or_archive_grading_instance($controller, $grade_id, $this->get_ai_grader_user()->id);
        
        if (!$instance) {
            throw new \Exception('Could not create grading instance');
        }
        
        debugging("Got grading instance: " . $instance->get_id(), DEBUG_DEVELOPER);
        
        // Update the instance with form data (form data contains adjusted scores)
        $instance->update($form_data);
        debugging("Instance updated with adjusted form data", DEBUG_DEVELOPER);
        
        $instance_id = $instance->get_id();
        $definition = $controller->get_definition();
        
        // FIXED: Calculate the grade from the rubric fillings (these should now be adjusted scores)
        $fillings = $DB->get_records('gradingform_rubric_ranges_f', ['instanceid' => $instance_id]);
        
        $total_score = 0;
        foreach ($fillings as $filling) {
            if (isset($definition->rubric_criteria[$filling->criterionid])) {
                $criterion = $definition->rubric_criteria[$filling->criterionid];
                
                if ($criterion['isranged'] && isset($filling->grade)) {
                    // For ranged criteria, use the grade field (already adjusted)
                    $total_score += (float)$filling->grade;
                    debugging("Added adjusted ranged score: {$filling->grade} for criterion {$filling->criterionid}", DEBUG_DEVELOPER);
                } else if (isset($criterion['levels'][$filling->levelid])) {
                    // For non-ranged, use level score (already adjusted)
                    $total_score += (float)$criterion['levels'][$filling->levelid]['score'];
                    debugging("Added adjusted level score: {$criterion['levels'][$filling->levelid]['score']} for criterion {$filling->criterionid}", DEBUG_DEVELOPER);
                }
            }
        }
        
        debugging("Calculated total score from adjusted criteria: {$total_score}", DEBUG_DEVELOPER);
        
        // Update the grading instance with calculated grade and set status to ACTIVE
        $instance_record = new \stdClass();
        $instance_record->id = $instance_id;
        $instance_record->status = self::INSTANCE_STATUS_ACTIVE;
        $instance_record->rawgrade = $total_score;
        $instance_record->timemodified = time();
        $DB->update_record('grading_instances', $instance_record);
        
        debugging("Updated instance status and rawgrade with adjusted total", DEBUG_DEVELOPER);
        
        // Use the calculated grade as final grade (this should match the input grade with leniency)
        $final_grade = (int)round($total_score);
        
        // Update the assign_grades record with the final calculated grade
        $update_record = new \stdClass();
        $update_record->id = $grade_id;
        $update_record->grade = $final_grade;
        $update_record->grader = $this->get_ai_grader_user()->id;
        $update_record->timemodified = time();
        $DB->update_record('assign_grades', $update_record);
        debugging("Updated assign_grades with final adjusted grade: {$final_grade}", DEBUG_DEVELOPER);
        
    } else {
        // For simple grading or regular rubric - grade should already have leniency applied
        debugging("Using simple grading workflow with adjusted grade", DEBUG_DEVELOPER);
        
        // Handle standard rubric if needed
        if ($grading_method === 'rubric' && !empty($criteria_scores)) {
            // FIXED: Pass adjusted criteria scores for standard rubrics
            $this->save_standard_rubric_grades($assignment, $submission, $criteria_scores);
        }
        
        // Update assign_grades table with adjusted grade
        $grade_record = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber ?? 0
        ]);
        
        if ($grade_record) {
            $grade_record->grade = $final_grade; // This is already adjusted
            $grade_record->grader = $this->get_ai_grader_user()->id;
            $grade_record->timemodified = time();
            $DB->update_record('assign_grades', $grade_record);
            $grade_id = $grade_record->id;
            debugging("Updated existing grade record with adjusted grade: {$final_grade}", DEBUG_DEVELOPER);
        } else {
            $grade_record = new \stdClass();
            $grade_record->assignment = $assignment->id;
            $grade_record->userid = $submission->userid;
            $grade_record->attemptnumber = $submission->attemptnumber ?? 0;
            $grade_record->timecreated = time();
            $grade_record->timemodified = time();
            $grade_record->grader = $this->get_ai_grader_user()->id;
            $grade_record->grade = $final_grade; // This is already adjusted
            $grade_id = $DB->insert_record('assign_grades', $grade_record);
            debugging("Created new grade record with adjusted grade: {$final_grade}", DEBUG_DEVELOPER);
        }
    }
    
    // Save feedback
    $this->save_feedback($assignment, $submission, $feedback, $grade_id);
    
    // Update gradebook using Moodle's grade API
    $this->update_gradebook($assignment, $submission, $final_grade);
    
    // Trigger the grade updated event using the assign object
    $user_grade = $assign->get_user_grade($submission->userid, true, $submission->attemptnumber ?? 0);
    if ($user_grade) {
        debugging("Grade successfully synchronized via assign API", DEBUG_DEVELOPER);
    }
    
    debugging("=== Complete grade save finished with final grade: {$final_grade} ===", DEBUG_DEVELOPER);
    return true;
}

/**
 * FIXED: Create new grading instance with proper archival of existing instances
 * This follows Moodle's standard grading versioning approach
 * 
 * @param object $controller Grading controller
 * @param int $grade_id The grade record ID (used as itemid)
 * @param int $rater_id The user ID of the grader
 * @return object|false The grading instance or false on failure
 */
private function create_or_archive_grading_instance($controller, $grade_id, $rater_id) {
    global $DB, $USER;
    
    debugging("Creating new grading instance with proper archival (grade_id: $grade_id, rater_id: $rater_id)", DEBUG_DEVELOPER);
    
    $definition = $controller->get_definition();
    
    // FIXED: Find any existing ACTIVE instances for this grade record and rater
    // The itemid should be the grade record ID, not submission ID
    $existing_instances = $DB->get_records('grading_instances', [
        'definitionid' => $definition->id,
        //'raterid' => $rater_id,
        'itemid' => $grade_id,  // FIXED: Use grade_id as itemid
        'status' => self::INSTANCE_STATUS_ACTIVE
    ]);
    
    debugging("Found " . count($existing_instances) . " existing active instances for grade_id $grade_id", DEBUG_DEVELOPER);
    
    // FIXED: Archive existing active instances using correct ARCHIVE status
    foreach ($existing_instances as $existing_instance) {
        debugging("Archiving existing grading instance: {$existing_instance->id} (setting to ARCHIVE status)", DEBUG_DEVELOPER);
        
        // Mark the existing instance as ARCHIVED to preserve history
        $archive_record = new \stdClass();
        $archive_record->id = $existing_instance->id;
        $archive_record->status = self::INSTANCE_STATUS_ARCHIVE; // FIXED: Use correct ARCHIVE status (3)
        $archive_record->timemodified = time();
        $DB->update_record('grading_instances', $archive_record);
        
        debugging("Archived instance {$existing_instance->id} with ARCHIVE status - preserved in history", DEBUG_DEVELOPER);
    }
    
    // FIXED: Create new instance with grade_id as itemid (proper Moodle convention)
    debugging("Creating new active grading instance with grade_id $grade_id as itemid", DEBUG_DEVELOPER);
    $new_instance = $controller->get_or_create_instance(0, $rater_id, $grade_id);  // FIXED: Use grade_id as itemid
    
    if ($new_instance) {
        // Set the new instance as INCOMPLETE initially (will be set to ACTIVE after successful update)
        $new_record = new \stdClass();
        $new_record->id = $new_instance->get_id();
        $new_record->status = self::INSTANCE_STATUS_INCOMPLETE; // Start as INCOMPLETE
        $new_record->timemodified = time();
        $DB->update_record('grading_instances', $new_record);
        
        debugging("Created new grading instance: {$new_instance->get_id()} with INCOMPLETE status, itemid: $grade_id", DEBUG_DEVELOPER);
    } else {
        debugging("ERROR: Failed to create new grading instance for grade_id $grade_id", DEBUG_DEVELOPER);
    }
    
    return $new_instance;
}

    /**
     * Save standard rubric grades (for regular rubrics, not ranged)
     * FIXED: Handle adjusted scores properly
     */
    private function save_standard_rubric_grades($assignment, $submission, $criteria_scores) {
        global $DB, $USER, $CFG;
        
        debugging("Saving standard rubric scores (adjusted)", DEBUG_DEVELOPER);
        
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        
        $context = \context_module::instance($assignment->cmid);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_controller($gradingmanager->get_active_method());
        
        if (!$controller) {
            throw new \Exception('Could not get grading controller for standard rubric');
        }
        
        // Get or create grade record
        $grade_record = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber ?? 0
        ]);
        
        if (!$grade_record) {
            $grade_record = new \stdClass();
            $grade_record->assignment = $assignment->id;
            $grade_record->userid = $submission->userid;
            $grade_record->attemptnumber = $submission->attemptnumber ?? 0;
            $grade_record->timecreated = time();
            $grade_record->timemodified = time();
            $grade_record->grader = $this->get_ai_grader_user()->id;
            $grade_record->grade = -1;
            $grade_id = $DB->insert_record('assign_grades', $grade_record);
        } else {
            $grade_id = $grade_record->id;
        }
        
        // FIXED: Use archival approach for standard rubrics too
        $instance = $this->create_or_archive_grading_instance($controller, $grade_id, $this->get_ai_grader_user()->id);
        
        // Prepare form data for standard rubric with adjusted scores
        $form_data = ['criteria' => []];
        
        foreach ($criteria_scores as $score) {
            $criterion_id = $score['criterion_id'];
            $level_id = $score['level_id'] ?? null;
            $feedback = $score['feedback'] ?? '';
            
            $form_data['criteria'][$criterion_id] = [
                'levelid' => $level_id,
                'remark' => $feedback
            ];
        }
        
        // Update the instance
        $instance->update($form_data);
        
        // Set instance as active
        $instance_record = new \stdClass();
        $instance_record->id = $instance->get_id();
        $instance_record->status = self::INSTANCE_STATUS_ACTIVE;
        $instance_record->timemodified = time();
        $DB->update_record('grading_instances', $instance_record);
        
        debugging("Standard rubric scores saved with archival", DEBUG_DEVELOPER);
    }

    /**
     * Update the gradebook with the new grade
     */
    private function update_gradebook($assignment, $submission, $grade) {
        global $CFG, $DB;
        
        debugging("Updating gradebook with adjusted grade: $grade", DEBUG_DEVELOPER);
        
        require_once($CFG->libdir . '/gradelib.php');
        
        try {
            // Method 1: Use the grade_update function (recommended)
            $grade_item = grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'iteminstance' => $assignment->id
            ]);
            
            if (!$grade_item) {
                debugging("Could not find grade item for assignment {$assignment->id}", DEBUG_DEVELOPER);
                return false;
            }
            
            debugging("Found grade item: id={$grade_item->id}, grademax={$grade_item->grademax}", DEBUG_DEVELOPER);
            
            // Scale the grade to match the grade item's scale
            $scaled_grade = ($grade / $assignment->grade) * $grade_item->grademax;
            debugging("Scaled adjusted grade: $scaled_grade (from $grade)", DEBUG_DEVELOPER);
            
            // Prepare grade update data
            $grades = new \stdClass();
            $grades->userid = $submission->userid;
            $grades->rawgrade = $scaled_grade;
            $grades->feedback = null; // Feedback is handled separately
            $grades->feedbackformat = FORMAT_HTML;
            $grades->dategraded = time();
            
            // Use grade_update - this handles history automatically
            $result = grade_update(
                'mod/assign',           // source
                $assignment->course,    // courseid  
                'mod',                  // itemtype
                'assign',               // itemmodule
                $assignment->id,        // iteminstance
                0,                      // itemnumber
                $grades,                // grades object
                null                    // itemdetails
            );
            
            if ($result == GRADE_UPDATE_OK) {
                debugging("Adjusted grade updated successfully via grade_update", DEBUG_DEVELOPER);
                
                // Verify the grade was saved
                $saved_grade = $DB->get_record('grade_grades', [
                    'itemid' => $grade_item->id,
                    'userid' => $submission->userid
                ]);
                
                if ($saved_grade) {
                    debugging("Verified adjusted grade in gradebook: finalgrade={$saved_grade->finalgrade}, rawgrade={$saved_grade->rawgrade}", DEBUG_DEVELOPER);
                }
                
                return true;
            } else {
                debugging("grade_update failed with result: $result", DEBUG_DEVELOPER);
                return false;
            }
            
        } catch (\Exception $e) {
            debugging("Exception in gradebook update: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Save feedback to assignfeedback_comments table
     */
    private function save_feedback($assignment, $submission, $feedback, $grade_id = null) {
        global $DB;
        
        debugging("Saving feedback for assignment {$assignment->id}, submission {$submission->id}", DEBUG_DEVELOPER);
        
        // If grade_id not provided, try to get it
        if (!$grade_id) {
            $grade_record = $DB->get_record('assign_grades', [
                'assignment' => $assignment->id,
                'userid' => $submission->userid,
                'attemptnumber' => $submission->attemptnumber ?? 0
            ]);
            
            if ($grade_record) {
                $grade_id = $grade_record->id;
            } else {
                debugging("No grade record found for feedback", DEBUG_DEVELOPER);
                return false;
            }
        }
        
        // Check if feedback record already exists
        $feedback_record = $DB->get_record('assignfeedback_comments', [
            'assignment' => $assignment->id,
            'grade' => $grade_id
        ]);
        
        $feedback_text = "🤖 <strong>AI-Generated Feedback</strong><br><br>" . $feedback;
        
        if ($feedback_record && isset($feedback_record->id)) {
            // Update existing record - ensure ID is present
            $update_record = new \stdClass();
            $update_record->id = $feedback_record->id;
            $update_record->commenttext = $feedback_text;
            $update_record->commentformat = FORMAT_HTML;
            
            $result = $DB->update_record('assignfeedback_comments', $update_record);
            debugging("Updated existing feedback record {$feedback_record->id}", DEBUG_DEVELOPER);
        } else {
            // Insert new record
            $insert_record = new \stdClass();
            $insert_record->assignment = $assignment->id;
            $insert_record->grade = $grade_id;
            $insert_record->commenttext = $feedback_text;
            $insert_record->commentformat = FORMAT_HTML;
            
            $result = $DB->insert_record('assignfeedback_comments', $insert_record);
            debugging("Inserted new feedback record", DEBUG_DEVELOPER);
        }
        
        if ($result) {
            debugging("Feedback saved successfully", DEBUG_DEVELOPER);
        } else {
            debugging("Failed to save feedback", DEBUG_DEVELOPER);
        }
        
        return $result;
    }
    
    /**
     * Save rubric scores to database
     * FIXED: Ensure adjusted scores are used in form data
     * 
     * @param object $assignment Assignment record
     * @param object $submission Submission record
     * @param array $criteria_scores Array of criterion scores from AI (already adjusted)
     */
    private function save_rubric_scores($assignment, $submission, $criteria_scores) {
        global $DB, $USER, $CFG;
        
        if (empty($criteria_scores)) {
            return null;
        }
        
        debugging("Saving rubric scores (these should be adjusted scores)", DEBUG_DEVELOPER);
        
        $grading_method = \local_ai_autograder\ai_service::get_grading_method($assignment);
        if ($grading_method !== 'ranged_rubric') {
            return null;
        }
        
        // Get the grading components properly
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/grade/grading/form/rubric_ranges/lib.php');
        
        $context = \context_module::instance($assignment->cmid);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_controller($gradingmanager->get_active_method());
        
        if (!$controller) {
            throw new \Exception('Could not get grading controller');
        }
        
        debugging("Got controller for ranged rubric", DEBUG_DEVELOPER);
        
        $definition = $controller->get_definition();
        $rubric_criteria = $definition->rubric_criteria;
        
        // Prepare form data in the correct format for ranged rubrics
        $form_data = ['criteria' => []];
        
        foreach ($criteria_scores as $score) {
            $criterion_id = $score['criterion_id'];
            $assigned_score = (int)round($score['score']); // FIXED: This should be the adjusted score
            $feedback = $score['feedback'] ?? '';
            
            debugging("Processing adjusted criterion {$criterion_id} with adjusted score {$assigned_score}", DEBUG_DEVELOPER);
            
            // Validate criterion exists
            if (!isset($rubric_criteria[$criterion_id])) {
                debugging("Criterion {$criterion_id} not found in definition", DEBUG_DEVELOPER);
                continue;
            }
            
            $criterion = $rubric_criteria[$criterion_id];
            
            // Find the appropriate level ID based on the ADJUSTED score
            $selected_level_id = null;
            
            if ($criterion['isranged']) {
                // For ranged criteria, find which level range the ADJUSTED score falls into
                $levels = $criterion['levels'];
                
                // Get levels sorted by score (descending for proper range checking)
                $sorted_levels = $levels;
                uasort($sorted_levels, function($a, $b) {
                    return $b['score'] - $a['score'];
                });
                
                $level_array = array_values($sorted_levels);
                
                // Find the appropriate level based on score ranges
                for ($i = 0; $i < count($level_array); $i++) {
                    $current_level = $level_array[$i];
                    $max_score = $current_level['score'];
                    
                    // Determine minimum score for this level
                    $min_score = 0;
                    if ($i < count($level_array) - 1) {
                        $min_score = $level_array[$i + 1]['score'] + 1;
                    }
                    
                    if ($assigned_score >= $min_score && $assigned_score <= $max_score) {
                        $selected_level_id = $current_level['id'];
                        break;
                    }
                }
                
                // If no level found, use the lowest level
                if (!$selected_level_id && !empty($level_array)) {
                    $selected_level_id = end($level_array)['id'];
                }
            } else {
                // For non-ranged criteria, find exact score match
                foreach ($criterion['levels'] as $level) {
                    if ($level['score'] == $assigned_score) {
                        $selected_level_id = $level['id'];
                        break;
                    }
                }
            }
            
            if (!$selected_level_id) {
                debugging("No valid level found for criterion {$criterion_id} with adjusted score {$assigned_score}", DEBUG_DEVELOPER);
                continue;
            }
            
            // Build form data for this criterion with ADJUSTED scores
            $form_data['criteria'][$criterion_id] = [
                'levelid' => $selected_level_id,
                'remark' => $feedback
            ];
            
            // FIXED: Add adjusted score for ranged criteria
            if ($criterion['isranged']) {
                $form_data['criteria'][$criterion_id]['grade'] = $assigned_score; // Adjusted score
            }
            
            debugging("Set criterion {$criterion_id}: levelid={$selected_level_id}, adjusted_grade={$assigned_score}", DEBUG_DEVELOPER);
        }
        
        debugging("Form data prepared with " . count($form_data['criteria']) . " criteria (adjusted scores)", DEBUG_DEVELOPER);
        
        // Validate that we have all required criteria
        if (count($form_data['criteria']) != count($rubric_criteria)) {
            debugging("WARNING: Form data has " . count($form_data['criteria']) . " criteria but rubric has " . count($rubric_criteria), DEBUG_DEVELOPER);
        }

        // Return the form data to be used by save_grade (contains adjusted scores)
        return $form_data;
    }

    private function get_reference_document($file_id) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($file_id);
        
        if ($file) {
            return $file->get_content();
        }
        
        return '';
    }
}