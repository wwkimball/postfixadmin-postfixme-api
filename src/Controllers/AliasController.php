<?php

namespace Pfme\Api\Controllers;

use Pfme\Api\Services\AliasService;

/**
 * Alias Controller - manages email alias operations
 */
class AliasController extends BaseController
{
    private AliasService $aliasService;

    public function __construct()
    {
        parent::__construct();
        $this->aliasService = new AliasService();
    }

    public function list(): void
    {
        $user = $this->getAuthenticatedUser();
        $params = $this->getQueryParams();

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(
            $this->config['pagination']['max_per_page'],
            max(1, (int)($params['per_page'] ?? $this->config['pagination']['default_per_page']))
        );

        $result = $this->aliasService->getAliasesForMailbox(
            $user['mailbox'],
            $params['q'] ?? null,
            $params['status'] ?? null,
            $page,
            $perPage,
            $params['sort'] ?? 'address'
        );

        $this->success($this->paginate($result['data'], $result['total']));
    }

    public function create(): void
    {
        $user = $this->getAuthenticatedUser();
        $input = $this->getJsonInput();

        // Validate input
        if (empty($input['local_part'])) {
            $this->error('local_part is required', 400, 'invalid_input');
        }

        if (empty($input['destinations']) || !is_array($input['destinations'])) {
            $this->error('destinations must be a non-empty array', 400, 'invalid_input');
        }

        try {
            $alias = $this->aliasService->createAlias(
                $input['local_part'],
                $user['domain'],
                $input['destinations'],
                $user['mailbox']
            );

            $this->success($alias, 201);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 400, 'creation_failed');
        }
    }

    public function update(string $id): void
    {
        $user = $this->getAuthenticatedUser();
        $input = $this->getJsonInput();

        try {
            $updates = [];

            if (isset($input['local_part'])) {
                $updates['local_part'] = $input['local_part'];
            }

            if (isset($input['destinations'])) {
                if (!is_array($input['destinations']) || empty($input['destinations'])) {
                    $this->error('destinations must be a non-empty array', 400, 'invalid_input');
                }
                $updates['destinations'] = $input['destinations'];
            }

            if (isset($input['active'])) {
                $updates['active'] = (bool)$input['active'];
            }

            $alias = $this->aliasService->updateAlias($id, $user['mailbox'], $updates);

            if (!$alias) {
                $this->error('Alias not found or access denied', 404, 'not_found');
            }

            $this->success($alias);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 400, 'update_failed');
        }
    }

    public function delete(string $id): void
    {
        $user = $this->getAuthenticatedUser();

        try {
            $result = $this->aliasService->deleteAlias($id, $user['mailbox']);

            if (!$result) {
                $this->error('Alias not found or access denied', 404, 'not_found');
            }

            $this->success(['message' => 'Alias deleted successfully']);
        } catch (\Exception $e) {
            $statusCode = ($e->getMessage() === 'Alias must be disabled before deletion') ? 409 : 400;
            $this->error($e->getMessage(), $statusCode, 'deletion_failed');
        }
    }
}
