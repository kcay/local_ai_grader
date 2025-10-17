<?php
// ==============================================================================
// FILE: classes/grading_handler.php
// ==============================================================================
namespace local_ai_autograder;

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
            $prompt .= "**{$criterion['description']}**\n";
            
            if (!empty($criterion['ranges'])) {
                // Find the max score for this criterion
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
        $prompt .= "4. You can assign ANY integer score within the range (e.g., 13, 16, 18, etc.).  No decimals\n";
        $prompt .= "5. Higher scores within a range indicate better performance in that level\n";
        $prompt .= "6. Provide specific feedback explaining your score choice\n\n";
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "criteria_scores": [' . "\n";
        $prompt .= '    {' . "\n";
        $prompt .= '      "criterion_id": <criterion_id_number>,' . "\n";
        $prompt .= '      "score": <integer_score_within_range>,' . "\n";
        $prompt .= '      "feedback": "<specific_feedback_for_this_criterion>"' . "\n";
        $prompt .= '    },' . "\n";
        $prompt .= "    ...\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_feedback": "<comprehensive_summary_feedback>"' . "\n";
        $prompt .= "}\n\n";
        
        $prompt .= "**Example:** If a criterion has ranges like:\n";
        $prompt .= "- Excellent (18 - 20 points)\n";
        $prompt .= "- Good (15 - 17 points)\n";
        $prompt .= "- Satisfactory (10 - 14 points)\n";
        $prompt .= "- Needs Improvement (0 - 9 points)\n\n";
        
        $prompt .= "You might assign:\n";
        $prompt .= "- 19 for work that is excellent but not quite perfect\n";
        $prompt .= "- 16 for work that is clearly good but has minor issues\n";
        $prompt .= "- 12 for work that meets basic requirements but lacks depth\n\n";
        
        $prompt .= "Be precise and fair in your scoring. The integer precision allows for nuanced evaluation.";
        
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
    
    private function process_ranged_rubric_response($response, $assignment) {
        $criteria_scores = $response['criteria_scores'] ?? [];
        $total_score = 0;
        $formatted_scores = [];
        
        foreach ($criteria_scores as $criterion) {
            $score = $criterion['score'] ?? 0;
            $total_score += $score;
            
            // Ensure we have the required fields for ranged rubric
            $formatted_scores[] = [
                'criterion_id' => $criterion['criterion_id'] ?? $criterion['criterionid'] ?? 0,
                'score' => $score,
                'feedback' => $criterion['feedback'] ?? $criterion['detailed_feedback'] ?? ''
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
                $feedback .= sprintf(
                    "- %s (Score: %.2f)\n",
                    $score['feedback'],
                    $score['score']
                );
            }
        }
        
        return [
            'grade' => min($total_score, $assignment->grade),
            'feedback' => $formatted_feedback,
            'criteria_scores' => $formatted_scores
        ];
    }
    
    /**
     * Save grade to database
     */
   /* public function save_grade($assignment, $submission, $grade, $feedback, $criteria_scores = []) {
        global $DB, $USER;
        
        // Ensure assignment has cmid
        if (!isset($assignment->cmid)) {
            $cm = get_coursemodule_from_instance('assign', $assignment->id);
            if ($cm) {
                $assignment->cmid = $cm->id;
            }
        }
        
        // Save main grade
        $grade_record = $DB->get_record('assign_grades', [
            'assignment' => $assignment->id,
            'userid' => $submission->userid,
            'attemptnumber' => $submission->attemptnumber
        ]);
        
        if ($grade_record) {
            $grade_record->grade = $grade;
            $grade_record->grader = $USER->id;
            $grade_record->timemodified = time();
            $DB->update_record('assign_grades', $grade_record);
        } else {
            $grade_record = new \stdClass();
            $grade_record->assignment = $assignment->id;
            $grade_record->userid = $submission->userid;
            $grade_record->attemptnumber = $submission->attemptnumber;
            $grade_record->timecreated = time();
            $grade_record->timemodified = time();
            $grade_record->grader = $USER->id;
            $grade_record->grade = $grade;
            $DB->insert_record('assign_grades', $grade_record);
        }
        
        // Save feedback
        $this->save_feedback($assignment, $submission, $feedback);
        
        // Save rubric scores if applicable
        if (!empty($criteria_scores)) {
            $this->save_rubric_scores($assignment, $submission, $criteria_scores);
        }
    }*/
    
   /**
 * Save grade to database with proper feedback handling
 */
public function save_grade($assignment, $submission, $grade, $feedback, $criteria_scores = []) {
    global $DB, $USER;
    
    debugging("Saving grade: Assignment ID {$assignment->id}, Submission ID {$submission->id}, Grade: $grade", DEBUG_DEVELOPER);
    
    // Ensure assignment has cmid
    if (!isset($assignment->cmid)) {
        $cm = get_coursemodule_from_instance('assign', $assignment->id);
        if ($cm) {
            $assignment->cmid = $cm->id;
        }
    }
    
    // Save main grade first
    $grade_record = $DB->get_record('assign_grades', [
        'assignment' => $assignment->id,
        'userid' => $submission->userid,
        'attemptnumber' => $submission->attemptnumber ?? 0
    ]);
    
    if ($grade_record) {
        debugging("Updating existing grade record ID: {$grade_record->id}", DEBUG_DEVELOPER);
        $grade_record->grade = $grade;
        $grade_record->grader = $USER->id;
        $grade_record->timemodified = time();
        $DB->update_record('assign_grades', $grade_record);
        $grade_id = $grade_record->id;
    } else {
        debugging("Creating new grade record", DEBUG_DEVELOPER);
        $grade_record = new \stdClass();
        $grade_record->assignment = $assignment->id;
        $grade_record->userid = $submission->userid;
        $grade_record->attemptnumber = $submission->attemptnumber ?? 0;
        $grade_record->timecreated = time();
        $grade_record->timemodified = time();
        $grade_record->grader = $USER->id;
        $grade_record->grade = $grade;
        $grade_id = $DB->insert_record('assign_grades', $grade_record);
    }
    
    // Save feedback using the correct grade ID
    $this->save_feedback($assignment, $submission, $feedback, $grade_id);
    
    // Save rubric scores if applicable
    if (!empty($criteria_scores)) {
        $this->save_rubric_scores($assignment, $submission, $criteria_scores);
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
        global $DB;
        
        if (empty($criteria_scores)) {
            return;
        }
        
        // Get grading method
        $grading_method = \local_ai_autograder\ai_service::get_grading_method($assignment);
        
        // Get or create grading instance
        $instance = $this->get_or_create_grading_instance($assignment, $submission);
        
        if (!$instance) {
            throw new \Exception('Could not create grading instance');
        }
        
        // Save based on grading method
        if ($grading_method == 'ranged_rubric') {
            $this->save_ranged_rubric_scores($instance->id, $criteria_scores);
        } else {
            $this->save_standard_rubric_scores($instance->id, $criteria_scores);
        }
    }
    
    /**
     * Get or create a grading instance
     * 
     * @param object $assignment
     * @param object $submission
     * @return object|false Grading instance
     */
    private function get_or_create_grading_instance($assignment, $submission) {
        global $DB, $USER;
        
        // Get grading area
        $context = \context_module::instance($assignment->cmid);
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $context->id,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
            return false;
        }
        
        // Get grading definition
        $definition = $DB->get_record('grading_definitions', [
            'areaid' => $grading_area->id
        ]);
        
        if (!$definition) {
            return false;
        }
        
        // Check if instance already exists
        $instance = $DB->get_record('grading_instances', [
            'definitionid' => $definition->id,
            'itemid' => $submission->id
        ]);
        
        if ($instance) {
            return $instance;
        }
        
        // Create new instance
        $instance = new \stdClass();
        $instance->definitionid = $definition->id;
        $instance->raterid = $USER->id;
        $instance->itemid = $submission->id;
        $instance->rawgrade = null; // Will be calculated
        $instance->status = 0;
        $instance->feedback = null;
        $instance->feedbackformat = 0;
        $instance->timemodified = time();
        
        $instance->id = $DB->insert_record('grading_instances', $instance);
        
        return $instance;
    }
    
   /**
     * Save ranged rubric scores using correct table structure
     * 
     * @param int $instanceid Grading instance ID
     * @param array $criteria_scores Array of criterion scores
     */
    private function save_ranged_rubric_scores($instanceid, $criteria_scores) {
        global $DB;
        
        debugging("Saving ranged rubric scores for instance $instanceid", DEBUG_DEVELOPER);
        debugging("Received " . count($criteria_scores) . " criterion scores", DEBUG_DEVELOPER);
        
        // Delete existing fillings for this instance
        $deleted = $DB->delete_records('gradingform_rubric_ranges_f', ['instanceid' => $instanceid]);
        debugging("Deleted $deleted existing filling records", DEBUG_DEVELOPER);

        // Validate that all criterion IDs exist in the current rubric definition
        $valid_criterion_ids = array_keys($this->get_rubric_definition_criteria($instanceid));
        debugging("Valid criterion IDs for this rubric: " . implode(', ', $valid_criterion_ids), DEBUG_DEVELOPER);
        
        foreach ($criteria_scores as $criterion_score) {

            $criterion_id = $criterion_score['criterion_id'];
            
            // VALIDATE CRITERION ID
            if (!in_array($criterion_id, $valid_criterion_ids)) {
                debugging("ERROR: Invalid criterion ID $criterion_id. Skipping.", DEBUG_DEVELOPER);
                continue;
            }

            if (!isset($criterion_score['criterion_id']) || !isset($criterion_score['score'])) {
                debugging("Skipping criterion score due to missing criterion_id or score", DEBUG_DEVELOPER);
                continue;
            }
            
            $assigned_grade = (float)$criterion_score['score'];
            $feedback = $criterion_score['feedback'] ?? '';
            
            debugging("Processing criterion $criterion_id with assigned score $assigned_grade", DEBUG_DEVELOPER);
            
            // Get all levels for this criterion - FIXED: using correct column 'score'
            $levels = $DB->get_records('gradingform_rubric_ranges_l', 
                ['criterionid' => $criterion_id], 
                'score DESC'); // FIXED: Order by 'score' not 'scoremin'
            
            if (empty($levels)) {
                debugging("ERROR: No levels found for criterion $criterion_id", DEBUG_DEVELOPER);
                continue;
            }
            
            debugging("Found " . count($levels) . " levels for criterion $criterion_id", DEBUG_DEVELOPER);
            
            // Find the appropriate level based on score
            $selected_level_id = null;
            $level_array = array_values($levels);
            
            for ($i = 0; $i < count($level_array); $i++) {
                $current_level = $level_array[$i];
                $next_level = isset($level_array[$i + 1]) ? $level_array[$i + 1] : null;
                
                // Calculate the range for this level
                $max_score = (float)$current_level->score;
                $min_score = $next_level ? ((float)$next_level->score + 0.01) : 0.0;
                
                debugging("Checking level {$current_level->id}: range {$min_score} - {$max_score} (assigned: {$assigned_grade})", DEBUG_DEVELOPER);
                
                // Check if the assigned grade falls within this level's range
                if ($assigned_grade <= $max_score && $assigned_grade >= $min_score) {
                    $selected_level_id = $current_level->id;
                    debugging("✅ Selected level {$selected_level_id} for score $assigned_grade", DEBUG_DEVELOPER);
                    break;
                }
            }
            
            // If no level found, handle edge cases
            if (!$selected_level_id) {
                // Score might be higher than highest level - use highest level
                if ($assigned_grade > $level_array[0]->score) {
                    $selected_level_id = $level_array[0]->id;
                    debugging("Score $assigned_grade exceeds highest level, using highest level {$selected_level_id}", DEBUG_DEVELOPER);
                } 
                // Score might be lower than lowest level - use lowest level
                else {
                    $lowest_level = end($level_array);
                    $selected_level_id = $lowest_level->id;
                    debugging("Score $assigned_grade below lowest level, using lowest level {$selected_level_id}", DEBUG_DEVELOPER);
                }
            }
            
            if ($selected_level_id) {
                // Create filling record using correct table structure
                $filling = new \stdClass();
                $filling->instanceid = $instanceid;
                $filling->criterionid = $criterion_id;
                $filling->levelid = $selected_level_id;
                $filling->grade = $assigned_grade; // IMPORTANT: This is the actual assigned score
                $filling->remark = $feedback;
                $filling->remarkformat = 0; // Plain text
                
                try {
                    $filling_id = $DB->insert_record('gradingform_rubric_ranges_f', $filling);
                    debugging("✅ Saved ranged rubric filling: ID $filling_id, criterion $criterion_id, level $selected_level_id, grade $assigned_grade", DEBUG_DEVELOPER);
                } catch (\Exception $e) {
                    debugging("❌ ERROR saving filling for criterion $criterion_id: " . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else {
                debugging("❌ ERROR: Could not determine level for criterion $criterion_id with score $assigned_grade", DEBUG_DEVELOPER);
            }
        }
        
        debugging("Completed saving ranged rubric scores for instance $instanceid", DEBUG_DEVELOPER);
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