<?php

namespace Pfme\Api\Controllers;

/**
 * Base Controller with common functionality
 */
abstract class BaseController
{
    protected array $config;
    protected array $authUser;

    public function __construct(?array $authUser = null)
    {
        $this->config = require __DIR__ . '/../../config/config.php';
        $this->authUser = $authUser ?? [];
    }

    protected function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $decoded = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON input', 400, 'invalid_json');
        }

        return $decoded ?? [];
    }

    protected function getQueryParams(): array
    {
        return $_GET;
    }

    protected function getAuthenticatedUser(): array
    {
        return $this->authUser;
    }

    protected function success(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
    {
        http_response_code($statusCode);

        $response = [
            'code' => $code,
            'message' => $message,
        ];

        if (!empty($details)) {
            $response['details'] = $details;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function paginate(array $data, int $total): array
    {
        $params = $this->getQueryParams();

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(
            $this->config['pagination']['max_per_page'],
            max(1, (int)($params['per_page'] ?? $this->config['pagination']['default_per_page']))
        );

        $totalPages = (int)ceil($total / $perPage);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
