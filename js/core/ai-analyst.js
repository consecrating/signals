/**
 * ai-analyst.js — AI-powered narrative generation for F&O signals
 *
 * Connects to OpenRouter API to generate institutional-grade trading narratives.
 * Uses Claude Sonnet as primary model with GPT-4o-mini as fallback.
 *
 * IMPORTANT: This generates ANALYSIS narratives only. All trading decisions
 * are made by the quantitative signal engine. The AI adds context and
 * human-readable explanation — it does NOT override signal logic.
 */

// ─── Configuration ───────────────────────────────────────────────────────────

const OPENROUTER_API_URL = 'https://openrouter.ai/api/v1/chat/completions';

/**
 * API Key loaded from environment or runtime config.
 * Set via: OPENROUTER_API_KEY environment variable, or pass in constructor options.
 * For browser: inject via build-time env or server-side proxy.
 */
const OPENROUTER_API_KEY = (typeof process !== 'undefined' && process.env?.OPENROUTER_API_KEY) || '';

/** Primary model — high-quality reasoning */
const PRIMARY_MODEL = 'anthropic/claude-sonnet-4';

/** Fallback model — faster, cheaper, still capable */
const FALLBACK_MODEL = 'openai/gpt-4o-mini';

/** System prompt enforcing institutional analysis style */
const SYSTEM_PROMPT = `You are an institutional-grade F&O trading analyst for Indian markets (NSE/BSE). 

Your analysis must be:
- Numerical and specific (exact levels, percentages, ₹ values)
- Under 220 words maximum
- Risk-management focused — always mention what can go wrong
- Actionable but NOT financial advice — present data-driven observations

Structure your response EXACTLY as:
1. **Thesis** (one line — the core trade rationale)
2. **Key Drivers** (2-3 bullet points with specific numbers)
3. **Invalidation** (the exact price level that kills this thesis)
4. **Risk Reminder** (position sizing, max loss awareness)

Use ₹ symbol for Indian Rupee values. Reference specific technical levels.
Never use vague language like "might" or "could" without attaching a probability.
Always remind that F&O trading carries substantial risk of capital loss.`;

// ─── AI Analyst Class ────────────────────────────────────────────────────────

export class AIAnalyst {
  /**
   * @param {object} [options]
   * @param {string} [options.apiKey] - Override API key
   * @param {string} [options.primaryModel] - Override primary model
   * @param {string} [options.fallbackModel] - Override fallback model
   * @param {number} [options.timeoutMs=15000] - Request timeout
   * @param {number} [options.maxRetries=2] - Max retry attempts
   */
  constructor(options = {}) {
    this.apiKey = options.apiKey || OPENROUTER_API_KEY;
    this.primaryModel = options.primaryModel || PRIMARY_MODEL;
    this.fallbackModel = options.fallbackModel || FALLBACK_MODEL;
    this.timeoutMs = options.timeoutMs || 15000;
    this.maxRetries = options.maxRetries || 2;
  }

  /**
   * Generate an AI narrative for a signal result.
   *
   * @param {object} signalResult - Output from SignalEngine.generate()
   * @returns {Promise<{narrative: string, model: string, latency_ms: number}>}
   */
  async generateNarrative(signalResult) {
    const startTime = Date.now();

    // Build the analysis prompt from signal data
    const userPrompt = this._buildPrompt(signalResult);

    // Try primary model first, fall back if it fails
    let response = await this._callAPI(this.primaryModel, userPrompt);

    if (!response.success) {
      // Fallback to secondary model
      response = await this._callAPI(this.fallbackModel, userPrompt);
    }

    const latencyMs = Date.now() - startTime;

    if (!response.success) {
      // Both models failed — return a structured fallback narrative
      return {
        narrative: this._fallbackNarrative(signalResult),
        model: 'fallback_local',
        latency_ms: latencyMs,
        error: response.error || 'All API calls failed',
      };
    }

    return {
      narrative: response.content,
      model: response.model,
      latency_ms: latencyMs,
    };
  }

  /**
   * Build the user prompt from signal engine output.
   * Includes all quantitative data the AI needs for analysis.
   */
  _buildPrompt(signal) {
    const {
      symbol,
      direction,
      confidence,
      netScore,
      components,
      entryZone,
      stopLoss,
      targets,
      atr,
      optionStrategy,
      analytics,
      rsi,
      riskVeto,
    } = signal;

    let prompt = `Analyze this F&O signal for ${symbol}:\n\n`;
    prompt += `**Direction:** ${direction} | **Confidence:** ${confidence}% | **Net Score:** ${netScore}/100\n`;
    prompt += `**RSI:** ${rsi || 'N/A'} | **ATR:** ₹${atr || 'N/A'}\n\n`;

    // Component breakdown
    if (components) {
      prompt += `**Component Scores (bias × weight):**\n`;
      for (const [key, val] of Object.entries(components)) {
        const contribution = (val.bias * val.weight).toFixed(1);
        prompt += `- ${key}: bias=${val.bias.toFixed(2)}, weight=${val.weight}, contribution=${contribution}\n`;
      }
      prompt += '\n';
    }

    // Trade setup
    if (entryZone && targets) {
      prompt += `**Trade Setup:**\n`;
      prompt += `- Entry Zone: ₹${entryZone.low.toFixed(0)} – ₹${entryZone.high.toFixed(0)}\n`;
      prompt += `- Stop Loss: ₹${stopLoss}\n`;
      prompt += `- Targets: ${targets.map((t) => `${t.label}=₹${t.price.toFixed(0)}`).join(', ')}\n\n`;
    }

    // Option strategy
    if (optionStrategy) {
      prompt += `**Option Strategy:** ${optionStrategy.action}\n`;
      prompt += `- Premium: ₹${optionStrategy.estimatedPremium} | Delta: ${optionStrategy.delta}\n`;
      prompt += `- Capital Required: ₹${optionStrategy.capitalRequired} per lot\n\n`;
    }

    // Analytics
    if (analytics) {
      prompt += `**Analytics:**\n`;
      prompt += `- Signal Strength: ${analytics.signalStrength}\n`;
      prompt += `- Momentum: ${analytics.momentumGauge.value}/100 (${analytics.momentumGauge.label})\n`;
      prompt += `- Vol Squeeze: ${analytics.volatilitySqueeze.detected ? 'YES — explosive move expected' : 'No'}\n`;
      prompt += `- Risk/Lot: ₹${analytics.riskCalculator.riskPerLot} | Reward T1/Lot: ₹${analytics.riskCalculator.rewardT1PerLot}\n\n`;
    }

    if (riskVeto) {
      prompt += `⚠️ **Risk Flag Active:** ${riskVeto}\n\n`;
    }

    prompt += `Generate your 4-part institutional analysis (under 220 words). Remember: this is for someone trading with real capital in Indian F&O markets.`;

    return prompt;
  }

