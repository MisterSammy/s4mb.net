---
title: "Integrating Third-Party Identity Verification (SumSub) into Laravel"
date: 2025-01-09
excerpt: "Identity verification is a black box until you have to build it. Here's how to integrate SumSub's KYC service with webhooks, token refresh, and graceful error handling."
tags: [laravel, integration, kyc, verification, api]
slug: integrating-sumsub-identity-verification-laravel
---

If you're building software for regulated industries - finance, healthcare, property management, marketplace platforms - you'll eventually need to verify that users are who they claim to be. Know Your Customer (KYC) requirements aren't optional. They're often the law.

You have two options: build verification infrastructure yourself (document scanning, liveness detection, database checks against sanctions lists) or integrate a third-party service that specializes in this.

Unless identity verification is your core business, the second option wins. Services like SumSub, Onfido, and Jumio have teams dedicated to staying ahead of fraud techniques and regulatory changes. Your job is to integrate their SDK and handle the results.

This post walks through integrating SumSub into a Laravel application. The concepts apply to similar services - they all follow the same general pattern of SDK embedding, webhook handling, and status management.

## Prerequisites

Before starting, you'll need:

- A SumSub account with API credentials (App Token and Secret Key from the dashboard)
- Laravel 9+ application
- Guzzle HTTP client (`composer require guzzlehttp/guzzle`)
- A publicly accessible webhook URL (use ngrok for local testing)

## Sandbox Environment

You'll want to start developing in a safe environment. Thankfully, SumSub provides a sandbox environment you can create in their dashboard. A few things to know:

- **Verifications aren't processed automatically.** You'll need to trigger status changes manually via SumSub's dashboard or by calling their API endpoints.
- **Use sandbox credentials.** Make sure your App Token and Secret Key are from the sandbox environment, not production.
- **Test webhooks with ngrok.** SumSub needs a publicly accessible URL to send webhooks. Run `ngrok http 80` and configure that URL in SumSub's dashboard.

To manually trigger a webhook for testing, you can use SumSub's "Simulate review response" feature in the dashboard, or call the API directly to change an applicant's status.

## Configuration

Store your SumSub credentials in environment variables. Use your sandbox credentials during development and production credentials when you deploy:

```env
# .env.local (development) - sandbox credentials
SUMSUB_API_URL=https://api.sumsub.com
SUMSUB_APP_TOKEN=sbx_your_sandbox_app_token
SUMSUB_SECRET_KEY=your_sandbox_secret_key
SUMSUB_WEBHOOK_SECRET=your_sandbox_webhook_secret

# .env.production - production credentials
SUMSUB_API_URL=https://api.sumsub.com
SUMSUB_APP_TOKEN=your_production_app_token
SUMSUB_SECRET_KEY=your_production_secret_key
SUMSUB_WEBHOOK_SECRET=your_production_webhook_secret
```

The API URL is the same for both environments - SumSub routes requests based on your credentials. Sandbox tokens typically have an `sbx_` prefix, making it easy to verify you're not accidentally using production credentials during development.

Reference them in config:

```php
// config/services.php
'sumsub' => [
    'api_url' => env('SUMSUB_API_URL'),
    'app_token' => env('SUMSUB_APP_TOKEN'),
    'secret_key' => env('SUMSUB_SECRET_KEY'),
    'webhook_secret' => env('SUMSUB_WEBHOOK_SECRET'),
],
```

The webhook secret is separate from your API secret key - you'll set it when configuring webhooks in SumSub's dashboard.

## The Integration Architecture

A typical identity verification flow has four parts:

1. **Generate an access token** that lets the user interact with the SDK
2. **Embed the SDK** in your frontend for document upload and liveness checks
3. **Handle webhooks** when verification completes or fails
4. **(Optional) Create an applicant** if you need to pre-populate data

Here's how these pieces connect:

