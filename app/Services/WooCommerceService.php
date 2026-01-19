<?php

namespace App\Services;

use Automattic\WooCommerce\Client;

class WooCommerceService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.woocommerce.url'),
            config('services.woocommerce.key'),
            config('services.woocommerce.secret'),
            [
                'version' => config('services.woocommerce.version'),
                // 'timeout' => 300,
            ]
        );
    }

    public function getProducts(array $params = [])
    {
        return $this->client->get('products', $params);
    }

    public function createProduct(array $data)
    {
        return $this->client->post('products', $data);
    }

    public function updateProduct(int $id, array $data)
    {
        return $this->client->put("products/{$id}", $data);
    }

    public function deleteProduct(int $id)
    {
        return $this->client->delete("products/{$id}", ['force' => true]);
    }

    public function getProduct(int $id)
    {
        return $this->client->get("products/{$id}");
    }

    /**
     * Batch create, update, or delete products
     * 
     * @param array $data Array with 'create', 'update', and/or 'delete' keys
     * @return mixed
     */
    public function batchProducts(array $data)
    {
        return $this->client->post('products/batch', $data);
    }

    /**
     * Get categories with optional parameters
     */
    public function getCategories(array $params = [])
    {
        return $this->client->get('products/categories', $params);
    }

    /**
     * Batch create, update, or delete categories
     * 
     * @param array $data Array with 'create', 'update', and/or 'delete' keys
     * @return mixed
     */
    public function batchCategories(array $data)
    {
        return $this->client->post('products/categories/batch', $data);
    }
}
