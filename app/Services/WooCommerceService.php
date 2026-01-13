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
                'timeout' => 30,
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
}
