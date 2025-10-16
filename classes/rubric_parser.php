<?php
// ==============================================================================
// FILE: classes/rubric_parser.php
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
        
        // Get grading definition
        $grading_def = $this->get_grading_definition($assignment);
        
        if (!$grading_def || $grading_def->method != 'rubric') {
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
     * Get ranged rubric data
     * 
     * @param object $assignment
     * @return array|false
     */
    public function get_ranged_rubric_data($assignment) {
        global $DB;
        
        // Check if ranged rubric tables exist
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('gradingform_rubric_ranges_c')) {
            // Fall back to regular rubric
            return $this->convert_rubric_to_ranged($assignment);
        }
        
        $grading_def = $this->get_grading_definition($assignment);
        
        if (!$grading_def) {
            return false;
        }
        
        // Get ranged criteria
        $criteria = $DB->get_records('gradingform_rubric_ranges_c',
            ['definitionid' => $grading_def->id],
            'sortorder ASC');
        
        $rubric_data = [
            'definition_id' => $grading_def->id,
            'criteria' => []
        ];
        
        foreach ($criteria as $criterion) {
            $ranges = $DB->get_records('gradingform_rubric_ranges_l',
                ['criterionid' => $criterion->id],
                'scoremin DESC');
            
            $criterion_data = [
                'id' => $criterion->id,
                'description' => $criterion->description,
                'ranges' => []
            ];
            
            foreach ($ranges as $range) {
                $criterion_data['ranges'][] = [
                    'id' => $range->id,
                    'definition' => $range->definition,
                    'min_score' => $range->scoremin,
                    'max_score' => $range->scoremax
                ];
            }
            
            $rubric_data['criteria'][] = $criterion_data;
        }
        
        return $rubric_data;
    }
    
    /**
     * Convert regular rubric to ranged format
     * 
     * @param object $assignment
     * @return array|false
     */
    private function convert_rubric_to_ranged($assignment) {
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
                    'min_score' => $next_level ? $next_level['score'] + 1 : 0,
                    'max_score' => $level['score']
                ];
            }
            
            $criterion['ranges'] = $ranges;
            unset($criterion['levels']);
        }
        
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
        
        $grading_area = $DB->get_record('grading_areas', [
            'contextid' => $assignment->contextid ?? 0,
            'component' => 'mod_assign',
            'areaname' => 'submissions'
        ]);
        
        if (!$grading_area) {
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
                foreach ($criterion['levels'] as $level) {
                    $output .= "- {$level['definition']} ({$level['score']} points)\n";
                }
            } elseif (isset($criterion['ranges'])) {
                foreach ($criterion['ranges'] as $range) {
                    $output .= "- {$range['definition']}: {$range['min_score']}-{$range['max_score']} points\n";
                }
            }
            
            $output .= "\n";
        }
        
        return $output;
    }
}