<?php

namespace App\Http\Controllers;

use App\Http\Requests\Products\IndexProductRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\ProductSearch;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public function index(IndexProductRequest $request): AnonymousResourceCollection
    {
        $products = $this->products->search(
            $request->filters(),
            $request->integer('per_page', 15),
            $request->integer('page', 1),
        );
        $products->appends($request->validated());

        return ProductResource::collection($products);
    }

    public function suggestions(IndexProductRequest $request, ProductSearch $search): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:255']]);
        $results = $search->search($request->filters(), min($request->integer('per_page', 10), 20), 1, true);

        return response()->json(['data' => $results->getCollection()->map(
            fn (Product $product): array => ['id' => $product->id, 'name' => $product->name],
        )]);
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        return new ProductResource($this->products->create($request->validated()));
    }

    public function show(int $product): ProductResource
    {
        return new ProductResource($this->products->findOrFail($product));
    }

    public function update(UpdateProductRequest $request, int $product): ProductResource
    {
        return new ProductResource($this->products->update($product, $request->validated()));
    }

    public function destroy(int $product): Response
    {
        $this->products->delete($product);

        return response()->noContent();
    }
}