```plaintext
┌─────────────────────────────────────────────────────────────────────────────┐
│                           VERIFICATION FLOW                                 │
└─────────────────────────────────────────────────────────────────────────────┘

     YOUR INFRASTRUCTURE                          SUMSUB
  ┌──────────────────────┐                  ┌──────────────────┐
  │                      │                  │                  │
  │   Laravel Backend    │                  │    SumSub API    │
  │                      │                  │                  │
  └──────────┬───────────┘                  └────────┬─────────┘
             │                                       │
             │  1. Generate Access Token (POST)      │
             │──────────────────────────────────────>│
             │<─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │  short-lived token
             │                                       │  (applicant auto-created
             │                                       │   if userId is new)
  ┌──────────┴───────────┐                  ┌────────┴─────────┐
  │                      │                  │                  │
  │   User's Browser     │                  │   SumSub SDK     │
  │                      │                  │   (in browser)   │
  └──────────┬───────────┘                  └────────┬─────────┘
             │                                       │
             │  2. Page loads with token             │
             │  ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─> │
             │                                       │
             │  3. SDK renders verification UI       │
             │<─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │
             │                                       │
             │  4. User uploads docs, takes selfie   │
             │  ════════════════════════════════════>│──┐
             │                                       │  │ Documents sent
             │                                       │  │ directly to SumSub
             │  5. Token expires? Refresh via        │  │ (not through your
             │     backend callback                  │  │ server)
             │  ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─> │<─┘
             │                                       │
  ┌──────────┴───────────┐                  ┌────────┴─────────┐
  │                      │                  │                  │
  │   Laravel Backend    │                  │  SumSub Backend  │
  │                      │                  │  (async review)  │
  └──────────┬───────────┘                  └────────┬─────────┘
             │                                       │
             │  6. Webhook: applicantReviewed        │
             │<══════════════════════════════════════│  minutes to days
             │     (GREEN/RED result)                   later
             │                                       
             │  7. Update database,                  
             │     trigger events                    
             ▼                                       

  ══════  Secure channel (your data)
  ─ ─ ─   Response / callback
  ──────  API request
```

The key insight: **user documents never touch your servers**. The SDK uploads directly to SumSub, which handles PII storage and processing. You only receive the verification result via webhook.

Note that you don't need to explicitly create an applicant before generating an access token. When you generate a token with a `userId` that doesn't exist yet, SumSub automatically creates the applicant record when the SDK initializes. The explicit `createApplicant()` method (shown later) is useful when you need to pre-populate applicant data, but it's optional for basic flows.

## Files You'll Need

Here's what you'll be adding to your Laravel project:

```plaintext
app/
├── Events/
│   └── UserFailedVerification.php      # Fired when verification fails
├── Http/
│   └── Controllers/
│       └── VerificationController.php  # API calls, token generation, webhooks
└── Models/
    └── Verification.php                # Only needed for non-user verification

config/
└── services.php                        # Add SumSub credentials (modify existing)

database/
└── migrations/
    ├── xxxx_add_verification_to_users_table.php  # For user verification
    └── xxxx_create_verifications_table.php       # For non-user verification

resources/
└── views/
    └── verifications/
        ├── create.blade.php            # SDK embed for authenticated users
        └── create-externally.blade.php # SDK embed for non-users (no auth)

routes/
├── api.php                             # Webhook endpoint, token refresh
└── web.php                             # Verification page routes

.env                                    # Add SUMSUB_* credentials
```

For simple cases where you're only verifying users, add `verification_status` and `sumsub_applicant_id` columns directly to your `users` table. The separate `Verification` model is for when you need to verify people who don't have accounts (covered later in this post).

Most of the work lives in the controller. The views are mostly just SDK embedding.

Let's implement each piece.

## Setting Up the Controller

First, a controller to handle verification-related requests:

```php
<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class VerificationController extends Controller
{
    /**
     * Show the verification form for authenticated users.
     */
    public function create(): View
    {
        $user = auth()->user();
        $levelName = 'basic-kyc'; // SumSub verification level

        // Use a prefixed user ID as SumSub's external reference
        $sumsubUserId = 'user_' . $user->id;

        // Generate a temporary access token for the SDK
        $accessToken = $this->getAccessToken($sumsubUserId, $levelName);

        return view('verifications.create', [
            'generated_access_token' => $accessToken,
            'sumsub_user_id' => $sumsubUserId,
        ]);
    }
}
```

