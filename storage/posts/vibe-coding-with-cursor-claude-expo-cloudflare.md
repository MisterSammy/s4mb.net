---
title: "Building a Recipe Generation App with Expo, Cloudflare Workers, and OpenAI"
date: 2026-01-09
excerpt: "A technical walkthrough of building a React Native recipe app with Expo, Cloudflare Workers for the backend, and OpenAI GPT-4o for recipe generation. Covers architecture decisions, rate limiting with KV storage, schema migrations in SQLite, and prompt engineering."
tags: [react-native, expo, cloudflare-workers, openai, typescript, sqlite, architecture]
slug: vibe-coding-with-cursor-claude-expo-cloudflare
---

I built a recipe generation app over the past few weeks, and I wanted to share what I learned about the architecture and some of the interesting technical challenges I ran into. The app is straightforward in concept: you tell it what ingredients you have in your kitchen, specify any dietary preferences or time constraints, and it generates recipes you can actually make. But as with most seemingly simple projects, the devil's in the details.

The real goal here was to build something practical that I'd actually use. I've tried other recipe apps before, and they always seemed to suggest dishes that required ingredients I'd never heard of or techniques that assumed I had a culinary degree. So I wanted something that understood the difference between "I'm making breakfast before work" and "I'm planning a fancy dinner party." The AI needed to match my reality, not some aspirational cooking show version of it.

This post covers the technical implementation: how I structured the React Native app with Expo, why I chose Cloudflare Workers for the backend, and how I engineered the prompts to get GPT-4o to generate sensible recipes instead of suggesting prosciutto-wrapped everything. I'll also walk through some of the more interesting challenges, like migrating SQLite schemas without breaking existing data, handling speech recognition in development builds, and rate limiting with KV storage.

## The Stack

The architecture is relatively straightforward:

```
┌─────────────────────────────────────┐
│     React Native App (Expo)         │
│     TypeScript + expo-router        │
├─────────────────────────────────────┤
│  • expo-sqlite (local persistence)  │
│  • expo-speech-recognition          │
│  • RevenueCat SDK (subscriptions)   │
│  • File-based routing               │
└──────────────┬──────────────────────┘
               │ HTTPS POST
               ▼
┌─────────────────────────────────────┐
│   Cloudflare Worker (TypeScript)    │
├─────────────────────────────────────┤
│  • Rate limiting (KV storage)       │
│  • Subscription validation (cached) │
│  • OpenAI GPT-4o integration        │
│  • Prompt engineering system        │
└─────────────────────────────────────┘
```

**Expo + React Native** made sense because I wanted native modules - specifically speech recognition and SQLite - without maintaining separate iOS and Android codebases. The managed workflow handles the native dependencies, and `expo-router` brings file-based routing similar to Next.js, which I've found makes navigation much more intuitive.

**Cloudflare Workers** became the backend because OpenAI API keys can't live in a mobile app. Someone would extract them from the binary within hours. Workers are globally distributed, have a generous free tier (which matters for a side project), and KV storage turned out to be a perfect fit for rate limiting counters and caching subscription status.

**OpenAI's GPT-4o** handles recipe generation. I initially tried building a rule-based system with a recipe database, but it quickly became unwieldy. The AI approach lets me handle dietary restrictions, time constraints, and ingredient substitutions in natural language. The key was getting the prompts right - more on that later.

**RevenueCat** manages in-app subscriptions. I didn't want to deal with App Store receipt validation myself, and RevenueCat abstracts that complexity while providing a unified API across platforms.

## The Backend: Cloudflare Workers

The worker is relatively simple - it routes requests, validates inputs, checks rate limits, and calls OpenAI. But there are some interesting details in how rate limiting and subscription validation work.

### Endpoint Structure

The worker handles two endpoints: `/generate` for recipe creation and `/parse-ingredients` for extracting ingredient lists from freeform text. Here's the core routing:

