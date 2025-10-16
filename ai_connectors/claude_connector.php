<?php
// ==============================================================================
// FILE: classes/ai_connectors/claude_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

class claude_connector extends base_connector {
    
    protected function load_config() {
        $this->api_key = get_config('local_ai_autograder', 'claude_api_key');
        $this->endpoint = get_config('local_ai_autograder', 'claude_endpoint') 
            ?: 'https://api.anthropic.com/v1/messages';
        $this->model = get_config('local_ai_autograder', 'claude_model') ?: 'claude-sonnet-4-5-20250929';
        $this->max_tokens = 2000;
        $this->temperature = 0.3;
    }
    
    public function send_request($prompt, $parameters = []) {
        if (!$this->validate_api_key()) {
            return [
                'success' => false,
                'error' => 'Claude API key not configured'
            ];
        }
        
        $payload = [
            'model' => $parameters['model'] ?? $this->model,
            'max_tokens' => $parameters['max_tokens'] ?? $this->max_tokens,
            'temperature' => $parameters['temperature'] ?? $this->temperature,
            'system' => 'You are an expert educational grader. Provide objective, constructive feedback. Always respond with valid JSON in the exact format requested.',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];
        
        $options = [
            'headers' => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->api_key,
                'anthropic-version: 2023-06-01'
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
        if (isset($raw_response['content'][0]['text'])) {
            $content = $raw_response['content'][0]['text'];
            
            // Try to extract JSON if wrapped in markdown code blocks
            if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            $parsed = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $parsed;
            }
            
            return ['content' => $content];
        }
        
        return ['error' => 'Invalid response format'];
    }
}