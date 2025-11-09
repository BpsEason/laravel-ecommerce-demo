<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        // 清空所有快取，確保測試環境一致
        Cache::flush();
    }

    /** @test */
    public function a_user_can_get_a_list_of_products()
    {
        Category::factory()->create(['name' => 'Electronics']);
        Product::factory(5)->create(['category_id' => Category::first()->id]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
                 ->assertJsonCount(5, 'data')
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'name', 'description', 'price', 'stock', 'category', 'image_url', 'created_at', 'updated_at']
                     ],
                     'links',
                     'meta'
                 ]);
    }

    /** @test */
    public function products_can_be_filtered_by_category()
    {
        $category1 = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        $category2 = Category::factory()->create(['name' => 'Books', 'slug' => 'books']);

        Product::factory(3)->create(['category_id' => $category1->id]);
        Product::factory(2)->create(['category_id' => $category2->id]);

        $response = $this->getJson('/api/products?category=electronics');

        $response->assertOk()
                 ->assertJsonCount(3, 'data')
                 ->assertJsonFragment(['name' => 'Electronics']);
    }

    /** @test */
    public function products_can_be_searched_by_name_or_description()
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Laptop Pro', 'description' => 'Powerful laptop']);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Gaming Mouse', 'description' => 'Ergonomic design']);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Keyboard', 'description' => 'Mechanical keyboard']);

        $response = $this->getJson('/api/products?search=laptop');
        $response->assertOk()
                 ->assertJsonCount(1, 'data')
                 ->assertJsonFragment(['name' => 'Laptop Pro']);

        $response = $this->getJson('/api/products?search=design');
        $response->assertOk()
                 ->assertJsonCount(1, 'data')
                 ->assertJsonFragment(['name' => 'Gaming Mouse']);
    }

    /** @test */
    public function products_list_is_paginated()
    {
        $category = Category::factory()->create();
        Product::factory(15)->create(['category_id' => $category->id]);

        $response = $this->getJson('/api/products?per_page=5');

        $response->assertOk()
                 ->assertJsonCount(5, 'data')
                 ->assertJsonPath('meta.total', 15)
                 ->assertJsonPath('meta.per_page', 5)
                 ->assertJsonPath('meta.current_page', 1);

        $response = $this->getJson('/api/products?per_page=5&page=2');
        $response->assertOk()
                 ->assertJsonPath('meta.current_page', 2);
    }

    /** @test */
    public function a_user_can_get_a_single_product_details()
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'Test Product', 'price' => 99.99]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertOk()
                 ->assertJson([
                     'data' => [
                         'id' => $product->id,
                         'name' => 'Test Product',
                         'price' => 99.99,
                         'category' => ['id' => $category->id, 'name' => $category->name],
                     ]
                 ]);
    }

    /** @test */
    public function it_returns_404_if_product_not_found()
    {
        $response = $this->getJson('/api/products/9999'); // 不存在的商品ID

        $response->assertStatus(404);
    }

    /** @test */
    public function a_user_can_get_a_list_of_categories()
    {
        Category::factory(3)->create();

        $response = $this->getJson('/api/categories');

        $response->assertOk()
                 ->assertJsonCount(3, 'data')
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'name', 'slug']
                     ]
                 ]);
    }

    /** @test */
    public function an_authenticated_user_can_create_a_product()
    {
        $user = User::factory()->create(); // 創建一個用戶來認證
        $category = Category::factory()->create();

        $productData = [
            'name' => 'New Product',
            'description' => 'This is a new product.',
            'price' => 123.45,
            'stock' => 50,
            'category_id' => $category->id,
            'image_url' => $this->faker->imageUrl(),
        ];

        $response = $this->actingAs($user, 'sanctum') // 使用 Sanctum 認證
                         ->postJson('/api/products', $productData);

        $response->assertStatus(201) // 創建成功
                 ->assertJsonFragment(['name' => 'New Product', 'price' => 123.45]);

        $this->assertDatabaseHas('products', ['name' => 'New Product']);
    }

    /** @test */
    public function product_creation_requires_valid_data()
    {
        $user = User::factory()->create(); // 創建一個用戶來認證

        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/products', [
                             'name' => '', // 缺少名稱
                             'price' => -10, // 無效價格
                             'stock' => 'abc', // 無效庫存
                             'category_id' => 999, // 不存在的分類
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'price', 'stock', 'category_id']);
    }

    /** @test */
    public function products_are_cached_after_first_request()
    {
        $category = Category::factory()->create();
        Product::factory(2)->create(['category_id' => $category->id]);

        // 第一次請求，應該會從資料庫獲取並快取
        $this->getJson('/api/products');
        $cacheKey = "products_page_1_perpage_10_search__category_"; // 預設快取鍵

        $this->assertTrue(Cache::has($cacheKey));

        // 清空資料庫，第二次請求應該從快取中讀取
        Product::query()->delete();

        $response = $this->getJson('/api/products');
        $response->assertOk()
                 ->assertJsonCount(2, 'data'); // 仍然能讀到 2 個產品，因為來自快取
    }
}