```typescript
export default {
  async fetch(request: Request, env: any): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname.replace(/\/$/, '');

    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'POST, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
        },
      });
    }

    if (request.method !== 'POST') {
      return new Response('Method not allowed', { status: 405 });
    }

    const body = await request.json();

    // Route to /generate endpoint
    if (path === '/generate' || path.endsWith('/generate')) {
      const { userId, ingredients, preferences, allowMissingIngredients, recipeCount } = body;

      // Validate subscription status (cached in KV)
      const subscriptionStatus = await validateSubscription(env, userId);

      // Check rate limit based on subscription tier
      const rateLimit = await checkRateLimit(env, userId, subscriptionStatus.isActive);

      if (!rateLimit.allowed) {
        return new Response(
          JSON.stringify({
            success: false,
            error: 'Rate limit exceeded',
            retryAfter: 3600,
          }),
          { status: 429, headers: { 'Content-Type': 'application/json' } }
        );
      }

      // Generate recipes with clamped count (1-5)
      const clampedCount = Math.max(1, Math.min(5, recipeCount || 2));
      const recipesResult = await generateRecipe(
        env.OPENAI_API_KEY,
        ingredients,
        { ...preferences, allowMissingIngredients },
        clampedCount
      );

      return new Response(
        JSON.stringify({
          success: true,
          recipes: recipesResult.recipes,
          rateLimitRemaining: rateLimit.remaining,
        }),
        { headers: { 'Content-Type': 'application/json' } }
      );
    }

    // Route to /parse-ingredients endpoint
    if (path === '/parse-ingredients' || path.includes('/parse-ingredients')) {
      const { text } = body;
      const ingredients = await parseIngredientsText(env.OPENAI_API_KEY, text);
      return new Response(
        JSON.stringify({ success: true, ingredients }),
        { headers: { 'Content-Type': 'application/json' } }
      );
    }

    return new Response(
      JSON.stringify({ success: false, error: `Unknown endpoint: ${path}` }),
      { status: 404, headers: { 'Content-Type': 'application/json' } }
    );
  },
};
```

The `/parse-ingredients` endpoint is surprisingly useful. Instead of manually entering each ingredient, you can paste in "I have eggs, milk, some flour, and butter in the fridge" and it extracts a clean list. The AI handles typos, plurals, and informal descriptions.

### Rate Limiting with KV Storage

Rate limiting uses Cloudflare KV with TTL-based expiration. Different limits apply based on subscription status:

```typescript
const RATE_LIMIT_CONFIG = {
  subscribers: {
    maxRequests: 1000,
    windowMs: 3600000, // 1 hour
  },
  nonSubscribers: {
    maxRequests: 10,
    windowMs: 3600000,
  },
};

export async function checkRateLimit(
  env: any,
  userId: string,
  isSubscriber: boolean
): Promise<{ allowed: boolean; remaining: number }> {
  const config = isSubscriber
    ? RATE_LIMIT_CONFIG.subscribers
    : RATE_LIMIT_CONFIG.nonSubscribers;

  // Fail open if KV namespace unavailable
  if (!env.SUBSCRIPTION_CACHE) {
    return { allowed: true, remaining: config.maxRequests };
  }

  const key = `ratelimit:${userId}`;
  const cached = await env.SUBSCRIPTION_CACHE.get(key);

  if (!cached) {
    // First request: initialize counter with TTL
    await env.SUBSCRIPTION_CACHE.put(key, '1', {
      expirationTtl: Math.floor(config.windowMs / 1000),
    });
    return { allowed: true, remaining: config.maxRequests - 1 };
  }

  const count = parseInt(cached, 10);
  if (count >= config.maxRequests) {
    return { allowed: false, remaining: 0 };
  }

  // Increment counter, preserve TTL
  await env.SUBSCRIPTION_CACHE.put(key, String(count + 1), {
    expirationTtl: Math.floor(config.windowMs / 1000),
  });

  return { allowed: true, remaining: config.maxRequests - count - 1 };
}
```

