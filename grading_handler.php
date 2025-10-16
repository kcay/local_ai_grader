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
        $assignment = $data['assignment'];
        $config = $data['config'];
        $submission = $data['submission_content'];
        
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
        
        $prompt .= "**Student Submission:**\n";
        $prompt .= $submission . "\n\n";
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "score": <numeric score out of ' . $assignment->grade . ">,\n";
        $prompt .= '  "feedback": "<detailed constructive feedback>",'. "\n";
        $prompt .= '  "strengths": ["strength 1", "strength 2"],'. "\n";
        $prompt .= '  "improvements": ["area 1", "area 2"]'. "\n";
        $prompt .= "}\n\n";
        $prompt .= "Provide objective, constructive feedback. Be specific about strengths and areas for improvement.";
        
        return $prompt;
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
     * Build ranged rubric grading prompt
     */
    private function build_ranged_rubric_prompt($data) {
        global $CFG;
        require_once($CFG->dirroot . '/local/ai_autograder/classes/rubric_parser.php');
        
        $assignment = $data['assignment'];
        $submission = $data['submission_content'];
        $config = $data['config'];
        
        $parser = new rubric_parser();
        $rubric_data = $parser->get_ranged_rubric_data($assignment);
        
        if (!$rubric_data) {
            throw new \Exception('Could not load ranged rubric data');
        }
        
        $prompt = "You are grading a student submission using a ranged rubric with specific score ranges.\n\n";
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
        
        foreach ($rubric_data['criteria'] as $criterion) {
            $prompt .= "**{$criterion['description']}**\n";
            foreach ($criterion['ranges'] as $range) {
                $prompt .= "- {$range['definition']}: {$range['min_score']} to {$range['max_score']} points\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "**Student Submission:**\n";
        $prompt .= $submission . "\n\n";
        
        $prompt .= "**Required JSON Response Format:**\n";
        $prompt .= "{\n";
        $prompt .= '  "criteria_scores": [' . "\n";
        $prompt .= '    {"criterion_id": <id>, "score": <specific numeric score within range>, "feedback": "<specific feedback>"},' . "\n";
        $prompt .= "    ...\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_feedback": "<summary>"' . "\n";
        $prompt .= "}\n\n";
        $prompt .= "Assign a specific numeric score within each criterion's range based on the quality of work.";
        
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
    
    private function process_simple_response($response, $assignment) {
        $score = $response['score'] ?? 0;
        $feedback = $response['feedback'] ?? '';
        
        if (isset($response['strengths']) && is_array($response['strengths'])) {
            $feedback .= "\n\n**Strengths:**\n- " . implode("\n- ", $response['strengths']);
        }
        
        if (isset($response['improvements']) && is_array($response['improvements'])) {
            $feedback .= "\n\n**Areas for Improvement:**\n- " . implode("\n- ", $response['improvements']);
        }
        
        return [
            'grade' => min($score, $assignment->grade),
            'feedback' => $feedback
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
            'feedback' => $feedback,
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
            'feedback' => $feedback,
            'criteria_scores' => $formatted_scores
        ];
    }
    
    /**
     * Save grade to database
     */
    public function save_grade($assignment, $submission, $grade, $feedback, $criteria_scores = []) {
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
    }
    
    private function save_feedback($assignment, $submission, $feedback) {
        global $DB;
        
        $feedback_record = $DB->get_record('assignfeedback_comments', [
            'assignment' => $assignment->id,
            'grade' => $submission->id
        ]);
        
        $feedback_text = "🤖 **AI-Generated Feedback**\n\n" . $feedback;
        
        if ($feedback_record) {
            $feedback_record->commenttext = $feedback_text;
            $DB->update_record('assignfeedback_comments', $feedback_record);
        } else {
            $feedback_record = new \stdClass();
            $feedback_record->assignment = $assignment->id;
            $feedback_record->grade = $submission->id;
            $feedback_record->commenttext = $feedback_text;
            $feedback_record->commentformat = FORMAT_MARKDOWN;
            $DB->insert_record('assignfeedback_comments', $feedback_record);
        }
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
     * Save ranged rubric scores
     * 
     * @param int $instanceid Grading instance ID
     * @param array $criteria_scores Array of criterion scores
     */
    private function save_ranged_rubric_scores($instanceid, $criteria_scores) {
        global $DB;
        
        // Delete existing fillings for this instance
        $DB->delete_records('gradingform_rubric_ranges_f', ['instanceid' => $instanceid]);
        
        foreach ($criteria_scores as $criterion_score) {
            if (!isset($criterion_score['criterion_id']) || !isset($criterion_score['score'])) {
                continue;
            }
            
            $criterion_id = $criterion_score['criterion_id'];
            $grade = $criterion_score['score'];
            $feedback = $criterion_score['feedback'] ?? '';
            
            // Get the criterion to find associated levels
            $criterion = $DB->get_record('gradingform_rubric_ranges_c', ['id' => $criterion_id]);
            
            if (!$criterion) {
                continue;
            }
            
            // Get all levels for this criterion to determine which level the score falls into
            $levels = $DB->get_records('gradingform_rubric_ranges_l', 
                ['criterionid' => $criterion_id], 
                'score DESC');
            
            if (empty($levels)) {
                continue;
            }
            
            // Find the appropriate level based on score
            $selected_level_id = null;
            foreach ($levels as $level) {
                if ($grade >= $level->score) {
                    $selected_level_id = $level->id;
                    break;
                }
            }
            
            // If no level found, use the lowest level
            if (!$selected_level_id) {
                $lowest_level = end($levels);
                $selected_level_id = $lowest_level->id;
            }
            
            // Create filling record
            $filling = new \stdClass();
            $filling->instanceid = $instanceid;
            $filling->criterionid = $criterion_id;
            $filling->levelid = $selected_level_id;
            $filling->grade = $grade;
            $filling->remark = $feedback;
            $filling->remarkformat = 0; // Plain text
            
            $DB->insert_record('gradingform_rubric_ranges_f', $filling);
        }
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