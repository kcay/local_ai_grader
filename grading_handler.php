<?php
// ==============================================================================
// FILE: classes/grading_handler.php
// ==============================================================================
namespace local_ai_autograder;

use grade_item;

defined('MOODLE_INTERNAL') || die();

class grading_handler {
    
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
     * Save grade with proper controller integration
     */
    
    public function save_grade($assignment, $submission, $grade, $feedback, $criteria_scores = []) {
        global $DB, $USER, $CFG;
        
        debugging("=== Starting complete grade save ===", DEBUG_DEVELOPER);
        
        // Ensure assignment has cmid
        if (!isset($assignment->cmid)) {
            $cm = get_coursemodule_from_instance('assign', $assignment->id);
            if ($cm) {
                $assignment->cmid = $cm->id;
            }
        }
        
        $final_grade = (int)round($grade);
        
        // Step 1: Save rubric scores (this handles instance status management)
        $calculated_grade = $final_grade;
        if (!empty($criteria_scores)) {
            $calculated_grade = $this->save_rubric_scores($assignment, $submission, $criteria_scores);
            if ($calculated_grade !== null) {
                $final_grade = (int)round($calculated_grade);
            }
            debugging("Used calculated grade from rubric: $final_grade", DEBUG_DEVELOPER);
        }
        
        // Step 2: Update assign_grades table
        $grade_record = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber ?? 0
        ]);
        
        if ($grade_record) {
            $grade_record->grade = $final_grade;
            $grade_record->grader = $USER->id;
            $grade_record->timemodified = time();
            $DB->update_record('assign_grades', $grade_record);
            $grade_id = $grade_record->id;
        } else {
            $grade_record = new \stdClass();
            $grade_record->assignment = $assignment->id;
            $grade_record->userid = $submission->userid;
            $grade_record->attemptnumber = $submission->attemptnumber ?? 0;
            $grade_record->timecreated = time();
            $grade_record->timemodified = time();
            $grade_record->grader = $USER->id;
            $grade_record->grade = $final_grade;
            $grade_id = $DB->insert_record('assign_grades', $grade_record);
        }
        
        // Step 3: Save feedback
        $this->save_feedback($assignment, $submission, $feedback, $grade_id);
        
        // Step 4: Update gradebook (this updates mdl_grade_grades and history)
        $this->update_gradebook($assignment, $submission, $final_grade);
        
        // Step 5: Trigger assignment grade update for full consistency
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $cm = get_coursemodule_from_instance('assign', $assignment->id);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $assignment->course);
        
        $grade_obj = (object)[
            'userid' => $submission->userid,
            'grade' => $final_grade,
            'attemptnumber' => $submission->attemptnumber ?? 0
        ];
        
        $assign->update_grade($grade_obj);
        debugging("Triggered assign->update_grade for full consistency", DEBUG_DEVELOPER);
        
        debugging("=== Complete grade save finished ===", DEBUG_DEVELOPER);
        return true;
    }


    /**
     * Update the gradebook with the new grade
     */
 
    private function update_gradebook($assignment, $submission, $grade) {
        global $CFG, $DB;
        
        debugging("Updating gradebook with grade: $grade", DEBUG_DEVELOPER);
        
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
            debugging("Scaled grade: $scaled_grade (from $grade)", DEBUG_DEVELOPER);
            
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
                debugging("Grade updated successfully via grade_update", DEBUG_DEVELOPER);
                
                // Verify the grade was saved
                $saved_grade = $DB->get_record('grade_grades', [
                    'itemid' => $grade_item->id,
                    'userid' => $submission->userid
                ]);
                
                if ($saved_grade) {
                    debugging("Verified grade in gradebook: finalgrade={$saved_grade->finalgrade}, rawgrade={$saved_grade->rawgrade}", DEBUG_DEVELOPER);
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
        
        if ($feedback_record) {
            $feedback_record->commenttext = $feedback_text;
            $feedback_record->commentformat = FORMAT_HTML; // CHANGED from 1 to FORMAT_HTML
            $result = $DB->update_record('assignfeedback_comments', $feedback_record);
        } else {
            $feedback_record = new \stdClass();
            $feedback_record->assignment = $assignment->id;
            $feedback_record->grade = $grade_id;
            $feedback_record->commenttext = $feedback_text;
            $feedback_record->commentformat = FORMAT_HTML; // CHANGED from 1 to FORMAT_HTML
            $result = $DB->insert_record('assignfeedback_comments', $feedback_record);
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
     * 
     * @param object $assignment Assignment record
     * @param object $submission Submission record
     * @param array $criteria_scores Array of criterion scores from AI
     */
    

    private function save_rubric_scores($assignment, $submission, $criteria_scores) {
        global $DB, $USER, $CFG;
        
        if (empty($criteria_scores)) {
            return;
        }
        
        debugging("Saving rubric scores with status management", DEBUG_DEVELOPER);
        
        $grading_method = \local_ai_autograder\ai_service::get_grading_method($assignment);
        if ($grading_method !== 'ranged_rubric') {
            return;
        }
        
        // Get the controller
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $context = \context_module::instance($assignment->cmid);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_controller($gradingmanager->get_active_method());
        
        if (!$controller) {
            throw new \Exception('Could not get grading controller');
        }
        
        // CRITICAL: Mark all existing instances for this submission as NEEDUPDATE
        $definition_id = $controller->get_definition()->id;
        $existing_instances = $DB->get_records('grading_instances', [
            'definitionid' => $definition_id,
            'itemid' => $submission->id,
            'status' => 1 // Currently active instances
        ]);
        
        foreach ($existing_instances as $old_instance) {
            $DB->update_record('grading_instances', (object)[
                'id' => $old_instance->id,
                'status' => 2, // NEEDUPDATE - this marks old instances as superseded
                'timemodified' => time()
            ]);
            debugging("Marked old instance {$old_instance->id} as NEEDUPDATE", DEBUG_DEVELOPER);
        }
        
        // Create new instance through controller
        $instance = $controller->get_or_create_instance(0, $USER->id, $submission->id);
        
        if (!$instance) {
            throw new \Exception('Could not create controller instance');
        }
        
        debugging("Created new instance: " . $instance->get_id(), DEBUG_DEVELOPER);
        
        // Prepare form data
        $form_data = ['criteria' => []];
        
        foreach ($criteria_scores as $score) {
            $criterion_id = $score['criterion_id'];
            $assigned_score = (int)round($score['score']);
            $feedback = $score['feedback'] ?? '';
            
            // Get appropriate level
            $levels = $DB->get_records('gradingform_rubric_ranges_l', 
                ['criterionid' => $criterion_id], 'score DESC');
            
            if (!empty($levels)) {
                $selected_level_id = null;
                foreach ($levels as $level) {
                    if ($assigned_score >= $level->score) {
                        $selected_level_id = $level->id;
                        break;
                    }
                }
                
                if (!$selected_level_id) {
                    $lowest_level = end($levels);
                    $selected_level_id = $lowest_level->id;
                }
                
                $form_data['criteria'][$criterion_id] = [
                    'levelid' => $selected_level_id,
                    'grade' => $assigned_score,
                    'remark' => $feedback
                ];
            }
        }
        
        // Update the instance
        $instance->update($form_data);
        
        // Calculate final grade
        $calculated_grade = $instance->get_grade();
        debugging("Calculated grade from instance: $calculated_grade", DEBUG_DEVELOPER);
        
        // CRITICAL: Set the new instance as ACTIVE
        $DB->update_record('grading_instances', (object)[
            'id' => $instance->get_id(),
            'status' => 1, // ACTIVE - this makes the frontend show this instance
            'rawgrade' => $calculated_grade,
            'timemodified' => time()
        ]);
        
        debugging("Set new instance {$instance->get_id()} as ACTIVE", DEBUG_DEVELOPER);
        
        return $calculated_grade;
    }

    /**
     * Create grading instance directly via database (creates versioning like standard behavior)
     */
    private function create_grading_instance_direct($assignment, $submission) {
        global $DB, $USER;
        
        debugging("Creating grading instance directly", DEBUG_DEVELOPER);
        
        // Get grading definition
        $context = \context_module::instance($assignment->cmid);
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $context->id,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
            debugging("No grading area found", DEBUG_DEVELOPER);
            return false;
        }
        
        $definition = $DB->get_record('grading_definitions', [
            'areaid' => $grading_area->id
        ]);
        
        if (!$definition) {
            debugging("No grading definition found", DEBUG_DEVELOPER);
            return false;
        }
        
        debugging("Found definition: {$definition->id}, method: {$definition->method}", DEBUG_DEVELOPER);
        
        // Create new instance (this mimics the standard versioning behavior)
        // Each grading attempt gets a new instance, which is what creates the versioning
        $instance = new \stdClass();
        $instance->definitionid = $definition->id;
        $instance->raterid = $USER->id;
        $instance->itemid = $submission->id;
        $instance->rawgrade = null; // Will be set after calculating total
        $instance->status = 0; // INCOMPLETE initially
        $instance->feedback = null;
        $instance->feedbackformat = 0;
        $instance->timemodified = time();
        
        try {
            $instance->id = $DB->insert_record('grading_instances', $instance);
            debugging("Successfully created grading instance: {$instance->id}", DEBUG_DEVELOPER);
            return $instance;
        } catch (\Exception $e) {
            debugging("Failed to create grading instance: " . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
    
    
  

    /**
     * Get valid criterion IDs for the rubric instance
     */
    private function get_rubric_definition_criteria($instanceid) {
        global $DB;
        
        // Get the grading instance to find the definition
        $instance = $DB->get_record('grading_instances', ['id' => $instanceid]);
        if (!$instance) {
            return [];
        }
        
        // Get criteria for this definition
        $criteria = $DB->get_records('gradingform_rubric_ranges_c', 
            ['definitionid' => $instance->definitionid], 
            'sortorder ASC');
        
        return $criteria;
    }
    
    /**
     * Save standard rubric scores
     * 
     * @param int $instanceid Grading instance ID
     * @param array $criteria_scores Array of criterion scores
     */
    private function save_standard_rubric_scores($instanceid, $criteria_scores) {
        global $DB;
        
        // Delete existing fillings for this instance
        $DB->delete_records('gradingform_rubric_fillings', ['instanceid' => $instanceid]);
        
        foreach ($criteria_scores as $criterion_score) {
            if (!isset($criterion_score['criterion_id']) || !isset($criterion_score['level_id'])) {
                continue;
            }
            
            $criterion_id = $criterion_score['criterion_id'];
            $level_id = $criterion_score['level_id'];
            $feedback = $criterion_score['feedback'] ?? '';
            
            // Verify the level exists and belongs to this criterion
            $level = $DB->get_record('gradingform_rubric_levels', [
                'id' => $level_id,
                'criterionid' => $criterion_id
            ]);
            
            if (!$level) {
                continue;
            }
            
            // Create filling record
            $filling = new \stdClass();
            $filling->instanceid = $instanceid;
            $filling->criterionid = $criterion_id;
            $filling->levelid = $level_id;
            $filling->remark = $feedback;
            $filling->remarkformat = 0; // Plain text
            
            $DB->insert_record('gradingform_rubric_fillings', $filling);
        }
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