SumSub requires a `userId` parameter (also referred to as `externalUserId` in their API responses) when generating access tokens. This string links SumSub's applicant record to your database. It should be unique and meaningful - your internal user ID works well. SumSub advises against randomly generating these IDs in production. The prefix (`user_`) helps distinguish these from other types of verifiable entities (more on that later).

## HMAC Signature Authentication

SumSub's API uses HMAC signatures for authentication. Every request must include a timestamp, your app token, and a signature computed from the request details:

```php
private function createSignature(int $ts, string $httpMethod, string $url, string $httpBody): string
{
    // Concatenate: timestamp + HTTP method (uppercase) + URL path + request body
    $signatureString = $ts . strtoupper($httpMethod) . $url . $httpBody;
    
    // HMAC-SHA256 with your secret key
    return hash_hmac('sha256', $signatureString, config('services.sumsub.secret_key'));
}
```

The signature proves that the request came from someone who knows your secret key and hasn't been tampered with in transit. The timestamp prevents replay attacks - old signatures become invalid.

Here's how to send an authenticated request:

```php
public function sendHttpRequest(Request $request, string $url): \Psr\Http\Message\ResponseInterface
{
    $client = new Client();
    $ts = time(); // Current Unix timestamp
    
    // Add authentication headers
    $request = $request->withHeader('X-App-Token', config('services.sumsub.app_token'));
    $request = $request->withHeader('X-App-Access-Sig', 
        $this->createSignature($ts, $request->getMethod(), $url, (string) $request->getBody())
    );
    $request = $request->withHeader('X-App-Access-Ts', $ts);
    
    // Reset stream position before sending
    // (Guzzle may have read the body during header generation)
    $request->getBody()->rewind();
    
    try {
        $response = $client->send($request);
        
        if (!in_array($response->getStatusCode(), [200, 201])) {
            // Log the correlation ID for debugging with SumSub support
            Log::error('SumSub API error', [
                'correlationId' => $response->getHeader('X-Correlation-Id')[0] ?? null,
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ]);
            
            throw new \RuntimeException('SumSub API request failed: ' . $response->getStatusCode());
        }
        
        return $response;
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        Log::error('SumSub request failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}
```

## Creating an Applicant (Optional)

Before a user can verify their identity, you need an "applicant" record on SumSub's side. However, this step is optional - SumSub will automatically create the applicant when you generate an access token with a new `userId`. 

Use explicit applicant creation when you need to pre-populate data like email, phone, or address for cross-validation:

```php
public function createApplicant(string $externalUserId, string $levelName): string
{
    $requestBody = [
        'externalUserId' => $externalUserId,
        // Optional: pre-populate data for cross-validation
        // 'email' => $user->email,
        // 'phone' => $user->phone,
    ];
    
    $url = '/resources/applicants?levelName=' . urlencode($levelName);
    
    $request = new Request(
        'POST', 
        config('services.sumsub.api_url') . $url
    );
    $request = $request->withHeader('Content-Type', 'application/json');
    $request = $request->withBody(Utils::streamFor(json_encode($requestBody)));
    
    $responseBody = $this->sendHttpRequest($request, $url)->getBody();
    
    // Return SumSub's applicant ID for future reference
    return json_decode($responseBody)->id;
}
```

The `levelName` refers to a verification flow you configure in SumSub's dashboard. Different levels can require different documents - ID only, ID plus proof of address, ID plus selfie, etc.

## Generating Access Tokens

The WebSDK needs a short-lived token to authenticate the user's session. This token is scoped to a specific user and level:

```php
public function getAccessToken(string $externalUserId, string $levelName, int $ttlInSecs = 600): string
{
    $url = '/resources/accessTokens/sdk';
    
    $requestBody = [
        'userId' => $externalUserId,
        'levelName' => $levelName,
        'ttlInSecs' => $ttlInSecs,
    ];
    
    $request = new Request(
        'POST', 
        config('services.sumsub.api_url') . $url
    );
    $request = $request->withHeader('Content-Type', 'application/json');
    $request = $request->withBody(Utils::streamFor(json_encode($requestBody)));
    
    $responseBody = $this->sendHttpRequest($request, $url)->getBody();
    
    return json_decode($responseBody)->token;
}
```

