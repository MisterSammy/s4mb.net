---
title: "Real-Time Multiplayer Games with Laravel Reverb and Phaser.js"
date: 2026-01-10
excerpt: "You don't need a Node.js backend or a dedicated game server to build multiplayer browser games. This post shows how Laravel Reverb and Phaser.js combine to create a real-time multiplayer experience using the stack you already know."
tags: [laravel, websockets, reverb, phaser, multiplayer, tutorial]
slug: real-time-multiplayer-games-with-laravel-reverb-and-phaserjs
---

If you're a Laravel developer who's ever wanted to build a real-time multiplayer game, you've probably assumed you'd need to step outside your comfort zone. Maybe reach for Socket.io with a Node.js backend, or set up a dedicated game server in Go or Rust. The Laravel ecosystem, powerful as it is, isn't typically associated with game development.

But here's the thing: Laravel Reverb changes that equation. Combined with Phaser.js on the frontend, you can build a fully functional real-time multiplayer 2D game using the framework you already know. No new backend language required.

This post walks through how I built a multiplayer game where authenticated users move around a shared world, seeing each other's positions update in real-time. The key insight—and what makes this feel magical—is understanding how a logged-in Laravel user maps to a character on a Phaser canvas.

Here's the architecture we're working with:

```plaintext
app/
├── Events/
│   └── PlayerJoined.php          # Broadcast when player enters
├── Http/Controllers/
│   └── GameController.php        # Game API endpoints
resources/
├── js/
│   ├── echo.js                   # Laravel Echo configuration
│   └── phaser-game.js            # Phaser game implementation
└── views/
    └── dashboard.blade.php       # Game container with user data
routes/
├── channels.php                  # Presence channel authorization
└── web.php                       # HTTP routes
```

## The Magic: Connecting Users to Canvas Positions

The core question this architecture answers is: **How does a logged-in Laravel user become a character on a canvas?**

The answer involves three connection points:

1. **Blade passes user identity to JavaScript** via data attributes
2. **Presence channels tie WebSocket connections to authenticated users**
3. **Client events carry position data directly over the WebSocket**

Let me show you each piece.

### Step 1: Blade to Phaser

The dashboard view is deceptively simple:

```blade
<x-app-layout>
    <div id="phaser-game" 
         class="game-container" 
         data-user-id="{{ Auth::id() }}" 
         data-user-name="{{ $userName }}">
    </div>
</x-app-layout>
```

When Phaser initializes, it reads these data attributes:

```javascript
const gameContainer = document.getElementById('phaser-game');
const userId = parseInt(gameContainer.getAttribute('data-user-id'));
const userName = gameContainer.getAttribute('data-user-name');

const characterScene = new CharacterScene();
characterScene.userId = userId;
characterScene.userName = userName;
```

Now the game scene knows *who* is playing. This user ID becomes the key that connects everything else.

### Step 2: Presence Channels

Laravel's presence channels are designed for exactly this use case—tracking who's connected to a shared space. The channel authorization returns user data that other clients can see:

```php
// routes/channels.php
Broadcast::channel('game', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
```

When a player joins the presence channel, Laravel Echo provides three callbacks:

```javascript
window.Echo.join('game')
    .here((users) => {
        // Called once with all currently connected users
        users.forEach((user) => {
            if (user.id !== this.userId) {
                this.addOtherPlayer(user.id, user.name, defaultX, defaultY);
            }
        });
    })
    .joining((user) => {
        // Called when a new user joins
        if (user.id !== this.userId) {
            this.addOtherPlayer(user.id, user.name, defaultX, defaultY);
        }
    })
    .leaving((user) => {
        // Called when a user disconnects
        this.removeOtherPlayer(user.id);
    });
```

The presence channel handles the hard part—tracking who's online—automatically. You don't need to maintain a list of connected users or handle disconnection cleanup. Laravel does it for you.

One caveat: presence channels fire `leaving` per connection, not per user. If someone has two browser tabs open and closes one, other players will briefly see them "leave" even though they're still connected in the other tab. For a casual game this is fine, but for production you might want to track connection counts per user and only remove players when all their connections close.

