---
title: "Integrating Third-Party Identity Verification (SumSub) into Laravel"
date: 2025-01-09
excerpt: "Identity verification is a black box until you have to build it. Here's how to integrate SumSub's KYC service with webhooks, token refresh, and graceful error handling."
tags: [laravel, integration, kyc, verification, api]
slug: integrating-sumsub-identity-verification-laravel
---

If you're building software for regulated industries—finance, healthcare, property management, marketplace platforms—you'll eventually need to verify that users are who they claim to be. Know Your Customer (KYC) requirements aren't optional. They're often the law.

You have two options: build verification infrastructure yourself (document scanning, liveness detection, database checks against sanctions lists) or integrate a third-party service that specializes in this.

Unless identity verification is your core business, the second option wins. Services like SumSub, Onfido, and Jumio have teams dedicated to staying ahead of fraud techniques and regulatory changes. Your job is to integrate their SDK and handle the results.

This post walks through integrating SumSub into a Laravel application. The concepts apply to similar services—they all follow the same general pattern of SDK embedding, webhook handling, and status management.

## The Integration Architecture

A typical identity verification flow has four parts:

1. **Create an applicant** on the verification service
2. **Generate an access token** that lets the user interact with the SDK
3. **Embed the SDK** in your frontend for document upload and liveness checks
4. **Handle webhooks** when verification completes or fails

Let's implement each piece.

## Setting Up the Controller

First, a controller to handle verification-related requests:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Verification;
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
        $verification = $user->verification;
        
        // Get an external user ID from our verification record
        $externalUserId = $verification->external_user_id;
        $levelName = 'basic-kyc'; // SumSub verification level
        
        // Generate a temporary access token for the SDK
        $accessToken = $this->getAccessToken($externalUserId, $levelName);
        
        return view('verifications.create', [
            'generated_access_token' => $accessToken,
            'external_user_id' => $externalUserId,
        ]);
    }
}
```

The `external_user_id` is a unique identifier we generate when creating a verification record. SumSub uses this to link their applicant to our user. We store it so we can match webhook events back to our records.

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

The signature proves that the request came from someone who knows your secret key and hasn't been tampered with in transit. The timestamp prevents replay attacks—old signatures become invalid.

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
                'correlationId' => $response->getHeader('X-Correlation-Id'),
                'status' => $response->getStatusCode(),
            ]);
        }
        
        return $response;
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        Log::error('SumSub request failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}
```

## Creating an Applicant

Before a user can verify their identity, you need to create an "applicant" record on SumSub's side:

