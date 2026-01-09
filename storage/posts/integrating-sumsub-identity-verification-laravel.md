---
title: "Integrating Third-Party Identity Verification (SumSub) into Laravel"
date: 2025-01-09
excerpt: "KYC and AML verification is a black box until you have to build it. Here's how to integrate SumSub's identity verification service with webhooks, token refresh, and graceful error handling."
tags: [laravel, integration, kyc, aml, verification, api]
slug: integrating-sumsub-identity-verification-laravel
---

If you're building software for regulated industries—finance, legal, healthcare—you'll eventually need to verify that users are who they claim to be. Know Your Customer (KYC) and Anti-Money Laundering (AML) requirements aren't optional. They're the law.

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
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use GuzzleHttp;

class VerificationController extends Controller
{
    /**
     * Show the verification form for authenticated users
     */
    public function create()
    {
        $user = Auth::user();
        $verification = $user->verifications->first();
        
        // Get an external user ID from our verification record
        $externalUserId = $verification->external_user_id;
        $levelName = 'Onboarding'; // SumSub verification level
        
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
private function createSignature($ts, $httpMethod, $url, $httpBody)
{
    // Concatenate: timestamp + HTTP method (uppercase) + URL path + request body
    $signatureString = $ts . strtoupper($httpMethod) . $url . $httpBody;
    
    // HMAC-SHA256 with your secret key
    return hash_hmac('sha256', $signatureString, config('app.sumsub_secret_key'));
}
```

The signature proves that the request came from someone who knows your secret key and hasn't been tampered with in transit. The timestamp prevents replay attacks—old signatures become invalid.

Here's how to send an authenticated request:

```php
public function sendHttpRequest($request, $url)
{
    $client = new GuzzleHttp\Client();
    $ts = time(); // Current Unix timestamp
    
    // Add authentication headers
    $request = $request->withHeader('X-App-Token', config('app.sumsub_app_token'));
    $request = $request->withHeader('X-App-Access-Sig', 
        $this->createSignature($ts, $request->getMethod(), $url, $request->getBody())
    );
    $request = $request->withHeader('X-App-Access-Ts', $ts);
    
    // Reset stream position before sending
    // (Guzzle may have read the body during header generation)
    $request->getBody()->rewind();
    
    try {
        $response = $client->send($request);
        
        if ($response->getStatusCode() != 200 && $response->getStatusCode() != 201) {
            // Log the correlation ID for debugging with SumSub support
            Log::error('SumSub API error', [
                'correlationId' => $response->getHeader('X-Correlation-Id'),
                'status' => $response->getStatusCode(),
            ]);
        }
        
        return $response;
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        Log::error('SumSub request failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}
```

## Creating an Applicant

Before a user can verify their identity, you need to create an "applicant" record on SumSub's side:

```php
public function createApplicant($externalUserId, $levelName)
{
    $requestBody = [
        'externalUserId' => $externalUserId
    ];
    
    $url = '/resources/applicants?levelName=' . $levelName;
    
    $request = new GuzzleHttp\Psr7\Request(
        'POST', 
        config('app.sumsub_api_url') . $url
    );
    $request = $request->withHeader('Content-Type', 'application/json');
    $request = $request->withBody(
        GuzzleHttp\Psr7\Utils::streamFor(json_encode($requestBody))
    );
    
    $responseBody = $this->sendHttpRequest($request, $url)->getBody();
    
    // Return SumSub's applicant ID for future reference
    return json_decode($responseBody)->{'id'};
}
```

The `levelName` refers to a verification flow you configure in SumSub's dashboard. Different levels can require different documents—ID only, ID plus proof of address, ID plus selfie, etc.

## Generating Access Tokens

The WebSDK needs a short-lived token to authenticate the user's session. This token is scoped to a specific user and level:

```php
public function getAccessToken($externalUserId, $levelName)
{
    $url = "/resources/accessTokens?userId=" . $externalUserId . "&levelName=" . $levelName;
    
    $request = new GuzzleHttp\Psr7\Request(
        'POST', 
        config('app.sumsub_api_url') . $url
    );
    
    $responseBody = $this->sendHttpRequest($request, $url)->getBody();
    
    return json_decode($responseBody)->{'token'};
}
```

These tokens expire after a short period (typically 10-30 minutes). The SDK handles refreshing them, but you need to provide a callback function.

## Embedding the WebSDK

Now for the frontend. SumSub provides a JavaScript SDK that handles the entire verification UI—document upload, camera access, liveness detection, and progress feedback:

```blade
<x-app-layout>
    <!-- Load SumSub's SDK -->
    <script src="https://static.sumsub.com/idensic/static/sns-websdk-builder.js"></script>
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    
    <div class="max-w-2xl mx-auto p-6">
        <h2 class="text-xl font-semibold mb-4">Verify Your Identity</h2>
        
        <p class="mb-4">
            We're required to verify that you are who you say you are. 
            This is quick and easy—just follow the steps below.
        </p>
        
        <ol class="list-decimal ml-6 mb-6 space-y-2">
            <li>Take a photo of your ID (passport, driver's license, or national ID)</li>
            <li>Take a selfie holding your ID next to your face</li>
            <li>Upload proof of address (dated within the last 3 months)</li>
        </ol>
        
        <!-- SDK renders here -->
        <div id="sumsub-websdk-container"></div>
        
        <p class="mt-6 text-sm text-gray-500">
            We can't see any data you provide to SumSub except the verification result.
            See their <a href="https://sumsub.com/privacy-and-cookie-policy/">Privacy Policy</a>.
        </p>
    </div>
    
    <script>
        function launchWebSdk(accessToken) {
            let snsWebSdkInstance = snsWebSdk.init(
                accessToken,
                // Token refresh callback
                () => this.getNewAccessToken()
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
        
        function getNewAccessToken() {
            let externalUserId = @json($external_user_id);
            let levelName = 'Onboarding';
            
            return axios.post(`/api/verifications/token/${externalUserId}/${levelName}`)
                .then(response => response.data)
                .catch(error => {
                    console.error('Token refresh failed:', error);
                });
        }
        
        // Launch SDK with initial token
        let accessToken = @json($generated_access_token);
        launchWebSdk(accessToken);
    </script>
</x-app-layout>
```

The SDK handles all the complexity of document capture, image quality validation, and secure upload. Your job is just to embed it and style it to match your app.

## Handling Webhooks

The real magic happens asynchronously. When SumSub finishes processing a verification, they send a webhook to your server. This is where you update your database and trigger any downstream actions:

```php
public function handleWebhook(Request $request)
{
    Log::info('SumSub webhook received', ['payload' => $request->all()]);
    
    $webhook = $request->all();
    
    // Find the verification record by external user ID
    $verification = Verification::where('external_user_id', $webhook['externalUserId'])
        ->firstOrFail();
    
    // Handle different webhook types
    switch ($webhook['type']) {
        
        case 'applicantCreated':
            // Store SumSub's applicant ID for future API calls
            $verification->sumsub_applicant_id = $webhook['applicantId'];
            Log::info('Applicant created', ['id' => $webhook['applicantId']]);
            break;
            
        case 'applicantPending':
            // User has submitted documents, review in progress
            $verification->status = 'processing';
            Log::info('Verification processing');
            break;
            
        case 'applicantOnHold':
            // Manual review required
            $verification->status = 'on hold';
            Log::info('Verification on hold');
            break;
            
        case 'applicantReviewed':
            // Final decision received
            $reviewResult = $webhook['reviewResult']['reviewAnswer'];
            
            if ($reviewResult === 'GREEN') {
                // Approved
                $verification->status = 'complete';
                $verification->result = 'pass';
                Log::info('Verification passed');
                
            } elseif ($reviewResult === 'RED') {
                // Check if it's a retry request or final rejection
                $rejectType = $webhook['reviewResult']['reviewRejectType'] ?? null;
                
                if ($rejectType === 'RETRY') {
                    // User needs to resubmit documents
                    $verification->status = 'processing';
                    $verification->result = 'retrying';
                    Log::info('Verification needs retry');
                } else {
                    // Final rejection
                    $verification->status = 'complete';
                    $verification->result = 'fail';
                    Log::info('Verification failed');
                    
                    // Trigger notification to ops team
                    event(new UserFailedVerification($verification));
                }
            }
            break;
            
        default:
            $verification->status = 'processing';
            Log::warning('Unhandled webhook type', ['type' => $webhook['type']]);
    }
    
    $verification->save();
    
    // Acknowledge receipt
    return ['status' => 'OK'];
}
```

Register the webhook route without CSRF protection (webhooks come from SumSub, not your frontend):

```php
// routes/api.php
Route::post('/sumsub', [VerificationController::class, 'handleWebhook']);
```

## External User Verification

Not all users have accounts in your system. Sometimes you need to verify someone who isn't registered—a company officer, a beneficial owner, someone named in documents.

Create a verification flow that works without authentication:

```php
public function createExternally(string $uuid)
{
    // Look up verification by a unique URL token
    $verification = Verification::where('external_url_string', $uuid)->first();
    
    if (!$verification) {
        abort(404);
    }
    
    $levelName = 'Onboarding';
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
    'client_member_id' => $clientMember->id,
    'status' => 'not requested',
    'external_user_id' => uniqid(), // For SumSub
    'external_url_string' => Str::uuid(), // For our public URL
]);

// Send verification link
$verificationUrl = url('/verify/' . $verification->external_url_string);
```

The `external_url_string` is a UUID that's hard to guess. The recipient clicks the link, completes verification, and webhooks update your records.

## Tracking Verification Status

With multiple status fields and the asynchronous nature of verification, build a helper to display human-readable status messages:

```php
public function getVerificationStatus(): string
{
    $verification = $this->verification;
    
    if (!$verification) {
        return "Verification record not found. Contact support.";
    }
    
    return match([$verification->status, $verification->result]) {
        ['not requested', null] => 
            "We haven't sent the verification request yet.",
        ['requested', null] => 
            "Verification email sent. Waiting for completion.",
        ['processing', 'pending'] => 
            "Documents submitted. SumSub is reviewing.",
        ['processing', 'retrying'] => 
            "There was an issue. Please resubmit your documents.",
        ['complete', 'fail'] => 
            "Verification was rejected. Contact support.",
        ['complete', 'pass'] => 
            "Verification approved. You're all set!",
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
// config/app.php
'sumsub_api_url' => env('SUMSUB_API_URL'),
'sumsub_app_token' => env('SUMSUB_APP_TOKEN'),
'sumsub_secret_key' => env('SUMSUB_SECRET_KEY'),
```

## Security Considerations

**Verify webhook signatures.** SumSub signs webhooks so you can verify they're legitimate. In production, check the signature before processing.

**Rate limit token generation.** The token endpoint could be abused to generate many tokens. Add rate limiting.

**Log everything.** Identity verification is often audited. Log webhook payloads, status changes, and any errors.

**Handle failures gracefully.** SumSub might be down. Cache tokens, retry failed requests, and show users helpful error messages.

## Conclusion

Integrating identity verification isn't conceptually difficult—it's just fiddly. You're coordinating between your backend, a JavaScript SDK, and asynchronous webhooks. The pieces are simple; the complexity is in handling all the edge cases.

Start with the happy path: token generation, SDK embedding, and basic webhook handling. Then layer in error handling, retry logic, and status messaging. Before you know it, you'll have a robust verification flow that satisfies regulators and provides a smooth user experience.

The same patterns apply to other verification services. The API details differ, but the architecture—tokens, SDKs, webhooks—remains consistent. Once you've integrated one, you can integrate them all.

