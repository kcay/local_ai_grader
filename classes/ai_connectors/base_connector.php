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
    protected function make_http_request(string $url, array $options = []): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // Validate URL.
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'error' => 'Invalid or empty URL',
                'http_code' => 0
            ];
        }

        // Defaults.
        $method = strtoupper($options['method'] ?? (!empty($options['post_data']) ? 'POST' : 'GET'));
        $timeout = (int)($options['timeout'] ?? 60);
        $max_retries = (int)(get_config('local_ai_autograder', 'max_retries') ?? 3);
        $headers = $options['headers'] ?? [];
        $post_data = $options['post_data'] ?? null;

        $attempt = 0;
        $last_error = null;
        $response = null;
        $http_code = 0;

        while ($attempt < $max_retries) {
            try {
                // Create new HTTP client for each attempt
                $curl = new \curl();
                
                // Set timeout
                $curl->setopt(['CURLOPT_TIMEOUT' => $timeout]);
                
                // Set headers - convert associative array to curl format
                if (!empty($headers)) {
                    $curl_headers = [];
                    foreach ($headers as $key => $value) {
                        $curl_headers[] = $key . ': ' . $value;
                    }
                    $curl->setHeader($curl_headers);
                }

                // Make the request based on method
                if ($method === 'POST') {
                    $response = $curl->post($url, $post_data);
                } else if ($method === 'GET') {
                    $response = $curl->get($url);
                } else if ($method === 'PUT') {
                    $response = $curl->put($url, $post_data);
                } else if ($method === 'DELETE') {
                    $response = $curl->delete($url);
                } else {
                    return [
                        'success' => false,
                        'error' => "Unsupported HTTP method: $method",
                        'http_code' => 0
                    ];
                }

                // Get HTTP response info
                $info = $curl->get_info();
                $http_code = $info['http_code'] ?? 0;
                $curl_error = $curl->get_errno();
                
                // Check for cURL errors first (network/timeout issues)
                if ($curl_error !== 0) {
                    $curl_error_msg = $curl->error;
                    $last_error = "cURL error ($curl_error): $curl_error_msg";
                    
                    // These are network/timeout errors that should be retried
                    debugging("Network/timeout error on attempt " . ($attempt + 1) . ": $last_error", DEBUG_DEVELOPER);
                }
                // Check for successful HTTP response
                else if ($http_code >= 200 && $http_code < 300 && $response !== false) {
                    // Try to decode JSON if possible
                    $decoded = json_decode($response, true);
                    return [
                        'success' => true,
                        'data' => $decoded !== null ? $decoded : $response,
                        'http_code' => $http_code
                    ];
                }
                // 4xx client errors - don't retry these
                else if ($http_code >= 400 && $http_code < 500) {
                    debugging("Client error ($http_code) - not retrying: $response", DEBUG_DEVELOPER);
                    return [
                        'success' => false,
                        'error' => "Client error ($http_code): " . $response,
                        'http_code' => $http_code
                    ];
                }
                // 5xx server errors - these can be retried
                else if ($http_code >= 500) {
                    $last_error = "Server error ($http_code): " . $response;
                    debugging("Server error on attempt " . ($attempt + 1) . ": $last_error", DEBUG_DEVELOPER);
                }
                // Other unexpected responses
                else {
                    $last_error = "Unexpected HTTP response code: $http_code";
                    debugging("Unexpected response on attempt " . ($attempt + 1) . ": $last_error", DEBUG_DEVELOPER);
                }

            } catch (\moodle_exception $e) {
                $last_error = 'Moodle exception: ' . $e->getMessage();
                debugging("Moodle exception on attempt " . ($attempt + 1) . ": $last_error", DEBUG_DEVELOPER);
            } catch (\Exception $e) {
                $last_error = 'HTTP request failed: ' . $e->getMessage();
                debugging("Exception on attempt " . ($attempt + 1) . ": $last_error", DEBUG_DEVELOPER);
            }

            $attempt++;
            
            // Only retry if we haven't reached max attempts and it's a retryable error
            if ($attempt < $max_retries && $this->should_retry_error($http_code, $last_error)) {
                $backoff = min(pow(2, $attempt), 30); // Cap backoff at 30 seconds
                debugging("Retrying attempt " . ($attempt + 1) . "/$max_retries after {$backoff}s due to: $last_error", DEBUG_DEVELOPER);
                sleep($backoff);
            } else {
                break;
            }
        }

        debugging("HTTP request to $url failed after $max_retries attempts. Final error: $last_error", DEBUG_DEVELOPER);

        return [
            'success' => false,
            'error' => $last_error ?? 'Unknown error',
            'http_code' => $http_code
        ];
    }

    /**
     * Determine if an error should trigger a retry
     * 
     * @param int $http_code
     * @param string $error_message
     * @return bool
     */
    private function should_retry_error(int $http_code, string $error_message): bool {
        // Don't retry 4xx client errors
        if ($http_code >= 400 && $http_code < 500) {
            return false;
        }
        
        // Retry on:
        // - Network/timeout errors (cURL errors)
        // - 5xx server errors
        // - No HTTP code (network issues)
        // - Rate limiting (429)
        if ($http_code === 0 || 
            $http_code >= 500 || 
            $http_code === 429 ||
            strpos($error_message, 'cURL error') !== false ||
            strpos($error_message, 'timeout') !== false ||
            strpos($error_message, 'network') !== false) {
            return true;
        }
        
        return false;
    }

}
