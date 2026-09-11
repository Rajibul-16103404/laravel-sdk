<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Omnicast\LaravelSdk\Constants\Role;
use Omnicast\LaravelSdk\Constants\WsAction;
use Omnicast\LaravelSdk\DTOs\WebhookEvent;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\OmnicastService;
use Omnicast\LaravelSdk\OmnicastServiceProvider;
use Orchestra\Testbench\TestCase;

class OmnicastServiceTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $validConfig = [
        'base_url' => 'http://127.0.0.1:8080',
        'api_key' => 'dev_api_key_123',
        'api_secret' => 'dev_api_secret_456',
        'jwt_secret' => 'live_media_server_jwt_secret_key_2026',
        'turn_secret' => 'my_super_secure_turn_secret_999',
        'turn_realm' => 'omnicast.live',
        'turn_port' => 3478,
        'webhook_secret' => 'webhook_secret_xyz',
        'timeout' => 15,
        'jwt_ttl' => 86400,
    ];

    protected function getPackageProviders($app): array
    {
        return [OmnicastServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('omnicast', $this->validConfig);
    }

    // =========================================================================
    // 1. Config Validation
    // =========================================================================

    public function test_throws_when_base_url_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/base_url/');

        new OmnicastService(array_merge($this->validConfig, ['base_url' => '', 'api_url' => '']));
    }

    public function test_throws_when_api_key_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        new OmnicastService(array_merge($this->validConfig, ['api_key' => '']));
    }

    public function test_throws_when_jwt_secret_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/jwt_secret/');

        new OmnicastService(array_merge($this->validConfig, ['jwt_secret' => '']));
    }

    public function test_falls_back_to_legacy_api_url_if_base_url_absent(): void
    {
        $config = $this->validConfig;
        unset($config['base_url']);
        $config['api_url'] = 'http://legacy.test:8080';

        $service = new OmnicastService($config);
        $this->assertSame('http://legacy.test:8080', $service->getConfig()['base_url']);
    }

    // =========================================================================
    // 2. Authentication & Token Generation (REST API)
    // =========================================================================

    public function test_generates_token_via_rest_api(): void
    {
        Http::fake([
            '*/api/auth/token' => Http::response([
                'status' => 'success',
                'token' => 'jwt.token.here',
                'user_id' => 'user_101',
                'user_name' => 'Meharab Islam',
                'expires_in' => 86400,
                'ice_servers' => [
                    ['urls' => ['stun:stun.l.google.com:19302']],
                ],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->generateToken(
            userId: 'user_101',
            userName: 'Meharab Islam',
            avatarUrl: 'https://cdn.example.com/avatar.jpg',
            role: Role::VIEWER,
            roomId: 'room_abc123',
            canPublish: false,
            canSubscribe: true,
        );

        $this->assertSame('success', $res['status']);
        $this->assertSame('jwt.token.here', $res['token']);
        $this->assertSame('user_101', $res['user_id']);
        $this->assertCount(1, $res['ice_servers']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8080/api/auth/token'
                && $request['user_id'] === 'user_101'
                && $request['api_key'] === 'dev_api_key_123'
                && $request['api_secret'] === 'dev_api_secret_456'
                && $request['role'] === 'viewer'
                && $request['can_publish'] === false
                && $request['can_subscribe'] === true;
        });
    }

    public function test_generates_livekit_token(): void
    {
        Http::fake([
            '*/api/livekit/token' => Http::response([
                'status' => 'success',
                'token' => 'livekit.jwt.token',
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->generateLivekitToken('user_lk', 'LK User', role: Role::HOST);

        $this->assertSame('livekit.jwt.token', $res['token']);
    }

    public function test_gets_demo_token(): void
    {
        Http::fake([
            '*/auth/demo-token*' => Http::response([
                'success' => true,
                'token' => 'demo.token',
                'user_id' => 'test_user',
                'role' => 'host',
                'room_id' => 'my_room',
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->getDemoToken('test_user', Role::HOST, 'my_room');

        $this->assertTrue($res['success']);
        $this->assertSame('demo.token', $res['token']);
    }

    // =========================================================================
    // 3. Local Token Generation (Offline JWT)
    // =========================================================================

    public function test_generates_valid_host_token_offline(): void
    {
        $service = new OmnicastService($this->validConfig);
        $token = $service->generateHostToken('room-live-1', 'host-1', ['tier' => 'vip']);

        $this->assertIsString($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame('host-1', $payload['user_id']);
        $this->assertSame('room-live-1', $payload['room_id']);
        $this->assertSame('host', $payload['role']);
        $this->assertTrue($payload['can_publish']);
        $this->assertTrue($payload['can_subscribe']);
        $this->assertSame('omnicast', $payload['iss']);
        $this->assertSame(['tier' => 'vip'], $payload['metadata']);
    }

    public function test_generates_valid_viewer_and_cohost_tokens_offline(): void
    {
        $service = new OmnicastService($this->validConfig);

        $viewerToken = $service->generateJoinToken('room-2', 'viewer-1', Role::VIEWER);
        $viewerPayload = json_decode(base64_decode(explode('.', $viewerToken)[1]), true);
        $this->assertSame(Role::VIEWER, $viewerPayload['role']);
        $this->assertFalse($viewerPayload['can_publish']);
        $this->assertTrue($viewerPayload['can_subscribe']);

        $cohostToken = $service->generateJoinToken('room-2', 'cohost-1', 'cohost');
        $cohostPayload = json_decode(base64_decode(explode('.', $cohostToken)[1]), true);
        $this->assertSame(Role::COHOST, $cohostPayload['role']);
        $this->assertTrue($cohostPayload['can_publish']);
        $this->assertTrue($cohostPayload['can_subscribe']);
    }

    public function test_throws_on_invalid_join_role(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/Invalid role/');

        $service = new OmnicastService($this->validConfig);
        $service->generateJoinToken('room-1', 'user-1', 'superadmin');
    }

    // =========================================================================
    // 4. Room Management
    // =========================================================================

    public function test_creates_room_successfully(): void
    {
        Http::fake([
            '*/api/rooms' => Http::response([
                'success' => true,
                'room' => [
                    'room_id' => 'room_live_abc123',
                    'host_id' => 'user_101',
                    'room_name' => "Meharab's Live Show",
                    'room_type' => 'live',
                ],
            ], 201),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->createRoom('room_live_abc123', 'user_101', "Meharab's Live Show", 'live');

        $this->assertTrue($res['success']);
        $this->assertSame('room_live_abc123', $res['room']['room_id']);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-API-KEY', 'dev_api_key_123')
                && $request->hasHeader('X-API-SECRET', 'dev_api_secret_456')
                && $request['room_id'] === 'room_live_abc123';
        });
    }

    public function test_create_room_conflict_throws_409(): void
    {
        Http::fake([
            '*/api/rooms' => Http::response([
                'success' => false,
                'error' => 'room already exists',
            ], 409),
        ]);

        $service = new OmnicastService($this->validConfig);

        try {
            $service->createRoom('room_live_abc123', 'user_101');
            $this->fail('Expected OmnicastException was not thrown.');
        } catch (OmnicastException $e) {
            $this->assertTrue($e->isConflict());
            $this->assertSame(409, $e->getHttpStatusCode());
        }
    }

    public function test_server_draining_throws_503(): void
    {
        Http::fake([
            '*/api/rooms' => Http::response([
                'success' => false,
                'status' => 503,
                'error' => 'Service Unavailable: Server is currently draining',
            ], 503),
        ]);

        $service = new OmnicastService($this->validConfig);

        try {
            $service->createRoom('room_live_abc123');
            $this->fail('Expected OmnicastException was not thrown.');
        } catch (OmnicastException $e) {
            $this->assertTrue($e->isDraining());
            $this->assertSame(503, $e->getHttpStatusCode());
        }
    }

    public function test_lists_rooms(): void
    {
        Http::fake([
            '*/api/rooms' => Http::response([
                [
                    'room_id' => 'room_1',
                    'room_name' => 'Room 1',
                    'host_id' => 'host_1',
                    'host_name' => 'Host 1',
                    'main_seat_id' => 'host_1',
                    'host_score' => 100,
                    'created_at' => '2026-09-07T18:35:39Z',
                    'viewers_count' => 50,
                    'is_active' => true,
                ],
                [
                    'room_id' => 'room_2',
                    'room_name' => 'Room 2',
                    'host_id' => 'host_2',
                    'viewers_count' => 10,
                    'is_active' => true,
                ],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $rooms = $service->listRooms();

        $this->assertCount(2, $rooms);
        $this->assertSame('room_1', $rooms[0]['room_id']);
        $this->assertSame(2, $service->getRoomCount());
    }

    public function test_gets_single_room(): void
    {
        Http::fake([
            '*/api/rooms/room_live_abc123' => Http::response([
                'room_id' => 'room_live_abc123',
                'room_name' => 'Live Show',
                'host_id' => 'user_101',
                'viewers_count' => 240,
                'is_active' => true,
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $room = $service->getRoom('room_live_abc123');

        $this->assertSame('room_live_abc123', $room['room_id']);
        $this->assertSame(240, $room['viewers_count']);
    }

    public function test_get_room_not_found_throws_404(): void
    {
        Http::fake([
            '*/api/rooms/non_existent' => Http::response([
                'status' => 'error',
                'success' => false,
                'error' => 'Room not found',
            ], 404),
        ]);

        $service = new OmnicastService($this->validConfig);

        try {
            $service->getRoom('non_existent');
            $this->fail('Expected OmnicastException was not thrown.');
        } catch (OmnicastException $e) {
            $this->assertTrue($e->isNotFound());
            $this->assertSame(404, $e->getHttpStatusCode());
        }
    }

    public function test_participant_count_falls_back_to_room_info(): void
    {
        Http::fake([
            '*/rooms/room-fallback/participants' => Http::response([], 404),
            '*/api/rooms/room-fallback' => Http::response([
                'room_id' => 'room-fallback',
                'viewers_count' => 77,
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $count = $service->getParticipantCount('room-fallback');

        $this->assertSame(77, $count);
    }

    // =========================================================================
    // 5. STUN / TURN ICE Servers
    // =========================================================================

    public function test_gets_ice_servers(): void
    {
        Http::fake([
            '*/api/ice-servers*' => Http::response([
                'iceServers' => [
                    ['urls' => ['stun:stun.l.google.com:19302']],
                    [
                        'urls' => ['turn:live.myvps.com:3478?transport=udp'],
                        'username' => '1762531200:user_101',
                        'credential' => 'base64credential',
                    ],
                ],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->getIceServers('user_101');

        $this->assertArrayHasKey('iceServers', $res);
        $this->assertCount(2, $res['iceServers']);
    }

    public function test_gets_turn_credentials(): void
    {
        Http::fake([
            '*/api/turn_credentials*' => Http::response([
                'uris' => ['turn:live.myvps.com:3478?transport=udp'],
                'username' => '1762531200:user_101',
                'password' => 'base64HMACSHA1==',
                'ttl' => 86400,
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->getTurnCredentials('user_101');

        $this->assertSame('1762531200:user_101', $res['username']);
        $this->assertSame(86400, $res['ttl']);
    }

    public function test_generates_turn_credentials_offline_rfc5766(): void
    {
        $service = new OmnicastService($this->validConfig);
        $creds = $service->generateTurnCredentials('user_101', ttl: 3600);

        $this->assertArrayHasKey('username', $creds);
        $this->assertArrayHasKey('password', $creds);
        $this->assertSame(3600, $creds['ttl']);
        $this->assertStringEndsWith(':user_101', $creds['username']);

        // Verify HMAC-SHA1 calculation
        $expected = base64_encode(hash_hmac('sha1', $creds['username'], 'my_super_secure_turn_secret_999', true));
        $this->assertSame($expected, $creds['password']);
    }

    // =========================================================================
    // 6. Admin Endpoints
    // =========================================================================

    public function test_admin_lists_rooms(): void
    {
        Http::fake([
            '*/api/admin/rooms' => Http::response([
                'status' => 'success',
                'total_active_rooms' => 1,
                'timestamp' => 1762444800,
                'rooms' => [
                    [
                        'room_id' => 'room_live_abc123',
                        'room_name' => "Meharab's Live Show",
                        'host_id' => 'user_101',
                        'total_viewers' => 240,
                        'host_score' => 1500,
                        'created_at' => '2026-09-07T18:35:39Z',
                        'uptime_seconds' => 3600,
                    ],
                ],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->adminListRooms();

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total_active_rooms']);
        $this->assertSame(3600, $res['rooms'][0]['uptime_seconds']);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-API-Key', 'dev_api_key_123');
        });
    }

    public function test_admin_force_ends_room(): void
    {
        Http::fake([
            '*/api/admin/rooms/room_live_abc123/end' => Http::response([
                'status' => 'success',
                'message' => 'Room forcefully ended and destroyed successfully',
                'room_id' => 'room_live_abc123',
                'timestamp' => 1762444800,
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->forceEndRoom('room_live_abc123');

        $this->assertSame('success', $res['status']);
        $this->assertSame('room_live_abc123', $res['room_id']);
    }

    // =========================================================================
    // 7. Gift / Engagement API
    // =========================================================================

    public function test_sends_gift(): void
    {
        Http::fake([
            '*/api/gift' => Http::response([
                'success' => true,
                'message' => 'Gift processed successfully',
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->sendGift(
            roomId: 'room_live_abc123',
            senderId: 'user_202',
            senderName: 'Ahmed',
            giftId: 'gift_rose_001',
            giftName: 'Rose',
            receiverId: 'user_101',
            coins: 50,
            points: 50,
            amount: 1,
        );

        $this->assertTrue($res['success']);
        $this->assertSame('Gift processed successfully', $res['message']);

        Http::assertSent(function (Request $request): bool {
            return $request['room_id'] === 'room_live_abc123'
                && $request['sender_id'] === 'user_202'
                && $request['coins'] === 50;
        });
    }

    // =========================================================================
    // 8. Health Check
    // =========================================================================

    public function test_health_check_healthy(): void
    {
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'message' => 'Live Media Server is running',
                'active_rooms' => 5,
                'draining' => false,
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $res = $service->healthCheck();

        $this->assertSame('ok', $res['status']);
        $this->assertTrue($service->isHealthy());
        $this->assertFalse($service->isDraining());
    }

    public function test_health_check_draining(): void
    {
        Http::fake([
            '*/health' => Http::response([
                'status' => 'draining',
                'message' => 'Server is draining connections',
                'active_rooms' => 2,
                'draining' => true,
            ], 503),
        ]);

        $service = new OmnicastService($this->validConfig);

        $this->assertFalse($service->isHealthy());
        $this->assertTrue($service->isDraining());
    }

    // =========================================================================
    // 9. WebSocket Signaling Helpers
    // =========================================================================

    public function test_formats_websocket_url(): void
    {
        $service = new OmnicastService($this->validConfig);
        $wsUrl = $service->getWebSocketUrl('jwt_token_sample');

        $this->assertSame('ws://127.0.0.1:8080/ws?token=jwt_token_sample', $wsUrl);

        $sslService = new OmnicastService(array_merge($this->validConfig, [
            'base_url' => 'https://live.omnicast.io',
        ]));
        $this->assertSame('wss://live.omnicast.io/ws?token=test_jwt', $sslService->getWebSocketUrl('test_jwt'));
    }

    public function test_formats_websocket_message(): void
    {
        $service = new OmnicastService($this->validConfig);
        $msg = $service->formatWebSocketMessage(
            action: WsAction::CHAT,
            roomId: 'room_123',
            userId: 'user_456',
            payload: ['message' => 'Hello World!'],
            extra: ['user_name' => 'Alice'],
        );

        $this->assertSame('chat', $msg['action']);
        $this->assertSame('chat', $msg['event']);
        $this->assertSame('room_123', $msg['room_id']);
        $this->assertSame('user_456', $msg['user_id']);
        $this->assertSame('Alice', $msg['user_name']);
        $this->assertSame(['message' => 'Hello World!'], $msg['payload']);
    }

    // =========================================================================
    // 10. Webhook Verification & Processing
    // =========================================================================

    public function test_verifies_webhook_signature_and_handles_event(): void
    {
        $service = new OmnicastService($this->validConfig);

        $payload = json_encode([
            'event_type' => 'RoomStarted',
            'event' => 'RoomStarted',
            'room_id' => 'room_live_abc123',
            'user_id' => 'user_101',
            'timestamp' => 1762444800,
            'data' => [
                'host_id' => 'user_101',
                'created_at' => '2026-09-07T18:35:39Z',
            ],
        ], JSON_THROW_ON_ERROR);

        $validSignature = hash_hmac('sha256', $payload, 'webhook_secret_xyz');
        $this->assertTrue($service->verifyWebhookSignature($payload, $validSignature));
        $this->assertFalse($service->verifyWebhookSignature($payload, 'invalid_signature_hex'));

        $event = $service->handleWebhook($payload, $validSignature);
        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('RoomStarted', $event->eventType);
        $this->assertSame('room_live_abc123', $event->roomId);
        $this->assertSame('user_101', $event->userId);
        $this->assertSame('user_101', $event->get('host_id'));
    }

    public function test_handle_webhook_throws_on_tampered_payload(): void
    {
        $service = new OmnicastService($this->validConfig);

        $payload = '{"event":"RoomEnded"}';
        $invalidSig = 'wrong_sig';

        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/Invalid OmniCast webhook signature/');

        $service->handleWebhook($payload, $invalidSig);
    }

    // =========================================================================
    // 11. Utilities & Facade
    // =========================================================================

    public function test_calculates_room_uptime(): void
    {
        $service = new OmnicastService($this->validConfig);
        $past = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);

        $uptime = $service->getRoomUptime($past);
        $this->assertGreaterThanOrEqual(3599, $uptime);
        $this->assertLessThanOrEqual(3605, $uptime);
    }

    public function test_facade_resolves_instance(): void
    {
        $this->assertSame('http://127.0.0.1:8080', Omnicast::getConfig()['base_url']);

        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'draining' => false], 200),
        ]);

        $this->assertTrue(Omnicast::isHealthy());
    }
}