These tokens expire after the specified TTL (default 10 minutes). The SDK handles refreshing them, but you need to provide a callback function.

## Embedding the WebSDK

Now for the frontend. SumSub provides a JavaScript SDK that handles the entire verification UI - document upload, camera access, liveness detection, and progress feedback:

```blade
<x-app-layout>
    <!-- Load SumSub's SDK -->
    <script src="https://static.sumsub.com/idensic/static/sns-websdk-builder.js"></script>
    
    <div class="max-w-2xl mx-auto p-6">
        <h2 class="text-xl font-semibold mb-4">Verify Your Identity</h2>
        
        <p class="mb-4">
            We're required to verify that you are who you say you are. 
            This is quick and easy - just follow the steps below.
        </p>
        
        <ol class="list-decimal ml-6 mb-6 space-y-2">
            <li>Take a photo of your ID (passport, driver's license, or national ID)</li>
            <li>Take a selfie for liveness verification</li>
            <li>Upload proof of address if required (dated within the last 3 months)</li>
        </ol>
        
        <!-- SDK renders here -->
        <div id="sumsub-websdk-container"></div>
        
        <p class="mt-6 text-sm text-gray-500">
            Your data is processed securely by SumSub.
            See their <a href="https://sumsub.com/privacy-and-cookie-policy/" class="underline">Privacy Policy</a>.
        </p>
    </div>
    
    <script>
        function launchWebSdk(accessToken) {
            let snsWebSdkInstance = snsWebSdk.init(
                accessToken,
                // Token refresh callback - must return a Promise resolving to the new token
                () => getNewAccessToken()
            )
            .withConf({
                lang: 'en',
                // Custom styling to match your brand
                uiConf: {
                    customCssStr: `
                        :root { --black: #000000; --grey: #F5F5F5; }
                        button.submit { 
                            background-color: var(--black); 
                            border-radius: 6px; 
                        }
                    `
                },
                onMessage: (type, payload) => {
                    console.log('WebSDK message:', type, payload);
                },
                onError: (error) => {
                    console.error('WebSDK error:', error);
                },
            })
            .withOptions({ 
                addViewportTag: false, 
                adaptIframeHeight: true 
            })
            .on('stepCompleted', (payload) => {
                console.log('Step completed:', payload);
            })
            .build();
            
            snsWebSdkInstance.launch('#sumsub-websdk-container');
        }
        
        async function getNewAccessToken() {
            const sumsubUserId = @json($sumsub_user_id);
            const levelName = 'basic-kyc';

            const response = await fetch(`/api/verifications/token/${sumsubUserId}/${levelName}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            
            if (!response.ok) {
                throw new Error('Token refresh failed');
            }
            
            const data = await response.json();
            
            // Return just the token string, not the full response object
            return data.token;
        }
        
        // Launch SDK with initial token
        const accessToken = @json($generated_access_token);
        launchWebSdk(accessToken);
    </script>