The key format is `ratelimit:${userId}` where `userId` comes from RevenueCat's `originalAppUserId`. The TTL handles cleanup automatically - counters expire after the window ends, so there's no manual cleanup required. If KV is unavailable (say, during a Cloudflare outage), the system fails open rather than blocking legitimate requests.

### Subscription Validation and Caching

Subscription validation queries RevenueCat's API, but I cache the results in KV for an hour to reduce API calls:

```typescript
const CACHE_TTL = 3600; // 1 hour in seconds

export async function validateSubscription(
  env: any,
  userId: string
): Promise<{ isActive: boolean; expiryDate?: number }> {
  // Check cache first
  if (env.SUBSCRIPTION_CACHE) {
    const cacheKey = `subscription:${userId}`;
    const cached = await env.SUBSCRIPTION_CACHE.get(cacheKey, 'json');
    if (cached) {
      return cached;
    }
  }

  // Validate with RevenueCat API
  const response = await fetch(`https://api.revenuecat.com/v1/subscribers/${userId}`, {
    headers: {
      Authorization: `Bearer ${env.REVENUECAT_API_KEY}`,
      'X-Platform': 'ios',
    },
  });

  if (!response.ok) {
    return { isActive: false };
  }

  const data = await response.json();
  const entitlementId = env.REVENUECAT_ENTITLEMENT_ID || 'premium';
  const entitlement = data.subscriber?.entitlements?.[entitlementId];

  const status = {
    isActive: entitlement?.expires_date
      ? new Date(entitlement.expires_date) > new Date()
      : false,
    expiryDate: entitlement?.expires_date
      ? new Date(entitlement.expires_date).getTime()
      : undefined,
  };

  // Cache result
  if (env.SUBSCRIPTION_CACHE) {
    const cacheKey = `subscription:${userId}`;
    await env.SUBSCRIPTION_CACHE.put(cacheKey, JSON.stringify(status), {
      expirationTtl: CACHE_TTL,
    });
  }

  return status;
}
```

One gotcha: the entitlement ID must match exactly (case-sensitive) across three places: the client app config, the backend `wrangler.toml`, and the RevenueCat dashboard. A mismatch causes silent failures where paying users can't access premium features. This took me an embarrassing amount of time to debug.

### Prompt Engineering: Teaching the AI About Real Cooking

This was the most interesting part of the project. Getting GPT-4o to generate practical recipes required careful prompt engineering. The default behavior was... not great. Without guidance, it would suggest things like "pan-seared duck breast with shallot confit" for a weeknight dinner, or recommend I pick up prosciutto and gruyère for a snack.

I built a meal-type-specific configuration system that tailors prompts based on whether you're making breakfast, lunch, dinner, or a snack:

```typescript
const MEAL_CONFIGS: Record<string, MealTypeConfig> = {
  breakfast: {
    description: 'WHAT COUNTS AS BREAKFAST:',
    examples: 'Think normal morning food: oatmeal, eggs, pancakes, toast, smoothies, yogurt bowls, fruit, granola.',
    forbidden: 'NOT breakfast: rice dishes, stir-fries, curries, pasta, heavy cooked meals.',
    timeGuidance: (constraint: string) => {
      switch (constraint) {
        case 'quick': return '15 minutes or less';
        case 'elaborate': return 'Up to 30 minutes';
        default: return '15-20 minutes';
      }
    },
  },
  snack: {
    description: 'REALITY CHECK - SNACKS:',
    examples: 'A snack is something SMALL and QUICK. Takes 5-10 minutes max, no real cooking.',
    forbidden: 'NOT SNACKS: Rice dishes, pasta, stir-fries, curries, anything cooked in a pan for more than a few minutes.',
    extraRules: `
YOUR RULES:
- Maximum 5 ingredients per snack
- Maximum 10 minutes prep time
- No real cooking (blending and mixing only)`,
  },
  // ... lunch and dinner configs
};
```

The system message establishes the persona, which significantly affects output quality:

```typescript
function getSystemMessage(mealType: string, recipeCount: number, isFancy: boolean): string {
  if (isFancy) {
    return `You're a skilled home chef making ${recipeCount} special dinner recipes. 
The user wants to get fancy - think date night or dinner party. 
Feel free to suggest quality ingredients like prosciutto, gruyère, fresh herbs, good wine for cooking, or specialty items. 
Return only valid JSON.`;
  }
  
  return `You're a regular person making ${recipeCount} dinner recipes at home on a weeknight. 
You shop at a normal grocery store and your ingredients cost less than $50 total. 
You do NOT have prosciutto, pancetta, gruyère, shallots, specialty meats, or fancy cheeses. 
NEVER suggest these. If you need to add something, use the cheapest common option: 
ground beef, bacon, chicken thighs, cheddar, onion, garlic. 
This is real home cooking, not a cooking show. 
Return only valid JSON.`;
}
```

Without the explicit "you do NOT have prosciutto" instruction, GPT-4o kept suggesting upscale ingredients. The persona shift from "skilled chef" to "regular person shopping at a normal grocery store" made a dramatic difference in recipe quality.

There's also a "fancy mode" toggle that changes the persona and ingredient suggestions. Same ingredients in your kitchen, but now it's date night instead of a Tuesday, so the AI suggests something more ambitious.

### The Water Problem

Here's something I didn't expect: recipes flag ingredients as missing if they're not in your inventory, which makes sense. But water is automatically marked as available:

```typescript
parsedIngredients.map((ing: RecipeIngredient) => {
  if (ing.name.toLowerCase() === 'water') {
    return { ...ing, inInventory: true };
  }
  return ing;
})
```

No one lists water when they're entering ingredients, but everyone has access to it. This seems obvious in retrospect, but it took a few test generations where recipes were flagged as "missing ingredients" because they required water before I realized the issue.

And there's something worth noting here: we take for granted that turning on a tap gives us clean, safe drinking water. That's not universal. In many parts of the world, clean water is a precious resource that requires planning and effort to obtain. The automatic assumption that water is always available is a privilege of living in the Western world with modern infrastructure. It's easy to forget how lucky we are.

## The React Native App

The mobile app uses Expo with TypeScript, `expo-router` for file-based routing, `expo-sqlite` for local persistence, and `expo-speech-recognition` for voice input. The database stores ingredients, recipes, preferences, usage tracking, and settings.

### Database Schema and Migrations

The database uses five tables: `ingredients`, `recipes`, `preferences`, `usage`, and `app_settings`. Schema migrations use `PRAGMA table_info` to detect missing columns and `ALTER TABLE` to add them:

```typescript
export const initDatabase = async (): Promise<SQLite.SQLiteDatabase> => {
  const db = await SQLite.openDatabaseAsync('kitchentool.db');
  
  // Create recipes table
  await db.execAsync(`
    CREATE TABLE IF NOT EXISTS recipes (
      id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      ingredients_json TEXT NOT NULL,
      instructions_json TEXT NOT NULL,
      generated_at INTEGER NOT NULL,
      is_favorite INTEGER DEFAULT 0,
      viewed INTEGER DEFAULT 0
    );
  `);
  
  // Migration: add viewed column if missing
  try {
    const columns = await db.getAllAsync<{ name: string }>(
      'PRAGMA table_info(recipes)'
    );
    const hasViewed = (columns || []).some(c => c.name === 'viewed');
    if (!hasViewed) {
      await db.execAsync(
        'ALTER TABLE recipes ADD COLUMN viewed INTEGER DEFAULT 0'
      );
    }
  } catch (error) {
    // Migration fails silently; reads will default to false
    console.warn('Recipes viewed migration failed:', error);
  }
  
  // Similar migrations for other tables...
  
  return db;
};
```

SQLite doesn't support removing columns or changing types easily, so migrations only add columns with safe defaults. This keeps things simple - existing data stays valid, and new columns are optional.

### Schema Evolution and Backwards Compatibility

The ingredient format changed mid-development from strings to objects with inventory status. The parser handles both formats so existing recipes continue working:

```typescript
function parseRecipeIngredients(rawJson: string): RecipeIngredient[] {
  try {
    const parsed = JSON.parse(rawJson);
    if (!Array.isArray(parsed)) return [];

    // Legacy format: string[] → ["chicken", "onion", "garlic"]
    if (parsed.every((x) => typeof x === 'string')) {
      return (parsed as string[]).map((name) => ({
        name,
        inInventory: true, // Legacy recipes assume all ingredients available
      }));
    }

    // Current format: { name, inInventory }[]
    return parsed
      .map((x: any): RecipeIngredient | null => {
        if (!x) return null;
        const name = typeof x.name === 'string' ? x.name : '';
        const inInventory = typeof x.inInventory === 'boolean' ? x.inInventory : true;
        if (!name.trim()) return null;
        return { name: name.trim(), inInventory };
      })
      .filter((x): x is RecipeIngredient => x !== null);
  } catch {
    return [];
  }
}
```

This backwards-compatible approach is simpler than migrating all records upfront. It also handles corrupt data gracefully - if parsing fails, you get an empty array rather than a crash.

### Speech Recognition (Development Build Only)

Speech recognition uses `expo-speech-recognition`, but it only works in development builds, not Expo Go. The implementation handles the missing module gracefully:

```typescript
// Try to import speech recognition, handle if unavailable (Expo Go)
let ExpoSpeechRecognitionModule: any = null;
let useSpeechRecognitionEvent: any = null;
let isSpeechRecognitionAvailable = false;

