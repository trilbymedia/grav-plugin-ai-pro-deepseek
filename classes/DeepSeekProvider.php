<?php
namespace Grav\Plugin\AIProDeepSeek;

// Only define the class if AI Pro is available
if (!class_exists('\Grav\Plugin\AIPro\Providers\AbstractProvider')) {
    // Create a dummy class that does nothing when AI Pro is not available
    class DeepSeekProvider {
        public function __construct($name, $config = null) {}
        public static function getModelOptions(): array { 
            return ['deepseek-chat' => 'DeepSeek Chat (AI Pro plugin required)'];
        }
    }
    return;
}

use Grav\Plugin\AIPro\Providers\AbstractProvider;
use Grav\Plugin\AIPro\Models\Request;
use Grav\Plugin\AIPro\Models\Response;

/**
 * DeepSeek Provider implementation
 * 
 * DeepSeek uses an OpenAI-compatible API, so this implementation
 * is similar to the OpenAI provider with DeepSeek-specific models
 */
class DeepSeekProvider extends AbstractProvider
{
    /**
     * Constructor
     */
    public function __construct(string $name, $config = null)
    {
        $grav = \Grav\Common\Grav::instance();

        // Merge explicit config with the plugin configuration so required keys are always present.
        $pluginConfig = $grav['config']->get('plugins.ai-pro-deepseek');
        if ($pluginConfig instanceof \Grav\Common\Data\Data) {
            $pluginConfig = $pluginConfig->toArray();
        }

        if ($config instanceof \Grav\Common\Data\Data) {
            $config = $config->toArray();
        }

        if ($config === null) {
            $config = is_array($pluginConfig) ? $pluginConfig : [];
        } else {
            $config = (array)$config;
            if (is_array($pluginConfig)) {
                $config = array_replace($pluginConfig, $config);
            }
        }

        parent::__construct($name, $config);
    }
    