</x-app-layout>
```

The SDK handles all the complexity of document capture, image quality validation, and secure upload. Your job is just to embed it and style it to match your app.

Note the token refresh callback returns `data.token` - the SDK expects a string, not the full response object.

## Handling Webhooks

The real magic happens asynchronously. When SumSub finishes processing a verification, they send a webhook to your server. This is where you update your database and trigger any downstream actions.

First, let's verify the webhook signature to ensure it's actually from SumSub:

```php
private function verifyWebhookSignature(HttpRequest $request): bool
{
    $signature = $request->header('X-Payload-Digest');
    $algorithm = $request->header('X-Payload-Digest-Alg');
    
    if (!$signature || !$algorithm) {
        return false;
    }
    
    $webhookSecret = config('services.sumsub.webhook_secret');
    $payload = $request->getContent();
    
    if ($algorithm === 'HMAC_SHA256_HEX') {
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
    } elseif ($algorithm === 'HMAC_SHA512_HEX') {
        $expectedSignature = hash_hmac('sha512', $payload, $webhookSecret);
    } else {
        Log::warning('Unknown webhook signature algorithm', ['algorithm' => $algorithm]);
        return false;
    }
    
    return hash_equals($expectedSignature, $signature);
}
```

Now the main webhook handler:

```php
public function handleWebhook(HttpRequest $request): array
{
    // Verify the webhook is actually from SumSub
    if (!$this->verifyWebhookSignature($request)) {
        Log::warning('Invalid SumSub webhook signature');
        abort(401, 'Invalid signature');
    }

    Log::info('SumSub webhook received', ['payload' => $request->all()]);

    $webhook = $request->all();

    // Parse the external user ID we sent to SumSub (e.g., "user_123")
    $sumsubUserId = $webhook['externalUserId'];

    // Extract the user ID from our prefixed format
    if (str_starts_with($sumsubUserId, 'user_')) {
        $userId = (int) str_replace('user_', '', $sumsubUserId);
        $user = User::findOrFail($userId);
    } else {
        // Handle other entity types (see "Verifying Non-Users" section)
        throw new \Exception("Unknown external user ID format: {$sumsubUserId}");
    }

    // Handle different webhook types
    match ($webhook['type']) {
        'applicantCreated' => $this->handleApplicantCreated($user, $webhook),
        'applicantPending' => $this->handleApplicantPending($user),
        'applicantOnHold' => $this->handleApplicantOnHold($user),
        'applicantReviewed' => $this->handleApplicantReviewed($user, $webhook),
        default => $this->handleUnknownType($user, $webhook),
    };

    $user->save();

    // Acknowledge receipt
    return ['status' => 'OK'];
}

private function handleApplicantCreated(User $user, array $webhook): void
{
    $user->sumsub_applicant_id = $webhook['applicantId'];
    Log::info('Applicant created', ['id' => $webhook['applicantId']]);
}

private function handleApplicantPending(User $user): void
{
    $user->verification_status = 'processing';
    Log::info('Verification processing');
}

private function handleApplicantOnHold(User $user): void
{
    $user->verification_status = 'on_hold';
    Log::info('Verification on hold - manual review required');
}

private function handleApplicantReviewed(User $user, array $webhook): void
{
    $reviewResult = $webhook['reviewResult']['reviewAnswer'];

    if ($reviewResult === 'GREEN') {
        $user->verification_status = 'verified';
        Log::info('Verification passed');

    } elseif ($reviewResult === 'RED') {
        $rejectType = $webhook['reviewResult']['reviewRejectType'] ?? null;

        if ($rejectType === 'RETRY') {
            $user->verification_status = 'retry_requested';
            Log::info('Verification needs retry');
        } else {
            $user->verification_status = 'failed';
            Log::info('Verification failed');

            event(new \App\Events\UserFailedVerification($user));
        }
    }
}

private function handleUnknownType(User $user, array $webhook): void
{
    Log::warning('Unhandled webhook type', ['type' => $webhook['type']]);
}
```

Register the webhook route. Since webhooks come from SumSub (not your frontend), they don't need CSRF protection:

```php
// routes/api.php
Route::post('/webhooks/sumsub', [VerificationController::class, 'handleWebhook'])
    ->withoutMiddleware(['throttle:api']); // Webhooks shouldn't be rate-limited
