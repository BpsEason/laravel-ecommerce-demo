<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase; // 每次測試後重置資料庫，保證測試獨立性

    /** @test */
    public function a_user_can_register()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'user' => ['id', 'name', 'email', 'created_at', 'updated_at'],
                     'access_token',
                     'token_type',
                 ])
                 ->assertJsonFragment(['email' => 'john@example.com']);

        // 驗證用戶已寫入資料庫
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);
    }

    /** @test */
    public function registration_requires_valid_data()
    {
        $response = $this->postJson('/api/register', [
            'name' => '', // 缺少名稱
            'email' => 'invalid-email', // 無效的 email 格式
            'password' => 'short', // 密碼太短
            'password_confirmation' => 'mismatch', // 密碼不匹配
        ]);

        $response->assertStatus(422) // HTTP 422 Unprocessable Entity
                 ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    /** @test */
    public function a_user_can_login_and_get_a_token()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk() // HTTP 200 OK
                 ->assertJsonStructure([
                     'message',
                     'user' => ['id', 'name', 'email'],
                     'access_token',
                     'token_type',
                 ])
                 ->assertJsonFragment(['email' => 'test@example.com']);

        // 驗證用戶成功獲取了 token
        $this->assertNotNull($user->fresh()->tokens()->first());
    }

    /** @test */
    public function login_fails_with_invalid_credentials()
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password', // 錯誤密碼
        ]);

        $response->assertStatus(422) // 無效憑證也通常返回 422
                 ->assertJsonValidationErrors(['email']); // 或其他指示憑證錯誤的訊息
    }

    /** @test */
    public function a_logged_in_user_can_access_their_profile()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        $response->assertOk()
                 ->assertJson([
                     'user' => [
                         'id' => $user->id,
                         'name' => $user->name,
                         'email' => $user->email,
                     ]
                 ]);
    }

    /** @test */
    public function a_logged_in_user_can_update_their_profile()
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'name' => 'Old Name',
        ]);
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->putJson('/api/user', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            // 不傳遞密碼表示不修改密碼
        ]);

        $response->assertOk()
                 ->assertJson([
                     'message' => 'User profile updated successfully',
                     'user' => [
                         'id' => $user->id,
                         'name' => 'New Name',
                         'email' => 'new@example.com',
                     ]
                 ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    /** @test */
    public function a_user_can_logout()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/logout');

        $response->assertOk()
                 ->assertJson(['message' => 'Logged out successfully']);

        // 驗證 token 已被刪除
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertEmpty($user->fresh()->tokens);
    }
}