### Step 3: Position Updates via WebSocket

Here's where it gets interesting. You might think position updates need to go through the server—POST to a controller, validate, broadcast. But we already have a WebSocket connection open through Echo. Why not use it directly?

Laravel Echo supports "client events" (also called whispers) that send messages directly between clients through the WebSocket, without hitting your Laravel application:

```javascript
// Send position to other players
broadcastPosition() {
    const now = Date.now();
    if (now - this.lastBroadcastTime < this.broadcastInterval) {
        return;
    }
    this.lastBroadcastTime = now;
    
    this.channel.whisper('position', {
        x: this.character.x,
        y: this.character.y
    });
}

// Listen for other players' positions
this.channel = window.Echo.join('game')
    .listenForWhisper('position', (e) => {
        // e.userId is automatically included from the presence channel
        if (e.userId && e.userId !== this.userId) {
            this.updateOtherPlayerPosition(e.userId, e.x, e.y);
        }
    });
```

This approach has significant advantages for game development:

- **Lower latency**: Messages go directly through the WebSocket instead of HTTP request → Laravel routing → controller → broadcast
- **Less server load**: Your Laravel app doesn't process every position update
- **Simpler code**: No controller endpoints for position updates

The trade-off is that client events bypass server validation. Clients report their own positions, and other clients trust those reports. For an exploration game or social space, this is perfectly fine. For a competitive game where cheating matters, you'd want server-authoritative movement—but that's a different architecture entirely.

To enable client events, update your Reverb configuration:

```php
// config/reverb.php
'apps' => [
    [
        'app_id' => env('REVERB_APP_ID'),
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'enable_client_messages' => true,  // Required for whisper
        'enable_statistics' => true,
    ],
],
```

