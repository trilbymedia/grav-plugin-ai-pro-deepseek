<?php
namespace Grav\Plugin\AIProDeepSeek;

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
        // For third-party providers, we need to get the config from our own plugin namespace
        if ($config === null) {
            $grav = \Grav\Common\Grav::instance();
            $config = $grav['config']->get('plugins.ai-pro-deepseek');
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
        return $this->config->get('endpoint', 'https://api.deepseek.com/v1') . '/chat/completions';
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
        $temperature = $request->getTemperature() ?? $this->config->get('temperature', 0.7);
        $maxTokens = $request->getMaxTokens() ?? $this->config->get('max_tokens', 4096);
        
        $apiRequest = [
            'model' => $model,
            'messages' => $request->getMessagesWithContext(),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
        
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
            $apiRequest['messages'][0]['content'] = 
                "Language: " . $request->getOption('code_language') . "\n\n" . 
                $apiRequest['messages'][0]['content'];
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
        try {
            // Try a minimal request to validate API key
            $request = new Request();
            $request->addUserMessage('Hello');
            $request->setMaxTokens(5);
            
            $this->chat($request);
            return true;
            
        } catch (\Exception $e) {
            // Check if it's an auth error vs other errors
            if (strpos($e->getMessage(), 'Invalid API key') !== false ||
                strpos($e->getMessage(), '401') !== false) {
                return false;
            }
            // Other errors might still mean valid credentials
            return true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getModels(): array
    {
        // Return cached models if available
        if (!empty($this->models)) {
            return $this->models;
        }
        
        // For better performance, we'll use a static list of models
        // DeepSeek's model list doesn't change frequently
        $this->models = $this->getDefaultModels();
        
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
                'name' => 'Deepseek Reasoner',
                'context_window' => 16384,
                'description' => ''
            ],
        ];
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
                // Return default options if not configured
                $models = [
                    'deepseek-chat' => 'DeepSeek Chat',
                    'deepseek-coder' => 'DeepSeek Coder'
                ];
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
            
            // Cache the models for 24 hours
            $cache->save($cacheKey, $options, 86400);
            $modelsCache = $options;
            
            return $options;
            
        } catch (\Exception $e) {
            // Return defaults on error
            return [
                'deepseek-chat' => 'DeepSeek Chat',
                'deepseek-coder' => 'DeepSeek Coder'
            ];
        }
    }
}