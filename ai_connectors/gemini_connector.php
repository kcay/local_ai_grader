<?php
// ==============================================================================
// FILE: classes/ai_connectors/gemini_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

class gemini_connector extends base_connector {
    
    protected function load_config() {
        $this->api_key = trim(get_config('local_ai_autograder', 'gemini_api_key'));
        $this->endpoint = trim(get_config('local_ai_autograder', 'gemini_endpoint')) 
            ?: 'https://generativelanguage.googleapis.com/v1beta/models/';
        $this->model = trim(get_config('local_ai_autograder', 'gemini_model')) ?: 'gemini-2.0-flash-exp';
        $this->max_tokens = (int)(get_config('local_ai_autograder', 'gemini_max_tokens') ?: 2000);
        $this->temperature = (float)(get_config('local_ai_autograder', 'gemini_temperature') ?: 0.3);
    }
    
    public function send_request($prompt, $parameters = []) {
        if (!$this->validate_api_key()) {
            return [
                'success' => false,
                'error' => 'Gemini API key not configured'
            ];
        }

        // Debug: Log the prompt length
        debugging('AI Prompt length: ' . strlen($prompt) . ' characters', DEBUG_DEVELOPER);

        // Truncate prompt if too long (Gemini has limits)
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

        // Build URL with API key
        $url = rtrim($this->endpoint, '/') . '/' . $model . ':generateContent?key=' . $this->api_key;

        // Prepare request payload
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => "You are an expert educational grader. Provide objective, constructive feedback. Always respond with valid JSON.\n\n" . $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $max_tokens,
                'responseMimeType' => 'application/json'
            ]
        ];

        debugging('Gemini Endpoint: ' . $url, DEBUG_DEVELOPER);
        debugging('Model: ' . $model . ', max_tokens: ' . $max_tokens . ', temperature: ' . $temperature, DEBUG_DEVELOPER);

        // Prepare HTTP options - use associative array for headers
        $options = [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'post_data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ];

        // Make API call
        $result = $this->make_http_request($url, $options);
        
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
        debugging('Gemini request failed: ' . ($result['error'] ?? 'Unknown error'), DEBUG_DEVELOPER);
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