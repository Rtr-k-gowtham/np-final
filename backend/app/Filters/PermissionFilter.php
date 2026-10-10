<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('perm');

        $required = $arguments ?? [];
        if (empty($required)) return;

        foreach ($required as $code) {
            if (has_permission($code)) return;
        }

        return service('response')->setStatusCode(403)->setJSON([
            'success' => false,
            'message' => 'Access denied (missing permission)',
            'data'    => null,
            'errors'  => null
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
