<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\AIProDeepSeek\DeepSeekProvider;
use RocketTheme\Toolbox\Event\Event;

/**
 * AI Pro DeepSeek Provider Plugin
 * 
 * This plugin adds DeepSeek LLM support to the AI Pro plugin
 */
class AiProDeepseekPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0]
            ],
            'onAIProvidersRegister' => ['onAIProvidersRegister', 0]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader|null
     */
    public function autoload(): ?ClassLoader
    {
        // Only load our autoloader if AI Pro is enabled
        // This prevents class loading errors when AI Pro is disabled
        $aiProEnabled = $this->grav['config']->get('plugins.ai-pro.enabled');
        
        if (!$aiProEnabled) {
            return null;
        }
        
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Check if AI Pro plugin is enabled
        $aiProEnabled = $this->grav['config']->get('plugins.ai-pro.enabled');
        
        if (!$aiProEnabled) {
            // AI Pro is not enabled, stop processing
            $this->grav['log']->debug('AI Pro DeepSeek: AI Pro plugin is not enabled, DeepSeek provider will not be registered');
            // Remove our event subscriptions to prevent further processing
            $this->disable([
                'onAIProvidersRegister' => ['onAIProvidersRegister', 0]
            ]);
            return;
        }
        
        // Check if AI Pro classes are available
        if (!class_exists('\Grav\Plugin\AIPro\Providers\AbstractProvider')) {
            $this->grav['log']->warning('AI Pro DeepSeek: AI Pro classes not found, DeepSeek provider will not be registered');
            // Remove our event subscriptions to prevent further processing
            $this->disable([
                'onAIProvidersRegister' => ['onAIProvidersRegister', 0]
            ]);
            return;
        }
        
        // AI Pro is enabled and classes are available, continue normally
        $this->grav['log']->debug('AI Pro DeepSeek: AI Pro is enabled, DeepSeek provider ready');
    }

    /**
     * Register DeepSeek provider with AI Pro
     */
    public function onAIProvidersRegister(Event $event): void
    {
        $this->grav['log']->debug('AI Pro DeepSeek: onAIProvidersRegister event fired');
        
        $providers = $event['providers'];
        
        // Register our provider if it's enabled in config
        $pluginEnabled = $this->grav['config']->get('plugins.ai-pro-deepseek.enabled');
        if ($pluginEnabled) {
            $this->grav['log']->debug('AI Pro DeepSeek: Registering DeepSeek provider');
            $providers->register('deepseek', DeepSeekProvider::class);
        } else {
            $this->grav['log']->debug('AI Pro DeepSeek: Plugin is not enabled, skipping provider registration');
        }
    }
}