**This is the connection.** The user ID from Laravel authentication flows through:
- Blade template → JavaScript scene (who you are)
- Presence channel → WebSocket connection (who's online)
- Client events → Position updates (where everyone is)

Every player knows who they are, the presence channel validates their identity, and position updates flow directly between clients.

## The Data Flow

Here's how a single movement propagates through the system:

```plaintext
┌─────────────────────────────────────────────────────────────────────┐
│                         PLAYER A'S BROWSER                          │
│  ┌─────────────────┐    ┌─────────────────┐                        │
│  │  Phaser Scene   │    │  Laravel Echo   │                        │
│  │                 │    │                 │                        │
│  │ WASD Key Press  │───▶│ channel.whisper │                        │
│  │ Update Position │    │ ('position',    │                        │
│  │                 │    │  {x, y})        │                        │
│  └─────────────────┘    └────────┬────────┘                        │
└──────────────────────────────────┼─────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         LARAVEL REVERB                              │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    WebSocket Server                          │   │
│  │                                                              │   │
│  │  Receives whisper on presence-game channel                   │   │
│  │  Broadcasts to all other subscribers                         │   │
│  │  (Laravel app not involved - direct WebSocket relay)         │   │
│  │                                                              │   │
│  └──────────────────────────────┬──────────────────────────────┘   │
└─────────────────────────────────┼───────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         PLAYER B'S BROWSER                          │
│  ┌─────────────────┐    ┌─────────────────┐                        │
│  │  Laravel Echo   │    │  Phaser Scene   │                        │
│  │                 │    │                 │                        │
│  │ listenFor       │───▶│ updateOther     │                        │
│  │ Whisper         │    │ PlayerPosition  │                        │
│  │ ('position')    │    │ (userId, x, y)  │                        │
│  └─────────────────┘    └─────────────────┘                        │
└─────────────────────────────────────────────────────────────────────┘
```

The round trip happens in milliseconds. Player A presses W, their position updates locally, Reverb relays the whisper, and Player B sees them move—all while both players remain authenticated Laravel users. In local testing, expect 20-50ms latency; over the internet, 50-150ms depending on geographic distance.

## Setting Up Laravel Reverb

Laravel Reverb is Laravel's first-party WebSocket server. Unlike Pusher (which is a hosted service), Reverb runs on your own infrastructure.

Install it with:

```bash
php artisan install:broadcasting
```

The configuration lives in two places.

**Backend configuration:**

```php
// config/broadcasting.php
'default' => env('BROADCAST_CONNECTION', 'reverb'),

'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST'),
            'port' => env('REVERB_PORT', 443),
            'scheme' => env('REVERB_SCHEME', 'https'),
        ],
    ],
],
```

**Frontend Echo configuration:**

```javascript
// resources/js/echo.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Uncomment for debugging connection issues
// Pusher.logToConsole = true;

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
if (reverbKey) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        cluster: 'mt1',
    });
}
```

Yes, you read that right—the frontend uses `pusher-js` even though Reverb isn't Pusher. Reverb implements the Pusher protocol, so the existing Pusher JavaScript library works out of the box. This is a deliberate design choice that lets you use battle-tested client libraries.

**Environment variables:**

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Presence channels require authentication. Echo automatically hits `/broadcasting/auth` to verify users can join a channel. Make sure `BroadcastServiceProvider` is registered in your `bootstrap/providers.php` and your `routes/channels.php` authorization is working.

## The Phaser Game Scene

The game uses Phaser 3, a mature JavaScript game framework. Here's the structure of the main scene:

```javascript
import Phaser from 'phaser';

class CharacterScene extends Phaser.Scene {
    constructor() {
        super({ key: 'CharacterScene' });
        
        // Player identity (set from data attributes)
        this.userId = null;
        this.userName = 'User';
        
        // Game objects
        this.character = null;
        this.otherPlayers = new Map(); // odId -> {text, nameText, position}
        
        // Networking
        this.channel = null;
        this.lastBroadcastTime = 0;
        this.broadcastInterval = 100; // ms between position updates
    }

    create() {
        // Create player character
        this.character = this.add.text(startX, startY, '@', {
            fontSize: '18px',
            fontFamily: 'monospace',
            color: '#ffff00'
        });
        
        // Set up keyboard controls
        this.cursors = {
            W: this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.W),
            A: this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.A),
            S: this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.S),
            D: this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.D),
        };
        
        // Initialize multiplayer
        this.initializeMultiplayer();
    }

    update(time, delta) {
        // Handle movement
        const moved = this.handleMovement(delta);
        
        // Broadcast if we moved
        if (moved) {
            this.broadcastPosition();
        }
        
        // Interpolate other players
        this.interpolateOtherPlayers(delta);
    }
    
    initializeMultiplayer() {
        if (!window.Echo) return;
        
        this.channel = window.Echo.join('game')
            .here((users) => {
                users.forEach((user) => {
                    if (user.id !== this.userId) {
                        this.addOtherPlayer(user.id, user.name, defaultX, defaultY);
                    }
                });
            })
            .joining((user) => {
                if (user.id !== this.userId) {
                    this.addOtherPlayer(user.id, user.name, defaultX, defaultY);
                }
            })
            .leaving((user) => {
                this.removeOtherPlayer(user.id);
            })
            .listenForWhisper('position', (e) => {
                if (e.userId && e.userId !== this.userId) {
                    if (!this.otherPlayers.has(e.userId)) {
                        this.addOtherPlayer(e.userId, e.userName, e.x, e.y);
                    } else {
                        this.setTargetPosition(e.userId, e.x, e.y);
                    }
                }
            })
            .listen('.player.joined', (e) => {
                if (e.userId !== this.userId) {
                    this.addOtherPlayer(e.userId, e.userName, e.x, e.y);
                }
            });
    }
    
    broadcastPosition() {
        const now = Date.now();
        if (now - this.lastBroadcastTime < this.broadcastInterval) {
            return;
        }
        this.lastBroadcastTime = now;
        
        this.channel?.whisper('position', {
            userId: this.userId,
            userName: this.userName,
            x: this.character.x,
            y: this.character.y
        });
    }
}
```

The game renders characters as ASCII text—`@` for the local player, letters like `T`, `R`, `F` for others. This gives it a retro terminal aesthetic, but the same architecture works for sprite-based games.

## Key Design Decisions

### Why Client Events Instead of HTTP?

The first version of this game used HTTP POST requests for position updates:

```javascript
// Old approach - don't do this
fetch('/game/position', {
    method: 'POST',
    body: JSON.stringify({ x, y })
});
```

This works, but adds unnecessary overhead. Each position update requires TCP connection handling, CSRF validation, Laravel routing, middleware execution, and controller instantiation—all before the broadcast happens.

With client events (whisper), messages flow directly through the WebSocket. Reverb relays them without involving your Laravel application. For position updates that happen 10 times per second, this difference matters.

The trade-off is trust. Client events bypass server validation entirely. If you need the server to verify positions (anti-cheat, collision detection, game logic), you'd use the HTTP approach or implement a hybrid where the server periodically validates client-reported positions.

### Why 100ms Update Intervals?

The client doesn't broadcast every frame. Instead, it throttles to 100ms intervals (10 updates per second):

```javascript
broadcastPosition() {
    const now = Date.now();
    if (now - this.lastBroadcastTime < this.broadcastInterval) {
        return;
    }
    this.lastBroadcastTime = now;
    
    this.channel?.whisper('position', { ... });
}
```

At 60 FPS, broadcasting every frame would mean 60 messages per second per player. That's excessive and would strain both Reverb and client connections.

10Hz is a common choice for casual multiplayer games. For reference, Minecraft runs at 20 ticks per second, and many MMOs use 10-15Hz for position updates. Competitive shooters use 60-128Hz, but they're optimized for that specific use case with UDP protocols and extensive netcode.

The key insight is that **interpolation makes low tick rates feel smooth**. Players don't perceive the 100ms gaps between updates because the client smoothly animates between known positions.

### Why Interpolation?

Network updates arrive at 100ms intervals, but the game renders at 60 FPS. Without interpolation, other players would teleport between positions. Smooth interpolation fills the gaps:

```javascript
interpolateOtherPlayers(delta) {
    // delta is milliseconds since last frame
    const lerpFactor = 1 - Math.pow(0.00001, delta / 1000);
    
    this.otherPlayers.forEach((player) => {
        // Smoothly move toward target position
        player.currentX = Phaser.Math.Linear(player.currentX, player.targetX, lerpFactor);
        player.currentY = Phaser.Math.Linear(player.currentY, player.targetY, lerpFactor);
        
        // Update visual position
        player.sprite.setPosition(player.currentX, player.currentY);
    });
}
```

Using `delta` in the lerp calculation makes the interpolation frame-rate independent. Whether the game runs at 30 FPS or 144 FPS, movement appears consistent.

### Why Presence Channels?

Presence channels provide automatic user tracking. When a player's browser closes or their connection drops, Laravel automatically fires the `leaving` callback for all other clients. You don't need to implement heartbeats, timeouts, or cleanup logic—it's handled at the framework level.

The alternative would be managing a list of connected users yourself, implementing keep-alive pings, and handling edge cases like network interruptions. Presence channels abstract all of that away.

## Handling the Join Flow

When a player first loads the game, they need to announce their existence with their starting position. This one does go through the server, since it's a one-time event that benefits from server validation:

```javascript
broadcastJoin(x, y) {
    if (!window.Echo || this.hasJoined) {
        return;
    }

    this.hasJoined = true;

    fetch('/game/join', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        },
        body: JSON.stringify({ x, y })
    });
}
```

The server broadcasts a `PlayerJoined` event:

```php
// app/Http/Controllers/GameController.php
public function join(Request $request)
{
    $request->validate([
        'x' => 'required|numeric',
        'y' => 'required|numeric',
    ]);

    $user = Auth::user();
    
    event(new PlayerJoined(
        $user->id,
        $user->name,
        $request->x,
        $request->y
    ));

    return response()->json(['success' => true]);
}
```

```php
// app/Events/PlayerJoined.php
class PlayerJoined implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $userId;
    public $userName;
    public $x;
    public $y;

    public function __construct($userId, $userName, $x, $y)
    {
        $this->userId = $userId;
        $this->userName = $userName;
        $this->x = $x;
        $this->y = $y;
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('game'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'player.joined';
    }
}
```

Notice `ShouldBroadcastNow` instead of `ShouldBroadcast`. The difference is queue handling—`ShouldBroadcast` puts the event on Laravel's queue, adding latency. For a game where events need to arrive immediately, bypassing the queue is essential.

The presence channel's `here()` callback handles players who were already in the game when you joined, while the `PlayerJoined` event handles announcing your arrival to players who are already there.

## Running the Development Server

Laravel provides a convenient way to run all required services:

```bash
composer run dev
```

This typically starts:
- Laravel development server (port 8000)
- Vite dev server (for hot reloading)
- Laravel Reverb WebSocket server (port 8080)
- Queue worker (if needed)

For production, you'd run Reverb as a supervised daemon:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Use Supervisor or systemd to keep it running:

```ini
[program:reverb]
command=php /var/www/app/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
```

## Scaling Considerations

**Connection limits.** Reverb's default `stream_select` event loop handles around 1,000 concurrent WebSocket connections. For more, install the `ext-uv` PHP extension—Reverb automatically uses it when available and can then handle thousands of connections. For even larger scale, Laravel Cloud offers managed Reverb clusters.

**Rate limiting.** Reverb doesn't currently rate-limit WebSocket messages. A malicious client could flood your server with whispers. For production games, implement client-side throttling (which we do with `broadcastInterval`), and consider adding server-side rate limiting if you switch to HTTP-based position updates.

**Geographic distribution.** WebSocket latency depends on physical distance. For a global player base, you'd want Reverb instances in multiple regions. Laravel Cloud handles this automatically; self-hosted requires more infrastructure work.

## When This Architecture Works Well

**Small to medium player counts.** This approach handles dozens of concurrent players without issue. The main constraint is that all players share one presence channel and receive all position updates.

**Turn-based or slow-paced games.** The 100ms update interval works well for exploration games, social spaces, or turn-based combat. Movement feels smooth thanks to interpolation.

**Authenticated experiences.** Since everything flows through Laravel authentication, you get user identity, permissions, and session management for free. Players can have persistent inventories, stats, or progress tied to their accounts.

**Rapid prototyping.** The biggest advantage is development speed. If you know Laravel, you can have a working multiplayer prototype in an afternoon. The same project in a custom game server would take significantly longer.

## When to Consider Alternatives

**Massive multiplayer.** If you're building an MMO with thousands of concurrent players in the same space, you'll need spatial partitioning (only broadcast to nearby players) or sharding (multiple game instances). This requires more sophisticated architecture than a single presence channel.

**Competitive real-time games.** For games where milliseconds matter (fighting games, competitive shooters), even WebSocket latency may be too high. Consider UDP-based protocols like WebRTC or dedicated game networking libraries. You'd also want server-authoritative movement to prevent cheating.

**Complex physics or AI.** If your game has server-side physics simulation, NPC AI, or complex game state, you need a game server that runs the simulation loop. This architecture works best when the client is trusted to report positions and the server's role is relay and persistence.

**Mobile with unreliable connections.** WebSockets can struggle with mobile network handoffs. For mobile games, consider protocols designed for unreliable connections, or implement robust reconnection logic.

## Conclusion

Laravel Reverb makes real-time multiplayer games accessible to Laravel developers. The combination of presence channels for user tracking, client events for low-latency position updates, and Phaser for rendering creates a capable foundation for multiplayer experiences.

The key insight is the identity chain: Laravel authentication provides the user, presence channels track their connection, and client events carry their movements to other players. Once you understand how these pieces connect, building multiplayer features becomes a matter of deciding what to broadcast and when.

You don't need to learn a new backend language or set up complex infrastructure. If you know Laravel, you can build real-time multiplayer games with the tools you already have.

The code for this project is available on GitHub. Questions or improvements? Open an issue or PR.