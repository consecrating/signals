# Configuration

## API Keys

The AI Analyst module requires an OpenRouter API key.

### Setup Options:

1. **Environment Variable** (recommended for Node.js/server):
   ```
   export OPENROUTER_API_KEY=sk-or-v1-your-key-here
   ```

2. **Constructor Option** (for browser/runtime):
   ```js
   const analyst = new AIAnalyst({
     apiKey: 'sk-or-v1-your-key-here'
   });
   ```

3. **Server-side Proxy** (recommended for production browser apps):
   Create a backend endpoint that proxies requests to OpenRouter, keeping the key server-side.

## Getting an API Key

1. Go to https://openrouter.ai/keys
2. Create a new key
3. Configure it with the setup option above