try {
  const speechRecognition = require('expo-speech-recognition');
  ExpoSpeechRecognitionModule = speechRecognition.ExpoSpeechRecognitionModule;
  useSpeechRecognitionEvent = speechRecognition.useSpeechRecognitionEvent;
  isSpeechRecognitionAvailable = true;
} catch (error) {
  console.log('Speech recognition not available (requires development build)');
  isSpeechRecognitionAvailable = false;
  useSpeechRecognitionEvent = () => {}; // No-op hook
}
```

When speech recognition isn't available, the UI shows a message directing users to type instead. The speech handling itself accumulates transcripts from continuous recognition:

```typescript
const handleSpeechResult = (transcript: string, isFinal: boolean) => {
  if (!isListeningRef.current) return;
  
  if (isFinal) {
    // Final results: extract only the NEW part and append
    setText((prev) => {
      const committedText = accumulatedFinalTextRef.current;
      const transcriptTrimmed = transcript.trim();
      
      if (committedText) {
        // Extract only new part if transcript includes committed text
        if (transcriptTrimmed.toLowerCase().startsWith(committedText.toLowerCase())) {
          const newPart = transcriptTrimmed.substring(committedText.length).trim();
          return committedText + (newPart ? ' ' + newPart : '');
        } else {
          // New phrase: append as continuation
          return committedText + ' ' + transcriptTrimmed;
        }
      } else {
        // First phrase
        return transcriptTrimmed;
      }
    });
  } else {
    // Interim results for real-time display
  }
};
```

The speech recognition system includes a 30-second auto-timeout to prevent extended recording sessions. This matters more than you'd think - when you're adding ingredients, you want to say "eggs, milk, butter" and have it stop, not keep recording while you walk around your kitchen.

### API Client with Retry Logic

The API client includes exponential backoff for transient failures:

```typescript
export async function generateRecipes(
  ingredients: Ingredient[],
  preferences: MealPreferences,
  recipeCount: number = 2
): Promise<Recipe[]> {
  // Get RevenueCat user ID for rate limiting
  const customerInfo = await Purchases.getCustomerInfo();
  const userId = customerInfo.originalAppUserId;

  const requestBody = {
    userId,
    ingredients: ingredients.map((i) => i.name),
    preferences: {
      dietaryRestrictions: preferences.dietaryRestrictions,
      spiceLevel: preferences.spiceLevel,
      timeConstraint: preferences.timeConstraint,
      mealType: preferences.mealType,
    },
    allowMissingIngredients: preferences.allowMissingIngredients,
    recipeCount: Math.max(1, Math.min(5, recipeCount)),
  };

  const response = await retryWithBackoff(
    async () => {
      const res = await fetch(`${API_URL}/generate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(requestBody),
      });
      if (!res.ok && res.status >= 500) {
        throw new Error(`Server error: ${res.status}`);
      }
      return res;
    },
    {
      maxAttempts: 3,
      initialDelay: 1000,
      retryable: (error: unknown) => {
        return handleError(error).retryable;
      },
    }
  );

  const data = await response.json();
  // Convert backend response to Recipe[]...
}
```

The retry logic only retries 5xx server errors, not 4xx client errors or 429 rate limits. Exponential backoff prevents hammering the server during outages.

## Lessons Learned

### Rate Limiting During Development

During development, I didn't have an active subscription, so the backend treated me as a non-subscriber with a strict 10-requests-per-hour limit. I hit the limit constantly while testing.

The workaround was temporarily raising the limit:

```typescript
nonSubscribers: {
  maxRequests: 999, // Temporarily raised for testing (normally 10)
  windowMs: 3600000,
}
```

Of course, I nearly forgot to lower it before launch. A pre-launch checklist or environment variable would have been smarter.

### Entitlement ID Matching

The RevenueCat entitlement ID must match exactly (case-sensitive) in three places: the client app, the backend `wrangler.toml`, and the RevenueCat dashboard. Any mismatch causes silent failures where paying users can't access premium features.

This is the kind of bug that's obvious in retrospect but took me hours to find. Document your entitlement IDs and verify all three locations before deploying.

### Cloudflare Workers Deployment

If your `wrangler.toml` defines a production environment, you must deploy with:

```bash
npx wrangler deploy --env production
```

Without `--env production`, the KV namespaces and secrets aren't available. The deployment succeeds, but the worker fails at runtime. This is easy to miss because the deployment itself doesn't error - the worker just can't access required resources.

## When This Stack Works

This architecture works well for:

- Mobile apps needing native modules (speech, camera, SQLite) without separate platform projects
- Apps requiring server-side API keys that can't be exposed to clients
- Projects benefiting from globally distributed backends with low latency
- Side projects where the Cloudflare Workers free tier is sufficient

Consider alternatives if:

- You need complex server-side business logic (Workers have execution time limits)
- Your backend requires persistent connections (Workers are request-response only)
- You're building for Android only (Expo adds overhead if you don't need iOS)
- Your app has complex native requirements Expo doesn't support

## Final Thoughts

This project reinforced a few things for me. First, AI is excellent at tasks with fuzzy requirements - generating recipes based on "whatever's in my fridge" is exactly the kind of problem that's tedious to solve with rules but natural for language models. Second, prompt engineering matters more than I initially expected. The difference between a good and bad prompt isn't subtle - it's the difference between "pan-seared duck breast with shallot confit" and "chicken tacos with stuff you already have."

And finally, there's something satisfying about building tools you actually use. This app lives on my phone, and I've used it dozens of times since building it. That's the real test of whether something works: not whether it's technically impressive, but whether you reach for it when you need it.

The code is deployed and serving users. It has error handling, retry logic, and graceful degradation when services are unavailable. It's not perfect, but it's solid enough that I trust it with real usage. And honestly, that's all I was aiming for.