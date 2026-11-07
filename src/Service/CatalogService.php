<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\Cacheable;
use App\Domain\Product;
use App\Repository\ProductRepository;

class CatalogService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /** @return list<Product> */
    #[Cacheable]
    public function listCategory(string $category): array
    {
        return $this->products->findByCategory($category);
    }

    public function product(int $id): Product
    {
        return $this->products->find($id) ?? throw new \InvalidArgumentException("Unknown product $id");
    }
}