```

## Verifying Non-Users

So far we've focused on verifying people who have accounts in your application. But many real-world scenarios require verifying people who *don't* have accounts.

Consider a legal firm building software to manage company formations. Regulations require KYC verification on all company directors - but only one director (the client) actually uses the platform. The other directors are just names in a database. They don't have login credentials, they're not `User` models, but they still need to complete identity verification.

Or imagine a property management platform where landlords must verify their identity. The landlord might be represented by a property manager who has the account. The landlord themselves never logs in, but they still need to prove who they are.

This is where a polymorphic `Verification` model becomes useful:

```php
// app/Models/Verification.php
class Verification extends Model
{
    protected $fillable = [
        'verifiable_id',
        'verifiable_type',
        'status',
        'result',
        'sumsub_user_id',      // The ID we send to SumSub
        'sumsub_applicant_id', // The ID SumSub returns
        'verification_url',    // UUID for the public verification link
    ];

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

Now any model can be verifiable:

```php
// A company director who doesn't have a user account
class Director extends Model
{
    public function verification(): MorphOne
    {
        return $this->morphOne(Verification::class, 'verifiable');
    }
}

// Your regular User model
class User extends Model
{
    public function verification(): MorphOne
    {
        return $this->morphOne(Verification::class, 'verifiable');
    }
}
```

When you need to verify a non-user, create a verification record with a unique public URL:

```php
use Illuminate\Support\Str;

$verification = Verification::create([
    'verifiable_id' => $director->id,
    'verifiable_type' => Director::class,
    'status' => 'pending',
    'sumsub_user_id' => 'director_' . $director->id,  // Prefixed for webhook routing
    'verification_url' => Str::uuid(),
]);

// Send this link via email
$verificationLink = url('/verify/' . $verification->verification_url);
```

The controller for this public route doesn't require authentication:

```php
public function createExternally(string $uuid): View
{
    $verification = Verification::where('verification_url', $uuid)->firstOrFail();

    $levelName = 'basic-kyc';
    $accessToken = $this->getAccessToken($verification->sumsub_user_id, $levelName);

    return view('verifications.create-externally', [
        'generated_access_token' => $accessToken,
        'sumsub_user_id' => $verification->sumsub_user_id,
    ]);
}
```

The UUID in the URL is unguessable, so only people with the link can access the verification page.

Update your webhook handler to route based on the prefix:

```php
$sumsubUserId = $webhook['externalUserId'];

if (str_starts_with($sumsubUserId, 'user_')) {
    $userId = (int) str_replace('user_', '', $sumsubUserId);
    $verifiable = User::findOrFail($userId);
} elseif (str_starts_with($sumsubUserId, 'director_')) {
    $directorId = (int) str_replace('director_', '', $sumsubUserId);
    $verifiable = Director::findOrFail($directorId);
}

// Update verification status on the verifiable model or its verification record
$verifiable->verification->update([
    'status' => 'verified',
    // ...
]);
```

This pattern scales to any number of verifiable entity types. The prefix convention keeps webhook routing simple, and the polymorphic relationship keeps your data model clean.

## Tracking Verification Status

With multiple status values and the asynchronous nature of verification, build a helper to display human-readable status messages. This is especially useful when using the polymorphic `Verification` model for non-users:

```php
// On the Verification model or a trait shared by verifiable models
public function getStatusMessage(): string
{
    return match ($this->status) {
        'pending' => "Verification link sent. Waiting for completion.",
        'processing' => 'Documents submitted. Under review.',
        'retry_requested' => 'There was an issue. Please resubmit your documents.',
        'on_hold' => 'Verification requires additional review.',
        'failed' => 'Verification was unsuccessful. Contact support for assistance.',
        'verified' => 'Identity verified.',
        default => "Status: {$this->status}",
    };
}
```

For simpler user-only verification, you can put this directly on the User model using `$this->verification_status`.

## Security Considerations

**Verify webhook signatures.** We covered this above, but it's worth emphasizing: always check the `X-Payload-Digest` header before processing any webhook.

**Rate limit token generation.** The token endpoint could be abused to generate many tokens. Add rate limiting:

```php
// routes/api.php
Route::post('/api/verifications/token/{userId}/{levelName}', [VerificationController::class, 'refreshToken'])
    ->middleware(['auth', 'throttle:10,1']); // 10 requests per minute
```

**Log everything.** Identity verification is often audited. Log webhook payloads, status changes, and any errors.

**Handle failures gracefully.** SumSub might be down. Cache tokens, retry failed requests, and show users helpful error messages.

## Conclusion

Integrating identity verification isn't conceptually difficult - it's just fiddly. You're coordinating between your backend, a JavaScript SDK, and asynchronous webhooks. The pieces are simple; the complexity is in handling all the edge cases.

Start with the happy path: token generation, SDK embedding, and basic webhook handling. Then layer in error handling, retry logic, and status messaging. Before you know it, you'll have a robust verification flow that satisfies regulators and provides a smooth user experience.

The same patterns apply to other verification services. The API details differ, but the architecture - tokens, SDKs, webhooks - remains consistent. Once you've integrated one, you can integrate them all.