    /**
     * {@inheritdoc}
     */
    protected function initialize(): void
    {
        $this->capabilities = [
            'chat' => true,
            'streaming' => true,
            'vision' => false,
            'function_calling' => true,
            'embeddings' => false,
            'translation' => true,
            'code_generation' => true, // DeepSeek specialty
        ];
        
        // Load models dynamically
        $this->models = [];
        
        // Pricing per 1M tokens (DeepSeek is known for competitive pricing)
        $this->pricing = [
            'deepseek-chat' => ['input' => 0.14, 'output' => 0.28],
            'deepseek-reasoner' => ['input' => 0.14, 'output' => 0.28],
            'deepseek-coder' => ['input' => 0.14, 'output' => 0.28],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isConfigured(): bool
    {
        // Use the config object that was passed to the constructor
        return $this->config->get('enabled') && !empty($this->config->get('api_key'));
    }

    /**
     * {@inheritdoc}
     */
    protected function getEndpoint(): string
    {
        $base = rtrim($this->config->get('endpoint', 'https://api.deepseek.com/v1'), '/');
        return $base . '/chat/completions';
    }

    /**
     * {@inheritdoc}
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function buildRequest(Request $request): array
    {
        $model = $request->getModel() ?? $this->config->get('model', 'deepseek-chat');
        $temperature = $request->getTemperature();
        if ($temperature === null) {
            $temperature = $this->config->get('temperature');
        }
        $maxTokens = $request->getMaxTokens() ?? $this->config->get('max_tokens', 4096);
        
        $apiRequest = [
            'model' => $model,
            'messages' => $request->getMessagesWithContext(),
            'max_tokens' => $maxTokens,
        ];
        if ($temperature !== null) {
            $apiRequest['temperature'] = $temperature;
        }

        // Aid troubleshooting by logging the request envelope without sensitive content.
        if (isset($this->grav['log'])) {
            try {
                $this->grav['log']->debug('AI Pro DeepSeek: Prepared request', [
                    'model' => $model,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'stream' => $request->getOption('stream') === true,
                    'message_roles' => array_map(static function ($message) {
                        return $message['role'] ?? 'unknown';
                    }, $apiRequest['messages']),
                ]);
            } catch (\Throwable $e) {
                // Ignore logging failures
            }
        }
        
        // Add optional parameters
        if ($request->getOption('top_p') !== null) {
            $apiRequest['top_p'] = $request->getOption('top_p');
        }
        
        if ($request->getOption('frequency_penalty') !== null) {
            $apiRequest['frequency_penalty'] = $request->getOption('frequency_penalty');
        }
        
        if ($request->getOption('presence_penalty') !== null) {
            $apiRequest['presence_penalty'] = $request->getOption('presence_penalty');
        }
        
        if ($request->getOption('stop') !== null) {
            $apiRequest['stop'] = $request->getOption('stop');
        }
        
        if ($request->getOption('stream') === true) {
            $apiRequest['stream'] = true;
        }
        
        // DeepSeek-specific: Add code-related hints for coder model
        if ($model === 'deepseek-coder' && $request->getOption('code_language')) {
            $language = $request->getOption('code_language');
            foreach ($apiRequest['messages'] as $index => $message) {
                if (($message['role'] ?? '') === 'user' && isset($message['content']) && is_string($message['content'])) {
                    $apiRequest['messages'][$index]['content'] = "Language: {$language}\n\n" . $message['content'];
                    break;
                }
            }
        }

        return $apiRequest;
    }

    /**
     * {@inheritdoc}
     */
    protected function parseResponse(array $response): Response
    {
        // DeepSeek uses OpenAI-compatible response format
        $result = Response::fromApiResponse($response, 'openai');
        
        // Add cost estimation if we have usage data
        if (!empty($result->getUsage())) {
            $usage = $result->getUsage();
            $model = $response['model'] ?? $this->config->get('model');
            
            // DeepSeek returns usage in a different unit (per 1M tokens)
            $cost = $this->estimateCost(
                $usage['prompt_tokens'] ?? 0,
                $usage['completion_tokens'] ?? 0
            );
            
            $usage['cost'] = $cost;
            $result->setUsage($usage);
        }
        
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function streamChat(Request $request, callable $callback): Response
    {
        $request->setOption('stream', true);
        $apiRequest = $this->buildRequest($request);
        
        $client = $this->grav['http_client'];
        
        $options = [
            'headers' => $this->getHeaders(),
            'json' => $apiRequest,
            'timeout' => $this->config->get('timeout', 60),
            'stream' => true,
        ];
        
        $response = new Response();
        $response->setIsStreaming(true);
        
        try {
            $httpResponse = $client->request('POST', $this->getEndpoint(), $options);
            $stream = $httpResponse->toStream();
            
            $buffer = '';
            while (!$stream->eof()) {
                $buffer .= $stream->read(1024);
                
                // Process complete SSE messages
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $message = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    
                    if (strpos($message, 'data: ') === 0) {
                        $data = substr($message, 6);
                        
                        if ($data === '[DONE]') {
                            break 2;
                        }
                        
                        $json = json_decode($data, true);
                        if ($json && isset($json['choices'][0]['delta']['content'])) {
                            $content = $json['choices'][0]['delta']['content'];
                            $response->appendContent($content);
                            $callback($content);
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            throw new \Exception('Streaming error: ' . $e->getMessage());
        }
        
        return $response;
    }

    /**
     * {@inheritdoc}
     * 
     * Override to adjust for DeepSeek's pricing model (per 1M tokens)
     */
    public function estimateCost(int $promptTokens, int $completionTokens): float
    {
        $model = $this->config->get('model', 'deepseek-chat');
        
        if (!isset($this->pricing[$model])) {
            return 0.0;
        }
        
        $pricing = $this->pricing[$model];
        
        // DeepSeek prices are per 1M tokens
        $promptCost = ($promptTokens / 1000000) * $pricing['input'];
        $completionCost = ($completionTokens / 1000000) * $pricing['output'];
        
        return round($promptCost + $completionCost, 6);
    }

    /**
     * {@inheritdoc}
     */
    public function countTokens(string $text): int
    {
        // DeepSeek uses similar tokenization to GPT models
        // This is an approximation
        $charCount = strlen($text);
        $wordCount = str_word_count($text);
        
        // For code, tokens tend to be smaller
        if ($this->config->get('model') === 'deepseek-coder') {
            $charEstimate = $charCount / 3.5;
            $wordEstimate = $wordCount * 1.5;
        } else {
            $charEstimate = $charCount / 4;
            $wordEstimate = $wordCount * 1.3;
        }
        
        return (int)ceil(($charEstimate + $wordEstimate) / 2);
    }

    /**
     * {@inheritdoc}
     */
    public function validateCredentials(): bool
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Provider deepseek is not properly configured');
        }

        try {
            $models = $this->fetchModelsFromApi(true);
            if (!empty($models)) {
                return true;
            }

            // Fallback to a minimal chat request if the models endpoint returns an empty list
            $request = new Request();
            $request->addUserMessage('Hello');
            $request->setMaxTokens(5);
            $this->chat($request);

            return true;

        } catch (\Exception $e) {
            $message = strtolower($e->getMessage());
            if (strpos($message, 'invalid api key') !== false || strpos($message, '401') !== false) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getModels(): array
    {
        if (!empty($this->models)) {
            return $this->models;
        }

        if (!$this->isConfigured()) {
            $this->models = $this->getDefaultModels();
            return $this->models;
        }

        $cacheKey = 'ai-pro-models-deepseek';
        if (isset($this->grav['cache'])) {
            $cached = $this->grav['cache']->fetch($cacheKey);
            if ($cached !== false) {
                $this->models = $cached;
                return $this->models;
            }
        }

        try {
            $models = $this->fetchModelsFromApi();
            if (!empty($models)) {
                $this->models = $models;
                if (isset($this->grav['cache'])) {
                    $this->grav['cache']->save($cacheKey, $this->models, 86400);
                }
                return $this->models;
            }
        } catch (\Exception $e) {
            if (isset($this->grav['log'])) {
                try {
                    $this->grav['log']->error('AI Pro DeepSeek: Failed to fetch models', ['message' => $e->getMessage()]);
                } catch (\Throwable $t) {
                    // ignore logging failures
                }
            }
        }

        $this->models = [];
        return $this->models;
    }
    
    /**
     * Format model name
     */
    protected function formatModelName(string $modelId): string
    {
        $name = str_replace('-', ' ', $modelId);
        $name = ucwords($name);
        return $name;
    }
    
    /**
     * Get default models as fallback
     */
    protected function getDefaultModels(): array
    {
        return [
            [
                'id' => 'deepseek-chat',
                'name' => 'DeepSeek Chat',
                'context_window' => 32768,
                'description' => 'General purpose conversational model'
            ],
            [
                'id' => 'deepseek-reasoner',
                'name' => 'DeepSeek Reasoner',
                'context_window' => 16384,
                'description' => 'Reasoning focused model'
            ],
            [
                'id' => 'deepseek-coder',
                'name' => 'DeepSeek Coder',
                'context_window' => 32768,
                'description' => 'Code generation and refactoring model'
            ],
        ];
    }

    /**
     * Parse OpenAI-compatible models response
     */
    protected function parseModelList(array $data): array
    {
        $out = [];
        foreach (($data['data'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $id = $m['id'] ?? null; if (!$id) continue;
            $name = $m['name'] ?? $this->formatModelName($id);
            $desc = $m['description'] ?? '';
            $ctx = $m['context_window'] ?? ($m['input_token_limit'] ?? 32768);
            $out[] = [ 'id' => $id, 'name' => $name, 'description' => $desc, 'context_window' => $ctx ];
        }
        // sort by name for readability
        usort($out, function($a,$b){ return strcmp($a['name'], $b['name']); });
        return $out;
    }

    /**
     * Fetch models from API with optional strict error handling
     */
    protected function fetchModelsFromApi(bool $strict = false): array
    {
        [$status, $body] = $this->requestModelEndpoint();
        return $this->handleModelResponse($body, $status, $strict);
    }

    /**
     * Execute request to DeepSeek models endpoint
     */
    protected function requestModelEndpoint(): array
    {
        $base = rtrim($this->config->get('endpoint', 'https://api.deepseek.com/v1'), '/');
        $url = $base . '/models';
        $timeout = (int)$this->config->get('timeout', 12);

        $headers = [
            'Authorization' => 'Bearer ' . $this->config->get('api_key'),
            'Accept' => 'application/json',
        ];

        $client = $this->grav['http_client'] ?? null;
        if ($client) {
            try {
                $response = $client->request('GET', $url, [
                    'headers' => $headers,
                    'timeout' => $timeout,
                ]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                return [$status, $body];
            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
                throw new \Exception('Network error: ' . $e->getMessage());
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config->get('api_key'),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error) {
            throw new \Exception('Network error: ' . ($error ?: 'Unable to contact DeepSeek API'));
        }

        return [$status ?: 0, $body ?: ''];
    }

    /**
     * Normalize API response status handling
     */
    protected function handleModelResponse(string $body, int $status, bool $strict): array
    {
        if ($status === 200) {
            $data = json_decode($body, true);
            if (!is_array($data)) {
                if ($strict) {
                    throw new \Exception('Invalid response from DeepSeek models endpoint');
                }
                return [];
            }
            return $this->parseModelList($data);
        }

        $message = $this->extractErrorMessage($body);
        if ($status === 401) {
            $message = 'Invalid API key';
        } elseif ($status === 429) {
            $message = $message ?: 'Rate limit exceeded';
        } elseif ($status >= 500) {
            $message = $message ?: 'DeepSeek API server error';
        } elseif ($status === 0) {
            $message = $message ?: 'Unable to reach DeepSeek API';
        } else {
            $message = $message ?: 'DeepSeek API error (HTTP ' . $status . ')';
        }

        if ($strict) {
            throw new \Exception($message);
        }

        if (isset($this->grav['log'])) {
            try {
                $this->grav['log']->error('AI Pro DeepSeek: Model fetch error', [
                    'status' => $status,
                    'message' => $message,
                ]);
            } catch (\Throwable $e) {
                // ignore logging errors
            }
        }

        return [];
    }

    /**
     * Extract error message from API payload
     */
    protected function extractErrorMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_string($error)) {
                return $error;
            }
            if (is_array($error)) {
                if (!empty($error['message']) && is_string($error['message'])) {
                    return $error['message'];
                }
                if (!empty($error['code']) && is_string($error['code'])) {
                    return $error['code'];
                }
            }
            if (!empty($decoded['message']) && is_string($decoded['message'])) {
                return $decoded['message'];
            }
        }

        $trimmed = trim($body);
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Get code languages supported by DeepSeek Coder
     */
    public function getSupportedLanguages(): array
    {
        return [
            'python', 'javascript', 'typescript', 'java', 'c++', 'c#',
            'go', 'rust', 'php', 'ruby', 'swift', 'kotlin', 'scala',
            'r', 'matlab', 'sql', 'shell', 'powershell', 'dockerfile',
            'yaml', 'json', 'xml', 'html', 'css', 'markdown'
        ];
    }
    
    /**
     * Get model options for blueprints
     */
    public static function getModelOptions(): array
    {
        // Use static cache to avoid multiple calls in same request
        static $modelsCache = null;
        
        if ($modelsCache !== null) {
            return $modelsCache;
        }
        
        try {
            // Check if AI Pro plugin is loaded first
            if (!class_exists('\Grav\Plugin\AIPro\Providers\AbstractProvider')) {
                return [
                    'deepseek-chat' => 'DeepSeek Chat (Enable AI Pro first)',
                    'deepseek-coder' => 'DeepSeek Coder (Enable AI Pro first)'
                ];
            }
            
            $grav = \Grav\Common\Grav::instance();
            
            // Check persistent cache to avoid expensive operations
            $cacheKey = 'ai-pro-form-models-deepseek';
            $cache = $grav['cache'];
            $cachedModels = $cache->fetch($cacheKey);
            
            if ($cachedModels !== false) {
                $modelsCache = $cachedModels;
                return $cachedModels;
            }
            
            // Only check if we're on the plugin config page to avoid unnecessary API calls
            $route = $grav['uri']->route();
            if (strpos($route, '/plugins/ai-pro-deepseek') === false) {
                $models = [
                    'deepseek-chat' => 'DeepSeek Chat',
                    'deepseek-coder' => 'DeepSeek Coder'
                ];
                $modelsCache = $models;
                return $models;
            }
            
            $config = $grav['config']->get('plugins.ai-pro-deepseek');

            if (!$config || !$config['enabled'] || empty($config['api_key'])) {
                $models = self::getDefaultModelOptions();
                $modelsCache = $models;
                return $models;
            }

            // Create provider instance
            $provider = new self('deepseek', new \Grav\Common\Data\Data($config));
            $models = $provider->getModels();

            $options = [];
            foreach ($models as $model) {
                $label = $model['name'];
                if (!empty($model['description'])) {
                    $label .= ' - ' . $model['description'];
                }
                $options[$model['id']] = $label;
            }

            if (empty($options)) {
                $options = self::getDefaultModelOptions();
            }

            // Cache the models for 24 hours
            $cache->save($cacheKey, $options, 86400);
            $modelsCache = $options;

            return $options;
            
        } catch (\Exception $e) {
            // Return defaults on error
            return self::getDefaultModelOptions();
        }
    }

    /**
     * Default blueprint options when API data is unavailable
     */
    protected static function getDefaultModelOptions(): array
    {
        return [
            'deepseek-chat' => 'DeepSeek Chat',
            'deepseek-reasoner' => 'DeepSeek Reasoner',
            'deepseek-coder' => 'DeepSeek Coder',
        ];
    }
}
