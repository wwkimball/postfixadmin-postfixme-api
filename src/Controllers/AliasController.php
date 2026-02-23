<?php
/**
 * PostfixMe API
 *
 * @package   PostfixMe API
 * @copyright Copyright (c) 2026 William Kimball, Jr., MBA, MSIS
 * @license   GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */


namespace Pfme\Api\Controllers;

use Pfme\Api\Services\AliasService;
use Pfme\Api\Services\ValidationService;

/**
 * Alias Controller - manages email alias operations
 */
class AliasController extends BaseController
{
    private AliasService $aliasService;
    private ValidationService $validationService;

    public function __construct(?array $authUser = null)
    {
        parent::__construct($authUser);
        $this->aliasService = new AliasService();
        $this->validationService = new ValidationService();
    }

    public function list(): void
    {
        $user = $this->getAuthenticatedUser();
        $params = $this->getQueryParams();

        $sort = $params['sort'] ?? 'address';
        $order = $params['order'] ?? null;
        $status = $params['status'] ?? null;

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(
            $this->config['pagination']['max_per_page'],
            max(1, (int)($params['per_page'] ?? $this->config['pagination']['default_per_page']))
        );

        $result = $this->aliasService->getAliasesForMailbox(
            $user['mailbox'],
            $params['q'] ?? null,
            $sort,
            $order,
            $page,
            $perPage,
            $status
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

        // Validate RFC 5321 local part format
        $validation = $this->validationService->validateLocalPart($input['local_part']);
        if (!$validation['valid']) {
            $this->error($validation['error'], 400, 'invalid_local_part_format');
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
            $this->exceptionError($e, 400, 'creation_failed');
        }
    }

    public function update(string $id): void
    {
        $user = $this->getAuthenticatedUser();
        $input = $this->getJsonInput();

        try {
            $updates = [];

            if (isset($input['local_part'])) {
                // Validate RFC 5321 local part format
                $validation = $this->validationService->validateLocalPart($input['local_part']);
                if (!$validation['valid']) {
                    $this->error($validation['error'], 400, 'invalid_local_part_format');
                }
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
            $this->exceptionError($e, 400, 'update_failed');
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
            $this->exceptionError($e, $statusCode, 'deletion_failed');
        }
    }

    public function destinations(): void
    {
        $user = $this->getAuthenticatedUser();
        $params = $this->getQueryParams();

        try {
            $mailboxes = $this->aliasService->getAvailableMailboxes(
                $user['domain'],
                $params['q'] ?? null
            );

            // Extract just the email addresses
            $destinations = array_map(function ($mailbox) {
                return $mailbox['email'];
            }, $mailboxes);

            $this->success(['destinations' => $destinations]);
        } catch (\Exception $e) {
            $this->exceptionError($e, 400, 'destinations_failed');
        }
    }
}
