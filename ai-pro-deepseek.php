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
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't need to do anything here since we're subscribing to onAIProvidersRegister directly
    }

    /**
     * Register DeepSeek provider with AI Pro
     */
    public function onAIProvidersRegister(Event $event): void
    {
        $this->grav['log']->addDebug('AI Pro DeepSeek: onAIProvidersRegister event fired');
        
        $providers = $event['providers'];
        
        // Register our provider if it's enabled in config
        $pluginEnabled = $this->grav['config']->get('plugins.ai-pro-deepseek.enabled');
        if ($pluginEnabled) {
            $this->grav['log']->addDebug('AI Pro DeepSeek: Registering DeepSeek provider');
            $providers->register('deepseek', DeepSeekProvider::class);
        } else {
            $this->grav['log']->addDebug('AI Pro DeepSeek: Plugin is not enabled, skipping provider registration');
        }
    }
}