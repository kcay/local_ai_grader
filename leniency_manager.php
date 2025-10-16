<?php

// ==============================================================================
// FILE: classes/leniency_manager.php
// ==============================================================================
namespace local_ai_autograder;

defined('MOODLE_INTERNAL') || die();

class leniency_manager {
    
    /**
     * Leniency multiplier mappings
     */
    const LENIENCY_MULTIPLIERS = [
        'very_lenient' => 1.10,  // +10%
        'lenient' => 1.05,       // +5%
        'moderate' => 1.00,      // 0%
        'strict' => 0.95,        // -5%
        'very_strict' => 0.90    // -10%
    ];
    
    /**
     * Apply leniency adjustment to score
     * 
     * @param float $raw_score Original AI score
     * @param string $leniency_level Leniency level name
     * @param float $max_grade Maximum possible grade
     * @return float Adjusted score
     */
    public function apply_leniency($raw_score, $leniency_level, $max_grade) {
        $multiplier = $this->get_multiplier($leniency_level);
        
        // Apply multiplier
        $adjusted_score = $raw_score * $multiplier;
        
        // Ensure score doesn't exceed maximum
        $adjusted_score = min($adjusted_score, $max_grade);
        
        // Ensure score doesn't go below 0
        $adjusted_score = max($adjusted_score, 0);
        
        return round($adjusted_score, 2);
    }
    
    /**
     * Get multiplier for leniency level
     * 
     * @param string $leniency_level
     * @return float
     */
    public function get_multiplier($leniency_level) {
        return self::LENIENCY_MULTIPLIERS[$leniency_level] ?? 1.00;
    }
    
    /**
     * Get all leniency levels with descriptions
     * 
     * @return array
     */
    public function get_leniency_levels() {
        return [
            'very_lenient' => [
                'name' => get_string('very_lenient', 'local_ai_autograder'),
                'multiplier' => 1.10,
                'description' => 'Dove: More generous grading (+10%)'
            ],
            'lenient' => [
                'name' => get_string('lenient', 'local_ai_autograder'),
                'multiplier' => 1.05,
                'description' => 'Slightly generous (+5%)'
            ],
            'moderate' => [
                'name' => get_string('moderate', 'local_ai_autograder'),
                'multiplier' => 1.00,
                'description' => 'Neutral/Balanced (0%)'
            ],
            'strict' => [
                'name' => get_string('strict', 'local_ai_autograder'),
                'multiplier' => 0.95,
                'description' => 'Slightly harsh (-5%)'
            ],
            'very_strict' => [
                'name' => get_string('very_strict', 'local_ai_autograder'),
                'multiplier' => 0.90,
                'description' => 'Hawk: Harsh grading (-10%)'
            ]
        ];
    }
    
    /**
     * Calculate percentage adjustment from leniency
     * 
     * @param string $leniency_level
     * @return int Percentage (-10 to +10)
     */
    public function get_percentage_adjustment($leniency_level) {
        $multiplier = $this->get_multiplier($leniency_level);
        return round(($multiplier - 1.00) * 100);
    }
    
    /**
     * Get leniency level description for display
     * 
     * @param string $leniency_level
     * @return string
     */
    public function get_description($leniency_level) {
        $levels = $this->get_leniency_levels();
        return $levels[$leniency_level]['description'] ?? 'Unknown';
    }
}
