<?php

// ==============================================================================
// FILE: classes/ai_connectors/gemini_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

class gemini_connector extends base_connector {
    
    protected function load_config() {
        $this->api_key = get_config('local_ai_autograder', 'gemini_api_key');
        $this->endpoint = get_config('local_ai_autograder', 'gemini_endpoint') 
            ?: 'https://generativelanguage.googleapis.com/v1beta/models/';
        $this->model = get_config('local_ai_autograder', 'gemini_model') ?: 'gemini-2.0-flash-exp';
        $this->max_tokens = 2000;
        $this->temperature = 0.3;
    }
    
    public function send_request($prompt, $parameters = []) {
        if (!$this->validate_api_key()) {
            return [
                'success' => false,
                'error' => 'Gemini API key not configured'
            ];
        }
        
        $model = $parameters['model'] ?? $this->model;
        $url = rtrim($this->endpoint, '/') . '/' . $model . ':generateContent?key=' . $this->api_key;
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => "You are an expert educational grader. Provide objective, constructive feedback. Respond with valid JSON.\n\n" . $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $parameters['temperature'] ?? $this->temperature,
                'maxOutputTokens' => $parameters['max_tokens'] ?? $this->max_tokens,
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $options = [
            'headers' => [
                'Content-Type: application/json'
            ],
            'post_data' => json_encode($payload)
        ];
        
        $result = $this->make_http_request($url, $options);
        
        if ($result['success']) {
            $data = json_decode($result['data'], true);
            return [
                'success' => true,
                'data' => $this->parse_response($data)
            ];
        }
        
        return $result;
    }
    
    public function parse_response($raw_response) {
        if (isset($raw_response['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $raw_response['candidates'][0]['content']['parts'][0]['text'];
            $parsed = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $parsed;
            }
            
            return ['content' => $content];
        }
        
        return ['error' => 'Invalid response format'];
    }
}