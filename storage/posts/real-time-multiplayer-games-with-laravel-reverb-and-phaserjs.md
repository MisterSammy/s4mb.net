---
title: "Real-Time Multiplayer Games with Laravel Reverb and Phaser.js"
date: 2026-01-09
excerpt: "You don't need a Node.js backend or a dedicated game server to build multiplayer browser games. This post shows how Laravel Reverb and Phaser.js combine to create a real-time multiplayer experience using the stack you already know."
tags: [laravel, websockets, reverb, phaser, multiplayer, tutorial]
slug: real-time-multiplayer-games-with-laravel-reverb-and-phaserjs
---

If you're a Laravel developer who's ever wanted to build a real-time multiplayer game, you've probably assumed you'd need to step outside your comfort zone. Maybe reach for Socket.io with a Node.js backend, or set up a dedicated game server in Go or Rust. The Laravel ecosystem, powerful as it is, isn't typically associated with game development.

But here's the thing: Laravel Reverb changes that equation. Combined with Phaser.js on the frontend, you can build a fully functional real-time multiplayer 2D game using the framework you already know. No new backend language required.

This post walks through how I built a multiplayer game where authenticated users move around a shared world, seeing each other's positions update in real-time. The key insight - and what makes this feel magical - is understanding how a logged-in Laravel user maps to a character on a Phaser canvas.

Here's the architecture we're working with:

```plaintext
app/
├── Events/
│   ├── PlayerJoined.php          # Broadcast when player enters
│   └── PlayerMoved.php           # Broadcast position updates
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
3. **Broadcast events carry user ID with position data**

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

Laravel's presence channels are designed for exactly this use case - tracking who's connected to a shared space. The channel authorization returns user data that other clients can see:

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

The presence channel handles the hard part. Tracking who's online - automatically. You don't need to maintain a list of connected users or handle disconnection cleanup. Laravel does it for you.

### Step 3: Position Events

When a player moves, the client sends their new position to the server:

```javascript
broadcastPosition() {
    fetch('/game/position', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        },
        body: JSON.stringify({
            x: this.character.x,
            y: this.character.y
        })
    });
}
```

The server receives this, attaches the authenticated user's identity, and broadcasts to everyone:

```php
// app/Http/Controllers/GameController.php
public function updatePosition(Request $request)
{
    $request->validate([
        'x' => 'required|numeric',
        'y' => 'required|numeric',
    ]);

    $user = Auth::user();
    
    // Broadcast includes user identity from server
    event(new PlayerMoved(
        $user->id,
        $user->name,
        $request->x,
        $request->y
    ));

    return response()->json(['success' => true]);
}
```

The event itself broadcasts on the presence channel:

```php
// app/Events/PlayerMoved.php
class PlayerMoved implements ShouldBroadcastNow
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
        return 'player.moved';
    }
}
```

Other clients receive this event and update the corresponding player's position:

```javascript
.listen('.player.moved', (e) => {
    const userId = e.userId;
    const x = e.x;
    const y = e.y;
    
    if (userId && userId !== this.userId) {
        if (!this.otherPlayers.has(userId)) {
            this.addOtherPlayer(userId, e.userName, x, y);
        } else {
            this.updateOtherPlayerPosition(userId, x, y);
        }
    }
});
```

**This is the connection.** The user ID from Laravel authentication flows through:
- Blade template → JavaScript scene
- Presence channel → WebSocket connection
- Broadcast events → Position updates

Every player knows who they are, the server validates that identity, and all position updates carry trusted user information.

## The Data Flow

Here's how a single movement propagates through the system:

```plaintext
┌─────────────────────────────────────────────────────────────────────┐
│                         PLAYER A'S BROWSER                          │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐ │
│  │  Phaser Scene   │    │  Laravel Echo   │    │   HTTP Client   │ │
│  │                 │    │                 │    │                 │ │
│  │ WASD Key Press  │───▶│                 │    │                 │ │
│  │ Update Position │    │                 │    │ POST /game/     │ │
│  │                 │───────────────────────────▶│    position    │ │
│  └─────────────────┘    └─────────────────┘    └────────┬────────┘ │
└─────────────────────────────────────────────────────────┼──────────┘
                                                          │
                                                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         LARAVEL SERVER                              │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐ │
│  │ GameController  │    │  PlayerMoved    │    │  Laravel Reverb │ │
│  │                 │    │     Event       │    │                 │ │
│  │ Auth::user()    │───▶│ userId, x, y    │───▶│  Broadcast to   │ │
│  │ Validate input  │    │ ShouldBroadcast │    │ presence-game   │ │
│  │                 │    │      Now        │    │                 │ │
│  └─────────────────┘    └─────────────────┘    └────────┬────────┘ │
└─────────────────────────────────────────────────────────┼──────────┘
                                                          │
                          ┌───────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         PLAYER B'S BROWSER                          │
