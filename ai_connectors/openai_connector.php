<?php

// ==============================================================================
// FILE: classes/ai_connectors/openai_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

class openai_connector extends base_connector {
    
    protected function load_config() {
        $this->api_key = get_config('local_ai_autograder', 'openai_api_key');
        $this->endpoint = get_config('local_ai_autograder', 'openai_endpoint') 
            ?: 'https://api.openai.com/v1/chat/completions';
        $this->model = get_config('local_ai_autograder', 'openai_model') ?: 'gpt-4o';
        $this->max_tokens = get_config('local_ai_autograder', 'openai_max_tokens') ?: 2000;
        $this->temperature = get_config('local_ai_autograder', 'openai_temperature') ?: 0.3;
    }
    
    public function send_request($prompt, $parameters = []) {
        if (!$this->validate_api_key()) {
            return [
                'success' => false,
                'error' => 'OpenAI API key not configured'
            ];
        }
        
        $payload = [
            'model' => $parameters['model'] ?? $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert educational grader. Provide objective, constructive feedback. Always respond with valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => $parameters['max_tokens'] ?? $this->max_tokens,
            'temperature' => $parameters['temperature'] ?? $this->temperature,
            'response_format' => ['type' => 'json_object']
        ];
        
        $options = [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key
            ],
            'post_data' => json_encode($payload)
        ];
        
        $result = $this->make_http_request($this->endpoint, $options);
        
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
        if (isset($raw_response['choices'][0]['message']['content'])) {
            $content = $raw_response['choices'][0]['message']['content'];
            $parsed = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $parsed;
            }
            
            return ['content' => $content];
        }
        
        return ['error' => 'Invalid response format'];
    }
}