  /**
   * Make an API call to OpenRouter.
   * @param {string} model
   * @param {string} userPrompt
   * @returns {Promise<{success: boolean, content?: string, model?: string, error?: string}>}
   */
  async _callAPI(model, userPrompt) {
    for (let attempt = 0; attempt <= this.maxRetries; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeoutMs);

        const response = await fetch(OPENROUTER_API_URL, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${this.apiKey}`,
            'Content-Type': 'application/json',
            'HTTP-Referer': 'https://fno-signal-pro.app',
            'X-Title': 'FNO Signal Pro',
          },
          body: JSON.stringify({
            model,
            messages: [
              { role: 'system', content: SYSTEM_PROMPT },
              { role: 'user', content: userPrompt },
            ],
            max_tokens: 500,
            temperature: 0.3, // Low temperature for consistent, precise analysis
            top_p: 0.9,
          }),
          signal: controller.signal,
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
          const errorText = await response.text().catch(() => 'Unknown error');
          if (attempt === this.maxRetries) {
            return { success: false, error: `HTTP ${response.status}: ${errorText}` };
          }
          // Wait before retry (exponential backoff)
          await this._sleep(1000 * (attempt + 1));
          continue;
        }

        const data = await response.json();

        if (!data.choices || !data.choices[0] || !data.choices[0].message) {
          if (attempt === this.maxRetries) {
            return { success: false, error: 'Invalid API response structure' };
          }
          await this._sleep(1000 * (attempt + 1));
          continue;
        }

        return {
          success: true,
          content: data.choices[0].message.content.trim(),
          model: data.model || model,
        };
      } catch (err) {
        if (err.name === 'AbortError') {
          if (attempt === this.maxRetries) {
            return { success: false, error: `Timeout after ${this.timeoutMs}ms` };
          }
        } else if (attempt === this.maxRetries) {
          return { success: false, error: err.message || 'Network error' };
        }
        await this._sleep(1000 * (attempt + 1));
      }
    }

    return { success: false, error: 'Max retries exhausted' };
  }

  /**
   * Local fallback narrative when API is unreachable.
   * Produces a structured but non-AI-generated summary.
   */
  _fallbackNarrative(signal) {
    const { symbol, direction, confidence, stopLoss, targets, optionStrategy, analytics } = signal;

    if (direction === 'NO_TRADE') {
      return `⏸️ NO TRADE for ${symbol}. Signal confidence insufficient for execution. Wait for clearer setup.`;
    }

    const action = direction === 'BUY' ? 'LONG' : 'SHORT';
    let narrative = `**Thesis:** ${action} ${symbol} with ${confidence}% quantitative confidence.\n\n`;
    narrative += `**Key Drivers:**\n`;
    narrative += `• Signal score aggregation across 7 components favors ${direction.toLowerCase()} bias\n`;

    if (analytics && analytics.momentumGauge) {
      narrative += `• Momentum gauge at ${analytics.momentumGauge.value}/100 (${analytics.momentumGauge.label})\n`;
    }
    if (optionStrategy) {
      narrative += `• Recommended: ${optionStrategy.action} @ ₹${optionStrategy.estimatedPremium}/lot\n`;
    }

    narrative += `\n**Invalidation:** Below ₹${stopLoss} (stop loss level)\n\n`;
    narrative += `**Risk Reminder:** F&O trading involves substantial risk. Never risk more than 2% of capital on a single trade. `;
    narrative += `Max loss per lot: ₹${optionStrategy ? optionStrategy.capitalRequired : 'premium paid'}. This is algorithmic analysis, not financial advice.`;

    return narrative;
  }

  /**
   * Sleep utility for retry backoff.
   * @param {number} ms
   */
  _sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  /**
   * Health check — verify API connectivity.
   * @returns {Promise<{ok: boolean, model: string, latency_ms: number}>}
   */
  async healthCheck() {
    const start = Date.now();
    const result = await this._callAPI(this.fallbackModel, 'Reply with: OK');
    return {
      ok: result.success,
      model: result.model || this.fallbackModel,
      latency_ms: Date.now() - start,
      error: result.error || undefined,
    };
  }
}

export default AIAnalyst;
