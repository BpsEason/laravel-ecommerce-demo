<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * 取得商品列表 (支援搜尋、分類、分頁)
     * GET /api/products
     */
    public function index(Request $request)
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search');
        $categorySlug = $request->query('category');

        // 使用 Cache key 包含所有查詢參數，確保不同查詢有不同快取
        $cacheKey = "products_page_{$page}_perpage_{$perPage}_search_{$search}_category_{$categorySlug}";

        $products = Cache::remember($cacheKey, 60 * 5, function () use ($search, $categorySlug, $perPage) {
            // 所有讀取操作走 Read Replica
            $query = Product::on('mysql_read')
                        ->with('category'); // 預加載分類，避免 N+1 問題

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($categorySlug) {
                $query->whereHas('category', function ($q) use ($categorySlug) {
                    $q->where('slug', $categorySlug);
                });
            }

            return $query->paginate($perPage);
        });

        return ProductResource::collection($products);
    }

    /**
     * 取得商品詳情
     * GET /api/products/{id}
     */
    public function show($id)
    {
        $cacheKey = "product_{$id}";

        $product = Cache::remember($cacheKey, 60 * 5, function () use ($id) {
            // 讀取操作走 Read Replica
            return Product::on('mysql_read')->with('category')->findOrFail($id);
        });

        return new ProductResource($product);
    }

    /**
     * 取得所有分類
     * GET /api/categories
     */
    public function categories()
    {
        $cacheKey = "categories_all";

        $categories = Cache::remember($cacheKey, 60 * 60, function () {
            // 讀取操作走 Read Replica
            return Category::on('mysql_read')->get();
        });

        return response()->json(CategoryResource::collection($categories));
    }

    // 後台管理員新增/更新/刪除商品，這些操作走主庫
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'category_id' => 'required|exists:categories,id',
            'image_url' => 'nullable|url',
        ]);

        // 寫入操作走主庫
        $product = Product::on('mysql_write')->create($request->all());

        // 清除相關快取
        Cache::forget("products_page_*"); // 清除所有產品列表快取
        Cache::forget("product_{$product->id}"); // 清除單個產品詳情快取

        return new ProductResource($product);
    }
}