```php
public function createApplicant(string $externalUserId, string $levelName): string
{
    $requestBody = [
        'externalUserId' => $externalUserId,
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

The `levelName` refers to a verification flow you configure in SumSub's dashboard. Different levels can require different documents—ID only, ID plus proof of address, ID plus selfie, etc.

## Generating Access Tokens

The WebSDK needs a short-lived token to authenticate the user's session. This token is scoped to a specific user and level:

```php
public function getAccessToken(string $externalUserId, string $levelName): string
{
    $url = '/resources/accessTokens?userId=' . urlencode($externalUserId) . '&levelName=' . urlencode($levelName);
    
    $request = new Request(
        'POST', 
        config('services.sumsub.api_url') . $url
    );
    
    $responseBody = $this->sendHttpRequest($request, $url)->getBody();
    
    return json_decode($responseBody)->token;
}
```

These tokens expire after a short period (typically 10-30 minutes). The SDK handles refreshing them, but you need to provide a callback function.

## Embedding the WebSDK

Now for the frontend. SumSub provides a JavaScript SDK that handles the entire verification UI—document upload, camera access, liveness detection, and progress feedback:

```blade
<x-app-layout>
    <!-- Load SumSub's SDK -->
    <script src="https://static.sumsub.com/idensic/static/sns-websdk-builder.js"></script>
    
    <div class="max-w-2xl mx-auto p-6">
        <h2 class="text-xl font-semibold mb-4">Verify Your Identity</h2>
        
        <p class="mb-4">
            We're required to verify that you are who you say you are. 
            This is quick and easy—just follow the steps below.
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
                // Token refresh callback
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
            const externalUserId = @json($external_user_id);
            const levelName = 'basic-kyc';
            
            const response = await fetch(`/api/verifications/token/${externalUserId}/${levelName}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            
            if (!response.ok) {
                throw new Error('Token refresh failed');
            }
            
            return response.json();
        }
        
        // Launch SDK with initial token
        const accessToken = @json($generated_access_token);
        launchWebSdk(accessToken);
    </script>
</x-app-layout>
```

The SDK handles all the complexity of document capture, image quality validation, and secure upload. Your job is just to embed it and style it to match your app.

## Handling Webhooks

The real magic happens asynchronously. When SumSub finishes processing a verification, they send a webhook to your server. This is where you update your database and trigger any downstream actions:

```php
public function handleWebhook(HttpRequest $request): array
{
    Log::info('SumSub webhook received', ['payload' => $request->all()]);
    
    $webhook = $request->all();
    
    // Find the verification record by external user ID
    $verification = Verification::where('external_user_id', $webhook['externalUserId'])
        ->firstOrFail();
    
    // Handle different webhook types
    match ($webhook['type']) {
        'applicantCreated' => $this->handleApplicantCreated($verification, $webhook),
        'applicantPending' => $this->handleApplicantPending($verification),
        'applicantOnHold' => $this->handleApplicantOnHold($verification),
        'applicantReviewed' => $this->handleApplicantReviewed($verification, $webhook),
        default => $this->handleUnknownType($verification, $webhook),
    };
    
    $verification->save();
    
    // Acknowledge receipt
    return ['status' => 'OK'];
}

private function handleApplicantCreated(Verification $verification, array $webhook): void
{
    $verification->sumsub_applicant_id = $webhook['applicantId'];
    Log::info('Applicant created', ['id' => $webhook['applicantId']]);
}

private function handleApplicantPending(Verification $verification): void
{
    $verification->status = 'processing';
    Log::info('Verification processing');
}

private function handleApplicantOnHold(Verification $verification): void
{
    $verification->status = 'on_hold';
    Log::info('Verification on hold - manual review required');
}

private function handleApplicantReviewed(Verification $verification, array $webhook): void
{
    $reviewResult = $webhook['reviewResult']['reviewAnswer'];
    
    if ($reviewResult === 'GREEN') {
        $verification->status = 'complete';
        $verification->result = 'pass';
        Log::info('Verification passed');
        
    } elseif ($reviewResult === 'RED') {
        $rejectType = $webhook['reviewResult']['reviewRejectType'] ?? null;
        
        if ($rejectType === 'RETRY') {
            $verification->status = 'processing';
            $verification->result = 'retry_requested';
            Log::info('Verification needs retry');
        } else {
            $verification->status = 'complete';
            $verification->result = 'fail';
            Log::info('Verification failed');
            
            event(new \App\Events\UserFailedVerification($verification));
        }
    }
}

private function handleUnknownType(Verification $verification, array $webhook): void
{
    $verification->status = 'processing';
    Log::warning('Unhandled webhook type', ['type' => $webhook['type']]);
}
```

Register the webhook route without CSRF protection (webhooks come from SumSub, not your frontend):

```php
// routes/api.php
Route::post('/webhooks/sumsub', [VerificationController::class, 'handleWebhook']);
```

## External User Verification

Not all users have accounts in your system. Sometimes you need to verify someone who isn't registered—a company officer, a beneficial owner, someone named in documents.

Create a verification flow that works without authentication:

```php
public function createExternally(string $uuid): View
{
    // Look up verification by a unique URL token
    $verification = Verification::where('external_url_string', $uuid)->first();
    
    if (!$verification) {
        abort(404);
    }
    
    $levelName = 'basic-kyc';
    $accessToken = $this->getAccessToken($verification->external_user_id, $levelName);
    
    return view('verifications.create-externally', [
        'generated_access_token' => $accessToken,
        'external_user_id' => $verification->external_user_id,
    ]);
}
```

When creating verification records for external users, generate a unique URL:

```php
use Illuminate\Support\Str;

$verification = Verification::create([
    'verifiable_id' => $member->id,
    'verifiable_type' => $member::class,
    'status' => 'not_requested',
    'external_user_id' => uniqid('usr_'), // For SumSub
    'external_url_string' => Str::uuid(),  // For our public URL
]);

// Send verification link
$verificationUrl = url('/verify/' . $verification->external_url_string);
```

The `external_url_string` is a UUID that's hard to guess. The recipient clicks the link, completes verification, and webhooks update your records.

## Tracking Verification Status

With multiple status fields and the asynchronous nature of verification, build a helper to display human-readable status messages:

```php
public function getVerificationStatusMessage(): string
{
    $verification = $this->verification;
    
    if (!$verification) {
        return 'Verification record not found. Contact support.';
    }
    
    return match ([$verification->status, $verification->result]) {
        ['not_requested', null] => 
            "We haven't sent the verification request yet.",
        ['requested', null] => 
            'Verification email sent. Waiting for completion.',
        ['processing', 'pending'] => 
            'Documents submitted. Under review.',
        ['processing', 'retry_requested'] => 
            'There was an issue. Please resubmit your documents.',
        ['on_hold', null] => 
            'Verification requires additional review.',
        ['complete', 'fail'] => 
            'Verification was unsuccessful. Contact support for assistance.',
        ['complete', 'pass'] => 
            'Verification approved. You\'re all set!',
        default => 
            "Status: {$verification->status}",
    };
}
```

## Configuration

Store your SumSub credentials in environment variables:

```env
SUMSUB_API_URL=https://api.sumsub.com
SUMSUB_APP_TOKEN=your_app_token
SUMSUB_SECRET_KEY=your_secret_key
```

Reference them in config:

```php
// config/services.php
'sumsub' => [
    'api_url' => env('SUMSUB_API_URL'),
    'app_token' => env('SUMSUB_APP_TOKEN'),
    'secret_key' => env('SUMSUB_SECRET_KEY'),
],
```

## Security Considerations

**Verify webhook signatures.** SumSub signs webhooks so you can verify they're legitimate. In production, always check the signature before processing.

**Rate limit token generation.** The token endpoint could be abused to generate many tokens. Add rate limiting.

**Log everything.** Identity verification is often audited. Log webhook payloads, status changes, and any errors.

**Handle failures gracefully.** SumSub might be down. Cache tokens, retry failed requests, and show users helpful error messages.

## Conclusion

Integrating identity verification isn't conceptually difficult—it's just fiddly. You're coordinating between your backend, a JavaScript SDK, and asynchronous webhooks. The pieces are simple; the complexity is in handling all the edge cases.

Start with the happy path: token generation, SDK embedding, and basic webhook handling. Then layer in error handling, retry logic, and status messaging. Before you know it, you'll have a robust verification flow that satisfies regulators and provides a smooth user experience.

The same patterns apply to other verification services. The API details differ, but the architecture—tokens, SDKs, webhooks—remains consistent. Once you've integrated one, you can integrate them all.
