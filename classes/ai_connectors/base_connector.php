<?php
// ==============================================================================
// FILE: classes/ai_connectors/base_connector.php
// ==============================================================================
namespace local_ai_autograder\ai_connectors;

defined('MOODLE_INTERNAL') || die();

abstract class base_connector {
    protected $api_key;
    protected $endpoint;
    protected $model;
    protected $max_tokens;
    protected $temperature;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_config();
    }
    
    /**
     * Load configuration from Moodle settings
     */
    abstract protected function load_config();
    
    /**
     * Send request to AI provider
     * 
     * @param string $prompt The prompt to send
     * @param array $parameters Optional parameters
     * @return array Response containing 'success', 'data', and 'error'
     */
    abstract public function send_request($prompt, $parameters = []);
    
    /**
     * Parse AI response into structured format
     * 
     * @param mixed $raw_response Raw response from API
     * @return array Parsed response
     */
    abstract public function parse_response($raw_response);
    
    /**
     * Validate API key is set
     * 
     * @return bool
     */
    public function validate_api_key() {
        return !empty($this->api_key);
    }
    
    /**
     * Get model name
     * 
     * @return string
     */
    public function get_model_name() {
        return $this->model;
    }
    
    /**
     * Make HTTP request with retry logic
     * 
     * @param string $url
     * @param array $options
     * @return array
     */
    protected function make_http_request($url, $options) {
        $max_retries = get_config('local_ai_autograder', 'max_retries') ?: 3;
        $attempt = 0;
        
        while ($attempt < $max_retries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            if (isset($options['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
            }
            
            if (isset($options['post_data'])) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post_data']);
            }
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response !== false && $http_code >= 200 && $http_code < 300) {
                return [
                    'success' => true,
                    'data' => $response,
                    'http_code' => $http_code
                ];
            }
            
            $attempt++;
            if ($attempt < $max_retries) {
                sleep(pow(2, $attempt)); // Exponential backoff
            }
        }
        
        return [
            'success' => false,
            'error' => $error ?: "HTTP $http_code",
            'http_code' => $http_code ?? 0
        ];
    }
}
