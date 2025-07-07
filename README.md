# AI Pro - DeepSeek Provider Plugin

This plugin extends the AI Pro plugin by adding support for DeepSeek's language models.

## Installation

### Manual Installation

1. Download the plugin and extract it to `/user/plugins/ai-pro-deepseek`
2. Run `composer install` in the plugin directory
3. Configure the plugin in Admin panel

### Requirements

- Grav v1.7.0 or higher
- AI Pro plugin v1.0.0 or higher
- DeepSeek API key

## Configuration

1. Get your API key from [platform.deepseek.com](https://platform.deepseek.com)
2. Enable the plugin in Grav Admin
3. Enter your API key in the plugin settings
4. Select your preferred model (DeepSeek Chat or DeepSeek Coder)

## Features

- Full integration with AI Pro's unified interface
- Support for DeepSeek Chat and DeepSeek Coder models
- Streaming responses
- Automatic retry with exponential backoff
- Token counting and cost estimation

## Usage

Once configured, DeepSeek will appear as an available provider in:
- AI Pro admin interface
- CLI commands: `bin/plugin ai-pro chat --provider=deepseek`
- AI-powered form fields

## Models

- **DeepSeek Chat**: General purpose model with 32K context window
- **DeepSeek Coder**: Specialized for code generation with 16K context window

## Development

This plugin demonstrates how to extend AI Pro with custom providers. Key files:

- `ai-pro-deepseek.php`: Main plugin file that registers the provider
- `classes/DeepSeekProvider.php`: Provider implementation
- `blueprints.yaml`: Configuration schema

## License

MIT License