│  ┌─────────────────┐    ┌─────────────────┐                        │
│  │  Laravel Echo   │    │  Phaser Scene   │                        │
│  │                 │    │                 │                        │
│  │ .listen(        │───▶│ updateOther     │                        │
│  │  'player.moved')│    │ PlayerPosition  │                        │
│  │                 │    │ (userId, x, y)  │                        │
│  └─────────────────┘    └─────────────────┘                        │
└─────────────────────────────────────────────────────────────────────┘
```

The round trip happens in milliseconds. Player A presses W, their position updates locally, the server broadcasts, and Player B sees them move - all while both players remain authenticated Laravel users.

## Setting Up Laravel Reverb

Laravel Reverb is Laravel's first-party WebSocket server. Unlike Pusher (which is a hosted service), Reverb runs on your own infrastructure. The configuration lives in two places.

**Backend broadcasting configuration:**

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

Yes, you read that right - the frontend uses `pusher-js` even though Reverb isn't Pusher. Reverb implements the Pusher protocol, so the existing Pusher JavaScript library works out of the box. This is a deliberate design choice that lets you use battle-tested client libraries.

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
        this.otherPlayers = new Map(); // userId -> {text, nameText, position}
        
        // Networking
        this.lastBroadcastTime = 0;
        this.broadcastInterval = 100; // ms
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
        
        // Broadcast initial position
        this.broadcastJoin(startX, startY);
    }

    update(time, delta) {
        // Handle movement
        this.handleMovement(delta);
        
        // Interpolate other players
        this.interpolateOtherPlayers();
    }
}
```

The game renders characters as ASCII text;`@` for the local player, letters like `T`, `R`, `F` for others. This gives it a retro terminal aesthetic, but the same architecture works for sprite-based games.

## Key Design Decisions

### Why `ShouldBroadcastNow`?

Both events implement `ShouldBroadcastNow` instead of `ShouldBroadcast`:

```php
class PlayerMoved implements ShouldBroadcastNow
```

The difference is queue handling. `ShouldBroadcast` puts the event on Laravel's queue, adding latency. For a game where position updates need to arrive immediately, bypassing the queue is essential. The trade-off is that broadcasting happens synchronously in the HTTP request, but for simple position data, this is negligible.

### Why Throttle Position Updates?

The client doesn't broadcast every frame. Instead, it throttles to 100ms intervals:

```javascript
broadcastPosition() {
    const now = Date.now();
    if (now - this.lastBroadcastTime < this.broadcastInterval) {
        return;
    }
    this.lastBroadcastTime = now;
    
    // Send position to server
    fetch('/game/position', { ... });
}
```

At 60 FPS, broadcasting every frame would mean 60 HTTP requests per second per player. That's excessive and would overwhelm the server. 100ms (10 updates per second) provides smooth movement without network spam.

### Why Interpolation?

Network updates arrive at 100ms intervals, but the game renders at 60 FPS. Without interpolation, other players would jump between positions. Smooth interpolation fills the gaps:

```javascript
this.otherPlayers.forEach((player) => {
    // Lerp from current position toward target
    const lerpSpeed = 0.15;
    player.gridX = Phaser.Math.Linear(player.gridX, player.targetGridX, lerpSpeed);
    player.gridY = Phaser.Math.Linear(player.gridY, player.targetGridY, lerpSpeed);
    
    // Update visual position
    const displayPos = gridToWorld(Math.round(player.gridX), Math.round(player.gridY));
    player.text.setPosition(displayPos.x, displayPos.y);
});
```

This creates the illusion of smooth movement even though we only receive 10 position updates per second.

### Why Presence Channels?

Presence channels provide automatic user tracking. When a player's browser closes or their connection drops, Laravel automatically fires the `leaving` callback for all other clients. You don't need to implement heartbeats, timeouts, or cleanup logic - it's handled at the framework level.

The alternative would be managing a list of connected users yourself, implementing keep-alive pings, and handling edge cases like network interruptions. Presence channels abstract all of that away.

## Handling the Join Flow

When a player first loads the game, they need to announce their existence:

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

Other clients receive this and create a new player sprite. The presence channel's `here()` callback handles players who were already in the game when you joined, while `joining()` handles players who arrive after you.

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

For production, you'd run Reverb as a daemon:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## When This Architecture Works Well

**Small to medium player counts.** This approach handles dozens of concurrent players without issue. For hundreds of players, you'd want to consider spatial partitioning (only broadcast to nearby players) or a dedicated game server.

**Turn-based or slow-paced games.** The 100ms update interval works well for exploration games, social spaces, or turn-based combat. For fast-paced shooters requiring 60Hz updates, you'd need a more optimized protocol.

**Authenticated experiences.** Since everything flows through Laravel authentication, you get user identity, permissions, and session management for free. Players can have persistent inventories, stats, or progress tied to their accounts.

**Rapid prototyping.** The biggest advantage is development speed. If you know Laravel, you can have a working multiplayer prototype in an afternoon. The same project in a custom game server would take significantly longer.

## When to Consider Alternatives

**Massive multiplayer.** If you're building an MMO with thousands of concurrent players, you'll need infrastructure designed for that scale. Consider dedicated game servers, sharding, or services built for massive concurrency.

**Competitive real-time games.** For games where milliseconds matter (fighting games, competitive shooters), the HTTP-to-WebSocket round trip adds latency that might be unacceptable. Consider UDP-based protocols or dedicated game networking libraries.

**Complex game state.** If your game has complex physics, AI, or state that needs to be authoritative on the server, you might want a game server that runs the simulation. This approach is better suited to games where the client is trusted to report positions.

## Conclusion

Laravel Reverb makes real-time multiplayer games accessible to Laravel developers. The combination of presence channels for user tracking, broadcast events for state synchronization, and Phaser for rendering creates a capable foundation for multiplayer experiences.

The key insight is the identity chain: Laravel authentication provides the user, presence channels track their connection, and broadcast events carry their actions to other players. Once you understand how these pieces connect, building multiplayer features becomes a matter of deciding what to broadcast and when.

You don't need to learn a new backend language or set up complex infrastructure. If you know Laravel, you can build real-time multiplayer games with the tools you already have.

