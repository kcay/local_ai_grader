<?php

// ==============================================================================
// FILE: classes/ai_connectors/openai_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

class openai_connector extends base_connector {
    
    protected function load_config() {
        $this->api_key = trim(get_config('local_ai_autograder', 'openai_api_key'));
        $this->endpoint = trim(get_config('local_ai_autograder', 'openai_endpoint')) 
            ?: 'https://api.openai.com/v1/chat/completions';
        $this->model = trim(get_config('local_ai_autograder', 'openai_model')) ?: 'gpt-4o';
        $this->max_tokens = (int)(get_config('local_ai_autograder', 'openai_max_tokens') ?: 2000);
        $this->temperature = (float)(get_config('local_ai_autograder', 'openai_temperature') ?: 0.3);
    }
    
   public function send_request($prompt, $parameters = []) {
        if (!$this->validate_api_key()) {
            return [
                'success' => false,
                'error' => 'OpenAI API key not configured'
            ];
        }

        // Debug: Log the prompt length
        debugging('AI Prompt length: ' . strlen($prompt) . ' characters', DEBUG_DEVELOPER);

        // Truncate prompt if too long (most APIs have limits)
        $max_prompt_length = 100000; // Adjust based on provider
        if (strlen($prompt) > $max_prompt_length) {
            debugging('Prompt too long, truncating...', DEBUG_DEVELOPER);
            $prompt = substr($prompt, 0, $max_prompt_length) . "\n\n[Content truncated due to length]";
        }

        // Ensure numeric parameters are correctly typed
        $max_tokens = isset($parameters['max_tokens']) 
            ? (int)$parameters['max_tokens'] 
            : $this->max_tokens;

        $temperature = isset($parameters['temperature']) 
            ? (float)$parameters['temperature'] 
            : $this->temperature;

        $model = isset($parameters['model']) 
            ? trim($parameters['model']) 
            : $this->model;

        // Prepare request payload
        $payload = [
            'model' => $model,
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
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object']
        ];

        debugging('OpenAI Endpoint: ' . $this->endpoint, DEBUG_DEVELOPER);
        debugging('Model: ' . $model . ', max_tokens: ' . $max_tokens . ', temperature: ' . $temperature, DEBUG_DEVELOPER);

        // Prepare HTTP options
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'post_data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ];

        // Make API call
        $result = $this->make_http_request($this->endpoint, $options);
        
        if ($result['success']) {
            // The make_http_request method already decoded the JSON
            // So $result['data'] is already an array, not a JSON string
            $data = $result['data'];
            
            // Handle case where data might still be a string (fallback)
            if (is_string($data)) {
                $data = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'success' => false,
                        'error' => 'Failed to parse JSON response: ' . json_last_error_msg()
                    ];
                }
            }
            
            return [
                'success' => true,
                'data' => $this->parse_response($data)
            ];
        }
        
        // Log and return failure
        debugging('OpenAI request failed: ' . ($result['error'] ?? 'Unknown error'), DEBUG_DEVELOPER);
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
