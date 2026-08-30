<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;
use Omnicast\LaravelSdk\OmnicastService;
use Orchestra\Testbench\TestCase;
use Omnicast\LaravelSdk\OmnicastServiceProvider;

class OmnicastServiceTest extends TestCase
{
    private array $validConfig = [
        'api_url'    => 'https://omnilive.lolipoplive.top/api',
        'api_key'    => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'jwt_secret' => 'test-jwt-secret-32-chars-minimum!',
        'timeout'    => 30,
        'jwt_ttl'    => 86400,
    ];

    protected function getPackageProviders($app): array
    {
        return [OmnicastServiceProvider::class];
    }

    // -------------------------------------------------------------------------
    // Constructor / Config Validation
    // -------------------------------------------------------------------------

    /** @test */
    public function it_throws_when_api_url_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/api_url/');

        new OmnicastService(array_merge($this->validConfig, ['api_url' => '']));
    }

    /** @test */
    public function it_throws_when_api_key_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        new OmnicastService(array_merge($this->validConfig, ['api_key' => null]));
    }

    /** @test */
    public function it_throws_when_jwt_secret_is_missing(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/jwt_secret/');

        new OmnicastService(array_merge($this->validConfig, ['jwt_secret' => '']));
    }

    // -------------------------------------------------------------------------
    // Token Generation
    // -------------------------------------------------------------------------

    /** @test */
    public function it_generates_a_valid_host_token(): void
    {
        $service = new OmnicastService($this->validConfig);
        $token   = $service->generateHostToken('room-1', 'user-1', ['name' => 'Alice']);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token); // JWT has 3 segments

        // Decode and verify payload (without verifying signature for simplicity)
        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(str_pad($parts[1], strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=')), true);

        $this->assertSame('host', $payload['role']);
        $this->assertSame('room-1', $payload['room_id']);
        $this->assertSame('user-1', $payload['user_id']);
        $this->assertSame('test-api-key', $payload['api_key']);
        $this->assertSame(['name' => 'Alice'], $payload['metadata']);
    }

    /** @test */
    public function it_generates_a_valid_viewer_join_token(): void
    {
        $service = new OmnicastService($this->validConfig);
        $token   = $service->generateJoinToken('room-2', 'user-2');

        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(str_pad($parts[1], strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=')), true);

        $this->assertSame('viewer', $payload['role']);
    }

    /** @test */
    public function it_generates_a_valid_co_host_join_token(): void
    {
        $service = new OmnicastService($this->validConfig);
        $token   = $service->generateJoinToken('room-3', 'user-3', 'co-host');

        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(str_pad($parts[1], strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=')), true);

        $this->assertSame('co-host', $payload['role']);
    }

    /** @test */
    public function it_throws_for_invalid_join_role(): void
    {
        $this->expectException(OmnicastException::class);
        $this->expectExceptionMessageMatches('/Invalid role/');

        $service = new OmnicastService($this->validConfig);
        $service->generateJoinToken('room-1', 'user-1', 'admin');
    }

    // -------------------------------------------------------------------------
    // REST API – Rooms
    // -------------------------------------------------------------------------

    /** @test */
    public function it_returns_rooms_on_success(): void
    {
        Http::fake([
            '*/rooms' => Http::response([
                ['id' => 'r1', 'name' => 'Room One'],
                ['id' => 'r2', 'name' => 'Room Two'],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $rooms   = $service->getRooms();

        $this->assertCount(2, $rooms);
        $this->assertSame('r1', $rooms[0]['id']);
    }

    /** @test */
    public function it_throws_on_failed_rooms_request(): void
    {
        Http::fake([
            '*/rooms' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(OmnicastException::class);

        $service = new OmnicastService($this->validConfig);
        $service->getRooms();
    }

    /** @test */
    public function it_returns_correct_room_count(): void
    {
        Http::fake([
            '*/rooms' => Http::response([
                ['id' => 'r1'],
                ['id' => 'r2'],
                ['id' => 'r3'],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $this->assertSame(3, $service->getRoomCount());
    }

    // -------------------------------------------------------------------------
    // REST API – Participants
    // -------------------------------------------------------------------------

    /** @test */
    public function it_returns_participants_on_success(): void
    {
        Http::fake([
            '*/rooms/room-1/participants' => Http::response([
                ['id' => 'p1', 'name' => 'Alice'],
                ['id' => 'p2', 'name' => 'Bob'],
            ], 200),
        ]);

        $service      = new OmnicastService($this->validConfig);
        $participants = $service->getParticipants('room-1');

        $this->assertCount(2, $participants);
        $this->assertSame('p1', $participants[0]['id']);
    }

    /** @test */
    public function it_throws_on_failed_participants_request(): void
    {
        Http::fake([
            '*/rooms/*/participants' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $this->expectException(OmnicastException::class);

        $service = new OmnicastService($this->validConfig);
        $service->getParticipants('nonexistent-room');
    }

    /** @test */
    public function it_returns_correct_participant_count(): void
    {
        Http::fake([
            '*/rooms/room-x/participants' => Http::response([
                ['id' => 'p1'],
                ['id' => 'p2'],
                ['id' => 'p3'],
                ['id' => 'p4'],
            ], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $this->assertSame(4, $service->getParticipantCount('room-x'));
    }

    /** @test */
    public function it_sends_correct_auth_headers(): void
    {
        Http::fake([
            '*/rooms' => Http::response([], 200),
        ]);

        $service = new OmnicastService($this->validConfig);
        $service->getRooms();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-API-Key', 'test-api-key')
                && $request->hasHeader('X-API-Secret', 'test-api-secret')
                && $request->hasHeader('Accept', 'application/json');
        });
    }
}
