<?php
// ==============================================================================
// FILE: classes/rubric_parser.php - COMPLETE UPDATED VERSION
// ==============================================================================
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class rubric_parser {
    
    /**
     * Get rubric data for an assignment
     * 
     * @param object $assignment
     * @return array|false Rubric data or false
     */
    public function get_rubric_data($assignment) {
        global $DB;
        
        debugging("Getting standard rubric data for assignment {$assignment->id}", DEBUG_DEVELOPER);
        
        // Get grading definition
        $grading_def = $this->get_grading_definition($assignment);
        
        if (!$grading_def || $grading_def->method != 'rubric') {
            debugging("No rubric grading definition found", DEBUG_DEVELOPER);
            return false;
        }
        
        // Get criteria
        $criteria = $DB->get_records('gradingform_rubric_criteria', 
            ['definitionid' => $grading_def->id], 
            'sortorder ASC');
        
        $rubric_data = [
            'definition_id' => $grading_def->id,
            'criteria' => []
        ];
        
        foreach ($criteria as $criterion) {
            $levels = $DB->get_records('gradingform_rubric_levels',
                ['criterionid' => $criterion->id],
                'score DESC');
            
            $criterion_data = [
                'id' => $criterion->id,
                'description' => $criterion->description,
                'levels' => []
            ];
            
            foreach ($levels as $level) {
                $criterion_data['levels'][] = [
                    'id' => $level->id,
                    'definition' => $level->definition,
                    'score' => $level->score
                ];
            }
            
            $rubric_data['criteria'][] = $criterion_data;
        }
        
        return $rubric_data;
    }
    
    /**
     * Get ranged rubric data with corrected table structure understanding
     * 
     * @param object $assignment
     * @return array|false
     */
    public function get_ranged_rubric_data($assignment) {
        global $DB;
        
        debugging("Getting ranged rubric data for assignment {$assignment->id}", DEBUG_DEVELOPER);
        
        // Check if ranged rubric tables exist
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('gradingform_rubric_ranges_c')) {
            debugging("Ranged rubric tables don't exist, falling back to regular rubric", DEBUG_DEVELOPER);
            return $this->convert_rubric_to_ranged($assignment);
        }
        
        $grading_def = $this->get_grading_definition($assignment);
        
        if (!$grading_def) {
            debugging("No grading definition found", DEBUG_DEVELOPER);
            return false;
        }
        
        debugging("Found grading definition ID: {$grading_def->id}, method: {$grading_def->method}", DEBUG_DEVELOPER);
        
        // Get ranged criteria
        $criteria = $DB->get_records('gradingform_rubric_ranges_c',
            ['definitionid' => $grading_def->id],
            'sortorder ASC');
        
        if (empty($criteria)) {
            debugging("No ranged criteria found for definition {$grading_def->id}, falling back to regular rubric", DEBUG_DEVELOPER);
            return $this->convert_rubric_to_ranged($assignment);
        }
        
        debugging("Found " . count($criteria) . " ranged criteria", DEBUG_DEVELOPER);
        
        $rubric_data = [
            'definition_id' => $grading_def->id,
            'criteria' => []
        ];
        
        foreach ($criteria as $criterion) {
            debugging("Processing criterion {$criterion->id}: {$criterion->description}", DEBUG_DEVELOPER);
            
            // Get levels for this criterion - FIXED: using correct column name 'score'
            $levels = $DB->get_records('gradingform_rubric_ranges_l',
                ['criterionid' => $criterion->id],
                'score DESC'); // FIXED: Use 'score' not 'scoremin'
            
            $criterion_data = [
                'id' => $criterion->id,
                'description' => $criterion->description,
                'ranges' => []
            ];
            
            if (!empty($levels)) {
                debugging("Found " . count($levels) . " levels for criterion {$criterion->id}", DEBUG_DEVELOPER);
                
                // Convert levels to ranges
                $level_array = array_values($levels);
                for ($i = 0; $i < count($level_array); $i++) {
                    $current_level = $level_array[$i];
                    $next_level = isset($level_array[$i + 1]) ? $level_array[$i + 1] : null;
                    
                    // For ranged rubrics, create ranges between level scores
                    // The range is from the next level's score + 0.01 to current level's score
                    $min_score = $next_level ? ($next_level->score + 0.01) : 0;
                    $max_score = $current_level->score;
                    
                    $criterion_data['ranges'][] = [
                        'id' => $current_level->id,
                        'definition' => $current_level->definition,
                        'min_score' => round($min_score, 2),
                        'max_score' => round($max_score, 2),
                        'level_score' => $current_level->score // Keep the actual level score for reference
                    ];
                    
                    debugging("Created range: {$min_score} - {$max_score} (level ID: {$current_level->id}, score: {$current_level->score})", DEBUG_DEVELOPER);
                }
            } else {
                debugging("No levels found for criterion {$criterion->id}", DEBUG_DEVELOPER);
            }
            
            $rubric_data['criteria'][] = $criterion_data;
        }
        
        debugging("Ranged rubric data prepared with " . count($rubric_data['criteria']) . " criteria", DEBUG_DEVELOPER);
        return $rubric_data;
    }
    
    /**
     * Convert regular rubric to ranged format
     * 
     * @param object $assignment
     * @return array|false
     */
    private function convert_rubric_to_ranged($assignment) {
        debugging("Converting standard rubric to ranged format", DEBUG_DEVELOPER);
        
        $rubric_data = $this->get_rubric_data($assignment);
        
        if (!$rubric_data) {
            return false;
        }
        
        // Convert discrete levels to ranges
        foreach ($rubric_data['criteria'] as &$criterion) {
            $ranges = [];
            
            for ($i = 0; $i < count($criterion['levels']); $i++) {
                $level = $criterion['levels'][$i];
                $next_level = $criterion['levels'][$i + 1] ?? null;
                
                $ranges[] = [
                    'id' => $level['id'],
                    'definition' => $level['definition'],
                    'min_score' => $next_level ? ($next_level['score'] + 0.01) : 0,
                    'max_score' => $level['score'],
                    'level_score' => $level['score']
                ];
            }
            
            $criterion['ranges'] = $ranges;
            unset($criterion['levels']);
        }
        
        debugging("Successfully converted standard rubric to ranged format", DEBUG_DEVELOPER);
        return $rubric_data;
    }
    
    /**
     * Get grading definition for assignment
     * 
     * @param object $assignment
     * @return object|false
     */
    private function get_grading_definition($assignment) {
        global $DB;
        
        // Ensure we have context ID
        if (!isset($assignment->contextid)) {
            $cm = get_coursemodule_from_instance('assign', $assignment->id);
            if ($cm) {
                $context = \context_module::instance($cm->id);
                $assignment->contextid = $context->id;
            }
        }
        
        if (!$assignment->contextid) {
            debugging("No context ID available for assignment {$assignment->id}", DEBUG_DEVELOPER);
            return false;
        }
        
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $assignment->contextid,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
            debugging("No grading area found for context {$assignment->contextid}", DEBUG_DEVELOPER);
            return false;
        }
        
        return $DB->get_record('grading_definitions', [
            'areaid' => $grading_area->id
        ]);
    }
    
    /**
     * Format rubric for AI prompt display
     * 
     * @param array $rubric_data
     * @return string Formatted rubric text
     */
    public function format_for_prompt($rubric_data) {
        $output = '';
        
        foreach ($rubric_data['criteria'] as $criterion) {
            $output .= "**{$criterion['description']}**\n";
            
            if (isset($criterion['levels'])) {
                // Standard rubric
                foreach ($criterion['levels'] as $level) {
                    $output .= "- {$level['definition']} ({$level['score']} points)\n";
                }
            } elseif (isset($criterion['ranges'])) {
                // Ranged rubric
                foreach ($criterion['ranges'] as $range) {
                    $output .= "- {$range['definition']}: {$range['min_score']}-{$range['max_score']} points\n";
                }
            }
            
            $output .= "\n";
        }
        
        return $output;
    }
}